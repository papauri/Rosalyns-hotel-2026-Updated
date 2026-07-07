<?php

/**
 * Gym Members — enrolled membership register.
 *
 * The operational heart of the Gym/Fitness preset: who is enrolled, on what
 * package, and when their membership lapses. Distinct from gym-inquiries.php
 * (sales leads); an inquiry that converts becomes a row here.
 *
 * Backed by the gym_members table (migration
 * admin/migrations/2026_07_03_create_gym_members.sql) — until that runs,
 * the page shows a "pending migration" notice instead of failing.
 */
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once __DIR__ . '/includes/gym-checkin-lib.php';
require_once __DIR__ . '/includes/gym-analytics-lib.php';
require_once __DIR__ . '/includes/gym-reminders-lib.php';

/** @var PDO $pdo */
/** @var array $user */
/** @var string $csrf_token */

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$gm_json = static function (bool $ok, string $msg): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit;
};

$gm_statuses = ['active', 'expired', 'suspended', 'cancelled'];

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gm_action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $gm_json(false, 'Security token invalid — refresh the page.');
    }
    $action = (string)$_POST['gm_action'];

    try {
        if ($action === 'member_save') {
            $memberId = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $packageId = (int)($_POST['package_id'] ?? 0);
            $type = trim((string)($_POST['membership_type'] ?? ''));
            $start = trim((string)($_POST['start_date'] ?? ''));
            $expiry = trim((string)($_POST['expiry_date'] ?? ''));
            $fee = ($_POST['monthly_fee'] ?? '') !== '' ? (float)($_POST['monthly_fee'] ?? 0) : null;
            $status = in_array($_POST['status'] ?? '', $gm_statuses, true) ? (string)$_POST['status'] : 'active';
            $notes = trim((string)($_POST['notes'] ?? ''));
            $changeReason = trim((string)($_POST['change_reason'] ?? ''));
            $isComplimentary = !empty($_POST['is_complimentary']) ? 1 : 0;

            if ($name === '' || mb_strlen($name) > 255) {
                $gm_json(false, 'Member name is required (max 255 characters).');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $gm_json(false, 'Enter a valid email address or leave it empty.');
            }
            $startDt = DateTime::createFromFormat('Y-m-d', $start);
            if (!$startDt) {
                $gm_json(false, 'A valid start date is required.');
            }
            if ($expiry !== '' && !DateTime::createFromFormat('Y-m-d', $expiry)) {
                $gm_json(false, 'Expiry date must be a valid date or left empty.');
            }
            if ($fee !== null && ($fee < 0 || $fee > 99999999)) {
                $gm_json(false, 'Fee must be zero or a positive amount.');
            }

            // Identity & accounting protection (AD-style): a member record is a
            // person, not a reusable slot. Renaming it — or repricing away from
            // the package — requires the gym_financials permission, is
            // rate-limited, and every change is audit-logged. Prevents staff
            // "recycling" one member's card/fee for someone else.
            $gm_can_financials = hasPermission((int)($user['id'] ?? 0), 'gym_financials');
            $gm_is_admin = ($user['role'] ?? '') === 'admin';

            // ── Package is the source of truth ──────────────────────────────
            // A chosen package sets the display name, the fee, the complimentary
            // flag, and (with the start date) the expiry. Non-privileged staff
            // can ONLY enrol against a real package, never free-type a price.
            $pkgDurationDays = null;
            if ($packageId > 0) {
                try {
                    $pkgStmt = $pdo->prepare("SELECT name, price, duration_days, is_complimentary FROM gym_packages WHERE id = ? AND is_active = 1 LIMIT 1");
                    $pkgStmt->execute([$packageId]);
                    $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);
                } catch (Throwable $e) { $pkg = false; }
                if (!$pkg) {
                    $gm_json(false, 'The selected package is no longer available. Refresh and try again.');
                }
                $type = (string)$pkg['name'];
                $isComplimentary = (int)$pkg['is_complimentary'] === 1 ? 1 : $isComplimentary;
                $pkgDurationDays = $pkg['duration_days'] !== null ? (int)$pkg['duration_days'] : null;
                // Complimentary → always free. Otherwise the package price is
                // authoritative unless a financials user typed an override.
                if ($isComplimentary) {
                    $fee = 0.0;
                } elseif (!$gm_can_financials || $fee === null) {
                    $fee = (float)$pkg['price'];
                }
                // Expiry auto-computes from start + package duration unless a
                // financials user supplied an explicit override date.
                if ($expiry === '' || !$gm_can_financials) {
                    $expiry = (string)(gymComputeExpiry($start, $pkgDurationDays) ?? '');
                }
            } else {
                // No package selected.
                if ($isComplimentary) {
                    $fee = 0.0;
                    if ($type === '') { $type = 'Complimentary'; }
                } elseif (!$gm_can_financials) {
                    // Non-privileged staff cannot enrol a paid member without a
                    // package (no free-typed pricing / open-ended memberships).
                    if ($memberId === 0) {
                        $gm_json(false, 'Choose a membership package. Only a manager can enrol a member without one.');
                    }
                }
            }

            if ($memberId > 0) {
                $curStmt = $pdo->prepare("SELECT * FROM gym_members WHERE id = ?");
                $curStmt->execute([$memberId]);
                $cur = $curStmt->fetch(PDO::FETCH_ASSOC);
                if (!$cur) {
                    $gm_json(false, 'Member not found.');
                }

                $nameChanged = $name !== (string)$cur['full_name'];

                // ── Name-change guards ──────────────────────────────────────
                // A membership is not transferable to another person. Name edits
                // are for genuine legal-name corrections only.
                if ($nameChanged) {
                    if (!$gm_can_financials) {
                        $gm_json(false, 'Member name changes require a manager (legal name corrections only — a membership is not transferable to another person).');
                    }
                    if ($changeReason === '' || mb_strlen($changeReason) < 5) {
                        $gm_json(false, 'A reason (min 5 characters) is required to change a member\'s name. This is recorded in the audit log.');
                    }
                    // Rate-limit churn: non-admins get max 2 name changes / 180 days.
                    $priorChanges = (int)($cur['name_change_count'] ?? 0);
                    $lastChanged = $cur['name_last_changed_at'] ?? null;
                    $within180 = $lastChanged !== null && (strtotime($lastChanged) > strtotime('-180 days'));
                    if (!$gm_is_admin && $within180 && $priorChanges >= 2) {
                        $gm_json(false, 'This member\'s name has already been changed twice in the last 180 days. Only an administrator can change it again — contact one to proceed.');
                    }
                }

                if (!$gm_can_financials) {
                    // Fee is never hand-set by non-privileged users: it either
                    // follows a real package (derived above) or stays as stored.
                    if ($packageId === 0 && !$isComplimentary) {
                        $fee = $cur['monthly_fee'] !== null ? (float)$cur['monthly_fee'] : null;
                    }
                }

                // Name-change bookkeeping for the guard.
                $newNameCount = (int)($cur['name_change_count'] ?? 0) + ($nameChanged ? 1 : 0);
                $nameChangedAtSql = $nameChanged ? date('Y-m-d H:i:s') : ($cur['name_last_changed_at'] ?? null);

                $stmt = $pdo->prepare("UPDATE gym_members SET full_name=?, email=?, phone=?, membership_type=?, start_date=?, expiry_date=?, monthly_fee=?, is_complimentary=?, status=?, notes=?, name_change_count=?, name_last_changed_at=? WHERE id=?");
                $stmt->execute([$name, $email ?: null, $phone ?: null, $type ?: null, $start, $expiry ?: null, $fee, $isComplimentary, $status, $notes ?: null, $newNameCount, $nameChangedAtSql, $memberId]);

                // ── Full audit trail ────────────────────────────────────────
                $before = [
                    'full_name' => (string)$cur['full_name'],
                    'membership_type' => (string)($cur['membership_type'] ?? ''),
                    'start_date' => (string)($cur['start_date'] ?? ''),
                    'expiry_date' => (string)($cur['expiry_date'] ?? ''),
                    'monthly_fee' => $cur['monthly_fee'],
                    'is_complimentary' => (int)($cur['is_complimentary'] ?? 0),
                    'status' => (string)($cur['status'] ?? ''),
                ];
                $after = [
                    'full_name' => $name,
                    'membership_type' => (string)$type,
                    'start_date' => $start,
                    'expiry_date' => (string)$expiry,
                    'monthly_fee' => $fee,
                    'is_complimentary' => $isComplimentary,
                    'status' => $status,
                ];
                if (function_exists('logGymMemberAudit')) {
                    $auditAction = $nameChanged ? 'name_changed' : 'updated';
                    $auditNote = $nameChanged ? ('Name change reason: ' . $changeReason) : ($changeReason ?: null);
                    logGymMemberAudit($memberId, $auditAction, $before, $after, $auditNote, (string)($cur['member_number'] ?? ''));
                }
                $gm_json(true, 'Member updated.');
            }
            do {
                $memberNumber = 'GM-' . strtoupper(substr(uniqid(), -6));
                $chk = $pdo->prepare("SELECT COUNT(*) FROM gym_members WHERE member_number = ?");
                $chk->execute([$memberNumber]);
            } while ((int)$chk->fetchColumn() > 0);
            $inquiryId = (int)($_POST['gym_inquiry_id'] ?? 0);
            $stmt = $pdo->prepare("INSERT INTO gym_members (member_number, full_name, email, phone, membership_type, start_date, expiry_date, monthly_fee, is_complimentary, status, notes, gym_inquiry_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$memberNumber, $name, $email ?: null, $phone ?: null, $type ?: null, $start, $expiry ?: null, $fee, $isComplimentary, $status, $notes ?: null, $inquiryId ?: null, (int)($user['id'] ?? 0)]);
            $newMemberId = (int)$pdo->lastInsertId();

            if (function_exists('logGymMemberAudit')) {
                logGymMemberAudit($newMemberId, $isComplimentary ? 'complimentary_granted' : 'enrolled', null, [
                    'full_name' => $name,
                    'membership_type' => (string)$type,
                    'start_date' => $start,
                    'expiry_date' => (string)$expiry,
                    'monthly_fee' => $fee,
                    'is_complimentary' => $isComplimentary,
                    'status' => $status,
                ], $notes ?: null, $memberNumber);
            }

            // Converting an inquiry: mark the sales lead converted so the
            // pipeline reflects reality. Best-effort — never blocks enrolment.
            if ($inquiryId > 0) {
                try {
                    $pdo->prepare("UPDATE gym_inquiries SET status='converted' WHERE id=? AND status NOT IN ('cancelled')")->execute([$inquiryId]);
                } catch (Throwable $e) { /* fine */ }
            }

            // Digital membership card (barcode) email — never blocks the enrolment.
            $cardNote = '';
            if ($email !== '') {
                try {
                    require_once '../config/email.php';
                    $cardResult = sendGymMemberCardEmail([
                        'member_number'   => $memberNumber,
                        'full_name'       => $name,
                        'email'           => $email,
                        'membership_type' => $type,
                        'expiry_date'     => $expiry ?: null,
                    ]);
                    $cardNote = !empty($cardResult['success'])
                        ? ' Membership card emailed.'
                        : ' (Card email failed: ' . (string)($cardResult['message'] ?? 'unknown error') . ')';
                } catch (Throwable $mailEx) {
                    error_log('gym-members card email: ' . $mailEx->getMessage());
                    $cardNote = ' (Card email failed — member saved.)';
                }
            }
            $gm_json(true, 'Member enrolled — ' . $memberNumber . '.' . $cardNote);
        }

        if ($action === 'member_card') {
            $memberId = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT member_number, full_name, email, membership_type, expiry_date FROM gym_members WHERE id = ?");
            $stmt->execute([$memberId]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) {
                $gm_json(false, 'Member not found.');
            }
            if (empty($member['email'])) {
                $gm_json(false, 'This member has no email address on file.');
            }
            require_once '../config/email.php';
            $cardResult = sendGymMemberCardEmail($member);
            $gm_json(!empty($cardResult['success']), (string)($cardResult['message'] ?? 'Card email failed.'));
        }

        if ($action === 'member_attendance') {
            $memberId = (int)($_POST['id'] ?? 0);
            if (!gym_attendance_table_exists($pdo)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'visits' => [], 'pending_migration' => true]);
                exit;
            }
            // Paginated visit log — 10 rows per page.
            $perPage = 10;
            $vpage = max(1, (int)($_POST['page'] ?? 1));
            $vTotal = (int)$pdo->query("SELECT COUNT(*) FROM gym_attendance WHERE member_id = " . (int)$memberId)->fetchColumn();
            $vPages = max(1, (int)ceil($vTotal / $perPage));
            if ($vpage > $vPages) { $vpage = $vPages; }
            $vOffset = ($vpage - 1) * $perPage;
            $stmt = $pdo->prepare("
                SELECT checked_in_at, checked_out_at, method,
                       CASE WHEN checked_out_at IS NULL THEN NULL
                            ELSE TIMESTAMPDIFF(MINUTE, checked_in_at, checked_out_at) END AS minutes
                FROM gym_attendance WHERE member_id = ?
                ORDER BY checked_in_at DESC LIMIT {$perPage} OFFSET {$vOffset}
            ");
            $stmt->execute([$memberId]);
            $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Page-only response: the visits table paginates without re-sending
            // the heavier stats/histograms on every page turn.
            if (!empty($_POST['visits_only'])) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success'     => true,
                    'visits'      => $visits,
                    'page'        => $vpage,
                    'total_pages' => $vPages,
                    'total'       => $vTotal,
                ]);
                exit;
            }

            // Personal peak profile from ALL of this member's visits: per-hour
            // histogram + weekday preference, for targeted marketing.
            $hourRows = $pdo->prepare("SELECT HOUR(checked_in_at) AS h, COUNT(*) AS c FROM gym_attendance WHERE member_id = ? GROUP BY h");
            $hourRows->execute([$memberId]);
            $hours = [];
            foreach ($hourRows->fetchAll(PDO::FETCH_ASSOC) as $hr) { $hours[(int)$hr['h']] = (int)$hr['c']; }
            $dayRows = $pdo->prepare("SELECT WEEKDAY(checked_in_at) AS d, COUNT(*) AS c FROM gym_attendance WHERE member_id = ? GROUP BY d");
            $dayRows->execute([$memberId]);
            $wdays = [];
            foreach ($dayRows->fetchAll(PDO::FETCH_ASSOC) as $dr) { $wdays[(int)$dr['d']] = (int)$dr['c']; }

            // In-depth fitness stats for the visit report: totals, averages,
            // consistency, weekday spread — all from this member's history.
            $statRow = $pdo->prepare("
                SELECT COUNT(*) AS total_visits,
                       SUM(CASE WHEN checked_in_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS visits_30d,
                       SUM(CASE WHEN checked_in_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS visits_7d,
                       AVG(CASE WHEN checked_out_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, checked_in_at, checked_out_at) END) AS avg_minutes,
                       MAX(CASE WHEN checked_out_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, checked_in_at, checked_out_at) END) AS longest_minutes,
                       SUM(CASE WHEN checked_out_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, checked_in_at, checked_out_at) ELSE 0 END) AS total_minutes,
                       MIN(checked_in_at) AS first_visit,
                       MAX(checked_in_at) AS last_visit,
                       COUNT(DISTINCT DATE(checked_in_at)) AS distinct_days
                FROM gym_attendance WHERE member_id = ?
            ");
            $statRow->execute([$memberId]);
            $stats = $statRow->fetch(PDO::FETCH_ASSOC) ?: [];

            // Weekly consistency: visits per ISO week over the last 8 weeks.
            $weekRows = $pdo->prepare("
                SELECT YEARWEEK(checked_in_at, 3) AS yw, COUNT(*) AS c
                FROM gym_attendance
                WHERE member_id = ? AND checked_in_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
                GROUP BY yw ORDER BY yw ASC
            ");
            $weekRows->execute([$memberId]);
            $weeks = $weekRows->fetchAll(PDO::FETCH_KEY_PAIR);

            // Segment for the marketing angle in the report header.
            $memRow = $pdo->prepare("SELECT status, start_date, expiry_date, membership_type FROM gym_members WHERE id = ?");
            $memRow->execute([$memberId]);
            $mem = $memRow->fetch(PDO::FETCH_ASSOC) ?: [];
            $segment = function_exists('gym_member_segment')
                ? gym_member_segment($mem, (int)($stats['visits_30d'] ?? 0), (int)($stats['total_visits'] ?? 0), $stats['last_visit'] ?? null)
                : null;

            // Time-of-day distribution (morning/midday/evening) for training-habit insight.
            $dayparts = ['early' => 0, 'morning' => 0, 'midday' => 0, 'afternoon' => 0, 'evening' => 0];
            foreach ($hours as $h => $c) {
                if ($h < 6)        { $dayparts['early']     += $c; }
                elseif ($h < 11)   { $dayparts['morning']   += $c; }
                elseif ($h < 14)   { $dayparts['midday']    += $c; }
                elseif ($h < 17)   { $dayparts['afternoon'] += $c; }
                else               { $dayparts['evening']   += $c; }
            }

            // Attendance streaks (consecutive distinct days) + monthly trend for
            // momentum. Pull distinct visit dates once, derive in PHP.
            $dateRows = $pdo->prepare("SELECT DISTINCT DATE(checked_in_at) d FROM gym_attendance WHERE member_id = ? ORDER BY d ASC");
            $dateRows->execute([$memberId]);
            $dates = $dateRows->fetchAll(PDO::FETCH_COLUMN);
            $longestStreak = 0; $currentStreak = 0; $prev = null;
            foreach ($dates as $ds) {
                if ($prev !== null && (strtotime($ds) - strtotime($prev)) === 86400) { $currentStreak++; }
                else { $currentStreak = 1; }
                if ($currentStreak > $longestStreak) { $longestStreak = $currentStreak; }
                $prev = $ds;
            }
            // "Current" streak counts only if the last visit day is today or yesterday.
            $activeStreak = 0;
            if ($prev !== null && (strtotime(date('Y-m-d')) - strtotime($prev)) <= 86400) {
                $activeStreak = $currentStreak;
            }

            // 6-month visit trend for the momentum sparkline.
            $monthRows = $pdo->prepare("
                SELECT DATE_FORMAT(checked_in_at, '%Y-%m') ym, COUNT(*) c
                FROM gym_attendance
                WHERE member_id = ? AND checked_in_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY ym ORDER BY ym ASC
            ");
            $monthRows->execute([$memberId]);
            $months = $monthRows->fetchAll(PDO::FETCH_KEY_PAIR);

            // Membership value: total paid to date vs visits = cost-per-visit,
            // a concrete retention/renewal talking point.
            $costPerVisit = null; $paidTotal = null;
            if (!empty($mem['membership_type'])) {
                try {
                    $paidStmt = $pdo->prepare("
                        SELECT COALESCE(SUM(p.total_amount),0)
                        FROM payments p
                        JOIN gym_inquiries gi ON gi.id = p.booking_id
                        JOIN gym_members gm ON gm.gym_inquiry_id = gi.id
                        WHERE gm.id = ? AND p.booking_type='gym'
                          AND p.payment_status IN ('completed','paid')
                          AND COALESCE(p.payment_type,'') <> 'refund' AND p.deleted_at IS NULL
                    ");
                    $paidStmt->execute([$memberId]);
                    $paidTotal = (float)$paidStmt->fetchColumn();
                    $tv = (int)($stats['total_visits'] ?? 0);
                    if ($paidTotal > 0 && $tv > 0) { $costPerVisit = round($paidTotal / $tv, 2); }
                } catch (Throwable $e) { /* optional */ }
            }

            header('Content-Type: application/json');
            echo json_encode([
                'success'       => true,
                'visits'        => $visits,
                'page'          => $vpage,
                'total_pages'   => $vPages,
                'total'         => $vTotal,
                'profile'       => gym_peak_profile($hours, $wdays),
                'hours'         => $hours,
                'wdays'         => $wdays,
                'stats'         => $stats,
                'weeks'         => $weeks,
                'segment'       => $segment,
                'dayparts'      => $dayparts,
                'streak'        => ['current' => $activeStreak, 'longest' => $longestStreak],
                'months'        => $months,
                'cost_per_visit' => $costPerVisit,
                'paid_total'    => $paidTotal,
            ]);
            exit;
        }

        if ($action === 'reminder_settings') {
            $enabled = !empty($_POST['enabled']) ? '1' : '0';
            $days = (int)($_POST['days'] ?? 3);
            if ($days < 1 || $days > 30) {
                $gm_json(false, 'Reminder days must be between 1 and 30.');
            }
            updateSetting('gym_reminder_enabled', $enabled);
            updateSetting('gym_reminder_days', (string)$days);
            $gm_json(true, $enabled === '1'
                ? 'Renewal reminders on — members are emailed ' . $days . ' day(s) before expiry.'
                : 'Renewal reminders switched off.');
        }

        if ($action === 'run_reminders') {
            require_once '../config/email.php';
            $run = gym_run_expiry_reminders($pdo);
            if ($run['pending_migration']) {
                $gm_json(false, 'Reminder log table missing — run admin/migrations/2026_07_04_gym_reminder_log.sql first.');
            }
            if ($run['disabled']) {
                $gm_json(false, 'Reminders are switched off — enable them first.');
            }
            $msg = 'Checked ' . $run['checked'] . ' due membership(s): ' . $run['sent'] . ' reminder(s) sent, ' . $run['skipped'] . ' already reminded.';
            if (!empty($run['errors'])) {
                $msg .= ' Errors: ' . implode(' | ', array_slice($run['errors'], 0, 3));
            }
            $gm_json(empty($run['errors']), $msg);
        }

        if ($action === 'member_status') {
            $memberId = (int)($_POST['id'] ?? 0);
            $status = in_array($_POST['status'] ?? '', $gm_statuses, true) ? (string)$_POST['status'] : '';
            if ($status === '') {
                $gm_json(false, 'Invalid status.');
            }
            $prevStmt = $pdo->prepare("SELECT status, member_number FROM gym_members WHERE id = ?");
            $prevStmt->execute([$memberId]);
            $prevRow = $prevStmt->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare("UPDATE gym_members SET status=? WHERE id=?")->execute([$status, $memberId]);
            if ($prevRow && function_exists('logGymMemberAudit')) {
                logGymMemberAudit($memberId, 'status_changed',
                    ['status' => (string)$prevRow['status']],
                    ['status' => $status], null, (string)$prevRow['member_number']);
            }
            $gm_json(true, 'Member marked ' . $status . '.');
        }

        if ($action === 'member_delete') {
            $memberId = (int)($_POST['id'] ?? 0);
            // Deleting a membership record is destructive and loses the audit
            // trail's subject — restrict to managers with financials rights.
            if (!hasPermission((int)($user['id'] ?? 0), 'gym_financials')) {
                $gm_json(false, 'Deleting a member requires a manager. Mark them cancelled instead to preserve their history.');
            }
            $delStmt = $pdo->prepare("SELECT full_name, member_number, membership_type, monthly_fee, status FROM gym_members WHERE id = ?");
            $delStmt->execute([$memberId]);
            $delRow = $delStmt->fetch(PDO::FETCH_ASSOC);
            if ($delRow && function_exists('logGymMemberAudit')) {
                logGymMemberAudit($memberId, 'deleted', $delRow, null, 'Member record permanently deleted', (string)$delRow['member_number']);
            }
            $pdo->prepare("DELETE FROM gym_members WHERE id=?")->execute([$memberId]);
            $gm_json(true, 'Member deleted.');
        }

        if ($action === 'member_history') {
            $memberId = (int)($_POST['id'] ?? 0);
            // History exposes past identity/pricing — gate behind gym_logs.
            if (!hasPermission((int)($user['id'] ?? 0), 'gym_logs')) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'You do not have permission to view member history.']);
                exit;
            }
            $entries = function_exists('getGymMemberAuditLog') ? getGymMemberAuditLog($memberId, 100) : [];
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'entries' => $entries]);
            exit;
        }

        $gm_json(false, 'Unknown action.');
    } catch (PDOException $e) {
        error_log('gym-members: ' . $e->getMessage());
        $gm_json(false, 'Database error — has the gym_members migration been run?');
    }
}

