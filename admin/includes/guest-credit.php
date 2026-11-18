<?php
/**
 * Guest credit (Ship M, owner 2026-11-18): money the hotel holds for guests on credit notes. Moving money onto a
 * credit note is NOT a refund and NOT revenue; it is a liability the hotel still owes until the note is used, voided
 * or expires. One source for End of Day (page, PDF, e-mail, CSV), Reports and the exports.
 *
 *   issued   = original_amount of notes issued in the range (goodwill and later-voided notes too: history never restates)
 *   used     = credit_note_applications.amount_applied applied in the range (net of negative put-backs)
 *   voided / expired = the unused part of notes whose end day falls in the range
 *   owed at a day = SUM(GREATEST(0, original - applications up to that day)) over notes issued by then that have
 *                   not ended by then (end day = the void day, or the day after expiry, whichever is earlier)
 *   identity: opening + issued - used - voided - expired = closing (within BALANCE_TOLERANCE)
 *
 * Days are hotel days (the DB session time zone is the hotel's). Read only: nothing here writes.
 * Pure twin for tests: rh_guest_credit_from_rows(). Unit tested by scripts/tests/guest_credit_ship_m.php.
 */

require_once __DIR__ . '/finance-refunds.php';

if (!function_exists('rh_guest_credit_end_day_sql')) {
    /** SQL expression: the day a note stops being owed (void day or the day after expiry, whichever is earlier); NULL = open. */
    function rh_guest_credit_end_day_sql(string $alias = 'cn'): string
    {
        $a = rh_refund_alias($alias);
        return "(CASE WHEN {$a}voided_at IS NOT NULL AND {$a}expires_at IS NOT NULL"
            . " THEN LEAST(DATE({$a}voided_at), DATE_ADD(DATE({$a}expires_at), INTERVAL 1 DAY))"
            . " WHEN {$a}voided_at IS NOT NULL THEN DATE({$a}voided_at)"
            . " WHEN {$a}expires_at IS NOT NULL THEN DATE_ADD(DATE({$a}expires_at), INTERVAL 1 DAY) END)";
    }

    /** SQL expression: 'voided' or 'expired' for a note that has ended (whichever end came first; a tie reads voided). */
    function rh_guest_credit_end_kind_sql(string $alias = 'cn'): string
    {
        $a = rh_refund_alias($alias);
        return "(CASE WHEN {$a}voided_at IS NOT NULL AND ({$a}expires_at IS NULL"
            . " OR DATE({$a}voided_at) <= DATE_ADD(DATE({$a}expires_at), INTERVAL 1 DAY)) THEN 'voided' ELSE 'expired' END)";
    }

    /** What the hotel owed guests on credit notes at the end of hotel day $day (Y-m-d). */
    function rh_guest_credit_owed_at(PDO $pdo, string $day): float
    {
        $st = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(0, cn.original_amount - COALESCE(a.used, 0))), 0)
            FROM credit_notes cn
            LEFT JOIN (SELECT credit_note_id, SUM(amount_applied) AS used FROM credit_note_applications
                       WHERE DATE(applied_at) <= :gc_d1 GROUP BY credit_note_id) a ON a.credit_note_id = cn.id
            WHERE DATE(cn.issued_at) <= :gc_d2
              AND (" . rh_guest_credit_end_day_sql('cn') . " IS NULL OR " . rh_guest_credit_end_day_sql('cn') . " > :gc_d3)");
        $st->execute([':gc_d1' => $day, ':gc_d2' => $day, ':gc_d3' => $day]);
        return round((float)$st->fetchColumn(), 2);
    }

    /**
     * Guest credit for hotel days $from..$to (Y-m-d, inclusive): opening, issued, issued_count, used, voided,
     * expired, closing (+ 'failed' => true when it could not be read; the figures are then 0 and must not be shown
     * as "nothing owed").
     */
    function rh_guest_credit_summary(PDO $pdo, string $from, string $to): array
    {
        $out = ['opening' => 0.0, 'issued' => 0.0, 'issued_count' => 0, 'used' => 0.0, 'voided' => 0.0, 'expired' => 0.0, 'closing' => 0.0, 'failed' => false];
        try {
            $prev = date('Y-m-d', strtotime($from . ' -1 day'));
            $out['opening'] = rh_guest_credit_owed_at($pdo, $prev);
            $out['closing'] = rh_guest_credit_owed_at($pdo, $to);

            $st = $pdo->prepare("SELECT COALESCE(SUM(original_amount), 0) AS amt, COUNT(*) AS n FROM credit_notes
                WHERE DATE(issued_at) >= :gc_f AND DATE(issued_at) <= :gc_t");
            $st->execute([':gc_f' => $from, ':gc_t' => $to]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['issued'] = round((float)($r['amt'] ?? 0), 2);
            $out['issued_count'] = (int)($r['n'] ?? 0);

            $st = $pdo->prepare("SELECT COALESCE(SUM(amount_applied), 0) FROM credit_note_applications
                WHERE DATE(applied_at) >= :gc_f AND DATE(applied_at) <= :gc_t");
            $st->execute([':gc_f' => $from, ':gc_t' => $to]);
            $out['used'] = round((float)$st->fetchColumn(), 2);

            $st = $pdo->prepare("SELECT
                    COALESCE(SUM(CASE WHEN k.end_kind = 'voided' THEN k.unused ELSE 0 END), 0) AS voided,
                    COALESCE(SUM(CASE WHEN k.end_kind = 'expired' THEN k.unused ELSE 0 END), 0) AS expired
                FROM (
                    SELECT e.end_kind, GREATEST(0, e.original_amount - COALESCE((SELECT SUM(a.amount_applied)
                            FROM credit_note_applications a
                            WHERE a.credit_note_id = e.id AND DATE(a.applied_at) <= e.end_day), 0)) AS unused
                    FROM (SELECT cn.id, cn.original_amount, " . rh_guest_credit_end_day_sql('cn') . " AS end_day,
                                 " . rh_guest_credit_end_kind_sql('cn') . " AS end_kind
                          FROM credit_notes cn) e
                    WHERE e.end_day IS NOT NULL AND e.end_day >= :gc_f AND e.end_day <= :gc_t
                ) k");
            $st->execute([':gc_f' => $from, ':gc_t' => $to]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['voided'] = round((float)($r['voided'] ?? 0), 2);
            $out['expired'] = round((float)($r['expired'] ?? 0), 2);
        } catch (Throwable $e) {
            error_log('rh_guest_credit_summary: ' . $e->getMessage());
            $out = ['opening' => 0.0, 'issued' => 0.0, 'issued_count' => 0, 'used' => 0.0, 'voided' => 0.0, 'expired' => 0.0, 'closing' => 0.0, 'failed' => true];
        }
        return $out;
    }

    /**
     * Pure twin of rh_guest_credit_summary (tests). $notes: rows with id, original_amount, issued_at, voided_at,
     * expires_at; $apps: rows with credit_note_id, amount_applied, applied_at. Also returns 'owed_at' => callable.
     */
    function rh_guest_credit_from_rows(array $notes, array $apps, string $from, string $to): array
    {
        $day = static function ($v): string { return substr((string)$v, 0, 10); };
        $endDay = static function (array $n) use ($day): ?string {
            $v = !empty($n['voided_at']) ? $day($n['voided_at']) : null;
            $x = !empty($n['expires_at']) ? date('Y-m-d', strtotime($day($n['expires_at']) . ' +1 day')) : null;
            if ($v !== null && $x !== null) { return min($v, $x); }
            return $v ?? $x;
        };
        $endKind = static function (array $n) use ($day): string {
            if (empty($n['voided_at'])) { return 'expired'; }
            if (empty($n['expires_at'])) { return 'voided'; }
            return $day($n['voided_at']) <= date('Y-m-d', strtotime($day($n['expires_at']) . ' +1 day')) ? 'voided' : 'expired';
        };
        $appliedBy = static function (int $id, string $d) use ($apps, $day): float {
            $s = 0.0;
            foreach ($apps as $a) {
                if ((int)$a['credit_note_id'] === $id && $day($a['applied_at']) <= $d) { $s += (float)$a['amount_applied']; }
            }
            return $s;
        };
        $owedAt = static function (string $d) use ($notes, $day, $endDay, $appliedBy): float {
            $s = 0.0;
            foreach ($notes as $n) {
                $e = $endDay($n);
                if ($day($n['issued_at']) <= $d && ($e === null || $e > $d)) {
                    $s += max(0.0, (float)$n['original_amount'] - $appliedBy((int)$n['id'], $d));
                }
            }
            return round($s, 2);
        };
        $out = ['opening' => $owedAt(date('Y-m-d', strtotime($from . ' -1 day'))), 'issued' => 0.0, 'issued_count' => 0,
            'used' => 0.0, 'voided' => 0.0, 'expired' => 0.0, 'closing' => $owedAt($to), 'failed' => false];
        foreach ($notes as $n) {
            $i = $day($n['issued_at']);
            if ($i >= $from && $i <= $to) { $out['issued'] += (float)$n['original_amount']; $out['issued_count']++; }
            $e = $endDay($n);
            if ($e !== null && $e >= $from && $e <= $to) {
                $out[$endKind($n)] += max(0.0, (float)$n['original_amount'] - $appliedBy((int)$n['id'], $e));
            }
        }
        foreach ($apps as $a) {
            $d = $day($a['applied_at']);
            if ($d >= $from && $d <= $to) { $out['used'] += (float)$a['amount_applied']; }
        }
        foreach (['issued', 'used', 'voided', 'expired'] as $k) { $out[$k] = round($out[$k], 2); }
        $out['owed_at'] = $owedAt;
        return $out;
    }

    /**
     * The shared Guest credit lines for every screen / PDF / e-mail / CSV: a list of
     * ['key' => ..., 'label' => ..., 'amount' => float, 'help' => plain helper text]. Opening only when $period
     * (Reports); Voided / Expired unused only when above BALANCE_TOLERANCE. [] when the summary could not be read.
     */
    function rh_guest_credit_lines(array $s, bool $period): array
    {
        if (!empty($s['failed'])) {
            return [];
        }
        $tol = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;
        $lines = [];
        if ($period) {
            $lines[] = ['key' => 'opening', 'label' => 'Guest credit at start', 'amount' => (float)($s['opening'] ?? 0),
                'help' => 'What the hotel owed guests on credit notes when the period began.'];
        }
        $lines[] = ['key' => 'issued', 'label' => 'Guest credit issued', 'amount' => (float)($s['issued'] ?? 0),
            'help' => 'New credit notes: overpayments, store credit and goodwill. Money the hotel holds for guests; not a refund, not revenue.'];
        $lines[] = ['key' => 'used', 'label' => 'Guest credit used', 'amount' => (float)($s['used'] ?? 0),
            'help' => 'Credit notes spent on bills (net of any put back). No new money.'];
        if ((float)($s['voided'] ?? 0) > $tol) {
            $lines[] = ['key' => 'voided', 'label' => 'Guest credit voided', 'amount' => (float)$s['voided'],
                'help' => 'Credit cancelled, with nothing left owed on it.'];
        }
        if ((float)($s['expired'] ?? 0) > $tol) {
            $lines[] = ['key' => 'expired', 'label' => 'Guest credit expired unused', 'amount' => (float)$s['expired'],
                'help' => 'Credit that passed its expiry date with nothing left owed on it.'];
        }
        $lines[] = ['key' => 'closing', 'label' => 'Guest credit still owed', 'amount' => (float)($s['closing'] ?? 0),
            'help' => 'What the hotel still owes guests on credit notes at the end of the ' . ($period ? 'period.' : 'day.')];
        return $lines;
    }
}
