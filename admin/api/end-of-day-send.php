<?php

/**
 * End of Day Report sender (the Email and WhatsApp buttons on admin/end-of-day-report.php).
 *
 * POST JSON: { "date": "YYYY-MM-DD", "channel": "email|whatsapp", "csrf": "...", "cc_email": "extra@x.com" }
 *
 * Contract: .claude/EOD_SPEC.md (R1 / R2). Figures: rh_eod_report_data() (admin/includes/eod-report-data.php), the
 * same builder as the PDF download and the timed daily e-mail. Email: To = End-of-day report recipients
 * (rh_eod_report_recipients), CC = the saved End of Day copy list (eod_report_cc_emails) + the box's extras,
 * hidden staff copy by the mailer; test mode (Automated Emails > Test recipient set):
 * ONLY the test address, [TEST], no copies. WhatsApp: rh_eod_whatsapp_target(); never sent in test mode.
 * If the figures cannot be loaded nothing is sent (never a report of zeros).
 */

declare(strict_types=1);

require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../includes/eod-report-data.php';
require_once __DIR__ . '/../includes/front-desk-counts.php'; // tentative holds note
require_once __DIR__ . '/../../includes/report-mailer.php';
require_once __DIR__ . '/../../includes/whatsapp-functions.php';
require_once __DIR__ . '/../../includes/daily-reports-send.php';

header('Content-Type: application/json');

/** @var PDO $pdo */
/** @var array $user */

requireApiPermission('reports');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$today   = rh_eod_today();
$csrf    = (string)($body['csrf']     ?? '');
$date    = (string)($body['date']     ?? $today);
$channel = (string)($body['channel']  ?? 'email');
$cc_raw  = (string)($body['cc_email'] ?? '');

if (!function_exists('validateCsrfToken') || !validateCsrfToken($csrf)) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token. Please reload the page.']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
    $date = $today;
}
if (!in_array($channel, ['email', 'whatsapp'], true)) {
    echo json_encode(['success' => false, 'error' => 'Unknown channel']);
    exit;
}

$site_name       = getSetting('site_name') ?: 'Hotel';
$currency_symbol = getSetting('currency_symbol') ?: 'MWK ';
$testTo          = rh_report_test_recipient();

function _money(string $sym, int|float $v): string
{
    return $sym . number_format((float)$v, 2);
}

// WhatsApp in test mode: never sent (owner B3). Checked before any figure is read.
if ($channel === 'whatsapp' && $testTo !== '') {
    echo json_encode(['success' => false, 'error' => 'Test mode is on (Automated Emails > Test recipient), so the WhatsApp summary was not sent.']);
    exit;
}

// -----------------------------------------------------------------------------
// Figures: the shared builder. A failure sends NOTHING (F6).
// -----------------------------------------------------------------------------
try {
    $d = rh_eod_report_data($pdo, $date, $currency_symbol);
} catch (Throwable $eFetch) {
    if (function_exists('rh_log_event')) {
        rh_log_event('eod-report', 'error', 'EOD send stopped: figures could not be loaded', ['date' => $date, 'channel' => $channel, 'error' => $eFetch->getMessage()]);
    }
    echo json_encode(['success' => false, 'error' => 'The End of Day figures could not be loaded, so nothing was sent. Try again in a moment (the error is in System Logs).']);
    exit;
}

$ops = $d['ops'];
$rev = $d['rev'] + ['gym_gross' => 0, 'events_gross' => 0, 'pending' => 0, 'refunds' => 0, 'total_vat' => 0, 'txn_count' => 0];
$earned = $d['earned'];
$earned_total = (float)$d['earned_total'];
$gross = (float)$d['gross'];
$net = (float)$d['net'];
$adr = (float)$d['adr'];
$revpar = (float)$d['revpar'];
$methods = $d['methods'];
$method_totals = $d['method_totals'];
$cash_refunds_out = (float)$d['cash_refunds_out'];
$refunds_to_credit = (float)$d['refunds_to_credit'];
$cn_redeemed = (float)($d['credit_notes_redeemed'] ?? 0);
$pos = $d['pos'];
$top = array_slice($d['top_items'], 0, 5);
$reviews = ['c' => (int)($d['reviewRow']['cnt'] ?? 0), 'a' => (float)($d['reviewRow']['avg_rating'] ?? 0)];
$tom = $d['tom'];
$outstanding = (float)$d['outstanding'];
$outstanding_accounts = (float)$d['outstanding_accounts'];
$outstanding_tabs = (float)$d['outstanding_tabs'];
$outstanding_failed = (bool)$d['outstanding_failed'];
$housekeeping = $d['hk'];
$rooms_total = (int)$d['rooms_total'];
$rooms_occupied = (int)$d['rooms_occupied'];
$occupancy_pct = (float)$d['occupancy_pct'];
$arrivals_remaining = (int)$d['arrivals_remaining'];
$departures_remaining = (int)$d['departures_remaining'];
$rooms_unsold = (int)$d['rooms_unsold'];
$empty_room_opportunity = (float)$d['empty_room_opportunity'];
$payment_capture_rate = (float)$d['payment_capture_rate'];
$net_change = (float)$d['net_change'];
$pos_change = (float)$d['pos_change'];
$occupancy_change = (float)$d['occ_change'];
$daily_health_score = (int)$d['score'];
$daily_health_label = (string)$d['score_label'];

$dateLabel = date('l, F j, Y', (int)strtotime($date));
$asAt = (string)$d['generated_hm'];
$dayOpen = rh_eod_day_is_open($date); // still trading: every send says "as at HH:MM" (L21)
$byName = (string)($user['full_name'] ?? '') !== '' ? (string)$user['full_name'] : 'Admin';