// ── Data ─────────────────────────────────────────────────────────────────────
$gm_table_missing = false;
$gm_members = [];
$gm_counts = ['active' => 0, 'expiring' => 0, 'expired' => 0, 'all' => 0];
$gm_filter = (string)($_GET['filter'] ?? 'all');
try {
    $gm_counts['all']      = (int)$pdo->query("SELECT COUNT(*) FROM gym_members")->fetchColumn();
    $gm_counts['active']   = (int)$pdo->query("SELECT COUNT(*) FROM gym_members WHERE status='active'")->fetchColumn();
    $gm_counts['expiring'] = (int)$pdo->query("SELECT COUNT(*) FROM gym_members WHERE status='active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    $gm_counts['expired']  = (int)$pdo->query("SELECT COUNT(*) FROM gym_members WHERE status='expired' OR (expiry_date IS NOT NULL AND expiry_date < CURDATE())")->fetchColumn();

    $where = '1=1';
    if ($gm_filter === 'active')   { $where = "status='active'"; }
    if ($gm_filter === 'expiring') { $where = "status='active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"; }
    if ($gm_filter === 'expired')  { $where = "(status='expired' OR (expiry_date IS NOT NULL AND expiry_date < CURDATE()))"; }
    $gm_members = $pdo->query("SELECT * FROM gym_members WHERE $where ORDER BY status='active' DESC, expiry_date IS NULL ASC, expiry_date ASC, full_name ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $gm_table_missing = true;
}

// Attendance aggregates — degrade to empty until gym_attendance migration runs
$gm_attendance_ready = !$gm_table_missing && gym_attendance_table_exists($pdo);
$gm_visit_stats = [];      // member_id => ['visits' => n, 'last_in' => datetime, 'in_now' => 0|1]
$gm_in_gym_now = 0;
$gm_visits_today = 0;
$gm_peak_stats = [];       // member_id => ['hours' => [h=>c], 'wdays' => [d=>c]]
if ($gm_attendance_ready) {
    // Sweep stale open visits first so "In gym now" and last-visit stats
    // never count someone who forgot to scan out on a previous day.
    gym_auto_checkout_stale($pdo);
    try {
        foreach ($pdo->query("
            SELECT member_id, COUNT(*) AS visits, MAX(checked_in_at) AS last_in,
                   SUM(CASE WHEN checked_out_at IS NULL THEN 1 ELSE 0 END) AS in_now,
                   SUM(CASE WHEN checked_in_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS visits_30d
            FROM gym_attendance GROUP BY member_id
        ")->fetchAll(PDO::FETCH_ASSOC) as $vs) {
            $gm_visit_stats[(int)$vs['member_id']] = $vs;
        }
        // Per-member check-in hour + weekday histograms (small table; one pass each)
        foreach ($pdo->query("SELECT member_id, HOUR(checked_in_at) AS h, COUNT(*) AS c FROM gym_attendance GROUP BY member_id, h")->fetchAll(PDO::FETCH_ASSOC) as $hr) {
            $gm_peak_stats[(int)$hr['member_id']]['hours'][(int)$hr['h']] = (int)$hr['c'];
        }
        foreach ($pdo->query("SELECT member_id, WEEKDAY(checked_in_at) AS d, COUNT(*) AS c FROM gym_attendance GROUP BY member_id, d")->fetchAll(PDO::FETCH_ASSOC) as $dr) {
            $gm_peak_stats[(int)$dr['member_id']]['wdays'][(int)$dr['d']] = (int)$dr['c'];
        }
        $gm_in_gym_now   = (int)$pdo->query("SELECT COUNT(*) FROM gym_attendance WHERE checked_out_at IS NULL")->fetchColumn();
        $gm_visits_today = (int)$pdo->query("SELECT COUNT(*) FROM gym_attendance WHERE checked_in_at >= CURDATE()")->fetchColumn();
    } catch (PDOException $e) {
        $gm_attendance_ready = false;
    }
}

// Renewal-reminder configuration + last-run summary
$gm_reminder_cfg   = gym_reminder_settings();
$gm_reminder_ready = !$gm_table_missing && gym_reminder_log_table_exists($pdo);
$gm_reminder_run   = $gm_reminder_ready ? gym_reminder_last_run($pdo) : ['last_sent_at' => null, 'sent_today' => 0, 'total' => 0];

// Active packages drive the enrolment modal: id, price, duration (for auto
// expiry) and the complimentary flag, all consumed by the modal JS so the
// register never diverges from package pricing.
$gm_packages = [];
try {
    $gm_packages = $pdo->query("SELECT id, name, price, duration_days, duration_label, is_complimentary FROM gym_packages WHERE is_active=1 ORDER BY display_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* optional */ }

$gm_can_financials_ui = hasPermission((int)($user['id'] ?? 0), 'gym_financials');
$gm_can_logs_ui = hasPermission((int)($user['id'] ?? 0), 'gym_logs');
// Free "hotel guest" memberships only make sense when the gym is part of a
// hotel (bookings module on) rather than the main preset. Used to surface the
// complimentary option in the modal.
$gm_is_hotel_context = function_exists('moduleEnabled') && moduleEnabled('bookings');

$gm_currency = (string)getSetting('currency_symbol', 'K');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function() {
            var _t = '<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>';
            var _f = window.fetch;
            window.fetch = function(u, o) {
                if (o && o.body instanceof FormData && !o.body.has('csrf_token')) o.body.append('csrf_token', _t);
                return _f.apply(this, arguments);
            };
        })();
    </script>
    <title>Gym Members - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/menu-management.css?v=<?php echo @filemtime(__DIR__ . '/css/menu-management.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title">Gym Members</h2>
            <?php if (!$gm_table_missing): ?>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <?php if ($gm_attendance_ready): ?>
                <span class="cat-count" title="Members checked in right now"><i class="fas fa-person-running"></i> In gym now: <?php echo $gm_in_gym_now; ?></span>
                <span class="cat-count" title="Check-ins recorded today"><i class="fas fa-clock"></i> Visits today: <?php echo $gm_visits_today; ?></span>
                <?php endif; ?>
                <?php if (hasPermission((int)$user['id'], 'gym_checkin')): ?>
                <a class="btn-add" href="gym-checkin.php" style="text-decoration:none;background:#111827;color:#ffffff;">
                    <i class="fas fa-barcode"></i> Check-In Scanner
                </a>
                <?php endif; ?>
                <?php if (hasPermission((int)$user['id'], 'gym_reports')): ?>
                <a class="btn-add" href="gym-reports.php" style="text-decoration:none;background:#8B7355;color:#ffffff;">
                    <i class="fas fa-chart-line"></i> Gym Reports
                </a>
                <?php endif; ?>
                <button class="btn-add" onclick="gmOpenModal()">
                    <i class="fas fa-user-plus"></i> Enrol Member
                </button>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($gm_table_missing): ?>
            <?php showAlert('The membership register table (gym_members) has not been created yet — run the migration in admin/migrations/2026_07_03_create_gym_members.sql, then reload this page.', 'error'); ?>
        <?php else: ?>

        <!-- Renewal reminder engine — configurable days-before-expiry email -->
        <div style="background:#fff;border:1px solid #d5cfc4;border-radius:4px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
            <span style="font-weight:700;color:#8B7355;font-size:.82rem;letter-spacing:.05em;text-transform:uppercase;">
                <i class="fas fa-bell"></i> Renewal Reminders
            </span>
            <label style="display:inline-flex;align-items:center;gap:7px;font-size:.86rem;color:#3e3930;cursor:pointer;">
                <input type="checkbox" id="gmRemEnabled" <?php echo $gm_reminder_cfg['enabled'] ? 'checked' : ''; ?>>
                Email members
            </label>
            <label style="display:inline-flex;align-items:center;gap:7px;font-size:.86rem;color:#3e3930;">
                <input type="number" id="gmRemDays" min="1" max="30" value="<?php echo (int)$gm_reminder_cfg['days']; ?>" style="width:64px;padding:6px 8px;border:1px solid #d3cbc0;border-radius:4px;">
                day(s) before expiry
            </label>
            <button class="mm-btn mm-btn-sm" onclick="gmSaveReminderSettings()"><i class="fas fa-save"></i> Save</button>
            <button class="mm-btn mm-btn-sm" style="background:#8B7355;color:#fff;" onclick="gmRunReminders(this)" title="Checks for memberships expiring within the window and emails any not yet reminded — safe to click repeatedly">
                <i class="fas fa-paper-plane"></i> Send due reminders now
            </button>
            <span style="font-size:.78rem;color:#9a8f82;margin-left:auto;">
                <?php if (!$gm_reminder_ready): ?>
                    <i class="fas fa-triangle-exclamation" style="color:#B18247;"></i> Log table pending — run admin/migrations/2026_07_04_gym_reminder_log.sql
                <?php elseif ($gm_reminder_run['last_sent_at']): ?>
                    Last reminder sent <?php echo htmlspecialchars(date('M j, H:i', strtotime((string)$gm_reminder_run['last_sent_at']))); ?> · <?php echo (int)$gm_reminder_run['sent_today']; ?> today · <?php echo (int)$gm_reminder_run['total']; ?> all-time
                <?php else: ?>
                    No reminders sent yet — cron: scripts/gym_membership_reminders.php (daily)
                <?php endif; ?>
            </span>
        </div>

        <div class="menu-type-tabs" style="margin-bottom:18px;">
            <?php foreach (['all' => 'All', 'active' => 'Active', 'expiring' => 'Expiring ≤30d', 'expired' => 'Expired'] as $fk => $fl): ?>
                <a class="menu-type-tab <?php echo $gm_filter === $fk ? 'active' : ''; ?>" href="?filter=<?php echo $fk; ?>" style="text-decoration:none;">
                    <?php echo $fl; ?> <span class="cat-count" style="margin-left:6px;"><?php echo (int)$gm_counts[$fk]; ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($gm_members)): ?>
            <div class="empty-state">
                <i class="fas fa-id-card"></i>
                <p><?php echo $gm_filter === 'all' ? 'No members enrolled yet. Enrol your first member to start the register.' : 'No members match this filter.'; ?></p>
            </div>
        <?php else: ?>
            <table class="menu-table">
                <thead>
                    <tr>
                        <th style="width:110px;">Member #</th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Package</th>
                        <th style="width:100px;">Started</th>
                        <th style="width:100px;">Expires</th>
                        <th style="width:110px;">Fee (<?php echo htmlspecialchars($gm_currency); ?>/mo)</th>
                        <?php if ($gm_attendance_ready): ?>
                        <th style="width:120px;" title="Most recent check-in">Last Visit</th>
                        <th style="width:90px;" title="Total visits, and visits in the last 30 days">Visits</th>
                        <th style="width:160px;" title="Personal peak training time and marketing segment (click visits for the full breakdown)">Profile</th>
                        <?php endif; ?>
                        <th style="width:100px;">Status</th>
                        <th style="width:170px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gm_members as $m):
                        $statusColor = ['active' => '#2e7d32', 'expired' => '#9e4040', 'suspended' => '#B18247', 'cancelled' => '#6c757d'][$m['status']] ?? '#6c757d';
                        $expPill = gym_days_to_expiry($m['expiry_date'] ?? null, (string)$m['status'], (int)$gm_reminder_cfg['days']);
                    ?>
                        <tr id="member-<?php echo (int)$m['id']; ?>" data-focus="member-<?php echo (int)$m['id']; ?>">
                            <td><strong><?php echo htmlspecialchars($m['member_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($m['full_name']); ?></td>
                            <td style="font-size:.85rem;color:#7a6f63;">
                                <?php echo htmlspecialchars($m['email'] ?? ''); ?><?php echo ($m['email'] && $m['phone']) ? '<br>' : ''; ?><?php echo htmlspecialchars($m['phone'] ?? ''); ?>
                            </td>
                            <td><?php echo htmlspecialchars($m['membership_type'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($m['start_date']))); ?></td>
                            <td style="font-size:.85rem;">
                                <?php echo $m['expiry_date'] ? htmlspecialchars(date('M j, Y', strtotime($m['expiry_date']))) : '—'; ?>
                                <br><span style="display:inline-block;margin-top:3px;padding:1px 8px;border-radius:10px;font-size:.72rem;font-weight:700;color:<?php echo $expPill['color']; ?>;background:<?php echo $expPill['bg']; ?>;"><?php echo htmlspecialchars($expPill['label']); ?></span>
                            </td>
                            <td><?php echo $m['monthly_fee'] !== null ? number_format((float)$m['monthly_fee'], 2) : '—'; ?></td>
                            <?php if ($gm_attendance_ready):
                                $vs = $gm_visit_stats[(int)$m['id']] ?? null;
                                $inNow = $vs && (int)$vs['in_now'] > 0;
                            ?>
                            <td style="font-size:.85rem;">
                                <?php if ($inNow): ?>
                                    <span style="font-weight:700;color:#2e7d32;"><i class="fas fa-person-running"></i> In gym</span>
                                <?php elseif ($vs && $vs['last_in']): ?>
                                    <?php echo htmlspecialchars(date('M j, H:i', strtotime((string)$vs['last_in']))); ?>
                                <?php else: ?>
                                    <span style="color:#9a8f82;">Never</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;font-size:.85rem;">
                                <?php
                                    $v30 = $vs ? (int)($vs['visits_30d'] ?? 0) : 0;
                                    $freq = gym_frequency_label($v30, $m['start_date'] ?? null);
                                ?>
                                <?php if ($vs && (int)$vs['visits'] > 0): ?>
                                    <a href="#" data-no-spa onclick="gmShowLog(<?php echo (int)$m['id']; ?>, '<?php echo htmlspecialchars($m['full_name'], ENT_QUOTES); ?>'); return false;" style="font-weight:700;"><?php echo (int)$vs['visits']; ?></a>
                                <?php else: ?>
                                    <span style="color:#9a8f82;">0</span>
                                <?php endif; ?>
                                <br><span style="font-size:.72rem;font-weight:700;color:<?php echo $freq['color']; ?>;" title="<?php echo $v30; ?> visit(s) in the last 30 days"><?php echo htmlspecialchars($freq['label']); ?> · <?php echo $v30; ?>/30d</span>
                            </td>
                            <td style="font-size:.78rem;">
                                <?php
                                    $peak = gym_peak_profile($gm_peak_stats[(int)$m['id']]['hours'] ?? [], $gm_peak_stats[(int)$m['id']]['wdays'] ?? []);
                                    $seg  = gym_member_segment($m, $v30, $vs ? (int)$vs['visits'] : 0, $vs['last_in'] ?? null);
                                ?>
                                <?php if ($peak['top_slot']): ?>
                                    <span style="color:#5a5147;" title="<?php echo htmlspecialchars((string)$peak['summary']); ?>"><i class="fas fa-clock" style="color:#B18247;"></i> <?php echo htmlspecialchars((string)$peak['top_slot']); ?></span><br>
                                <?php endif; ?>
                                <span style="display:inline-block;margin-top:2px;padding:1px 8px;border-radius:10px;font-size:.7rem;font-weight:700;color:#fff;background:<?php echo $seg['color']; ?>;" title="<?php echo htmlspecialchars($seg['hint']); ?>"><?php echo htmlspecialchars($seg['segment']); ?></span>
                            </td>
                            <?php endif; ?>
                            <td><span style="font-weight:600;color:<?php echo $statusColor; ?>;"><?php echo ucfirst($m['status']); ?></span></td>
                            <td class="actions-cell">
                                <div class="action-buttons">
                                    <button class="btn-action" title="Edit" onclick="gmOpenModal(<?php echo htmlspecialchars(json_encode($m), ENT_QUOTES); ?>)"><i class="fas fa-pen"></i></button>
                                    <?php if (!empty($m['email'])): ?>
                                        <button class="btn-action" title="Resend membership card email" onclick="gmResendCard(<?php echo (int)$m['id']; ?>)"><i class="fas fa-envelope"></i></button>
                                    <?php endif; ?>
                                    <?php if ($m['status'] === 'active'): ?>
                                        <button class="btn-action" title="Suspend" onclick="gmStatus(<?php echo (int)$m['id']; ?>, 'suspended')"><i class="fas fa-pause"></i></button>
                                    <?php else: ?>
                                        <button class="btn-action btn-toggle active" title="Reactivate" onclick="gmStatus(<?php echo (int)$m['id']; ?>, 'active')"><i class="fas fa-play"></i></button>
                                    <?php endif; ?>
                                    <button class="btn-action btn-delete" title="Delete"
                                        onclick="gmConfirm('Delete member &quot;<?php echo htmlspecialchars($m['full_name'], ENT_QUOTES); ?>&quot;? This cannot be undone.', function(){ gmDelete(<?php echo (int)$m['id']; ?>); })"><i class="fas fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Member modal -->
    <div class="mm-modal" id="gmModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3 id="gmModalTitle">Enrol Member</h3>
                <button type="button" class="mm-modal-close" onclick="gmClose('gmModal')" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body">
                <input type="hidden" id="gmId" value="0">
                <input type="hidden" id="gmInquiryId" value="0">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Full name</label>
                <input type="text" id="gmName" maxlength="255" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;margin-bottom:12px;">
                <div style="display:flex;gap:12px;margin-bottom:12px;">
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Email <span style="font-weight:400;color:#9a8f82;">(optional)</span></label>
                        <input type="email" id="gmEmail" maxlength="255" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Phone <span style="font-weight:400;color:#9a8f82;">(optional)</span></label>
                        <input type="text" id="gmPhone" maxlength="50" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                </div>
                <input type="hidden" id="gmType" value="">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Membership package</label>
                <select id="gmPackage" onchange="gmApplyPackage()" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;margin-bottom:12px;">
                    <option value="0">— Select a package —</option>
                    <?php foreach ($gm_packages as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>"
                            data-name="<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>"
                            data-price="<?php echo htmlspecialchars((string)$p['price'], ENT_QUOTES); ?>"
                            data-days="<?php echo $p['duration_days'] !== null ? (int)$p['duration_days'] : ''; ?>"
                            data-comp="<?php echo (int)($p['is_complimentary'] ?? 0); ?>">
                            <?php echo htmlspecialchars($p['name']); ?><?php echo !empty($p['duration_label']) ? ' · ' . htmlspecialchars($p['duration_label']) : ''; ?><?php echo (int)($p['is_complimentary'] ?? 0) ? ' (Free)' : ' · ' . htmlspecialchars($gm_currency) . number_format((float)$p['price'], 0); ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if ($gm_can_financials_ui): ?><option value="custom">Custom (manager) — set fee &amp; dates manually</option><?php endif; ?>
                </select>
                <?php if ($gm_is_hotel_context): ?>
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;cursor:pointer;font-weight:600;">
                    <input type="checkbox" id="gmComplimentary" onchange="gmApplyComplimentary()">
                    <span>Complimentary — free entry for a hotel guest</span>
                </label>
                <?php endif; ?>
                <div style="display:flex;gap:12px;margin-bottom:12px;">
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Start date</label>
                        <input type="date" id="gmStart" onchange="gmRecalcExpiry()" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                    </div>
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Expiry <span id="gmExpiryHint" style="font-weight:400;color:#9a8f82;">(auto from package)</span></label>
                        <input type="date" id="gmExpiry" <?php echo $gm_can_financials_ui ? '' : 'readonly'; ?> style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;<?php echo $gm_can_financials_ui ? '' : 'background:#f3efe8;'; ?>">
                    </div>
                </div>
                <div style="display:flex;gap:12px;margin-bottom:12px;">
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Fee (<?php echo htmlspecialchars($gm_currency); ?>)</label>
                        <input type="number" id="gmFee" min="0" step="0.01" <?php echo $gm_can_financials_ui ? '' : 'readonly'; ?> style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;<?php echo $gm_can_financials_ui ? '' : 'background:#f3efe8;'; ?>">
                        <small style="color:#9a8f82;">Set by the package. <?php echo $gm_can_financials_ui ? 'Override allowed.' : 'Managers can override.'; ?></small>
                    </div>
                    <div style="flex:1;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Status</label>
                        <select id="gmStatus" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;">
                            <?php foreach ($gm_statuses as $s): ?><option value="<?php echo $s; ?>"><?php echo ucfirst($s); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div id="gmReasonWrap" style="display:none;margin-bottom:12px;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;color:#b45309;"><i class="fas fa-triangle-exclamation"></i> Reason for name change <span style="font-weight:400;">(required — audit logged)</span></label>
                    <input type="text" id="gmChangeReason" maxlength="255" placeholder="e.g. Legal name correction — marriage" style="width:100%;padding:9px;border:1px solid #e0b070;border-radius:4px;background:#fffaf0;">
                    <small style="color:#9a8f82;">A membership belongs to one person and is not transferable. Name edits are for genuine legal corrections only.</small>
                </div>
                <label style="display:block;font-weight:600;margin-bottom:4px;">Notes <span style="font-weight:400;color:#9a8f82;">(optional)</span></label>
                <textarea id="gmNotes" rows="2" style="width:100%;padding:9px;border:1px solid #d3cbc0;border-radius:4px;"></textarea>
            </div>
            <div class="mm-modal-foot" style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:14px 18px;">
                <button id="gmHistoryBtn" class="mm-btn mm-btn-ghost" style="display:none;align-items:center;gap:6px;" data-id="0" onclick="gmShowHistory(this.getAttribute('data-id'))"><i class="fas fa-clock-rotate-left"></i> History</button>
                <div style="display:flex;gap:10px;margin-left:auto;">
                    <button class="mm-btn mm-btn-ghost" onclick="gmClose('gmModal')">Cancel</button>
                    <button class="mm-btn mm-btn-primary" onclick="gmSave()">Save Member</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Member change history modal -->
    <div class="mm-modal" id="gmHistoryModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3>Member Change History</h3>
                <button type="button" class="mm-modal-close" onclick="gmClose('gmHistoryModal')" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body" id="gmHistoryBody" style="max-height:60vh;overflow-y:auto;">
                <p style="color:#9a8f82;">Loading…</p>
            </div>
            <div class="mm-modal-foot" style="display:flex;justify-content:flex-end;padding:14px 18px;">
                <button class="mm-btn mm-btn-ghost" onclick="gmClose('gmHistoryModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Attendance log modal -->
    <div class="mm-modal" id="gmLogModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3 id="gmLogTitle">Recent Visits</h3>
                <button type="button" class="mm-modal-close" onclick="gmClose('gmLogModal')" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body" id="gmLogBody" style="max-height:60vh;overflow-y:auto;">
                <p style="color:#9a8f82;">Loading…</p>
            </div>
        </div>
    </div>

    <!-- Confirm modal -->
    <div class="mm-modal" id="gmConfirmModal">
        <div class="mm-modal-card sm">
            <div class="mm-modal-head">
                <h3><i class="fas fa-triangle-exclamation" style="color:#f59e0b;"></i> Are you sure?</h3>
                <button type="button" class="mm-modal-close" onclick="gmClose('gmConfirmModal')" aria-label="Close">&times;</button>
            </div>
            <div class="mm-modal-body"><p id="gmConfirmText" style="margin:0;"></p></div>
            <div class="mm-modal-foot" style="display:flex;justify-content:flex-end;gap:10px;padding:14px 18px;">
                <button class="mm-btn mm-btn-ghost" onclick="gmClose('gmConfirmModal')">Cancel</button>
                <button class="mm-btn mm-btn-primary" id="gmConfirmYes" style="background:#c0392b;border-color:#c0392b;">Yes, continue</button>
            </div>
        </div>
    </div>

    <script>
        // Self-contained CSRF: this content-block script re-runs on every SPA
        // render (unlike the head fetch-shim), so gm* requests never depend on
        // page-load order to carry a token.
        var GM_CSRF = <?php echo json_encode($csrf_token); ?>;
        var GM_CAN_FIN = <?php echo $gm_can_financials_ui ? 'true' : 'false'; ?>;
        var GM_CAN_LOGS = <?php echo $gm_can_logs_ui ? 'true' : 'false'; ?>;
        // Active packages keyed by id: {name, price, days, comp} — drives auto price + expiry.
        var GM_PACKAGES = <?php echo json_encode(array_column(array_map(function ($p) {
            return [(int)$p['id'], ['name' => (string)$p['name'], 'price' => (float)$p['price'], 'days' => $p['duration_days'] !== null ? (int)$p['duration_days'] : null, 'comp' => (int)($p['is_complimentary'] ?? 0)]];
        }, $gm_packages), 1, 0)); ?>;

        function gmOpen(id) { document.getElementById(id).classList.add('open'); }
        function gmClose(id) { document.getElementById(id).classList.remove('open'); }
        function gmToast(msg, ok) { if (typeof Alert !== 'undefined' && Alert.show) { Alert.show(msg, ok ? 'success' : 'error'); } }

        function gmPost(fields) {
            var fd = new FormData();
            fd.append('csrf_token', GM_CSRF);
            Object.keys(fields).forEach(function (k) { fd.append(k, fields[k] == null ? '' : fields[k]); });
            return fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    gmToast(d.message || (d.success ? 'Saved.' : 'Failed.'), !!d.success);
                    if (d.success) { setTimeout(function () { window.location.reload(); }, 700); }
                    return d;
                })
                .catch(function () { gmToast('Network error — please try again.', false); });
        }

        var gmOrigName = '';

        function gmOpenModal(m) {
            document.getElementById('gmModalTitle').textContent = m ? 'Edit Member' : 'Enrol Member';
            document.getElementById('gmId').value = m ? m.id : 0;
            document.getElementById('gmInquiryId').value = 0;
            document.getElementById('gmName').value = m ? (m.full_name || '') : '';
            document.getElementById('gmEmail').value = m ? (m.email || '') : '';
            document.getElementById('gmPhone').value = m ? (m.phone || '') : '';
            document.getElementById('gmType').value = m ? (m.membership_type || '') : '';
            document.getElementById('gmStart').value = m ? (m.start_date || '') : new Date().toISOString().slice(0, 10);
            document.getElementById('gmExpiry').value = m ? (m.expiry_date || '') : '';
            document.getElementById('gmFee').value = m && m.monthly_fee != null ? m.monthly_fee : '';
            document.getElementById('gmStatus').value = m ? (m.status || 'active') : 'active';
            document.getElementById('gmNotes').value = m ? (m.notes || '') : '';
            gmOrigName = m ? (m.full_name || '') : '';

            // Match the package select to the stored membership type (by name).
            var pkgSel = document.getElementById('gmPackage');
            var matched = '0';
            var storedType = m ? (m.membership_type || '') : '';
            for (var i = 0; i < pkgSel.options.length; i++) {
                if (pkgSel.options[i].getAttribute('data-name') === storedType && storedType !== '') { matched = pkgSel.options[i].value; break; }
            }
            // Existing non-package members held for a manager show as "Custom".
            if (matched === '0' && m && storedType !== '' && GM_CAN_FIN) { matched = 'custom'; }
            pkgSel.value = matched;

            var compEl = document.getElementById('gmComplimentary');
            if (compEl) { compEl.checked = m ? !!(m.is_complimentary == 1) : false; }

            // Change-reason field only relevant when editing an existing member.
            var reasonWrap = document.getElementById('gmReasonWrap');
            if (reasonWrap) { reasonWrap.style.display = 'none'; }
            document.getElementById('gmChangeReason') && (document.getElementById('gmChangeReason').value = '');

            // History button visible for privileged users on existing members.
            var histBtn = document.getElementById('gmHistoryBtn');
            if (histBtn) { histBtn.style.display = (m && GM_CAN_LOGS) ? 'inline-flex' : 'none'; if (m) histBtn.setAttribute('data-id', m.id); }

            // Identity lock: non-privileged staff cannot rename an EXISTING member.
            var nameEl = document.getElementById('gmName');
            var lockIdentity = !!m && !GM_CAN_FIN;
            nameEl.readOnly = lockIdentity;
            nameEl.style.background = lockIdentity ? '#f3efe8' : '';
            nameEl.title = lockIdentity ? 'Name changes require a manager — memberships are not transferable.' : '';

            gmOpen('gmModal');
            setTimeout(function () { document.getElementById(lockIdentity ? 'gmEmail' : 'gmName').focus(); }, 60);
        }

        // Choosing a package fills the hidden type, the fee and the auto expiry.
        function gmApplyPackage() {
            var sel = document.getElementById('gmPackage');
            var val = sel.value;
            var compEl = document.getElementById('gmComplimentary');
            if (val === '0' || val === 'custom') {
                document.getElementById('gmType').value = (val === 'custom') ? (document.getElementById('gmType').value || '') : '';
                document.getElementById('gmExpiryHint').textContent = (val === 'custom') ? '(set manually)' : '(auto from package)';
                return;
            }
            var p = GM_PACKAGES[val];
            if (!p) { return; }
            document.getElementById('gmType').value = p.name;
            if (compEl) { compEl.checked = !!p.comp; }
            document.getElementById('gmFee').value = p.comp ? 0 : p.price;
            gmRecalcExpiry();
        }

        function gmApplyComplimentary() {
            var comp = document.getElementById('gmComplimentary');
            if (comp && comp.checked) { document.getElementById('gmFee').value = 0; }
        }

        // Expiry = start + package duration (days). Managers may override after.
        function gmRecalcExpiry() {
            var sel = document.getElementById('gmPackage');
            var p = GM_PACKAGES[sel.value];
            var start = document.getElementById('gmStart').value;
            var hint = document.getElementById('gmExpiryHint');
            if (!p || p.days == null || !start) { if (hint) hint.textContent = '(open-ended)'; return; }
            var d = new Date(start + 'T00:00:00');
            d.setDate(d.getDate() + (p.days - 1));
            document.getElementById('gmExpiry').value = d.toISOString().slice(0, 10);
            if (hint) hint.textContent = '(auto: ' + p.days + ' days)';
        }

        function gmSave() {
            var name = document.getElementById('gmName').value.trim();
            var start = document.getElementById('gmStart').value;
            if (!name) { gmToast('Member name is required.', false); return; }
            if (!start) { gmToast('Start date is required.', false); return; }

            // Surface the name-change reason field when the name actually changed.
            var reasonWrap = document.getElementById('gmReasonWrap');
            var reasonEl = document.getElementById('gmChangeReason');
            var isEdit = parseInt(document.getElementById('gmId').value, 10) > 0;
            if (isEdit && name !== gmOrigName) {
                if (reasonWrap && reasonWrap.style.display === 'none') {
                    reasonWrap.style.display = 'block';
                    if (reasonEl) reasonEl.focus();
                    gmToast('Name changed — please give a reason (audit logged).', false);
                    return;
                }
                if (reasonEl && reasonEl.value.trim().length < 5) {
                    gmToast('A reason (min 5 characters) is required to change the name.', false);
                    return;
                }
            }

            var pkgSel = document.getElementById('gmPackage');
            var pkgId = (pkgSel.value === 'custom' || pkgSel.value === '0') ? 0 : pkgSel.value;
            var compEl = document.getElementById('gmComplimentary');
            gmPost({
                gm_action: 'member_save',
                id: document.getElementById('gmId').value,
                gym_inquiry_id: document.getElementById('gmInquiryId').value,
                full_name: name,
                email: document.getElementById('gmEmail').value.trim(),
                phone: document.getElementById('gmPhone').value.trim(),
                package_id: pkgId,
                membership_type: document.getElementById('gmType').value.trim(),
                is_complimentary: compEl && compEl.checked ? 1 : 0,
                start_date: start,
                expiry_date: document.getElementById('gmExpiry').value,
                monthly_fee: document.getElementById('gmFee').value,
                status: document.getElementById('gmStatus').value,
                change_reason: reasonEl ? reasonEl.value.trim() : '',
                notes: document.getElementById('gmNotes').value.trim()
            });
        }

        function gmShowHistory(id) {
            var body = document.getElementById('gmHistoryBody');
            body.innerHTML = '<p style="color:#9a8f82;">Loading…</p>';
            gmOpen('gmHistoryModal');
            var fd = new FormData();
            fd.append('csrf_token', GM_CSRF);
            fd.append('gm_action', 'member_history');
            fd.append('id', id);
            fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.success) { body.innerHTML = '<p style="color:#c0392b;">' + gmEscape(d.message || 'Could not load history.') + '</p>'; return; }
                    var e = d.entries || [];
                    if (!e.length) { body.innerHTML = '<p style="color:#9a8f82;">No changes recorded yet.</p>'; return; }
                    var actLabel = { enrolled: 'Enrolled', updated: 'Updated', name_changed: 'Name changed', status_changed: 'Status changed', deleted: 'Deleted', complimentary_granted: 'Complimentary granted', repriced: 'Repriced', package_changed: 'Package changed', renewed: 'Renewed' };
                    body.innerHTML = e.map(function (r) {
                        var fields = (r.changed_fields || []).join(', ');
                        var who = gmEscape(r.performed_by_name || 'system');
                        var when = gmFmtDT(r.performed_at);
                        var note = r.note ? '<div style="color:#7a6f63;font-size:.78rem;margin-top:2px;">' + gmEscape(r.note) + '</div>' : '';
                        var diff = '';
                        if (r.old_values && r.new_values && fields) {
                            diff = '<div style="font-size:.76rem;color:#5a5147;margin-top:3px;">' + (r.changed_fields || []).map(function (f) {
                                return '<span style="display:inline-block;margin-right:10px;"><strong>' + gmEscape(f) + ':</strong> ' + gmEscape(r.old_values[f]) + ' → ' + gmEscape(r.new_values[f]) + '</span>';
                            }).join('') + '</div>';
                        }
                        return '<div style="border-left:3px solid #8B7355;padding:6px 0 6px 10px;margin-bottom:10px;">' +
                            '<div><strong>' + gmEscape(actLabel[r.action] || r.action) + '</strong> · <span style="color:#9a8f82;font-size:.78rem;">' + when + ' by ' + who + '</span></div>' +
                            diff + note + '</div>';
                    }).join('');
                })
                .catch(function () { body.innerHTML = '<p style="color:#c0392b;">Network error loading history.</p>'; });
        }

        function gmStatus(id, status) { gmPost({ gm_action: 'member_status', id: id, status: status }); }
        function gmDelete(id) { gmPost({ gm_action: 'member_delete', id: id }); }

        function gmResendCard(id) {
            var fd = new FormData();
            fd.append('gm_action', 'member_card');
            fd.append('id', id);
            fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { gmToast(d.message || (d.success ? 'Card sent.' : 'Failed.'), !!d.success); })
                .catch(function () { gmToast('Network error — please try again.', false); });
        }

        function gmEscape(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
        function gmFmtDT(dt) { if (!dt) return ''; var d = new Date(String(dt).replace(' ', 'T')); return isNaN(d) ? String(dt) : d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }); }
        function gmFmtDur(mins) { if (mins == null) return '<span style="color:#2e7d32;font-weight:700;">in gym</span>'; mins = parseInt(mins, 10); return mins >= 60 ? Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm' : mins + 'm'; }

        var gmLogState = { id: 0, name: '', page: 1, totalPages: 1 };

        function gmVisitsRows(visits) {
            if (!visits.length) { return '<tr><td colspan="4" style="text-align:center;color:#9a8f82;padding:14px;">No visits on this page.</td></tr>'; }
            return visits.map(function (v) {
                return '<tr><td>' + gmFmtDT(v.checked_in_at) + '</td><td>' + (v.checked_out_at ? gmFmtDT(v.checked_out_at) : '—') + '</td><td>' + gmFmtDur(v.minutes) + '</td><td>' + gmEscape(v.method) + '</td></tr>';
            }).join('');
        }

        function gmVisitsPager() {
            if (gmLogState.totalPages <= 1) { return ''; }
            var p = gmLogState.page, tp = gmLogState.totalPages;
            var btn = function (label, target, disabled) {
                return '<button type="button" onclick="gmVisitsPage(' + target + ')" ' + (disabled ? 'disabled' : '') +
                    ' style="border:1px solid #d5cfc4;background:' + (disabled ? '#f3efe8' : '#fff') + ';color:#5a5147;border-radius:3px;padding:4px 10px;font-size:.78rem;cursor:' + (disabled ? 'default' : 'pointer') + ';">' + label + '</button>';
            };
            return '<div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-top:10px;">' +
                btn('&laquo; Prev', p - 1, p <= 1) +
                '<span style="font-size:.76rem;color:#7a6f63;">Page ' + p + ' of ' + tp + '</span>' +
                btn('Next &raquo;', p + 1, p >= tp) + '</div>';
        }

        function gmVisitsPage(page) {
            if (page < 1 || page > gmLogState.totalPages) { return; }
            var wrap = document.getElementById('gmVisitsWrap');
            if (wrap) { wrap.style.opacity = '.5'; }
            var fd = new FormData();
            fd.append('csrf_token', GM_CSRF);
            fd.append('gm_action', 'member_attendance');
            fd.append('id', gmLogState.id);
            fd.append('page', page);
            fd.append('visits_only', 1);
            fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    gmLogState.page = d.page || page;
                    gmLogState.totalPages = d.total_pages || gmLogState.totalPages;
                    document.getElementById('gmVisitsBody').innerHTML = gmVisitsRows((d && d.visits) || []);
                    document.getElementById('gmVisitsPager').innerHTML = gmVisitsPager();
                    if (wrap) { wrap.style.opacity = '1'; }
                })
                .catch(function () { if (wrap) { wrap.style.opacity = '1'; } });
        }

        function gmShowLog(id, name) {
            gmLogState = { id: id, name: name, page: 1, totalPages: 1 };
            document.getElementById('gmLogTitle').textContent = 'Fitness Report — ' + name;
            document.getElementById('gmLogBody').innerHTML = '<p style="color:#9a8f82;">Loading…</p>';
            gmOpen('gmLogModal');
            var fd = new FormData();
            fd.append('csrf_token', GM_CSRF);
            fd.append('gm_action', 'member_attendance');
            fd.append('id', id);
            fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var body = document.getElementById('gmLogBody');
                    var visits = (d && d.visits) || [];
                    gmLogState.page = d.page || 1;
                    gmLogState.totalPages = d.total_pages || 1;
                    if (!parseInt((d.stats || {}).total_visits || 0, 10)) {
                        body.innerHTML = '<p style="color:#9a8f82;margin:0;">No visits recorded yet.</p>';
                        return;
                    }
                    var html = '';
                    // ── Fitness report header: segment + headline stats ──
                    var st = d.stats || {};
                    if (d.segment && d.segment.segment) {
                        html += '<div style="display:inline-block;background:' + gmEscape(d.segment.color || '#8B7355') + '1a;border:1px solid ' + gmEscape(d.segment.color || '#8B7355') + ';color:' + gmEscape(d.segment.color || '#8B7355') + ';border-radius:999px;padding:3px 12px;font-size:.74rem;font-weight:700;margin-bottom:10px;" title="' + gmEscape(d.segment.hint || '') + '">' + gmEscape(d.segment.segment) + '</div>';
                    }
                    var statCell = function (val, label) {
                        return '<div style="flex:1;min-width:82px;background:#fff;border:1px solid #e8e0d4;border-radius:6px;padding:8px 10px;text-align:center;">' +
                            '<div style="font-size:1.05rem;font-weight:700;color:#3e3930;">' + val + '</div>' +
                            '<div style="font-size:.68rem;color:#9a8f82;text-transform:uppercase;letter-spacing:.04em;">' + label + '</div></div>';
                    };
                    var avgM = st.avg_minutes != null ? Math.round(parseFloat(st.avg_minutes)) : null;
                    var totH = st.total_minutes ? (parseInt(st.total_minutes, 10) / 60).toFixed(1) : '0';
                    var streak = d.streak || {};
                    html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">' +
                        statCell(parseInt(st.total_visits || 0, 10), 'Total visits') +
                        statCell(parseInt(st.visits_30d || 0, 10) + ' / ' + parseInt(st.visits_7d || 0, 10), '30d / 7d') +
                        statCell(avgM != null ? gmFmtDur(avgM) : '—', 'Avg session') +
                        statCell(st.longest_minutes ? gmFmtDur(parseInt(st.longest_minutes, 10)) : '—', 'Longest') +
                        statCell(totH + 'h', 'Lifetime time') +
                        statCell((streak.current || 0) + 'd', 'Streak (best ' + (streak.longest || 0) + 'd)') +
                        (d.cost_per_visit != null ? statCell('<?php echo htmlspecialchars($gm_currency); ?>' + Number(d.cost_per_visit).toLocaleString(), 'Cost / visit') : '') +
                        '</div>';

                    // ── Training habits: part-of-day distribution ──
                    var dp = d.dayparts || {};
                    var dpTotal = Object.keys(dp).reduce(function (a, k) { return a + (dp[k] || 0); }, 0);
                    if (dpTotal > 0) {
                        var dpMeta = [['early', 'Early (before 6)'], ['morning', 'Morning (6–11)'], ['midday', 'Midday (11–2)'], ['afternoon', 'Afternoon (2–5)'], ['evening', 'Evening (5+)']];
                        html += '<div style="background:#FAF6F0;border:1px solid #e8e0d4;border-radius:6px;padding:12px 14px;margin-bottom:14px;">' +
                            '<div style="font-weight:700;color:#8B7355;font-size:.8rem;margin-bottom:8px;"><i class="fas fa-clock"></i> Training habits</div>' +
                            dpMeta.filter(function (m) { return dp[m[0]] > 0; }).map(function (m) {
                                var c = dp[m[0]]; var pct = Math.round((c / dpTotal) * 100);
                                return '<div style="display:flex;align-items:center;gap:8px;margin:3px 0;font-size:.74rem;color:#5a5147;">' +
                                    '<span style="width:130px;">' + m[1] + '</span>' +
                                    '<span style="flex:1;background:#ece4d8;border-radius:3px;overflow:hidden;"><span style="display:block;height:10px;width:' + Math.max(4, pct) + '%;background:#8B7355;"></span></span>' +
                                    '<span style="width:46px;text-align:right;">' + pct + '% (' + c + ')</span></div>';
                            }).join('') + '</div>';
                    }

                    // ── 6-month momentum trend ──
                    var months = d.months || {};
                    var moKeys = Object.keys(months);
                    if (moKeys.length > 1) {
                        var mmax = 0; moKeys.forEach(function (k) { if (months[k] > mmax) mmax = months[k]; });
                        html += '<div style="background:#FAF6F0;border:1px solid #e8e0d4;border-radius:6px;padding:12px 14px;margin-bottom:14px;">' +
                            '<div style="font-weight:700;color:#8B7355;font-size:.8rem;margin-bottom:8px;"><i class="fas fa-arrow-trend-up"></i> Monthly momentum (6 months)</div>' +
                            '<div style="display:flex;align-items:flex-end;gap:8px;height:52px;">' +
                            moKeys.map(function (k) {
                                var c = months[k]; var h = Math.max(6, Math.round((c / mmax) * 46));
                                var lbl = new Date(k + '-01T00:00:00').toLocaleDateString([], { month: 'short' });
                                return '<div style="flex:1;text-align:center;" title="' + gmEscape(k) + ': ' + c + ' visits">' +
                                    '<div style="font-size:.6rem;color:#9a8f82;">' + c + '</div>' +
                                    '<div style="background:#B18247;border-radius:3px 3px 0 0;height:' + h + 'px;"></div>' +
                                    '<div style="font-size:.62rem;color:#9a8f82;margin-top:2px;">' + lbl + '</div></div>';
                            }).join('') + '</div></div>';
                    }

                    // ── Weekly consistency: visits per week, last 8 weeks ──
                    var weeks = d.weeks || {};
                    var wkKeys = Object.keys(weeks);
                    if (wkKeys.length) {
                        var wmax = 0; wkKeys.forEach(function (k) { if (weeks[k] > wmax) wmax = weeks[k]; });
                        html += '<div style="background:#FAF6F0;border:1px solid #e8e0d4;border-radius:6px;padding:12px 14px;margin-bottom:14px;">' +
                            '<div style="font-weight:700;color:#8B7355;font-size:.8rem;margin-bottom:8px;"><i class="fas fa-calendar-week"></i> Weekly consistency (last 8 weeks)</div>' +
                            '<div style="display:flex;align-items:flex-end;gap:5px;height:52px;">' +
                            wkKeys.map(function (k) {
                                var c = weeks[k];
                                var h = Math.max(6, Math.round((c / wmax) * 46));
                                return '<div style="flex:1;text-align:center;" title="Week ' + gmEscape(String(k).slice(4)) + ': ' + c + ' visit(s)">' +
                                    '<div style="background:#B18247;border-radius:3px 3px 0 0;height:' + h + 'px;"></div>' +
                                    '<div style="font-size:.62rem;color:#9a8f82;margin-top:2px;">' + c + '</div></div>';
                            }).join('') + '</div></div>';
                    }

                    // Peak-time profile: summary line + per-hour histogram bars
                    if (d.profile && d.profile.summary) {
                        html += '<div style="background:#FAF6F0;border:1px solid #e8e0d4;border-radius:6px;padding:12px 14px;margin-bottom:14px;">' +
                            '<div style="font-weight:700;color:#8B7355;font-size:.8rem;margin-bottom:8px;"><i class="fas fa-chart-simple"></i> Peak training time: ' + gmEscape(d.profile.summary) + '</div>';
                        var hours = d.hours || {};
                        var max = 0;
                        Object.keys(hours).forEach(function (h) { if (hours[h] > max) max = hours[h]; });
                        if (max > 0) {
                            html += Object.keys(hours).sort(function (a, b) { return a - b; }).map(function (h) {
                                var c = hours[h];
                                var pct = Math.max(4, Math.round((c / max) * 100));
                                return '<div style="display:flex;align-items:center;gap:8px;margin:2px 0;font-size:.72rem;color:#5a5147;">' +
                                    '<span style="width:42px;text-align:right;">' + String(h).padStart(2, '0') + ':00</span>' +
                                    '<span style="flex:1;background:#ece4d8;border-radius:3px;overflow:hidden;"><span style="display:block;height:9px;width:' + pct + '%;background:#B18247;border-radius:3px;"></span></span>' +
                                    '<span style="width:20px;">' + c + '</span></div>';
                            }).join('');
                        }
                        html += '</div>';
                    }

                    // ── Visit log — paginated 10 per page ──
                    html += '<div style="font-weight:700;color:#8B7355;font-size:.8rem;margin-bottom:6px;"><i class="fas fa-list"></i> Visit log (' + parseInt(st.total_visits || 0, 10) + ' total)</div>' +
                        '<div id="gmVisitsWrap" style="transition:opacity .15s;">' +
                        '<table class="menu-table" style="margin:0;"><thead><tr><th>In</th><th>Out</th><th>Duration</th><th>Method</th></tr></thead>' +
                        '<tbody id="gmVisitsBody">' + gmVisitsRows(visits) + '</tbody></table>' +
                        '<div id="gmVisitsPager">' + gmVisitsPager() + '</div></div>';
                    body.innerHTML = html;
                })
                .catch(function () { document.getElementById('gmLogBody').innerHTML = '<p style="color:#c0392b;margin:0;">Could not load visits.</p>'; });
        }

        function gmSaveReminderSettings() {
            var days = parseInt(document.getElementById('gmRemDays').value, 10);
            if (isNaN(days) || days < 1 || days > 30) { gmToast('Reminder days must be between 1 and 30.', false); return; }
            gmPost({
                gm_action: 'reminder_settings',
                enabled: document.getElementById('gmRemEnabled').checked ? 1 : 0,
                days: days
            });
        }

        function gmRunReminders(btn) {
            btn.disabled = true;
            var fd = new FormData();
            fd.append('csrf_token', GM_CSRF);
            fd.append('gm_action', 'run_reminders');
            fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    gmToast(d.message || (d.success ? 'Done.' : 'Failed.'), !!d.success);
                    if (d.success) { setTimeout(function () { window.location.reload(); }, 1400); }
                })
                .catch(function () { gmToast('Network error — please try again.', false); })
                .finally(function () { btn.disabled = false; });
        }

        var gmConfirmCb = null;
        function gmConfirm(text, cb) {
            document.getElementById('gmConfirmText').textContent = text;
            gmConfirmCb = cb;
            gmOpen('gmConfirmModal');
        }
        document.getElementById('gmConfirmYes').addEventListener('click', function () {
            gmClose('gmConfirmModal');
            if (gmConfirmCb) { gmConfirmCb(); gmConfirmCb = null; }
        });
        ['gmModal', 'gmConfirmModal', 'gmLogModal'].forEach(function (id) {
            var el = document.getElementById(id);
            el.addEventListener('click', function (e) { if (e.target === el) { gmClose(id); } });
        });

        // "Enrol" handoff from gym-inquiries.php: ?enrol_name=…&enrol_email=…
        // opens the enrol modal pre-filled and links the member back to the
        // inquiry via gym_inquiry_id (marked converted on save).
        (function () {
            var q = new URLSearchParams(window.location.search);
            if (!q.has('enrol_name')) { return; }
            gmOpenModal();
            document.getElementById('gmName').value = q.get('enrol_name') || '';
            document.getElementById('gmEmail').value = q.get('enrol_email') || '';
            document.getElementById('gmPhone').value = q.get('enrol_phone') || '';
            var enrolType = q.get('enrol_type') || '';
            document.getElementById('gmType').value = enrolType;
            document.getElementById('gmInquiryId').value = parseInt(q.get('enrol_inquiry_id') || '0', 10) || 0;
            // Match the inquiry's package by name so price + expiry auto-fill.
            if (enrolType) {
                var pkgSel = document.getElementById('gmPackage');
                for (var i = 0; i < pkgSel.options.length; i++) {
                    if (pkgSel.options[i].getAttribute('data-name') === enrolType) { pkgSel.value = pkgSel.options[i].value; gmApplyPackage(); break; }
                }
            }
        })();

        // SPA-proof: expose the handlers used by inline onclick attributes
        // explicitly, so a re-rendered page never depends on the SPA's
        // automatic export heuristics for these to work on first click.
        window.gmOpen = gmOpen; window.gmClose = gmClose; window.gmToast = gmToast;
        window.gmPost = gmPost; window.gmOpenModal = gmOpenModal; window.gmSave = gmSave;
        window.gmStatus = gmStatus; window.gmDelete = gmDelete; window.gmResendCard = gmResendCard;
        window.gmShowLog = gmShowLog; window.gmVisitsPage = gmVisitsPage; window.gmSaveReminderSettings = gmSaveReminderSettings;
        window.gmRunReminders = gmRunReminders; window.gmConfirm = gmConfirm;
        window.gmApplyPackage = gmApplyPackage; window.gmApplyComplimentary = gmApplyComplimentary;
        window.gmRecalcExpiry = gmRecalcExpiry; window.gmShowHistory = gmShowHistory;
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>