// Module flags: rooms / occupancy only for booking businesses; conference / gym / events when on.
$eodModBookings   = !empty($d['mods']['bookings']);
$eodModConference = !empty($d['mods']['conference']);
$eodModGym        = !empty($d['mods']['gym']);
$eodModEvents     = !empty($d['mods']['events']);
$eodModHk         = !empty($d['mods']['housekeeping']);
$eodOrdersToday   = (int)($pos['orders'] ?? 0);

// "The day in brief" (AI lead's hook): only when its file exists and AI is available, else nothing at all.
$facts = rh_eod_headline_facts($d);
try {
    $brief = rh_eod_ai_brief($pdo, $date, $facts); // never throws itself; belt and braces so AI can never stop a send
} catch (Throwable $e) {
    error_log('EOD day in brief (send): ' . $e->getMessage());
    $brief = null;
}

// -----------------------------------------------------------------------------
// Channel: WhatsApp — concise plain-text summary
// -----------------------------------------------------------------------------
if ($channel === 'whatsapp') {
    $wa = rh_eod_whatsapp_target();
    $waNumber = $wa['number'];
    if ($waNumber === '') {
        echo json_encode(['success' => false, 'error' => 'No WhatsApp number to send to. Set "End of Day WhatsApp number" in Hotel Settings > Payments & documents.']);
        exit;
    }

    $healthEmoji = $daily_health_score >= 90 ? '🟢' : ($daily_health_score >= 75 ? '🟡' : ($daily_health_score >= 55 ? '🟠' : '🔴'));
    $netArrow    = $net_change >= 0 ? '▲' : '▼';
    $occArrow    = $occupancy_change >= 0 ? '▲' : '▼';
    $posArrow    = $pos_change >= 0 ? '▲' : '▼';

    $alerts_wa = [];
    if ($arrivals_remaining > 0)     $alerts_wa[] = '⚠️ ' . $arrivals_remaining . ' arrival(s) not checked in';
    if ($departures_remaining > 0)   $alerts_wa[] = '⚠️ ' . $departures_remaining . ' departure(s) not checked out';
    if ((float)$rev['pending'] > 0)  $alerts_wa[] = '💳 Pending payments: ' . _money($currency_symbol, (float)$rev['pending']);
    if ($outstanding > 0)            $alerts_wa[] = '📋 Outstanding folio: ' . _money($currency_symbol, $outstanding);
    if ($outstanding_accounts > 0)   $alerts_wa[] = '📋 Outstanding accounts: ' . _money($currency_symbol, $outstanding_accounts);
    if ($outstanding_tabs > 0)       $alerts_wa[] = '📋 Open tabs (unpaid): ' . _money($currency_symbol, $outstanding_tabs);
    if ($outstanding_failed)         $alerts_wa[] = 'Outstanding total unavailable (some accounts could not be read)';
    if ((int)$pos['voided_count'] > 0) $alerts_wa[] = '🚫 ' . (int)$pos['voided_count'] . ' POS void(s) — review required';

    $lines = [];
    $lines[] = "┌──────────────────────────────────┐";
    $lines[] = "│  📊 END OF DAY REPORT              │";
    $lines[] = "│  " . $site_name;
    $lines[] = "│  " . $dateLabel . ($dayOpen ? ' (as at ' . $asAt . ')' : '');
    $lines[] = "└──────────────────────────────────┘";
    $lines[] = '';
    if (($waBrief = rh_eod_brief_wa_line($brief)) !== null) {
        $lines[] = $waBrief;
        $lines[] = '';
    }
    $lines[] = $healthEmoji . " *Closeout Health: " . $daily_health_score . "/100 — " . $daily_health_label . "*";
    $lines[] = '';
    $lines[] = "━━━━━━━━━━ 💰 REVENUE ━━━━━━━━━━";
    $lines[] = "Revenue earned: *" . _money($currency_symbol, $earned_total) . "*";
    $lines[] = "  " . $netArrow . " vs yesterday: " . ($net_change >= 0 ? '+' : '') . _money($currency_symbol, $net_change);
    if ($eodModBookings)   { $lines[] = "  🏨 Rooms (nights stayed): " . _money($currency_symbol, (float)$earned['rooms_total_gross']); }
    if (abs((float)$earned['extras_gross']) > 0.004) { $lines[] = "  🧾 Other guest-bill charges: " . _money($currency_symbol, (float)$earned['extras_gross']); }
    if (abs((float)$earned['cancel_kept_gross']) > 0.004) { $lines[] = "  🛏️ Kept on cancellations: " . _money($currency_symbol, (float)$earned['cancel_kept_gross']); }
    if ($eodModConference || abs((float)$earned['conf_gross']) > 0.004) { $lines[] = "  🎪 Conference: " . _money($currency_symbol, (float)$earned['conf_gross']); }
    $lines[] = (isRestaurantEnabled() ? "  🍽️ F&B:         " : "  🛒 POS:         ") . _money($currency_symbol, (float)$earned['fnb_gross']);
    if ($eodModGym    || abs((float)$earned['gym_gross']) > 0.004)    { $lines[] = "  🏋️ Gym (when paid): " . _money($currency_symbol, (float)$earned['gym_gross']); }
    if ($eodModEvents || abs((float)$earned['events_gross']) > 0.004) { $lines[] = "  🎟️ Events: " . _money($currency_symbol, (float)$earned['events_gross']); }
    $lines[] = "  (Conference and events count on the event date; gym when paid)";
    $lines[] = "  (" . rh_eod_day_rules_note($date, function_exists('rh_pos_short_label') ? rh_pos_short_label() : 'POS') . ")";
    $lines[] = '';
    $lines[] = "Cash collected: *" . _money($currency_symbol, $net) . "*  (received " . _money($currency_symbol, $gross) . ((float)$rev['refunds'] > 0 ? ", refunds -" . _money($currency_symbol, (float)$rev['refunds']) : '') . ")";
    if ($refunds_to_credit > 0.004) {
        $lines[] = "  of the refunds, moved to credit notes (no cash out): " . _money($currency_symbol, $refunds_to_credit);
    }
    if ($cn_redeemed > 0.004) {
        $lines[] = "Credit notes redeemed (guest credit used, no new money): " . _money($currency_symbol, $cn_redeemed);
    }
    $lines[] = "VAT in cash collected: " . _money($currency_symbol, (float)$rev['total_vat']);
    $lines[] = '';
    if ($eodModBookings) {
        $lines[] = "━━━━━━━━━━ 🛏️ ROOMS ━━━━━━━━━━";
        $lines[] = "Occupancy: *" . number_format($occupancy_pct, 1) . "%* (" . $rooms_occupied . "/" . $rooms_total . " sellable rooms)";
        $lines[] = "  " . $occArrow . " " . ($occupancy_change >= 0 ? '+' : '') . number_format($occupancy_change, 1) . " pts vs yesterday";
        $lines[] = "ADR (incl. VAT): " . _money($currency_symbol, $adr) . "   RevPAR: " . _money($currency_symbol, $revpar);
        $lines[] = "Unsold: " . $rooms_unsold . " rooms  (opp. " . _money($currency_symbol, $empty_room_opportunity) . ")";
        $lines[] = '';
        $lines[] = "Arrivals:   " . (int)($ops['arrivals_completed'] ?? 0) . " done / " . (int)($ops['expected_arrivals'] ?? 0) . " expected";
        if (($tentNote = rh_fd_tentative_note($ops)) !== '') {
            $lines[] = "            " . $tentNote;
        }
        $lines[] = "Departures: " . (int)($ops['departures_completed'] ?? 0) . " done / " . (int)($ops['expected_departures'] ?? 0) . " expected";
        $lines[] = "Stay-overs: " . (int)($ops['stayovers'] ?? 0) . "   New bookings: " . (int)($ops['new_bookings'] ?? 0);
        if ((int)($ops['cancellations'] ?? 0) > 0 || (int)($ops['no_shows'] ?? 0) > 0) {
            $lines[] = "Cancellations: " . (int)($ops['cancellations'] ?? 0) . "   No-shows: " . (int)($ops['no_shows'] ?? 0);
        }
        $lines[] = '';
    }
    $lines[] = isRestaurantEnabled() ? "━━━━━━━━━━ 🍽️ F&B / POS ━━━━━━━━━━" : "━━━━━━━━━━ 🛒 POS ━━━━━━━━━━";
    $lines[] = "Orders: " . (int)$pos['orders'] . "   Gross: *" . _money($currency_symbol, (float)$pos['gross']) . "*";
    if ((int)($pos['room_charge_count'] ?? 0) > 0) {
        $lines[] = "  incl. charged to room: " . (int)$pos['room_charge_count'] . " (" . _money($currency_symbol, (float)$pos['room_charge_amount']) . ")";
    }
    $lines[] = "Margin (ex-VAT): " . _money($currency_symbol, (float)($pos['margin'] ?? 0)) . " (" . number_format((float)($pos['margin_pct'] ?? 0), 1) . "%)";
    $lines[] = "  " . $posArrow . " vs yesterday: " . ($pos_change >= 0 ? '+' : '') . _money($currency_symbol, $pos_change);
    if ($top) {
        $lines[] = "🔥 Top: " . implode(' · ', array_map(fn(array $t): string => (string)$t['item_name'], array_slice($top, 0, 3)));
    }
    $lines[] = '';
    $lines[] = "━━━━━━━━━━ 💵 CASHIER ━━━━━━━━━━";
    if ($cash_refunds_out > 0.004) {
        $lines[] = "Cash refunds paid: -" . _money($currency_symbol, $cash_refunds_out);
    }
    $lines[] = "Cash to reconcile: *" . _money($currency_symbol, (float)$method_totals['cash']) . "*";
    $lines[] = "Non-cash:          " . _money($currency_symbol, (float)$method_totals['non_cash']);
    $lines[] = "Capture rate:      " . number_format($payment_capture_rate, 1) . "%";
    $lines[] = '';
    if ($alerts_wa) {
        $lines[] = "━━━━━━━━━━ 🚨 ACTION ITEMS ━━━━━━━━━━";
        foreach ($alerts_wa as $aw) {
            $lines[] = $aw;
        }
        $lines[] = '';
    }
    if ($reviews['c'] > 0) {
        $stars = str_repeat('★', (int)round($reviews['a'])) . str_repeat('☆', 5 - (int)round($reviews['a']));
        $lines[] = "⭐ Reviews today: " . $reviews['c'] . " review(s)  " . $stars . " " . number_format($reviews['a'], 1) . "/5";
        $lines[] = '';
    }
    if ($eodModBookings) {
        $lines[] = "━━━━━━━━━━ 📅 TOMORROW ━━━━━━━━━━";
        $lines[] = date('D, j M Y', (int)strtotime($date . ' +1 day'));
        $lines[] = "Arrivals: " . (int)$tom['arrivals'] . "   Departures: " . (int)$tom['departures'];
        $lines[] = "Booked value of arrivals: " . _money($currency_symbol, (float)$tom['rev_forecast']);
        if (($tomTentNote = rh_fd_tentative_note($tom)) !== '') {
            $lines[] = $tomTentNote;
        }
        $lines[] = '';
    }
    $lines[] = "Figures as at " . $asAt . "; later changes show on the End of Day page.";
    $lines[] = "— " . $byName . " · " . $asAt . " · " . $site_name;

    $result = sendWhatsAppMessage($waNumber, implode("\n", $lines));

    if (function_exists('rh_log_event')) {
        rh_log_event('eod-report', $result['success'] ? 'info' : 'warning', 'EOD WhatsApp send', [
            'date' => $date, 'to' => $waNumber, 'to_source' => $wa['source'], 'success' => $result['success'],
        ]);
    }
    echo json_encode([
        'success' => (bool)$result['success'],
        'message' => $result['success'] ? "WhatsApp sent to {$waNumber} ({$wa['source']})." : ($result['message'] ?? 'WhatsApp send failed'),
        'error'   => $result['success'] ? null : ($result['message'] ?? 'WhatsApp send failed'),
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// Channel: Email — rich HTML + the PDF
// -----------------------------------------------------------------------------
// To: End-of-day report recipients (one helper, also the timer's). CC: the saved End of Day copy list, always (F2),
// plus any extra addresses typed in the box; valid, no repeats, never a To address.
$recipients = rh_eod_report_recipients();
$cc_emails = rh_daily_reports_cc($recipients, (string)getSetting('eod_report_cc_emails', '') . ',' . $cc_raw);
$subject = 'EOD Report — ' . $site_name . ' — ' . $dateLabel . ($dayOpen ? ' (as at ' . $asAt . ')' : '');
$route = rh_report_route($recipients, $cc_emails, $subject, $testTo);
if ($route['action'] !== 'send') {
    echo json_encode(['success' => false, 'error' => $route['note']]);
    exit;
}
if (!$route['test'] && !$recipients) {
    echo json_encode(['success' => false, 'error' => 'No one to send it to. Add an address under "End-of-day report recipients" (Hotel Settings > Payments & documents), or set the staff copy email in Email Settings.']);
    exit;
}

$row = function (string $label, string $value, bool $bold = false) {
    $w = $bold ? 'font-weight:700;' : '';
    return '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;color:#5e554d;">' . htmlspecialchars($label) . '</td>'
        . '<td style="padding:8px 12px;border-bottom:1px solid #eee;text-align:right;' . $w . '">' . $value . '</td></tr>';
};
$section_head = function (string $label): string {
    return '<tr><td colspan="2" style="padding:10px 12px 4px;background:#F3ECE4;font-size:10px;letter-spacing:0.1em;color:#8A775F;font-weight:700;text-transform:uppercase;border-top:2px solid #e5d9c9;">' . $label . '</td></tr>';
};
$kpiHelp = static function (string $t): string {
    return '<div style="font-size:9px;color:#8A775F;margin-top:4px;">' . htmlspecialchars($t) . '</div>';
};

$score_hex = $daily_health_score >= 90 ? '#166534' : ($daily_health_score >= 75 ? '#15803d' : ($daily_health_score >= 55 ? '#92400e' : '#b91c1c'));
$score_bg  = $daily_health_score >= 90 ? '#f0fdf4' : ($daily_health_score >= 75 ? '#f0fdf4' : ($daily_health_score >= 55 ? '#fffbeb' : '#fff1f2'));
$net_color = $net_change >= 0 ? '#166534' : '#b91c1c';
$occ_color = $occupancy_change >= 0 ? '#166534' : '#b91c1c';
$pos_color = $pos_change >= 0 ? '#166534' : '#b91c1c';
$net_sign  = $net_change >= 0 ? '+' : '';
$occ_sign  = $occupancy_change >= 0 ? '+' : '';
$pos_sign  = $pos_change >= 0 ? '+' : '';

$email_alerts = [];
if ($arrivals_remaining > 0)     $email_alerts[] = ['warn', '⚠️ Arrivals still open', $arrivals_remaining . ' expected arrival(s) not checked in.'];
if ($departures_remaining > 0)   $email_alerts[] = ['warn', '⚠️ Departures still open', $departures_remaining . ' expected departure(s) not checked out.'];
if ((float)$rev['pending'] > 0)  $email_alerts[] = ['warn', '💳 Pending payments', _money($currency_symbol, (float)$rev['pending']) . ' still pending.'];
if ($outstanding_tabs > 0)       $email_alerts[] = ['warn', '📋 Open tabs (unpaid)', _money($currency_symbol, $outstanding_tabs) . ' still owed on open tabs.'];
if ($outstanding_failed)         $email_alerts[] = ['warn', 'Outstanding figure incomplete', 'Some accounts could not be read, so the amount still owed may be understated.'];
if ($outstanding_accounts > 0)   $email_alerts[] = ['warn', '📋 Outstanding accounts', _money($currency_symbol, $outstanding_accounts) . ' still owed on conference, event and gym accounts.'];
if ($outstanding > 0)            $email_alerts[] = ['warn', '📋 Outstanding folio', _money($currency_symbol, $outstanding) . ' still owed on open bookings (in-house, upcoming and checked out).'];
if ((int)$pos['voided_count'] > 0) $email_alerts[] = ['warn', '🚫 POS voids', (int)$pos['voided_count'] . ' void(s) worth ' . _money($currency_symbol, (float)$pos['voided_value']) . ' — review required.'];
if ($eodModHk && ((int)$housekeeping['pending'] + (int)$housekeeping['in_progress']) > 0) {
    $email_alerts[] = ['info', '🧹 Housekeeping open', ((int)$housekeeping['pending'] + (int)$housekeeping['in_progress']) . ' task(s) not completed.'];
}

$html  = '<div style="font-family:Arial,Helvetica,sans-serif;color:#2A2723;max-width:660px;margin:0 auto;background:#ffffff;">';

// Header
$html .= '<div style="background:#231F1C;padding:20px 24px 16px;border-radius:6px 6px 0 0;">';
$html .= '<div style="font-size:11px;letter-spacing:0.15em;color:#B18247;text-transform:uppercase;margin-bottom:4px;">End of Day Report</div>';
$html .= '<div style="font-size:20px;font-weight:700;color:#ffffff;margin-bottom:2px;">' . htmlspecialchars($site_name) . '</div>';
$html .= '<div style="font-size:13px;color:#a89683;">' . htmlspecialchars($dateLabel) . ' &nbsp;·&nbsp; ' . ($dayOpen ? 'Still trading: figures as at ' : 'Generated ') . htmlspecialchars($asAt) . ' &nbsp;·&nbsp; By ' . htmlspecialchars($byName) . '</div>';
$html .= '</div>';

// The day in brief (only when the AI lead's hook returned one)
$html .= rh_eod_brief_html($brief);

// Health score
$html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $score_bg . ';border-left:5px solid ' . $score_hex . ';"><tr>';
$html .= '<td style="padding:16px 20px;vertical-align:middle;font-family:Arial,Helvetica,sans-serif;">';
$html .= '<div style="font-size:10px;letter-spacing:0.12em;color:' . $score_hex . ';text-transform:uppercase;font-weight:700;margin-bottom:4px;">Daily Closeout Health</div>';
$html .= '<div style="font-size:13px;color:' . $score_hex . ';">' . htmlspecialchars($daily_health_label) . '</div>';
$html .= '<div style="font-size:10px;color:#5e554d;margin-top:4px;">Starts at 100; open arrivals and departures, no-shows, cancellations, voids, pending payments, money still owed and open housekeeping lower it.</div>';
$html .= '</td><td align="right" style="padding:16px 20px;vertical-align:middle;white-space:nowrap;font-family:Arial,Helvetica,sans-serif;">';
$html .= '<span style="font-size:44px;font-weight:700;color:' . $score_hex . ';line-height:1;">' . $daily_health_score . '</span>';
$html .= '<span style="font-size:15px;font-weight:400;color:' . $score_hex . ';"> / 100</span>';
$html .= '</td></tr></table>';

// KPI strip (each tile says what it uses)
$html .= '<table style="width:100%;border-collapse:collapse;border-bottom:2px solid #e5d9c9;"><tr>';
$html .= '<td style="padding:16px;text-align:center;background:#F3ECE4;border-right:1px solid #e5d9c9;">';
$html .= '<div style="font-size:9px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;margin-bottom:4px;">Revenue Earned</div>';
$html .= '<div style="font-size:20px;font-weight:700;color:#231F1C;">' . _money($currency_symbol, $earned_total) . '</div>';
$html .= '<div style="font-size:11px;color:' . $net_color . ';margin-top:3px;">' . $net_sign . _money($currency_symbol, $net_change) . ' vs yesterday</div>';
$html .= $kpiHelp('What was earned this day, VAT and levy included');
$html .= '</td>';
if ($eodModBookings) {
    $html .= '<td style="padding:16px;text-align:center;background:#F3ECE4;border-right:1px solid #e5d9c9;">';
    $html .= '<div style="font-size:9px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;margin-bottom:4px;">Occupancy</div>';
    $html .= '<div style="font-size:20px;font-weight:700;color:#231F1C;">' . number_format($occupancy_pct, 1) . '%</div>';
    $html .= '<div style="font-size:11px;color:#5e554d;margin-top:3px;">' . $rooms_occupied . '&nbsp;/&nbsp;' . $rooms_total . ' rooms &nbsp;<span style="color:' . $occ_color . ';">' . $occ_sign . number_format($occupancy_change, 1) . ' pts</span></div>';
    $html .= $kpiHelp('Rooms occupied this night out of sellable rooms');
    $html .= '</td>';
    $html .= '<td style="padding:16px;text-align:center;background:#F3ECE4;border-right:1px solid #e5d9c9;">';
    $html .= '<div style="font-size:9px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;margin-bottom:4px;">ADR incl. VAT</div>';
    $html .= '<div style="font-size:20px;font-weight:700;color:#231F1C;">' . _money($currency_symbol, $adr) . '</div>';
    $html .= '<div style="font-size:11px;color:#5e554d;margin-top:3px;">RevPAR&nbsp;' . _money($currency_symbol, $revpar) . '</div>';
    $html .= $kpiHelp('Room revenue earned for the night per room occupied');
    $html .= '</td>';
} else {
    $html .= '<td style="padding:16px;text-align:center;background:#F3ECE4;border-right:1px solid #e5d9c9;">';
    $html .= '<div style="font-size:9px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;margin-bottom:4px;">Orders Today</div>';
    $html .= '<div style="font-size:20px;font-weight:700;color:#231F1C;">' . $eodOrdersToday . '</div>';
    $html .= '<div style="font-size:11px;color:#5e554d;margin-top:3px;">' . (int)($pos['voided_count'] ?? 0) . ' void(s)</div>';
    $html .= $kpiHelp('Orders paid at the till or charged to a room');
    $html .= '</td>';
    $html .= '<td style="padding:16px;text-align:center;background:#F3ECE4;border-right:1px solid #e5d9c9;">';
    $html .= '<div style="font-size:9px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;margin-bottom:4px;">Avg Order Value</div>';
    $html .= '<div style="font-size:20px;font-weight:700;color:#231F1C;">' . _money($currency_symbol, $eodOrdersToday > 0 ? ((float)($pos['gross'] ?? 0)) / $eodOrdersToday : 0) . '</div>';
    $html .= '<div style="font-size:11px;color:#5e554d;margin-top:3px;">per settled order</div>';
    $html .= $kpiHelp('Till sales divided by orders');
    $html .= '</td>';
}
$html .= '<td style="padding:16px;text-align:center;background:#F3ECE4;">';
$html .= '<div style="font-size:9px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;margin-bottom:4px;">' . (isRestaurantEnabled() ? 'F&amp;B / POS' : 'POS') . '</div>';
$html .= '<div style="font-size:20px;font-weight:700;color:#231F1C;">' . _money($currency_symbol, (float)$pos['gross']) . '</div>';
$html .= '<div style="font-size:11px;color:' . $pos_color . ';margin-top:3px;">' . $pos_sign . _money($currency_symbol, $pos_change) . ' vs yesterday</div>';
$html .= $kpiHelp('Till sales on the trading day, incl. charged to room');
$html .= '</td></tr></table>';

// Alerts
if ($email_alerts) {
    $html .= '<div style="padding:14px 16px;background:#fff8f0;border-left:4px solid #B18247;">';
    $html .= '<div style="font-size:10px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;font-weight:700;margin-bottom:8px;">Action Items Before Close</div>';
    foreach ($email_alerts as $ea) {
        $abg  = $ea[0] === 'warn' ? '#fff1f2' : '#fffbeb';
        $abdr = $ea[0] === 'warn' ? '#ef4444' : '#f59e0b';
        $html .= '<div style="margin-bottom:6px;padding:8px 10px;background:' . $abg . ';border-left:3px solid ' . $abdr . ';border-radius:3px;font-size:12px;">';
        $html .= '<strong>' . $ea[1] . '</strong> &mdash; ' . $ea[2];
        $html .= '</div>';
    }
    $html .= '</div>';
}

// Revenue earned + cash collected
$html .= '<table style="width:100%;border-collapse:collapse;">';
$html .= $section_head('Revenue Earned by Source');
$revSrcRows = [];
if ($eodModBookings)   { $revSrcRows[] = ['Rooms (nights stayed)', _money($currency_symbol, (float)$earned['rooms_total_gross'])]; }
if (abs((float)$earned['extras_gross']) > 0.004) { $revSrcRows[] = ['Other guest-bill charges', _money($currency_symbol, (float)$earned['extras_gross'])]; }
if (abs((float)$earned['cancel_kept_gross']) > 0.004) { $revSrcRows[] = ['Kept on cancellations', _money($currency_symbol, (float)$earned['cancel_kept_gross'])]; }
if ($eodModConference || abs((float)$earned['conf_gross']) > 0.004) { $revSrcRows[] = ['Conferences (on event date)', _money($currency_symbol, (float)$earned['conf_gross'])]; }
$revSrcRows[] = [isRestaurantEnabled() ? 'F&B / POS' : 'POS', _money($currency_symbol, (float)$earned['fnb_gross'])];
if ($eodModGym    || abs((float)$earned['gym_gross']) > 0.004)    { $revSrcRows[] = ['Gym (when paid)',    _money($currency_symbol, (float)$earned['gym_gross'])]; }
if ($eodModEvents || abs((float)$earned['events_gross']) > 0.004) { $revSrcRows[] = ['Events (on event date)', _money($currency_symbol, (float)$earned['events_gross'])]; }
foreach ($revSrcRows as $rr) {
    $html .= $row($rr[0], $rr[1]);
}
$html .= '<tr style="background:#231F1C;"><td style="padding:10px 12px;font-weight:700;color:#ffffff;font-size:14px;">REVENUE EARNED</td><td style="padding:10px 12px;text-align:right;font-weight:700;color:#B18247;font-size:14px;">' . _money($currency_symbol, $earned_total) . '</td></tr>';
$html .= '<tr><td colspan="2" style="padding:6px 12px;border-bottom:1px solid #eee;font-size:11px;color:#8A775F;">Rooms are the nights stayed at each booking&rsquo;s booked rate; guest-bill charges and sales count on the day they were posted or paid. Conferences and events count on their event date; gym counts on the day the payment is received. ' . htmlspecialchars(rh_eod_day_rules_note($date, function_exists('rh_pos_short_label') ? rh_pos_short_label() : 'POS')) . '</td></tr>';
$html .= $section_head('Cash Collected');
$html .= '<tr style="background:#F3ECE4;"><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #e5d9c9;">Payments received</td><td style="padding:8px 12px;text-align:right;font-weight:700;border-bottom:1px solid #e5d9c9;">' . _money($currency_symbol, $gross) . '</td></tr>';
if ((float)$rev['refunds'] > 0) {
    $html .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;color:#b91c1c;">Less: Refunds paid out</td><td style="padding:8px 12px;text-align:right;color:#b91c1c;border-bottom:1px solid #eee;">&minus;' . _money($currency_symbol, (float)$rev['refunds']) . '</td></tr>';
    if ($refunds_to_credit > 0.004) {
        $html .= $row('of which moved to credit notes (guest credit, no cash out)', _money($currency_symbol, $refunds_to_credit));
    }
}
$html .= '<tr style="background:#F3ECE4;"><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #e5d9c9;">CASH COLLECTED (NET)</td><td style="padding:8px 12px;text-align:right;font-weight:700;border-bottom:1px solid #e5d9c9;">' . _money($currency_symbol, $net) . '</td></tr>';
if ($cn_redeemed > 0.004) {
    $html .= $row('Credit notes redeemed (guest credit used, no new money)', _money($currency_symbol, $cn_redeemed));
}
$html .= $row('VAT in Cash Collected', _money($currency_symbol, (float)$rev['total_vat']));
$html .= $row('Total Transactions', (string)(int)$rev['txn_count']);
$html .= $row('Pending / Partial', _money($currency_symbol, (float)$rev['pending']));
$html .= $row('Outstanding Folio', _money($currency_symbol, $outstanding));
if ($outstanding_accounts > 0) { $html .= $row('Outstanding Accounts', _money($currency_symbol, $outstanding_accounts)); }
if ($outstanding_tabs > 0) { $html .= $row('Open Tabs (unpaid)', _money($currency_symbol, $outstanding_tabs)); }
if ($outstanding_failed) { $html .= $row('Outstanding total', 'Unavailable'); }
$html .= $row('Payment Capture Rate', number_format($payment_capture_rate, 1) . '%');

if ($eodModBookings) {
    $html .= $section_head('Front Office Activity');
    $html .= $row('Arrivals (done / expected)', (int)($ops['arrivals_completed'] ?? 0) . ' of ' . (int)($ops['expected_arrivals'] ?? 0));
    if ((int)($ops['tentative_arrivals'] ?? 0) > 0) {
        $html .= $row('Tentative holds (not counted)', (string)(int)$ops['tentative_arrivals']);
    }
    $html .= $row('Departures (done / expected)', (int)($ops['departures_completed'] ?? 0) . ' of ' . (int)($ops['expected_departures'] ?? 0));
    $html .= $row('Stay-overs tonight', (string)(int)($ops['stayovers'] ?? 0));
    $html .= $row('New Bookings Created', (string)(int)($ops['new_bookings'] ?? 0));
    $html .= $row('Cancellations', (string)(int)($ops['cancellations'] ?? 0));
    $html .= $row('No-Shows', (string)(int)($ops['no_shows'] ?? 0));
    $html .= $row('Rooms Sold / Sellable', $rooms_occupied . ' / ' . $rooms_total);
    $html .= $row('Unsold Room Opportunity', _money($currency_symbol, $empty_room_opportunity));
}

$html .= $section_head(isRestaurantEnabled() ? 'POS / F&B' : 'POS');
$html .= $row('Total Orders', (string)(int)$pos['orders']);
$html .= $row('Gross Revenue', _money($currency_symbol, (float)$pos['gross']));
if ((int)($pos['room_charge_count'] ?? 0) > 0) {
    $html .= $row('Charged to Room', (int)$pos['room_charge_count'] . ' orders, ' . _money($currency_symbol, (float)$pos['room_charge_amount']) . ' (in orders and gross; on guest bills)');
}
$html .= $row('Cost of Goods (COGS)', _money($currency_symbol, (float)$pos['cogs']));
$html .= $row('Margin (ex-VAT)', _money($currency_symbol, (float)($pos['margin'] ?? 0)) . ' (' . number_format((float)($pos['margin_pct'] ?? 0), 1) . '%)');
$html .= $row('POS vs Yesterday', $pos_sign . _money($currency_symbol, $pos_change));
$html .= $row('POS Voids', (int)$pos['voided_count'] . ' orders worth ' . _money($currency_symbol, (float)$pos['voided_value']));

if ($methods) {
    $html .= $section_head('Payment Method Mix');
    foreach ($methods as $mRow) {
        $mLabel = ucwords(str_replace('_', ' ', (string)$mRow['method']));
        $mShare = $gross > 0 ? ((float)$mRow['total'] / $gross) * 100 : 0;
        $html  .= $row($mLabel . ' (' . (int)$mRow['cnt'] . ' txn)', _money($currency_symbol, (float)$mRow['total']) . ' &nbsp;<small style="color:#8A775F;">(' . number_format($mShare, 1) . '%)</small>');
    }
    if ($cash_refunds_out > 0.004) { $html .= $row('Less cash refunds paid', '-' . _money($currency_symbol, $cash_refunds_out)); }
    $html .= '<tr style="background:#F3ECE4;"><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #e5d9c9;">Cash to Reconcile</td><td style="padding:8px 12px;text-align:right;font-weight:700;border-bottom:1px solid #e5d9c9;">' . _money($currency_symbol, (float)$method_totals['cash']) . '</td></tr>';
    $html .= '<tr style="background:#F3ECE4;"><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #e5d9c9;">Non-Cash Collected</td><td style="padding:8px 12px;text-align:right;font-weight:700;border-bottom:1px solid #e5d9c9;">' . _money($currency_symbol, (float)$method_totals['non_cash']) . '</td></tr>';
}
$html .= '</table>';

if ($top) {
    $html .= '<div style="background:#F7F3EE;border-top:1px solid #e5d9c9;padding:16px 20px;">';
    $html .= '<div style="font-size:10px;letter-spacing:0.1em;color:#8A775F;text-transform:uppercase;font-weight:700;margin-bottom:10px;">🔥 Top Selling Items</div>';
    $html .= '<table style="width:100%;border-collapse:collapse;">';
    $html .= '<tr style="background:#F3ECE4;"><th style="padding:6px 10px;text-align:left;font-size:11px;color:#5e554d;font-weight:600;">Item</th><th style="padding:6px 10px;text-align:right;font-size:11px;color:#5e554d;font-weight:600;">Qty</th><th style="padding:6px 10px;text-align:right;font-size:11px;color:#5e554d;font-weight:600;">Revenue</th></tr>';
    foreach ($top as $i => $t) {
        $bg  = $i % 2 === 0 ? '#ffffff' : '#F7F3EE';
        $qty = rtrim(rtrim(number_format((float)$t['qty'], 2, '.', ''), '0'), '.');
        $html .= '<tr style="background:' . $bg . ';">';
        $html .= '<td style="padding:6px 10px;font-size:12px;border-bottom:1px solid #eee;">' . htmlspecialchars((string)$t['item_name']) . '</td>';
        $html .= '<td style="padding:6px 10px;text-align:right;font-size:12px;border-bottom:1px solid #eee;">' . $qty . '</td>';
        $html .= '<td style="padding:6px 10px;text-align:right;font-size:12px;font-weight:600;border-bottom:1px solid #eee;">' . _money($currency_symbol, (float)$t['revenue']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table></div>';
}

$html .= '<table style="width:100%;border-collapse:collapse;">';
if ($eodModHk) {
    $html .= $section_head('Housekeeping');
    $html .= $row('Pending Tasks', (string)(int)$housekeeping['pending']);
    $html .= $row('In Progress', (string)(int)$housekeeping['in_progress']);
    $html .= $row('Completed Today', (string)(int)$housekeeping['completed']);
}
if ($reviews['c'] > 0) {
    $html .= $section_head('Guest Reviews');
    $html .= $row('Reviews Received', $reviews['c'] . ' review(s)');
    $html .= $row('Average Rating', number_format($reviews['a'], 1) . ' / 5');
}
$html .= '</table>';

if ($eodModBookings) {
    $html .= '<div style="background:#231F1C;padding:16px 20px;margin-top:4px;">';
    $html .= '<div style="font-size:10px;letter-spacing:0.1em;color:#B18247;text-transform:uppercase;font-weight:700;margin-bottom:8px;">Tomorrow Preview — ' . htmlspecialchars(date('D, j M Y', (int)strtotime($date . ' +1 day'))) . '</div>';
    $html .= '<table style="width:100%;"><tr>';
    $html .= '<td style="text-align:center;padding:10px;"><div style="font-size:9px;color:#a89683;text-transform:uppercase;letter-spacing:0.1em;">Arrivals</div><div style="font-size:22px;font-weight:700;color:#ffffff;">' . (int)$tom['arrivals'] . '</div></td>';
    $html .= '<td style="text-align:center;padding:10px;border-left:1px solid #3d3733;"><div style="font-size:9px;color:#a89683;text-transform:uppercase;letter-spacing:0.1em;">Departures</div><div style="font-size:22px;font-weight:700;color:#ffffff;">' . (int)$tom['departures'] . '</div></td>';
    $html .= '<td style="text-align:center;padding:10px;border-left:1px solid #3d3733;"><div style="font-size:9px;color:#a89683;text-transform:uppercase;letter-spacing:0.1em;">Booked value of arrivals</div><div style="font-size:22px;font-weight:700;color:#B18247;">' . _money($currency_symbol, (float)$tom['rev_forecast']) . '</div></td>';
    $html .= '</tr></table>';
    if ((int)($tom['tentative_arrivals'] ?? 0) > 0) {
        $html .= '<div style="font-size:11px;color:#a89683;text-align:center;margin-top:6px;">' . htmlspecialchars(rh_fd_tentative_note($tom)) . '</div>';
    }
    $html .= '</div>';
}

$html .= '<div style="padding:12px 20px;background:#F7F3EE;border-top:1px solid #e5d9c9;border-radius:0 0 6px 6px;">';
$html .= '<p style="font-size:11px;color:#8A775F;margin:0;">Figures as at ' . htmlspecialchars($asAt) . '; later changes show on the End of Day page. &nbsp;·&nbsp; Sent by ' . htmlspecialchars($byName) . ' &nbsp;·&nbsp; All amounts in ' . htmlspecialchars(trim($currency_symbol)) . ' &nbsp;·&nbsp; ' . htmlspecialchars($site_name) . '</p>';
$html .= '</div>';
$html .= '</div>';

// PDF attachment: the shared builder over the same data as the download (L6).
$attachments = [];
try {
    require_once __DIR__ . '/../../includes/eod-pdf-builder.php';
    $attachments[] = [
        'filename' => 'eod-report-' . $date . '.pdf',
        'content'  => buildEodPdf($d, $site_name, $currency_symbol, $byName),
        'mime'     => 'application/pdf',
    ];
} catch (Throwable $e) {
    error_log('EOD email PDF attachment: ' . $e->getMessage());
}

$emailCtx = function_exists('rh_email_context') ? rh_email_context(['kind' => 'end_of_day_report']) : null; // System Logs > Emails sent
try {
    // Test mode: rh_report_with_test_ctx makes the mailer send ONLY to the test address, [TEST], no copies.
    $result = rh_report_with_test_ctx($route['test'] ? $testTo : '', static function () use ($route, $html, $attachments) {
        return sendReportEmail($route['recipients'], $route['subject'], $html, $attachments, '', $route['cc']);
    });
} finally {
    $emailCtx = null; // script scope: never left open for the scheduler that runs after the page
    rh_email_context_clear();
}

if (function_exists('rh_log_event')) {
    rh_log_event('eod-report', $result['success'] ? 'info' : 'warning', 'EOD email send', [
        'date' => $date,
        'recipients' => array_column($route['recipients'], 'email'),
        'cc' => $route['cc'],
        'test_mode' => $route['test'],
        'success' => $result['success'],
    ]);
}

$recipientList = implode(', ', array_column($route['recipients'], 'email'));
$ccNote = $route['cc'] ? ' — CC: ' . implode(', ', $route['cc']) : '';
$okMsg = $route['test']
    ? 'TEST MODE: sent only to ' . $testTo . ' (no copies). Real recipients would be: ' . (implode(', ', array_column($recipients, 'email')) ?: 'none set') . '.'
    : 'Sent to ' . count($route['recipients']) . ' recipient(s): ' . $recipientList . $ccNote;
echo json_encode([
    'success' => (bool)$result['success'],
    'message' => $result['success'] ? $okMsg : ($result['message'] ?? 'Email send failed'),
    'error'   => $result['success'] ? null : ($result['message'] ?? 'Email send failed'),
]);
