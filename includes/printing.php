<?php
/**
 * Receipt-printer support for Restaurant & Bar (owner approval 2026-11-09).
 *
 * One print queue (print_jobs) fed from the places that fire an order to a station and where a sale is paid;
 * two ways to get a job onto paper, chosen per printer in admin/printers.php:
 *   - cloud printer  : the printer polls api/print-cloudprnt.php (Star CloudPRNT) or api/print-epson.php
 *                      (Epson Server Direct Print). No computer involved.
 *   - screen device  : the KDS/BDS/CDS page claims queued jobs for its station and prints them with the browser
 *                      (window.print into a hidden frame) on ANY printer the device can use.
 *
 * Layout: one line model (build_*), one layout step that wraps it to a number of characters per line
 * (rh_print_layout - 48 on an 80 mm roll, 32 on 58 mm, fewer on A6/A5/A4 sheets so the type is bigger), and one
 * encoder per output (plain text, Star Markup, Epson ePOS-Print XML, HTML). Every encoder reads the same rows, so
 * the content and the widths are identical whatever the printer.
 *
 * Money on a receipt is read from the order exactly as the existing receipt reads it - nothing is recomputed here.
 *
 * Printing must never break a sale or a fire: every hook below catches its own errors and does nothing while
 * migration 076 has not run. No DDL in this file.
 */

if (!defined('RH_PRINT_MAX_ATTEMPTS')) {
    define('RH_PRINT_MAX_ATTEMPTS', 5);       // unconfirmed hand-outs before a job is marked failed
    define('RH_PRINT_RESEND_AFTER', 120);     // seconds a handed-out job may stay unconfirmed before it is offered again
}

/* ============================== configuration helpers ============================== */

if (!function_exists('rh_print_stations')) {
    /** value => label. kitchen / bar / coffee_bar are the station values on order lines; all and receipt are printer-only. */
    function rh_print_stations(): array
    {
        return [
            'kitchen'    => 'Kitchen',
            'bar'        => 'Bar',
            'coffee_bar' => 'Coffee bar',
            'all'        => 'All stations',
            'receipt'    => 'Receipt / cash desk (receipts only)',
        ];
    }
}

/* ============================== business type (words and options only) ==============================
 * Printers follow the Modules page: a station's tickets exist only while its station module is on, and a shop (Restaurant
 * switch off, or no station on) prints receipts only. Saved printers are never changed or deleted by this - they show a
 * warning and enqueue skips them until the module is back on. */

if (!function_exists('rh_print_device_label')) {
    /** The "any printer the device can print to" mode, worded for the business: at a shop it is the till's own browser receipt. */
    function rh_print_device_label(?array $biz = null, bool $short = false): string
    {
        $biz = $biz ?? rh_print_biz();
        return ($biz['shop'] ? 'Till device' : 'Kitchen screen device') . ($short ? '' : ' (any printer)');
    }
}

if (!function_exists('rh_print_station_modules')) {
    /** Order-line station => the Modules switch that turns its screen on. Room service has no station value of its own. */
    function rh_print_station_modules(): array
    {
        return ['kitchen' => 'station_kds', 'bar' => 'station_bds', 'coffee_bar' => 'station_cds'];
    }
}

if (!function_exists('rh_print_station_screen_names')) {
    function rh_print_station_screen_names(): array
    {
        return ['kitchen' => 'Kitchen screen', 'bar' => 'Bar screen', 'coffee_bar' => 'Coffee bar screen'];
    }
}

if (!function_exists('rh_print_live_state')) {
    /**
     * The module state the business follows: ['kitchen' => bool, 'bar' => bool, 'coffee_bar' => bool, 'restaurant' => bool].
     * Fails open (everything on) when the module system is not loaded. $GLOBALS['rh_print_state_override'] is for tests only
     * (moduleEnabled() caches for the request, so a test cannot flip a module mid-run).
     */
    function rh_print_live_state(): array
    {
        if (isset($GLOBALS['rh_print_state_override']) && is_array($GLOBALS['rh_print_state_override'])) {
            return $GLOBALS['rh_print_state_override'];
        }
        $have = function_exists('moduleEnabled');
        $pos = !$have || moduleEnabled('pos');
        $state = [];
        foreach (rh_print_station_modules() as $station => $module) {
            $state[$station] = $pos && (!$have || moduleEnabled($module));
        }
        $state['restaurant'] = function_exists('isRestaurantEnabled') ? isRestaurantEnabled() : true;
        return $state;
    }
}

if (!function_exists('rh_print_biz')) {
    /**
     * Business profile from a module state (live when null): ['stations' => [k => bool], 'any' => at least one station on,
     * 'restaurant' => bool, 'shop' => Restaurant switch off OR no station on].
     */
    function rh_print_biz(?array $state = null): array
    {
        $state = $state ?? rh_print_live_state();
        $stations = [];
        foreach (array_keys(rh_print_station_modules()) as $s) {
            $stations[$s] = !empty($state[$s]);
        }
        $any = in_array(true, $stations, true);
        $restaurant = !array_key_exists('restaurant', $state) || !empty($state['restaurant']);
        return ['stations' => $stations, 'any' => $any, 'restaurant' => $restaurant, 'shop' => (!$restaurant || !$any)];
    }
}

if (!function_exists('rh_print_station_on')) {
    /** Is this printer station live? kitchen/bar/coffee_bar follow their module; "all" needs at least one; the receipt desk is always on. */
    function rh_print_station_on(string $station, ?array $biz = null): bool
    {
        $biz = $biz ?? rh_print_biz();
        if ($station === 'all') {
            return $biz['any'];
        }
        return $biz['stations'][$station] ?? true;
    }
}

if (!function_exists('rh_print_station_choices')) {
    /** The "Station it serves" choices (value => label): stations that are on, All stations when at least one is, and the receipt desk always. */
    function rh_print_station_choices(?array $biz = null): array
    {
        $biz = $biz ?? rh_print_biz();
        $out = [];
        foreach (rh_print_stations() as $v => $l) {
            if (rh_print_station_on($v, $biz)) {
                $out[$v] = $l;
            }
        }
        return $out;
    }
}

if (!function_exists('rh_print_printer_warning')) {
    /** Plain warning for a saved, switched-on printer whose station is switched off in Modules; '' when all is well. */
    function rh_print_printer_warning(array $p, ?array $biz = null): string
    {
        $biz = $biz ?? rh_print_biz();
        $station = (string)($p['station'] ?? '');
        $prints = (string)($p['prints'] ?? 'tickets');
        if (($p['mode'] ?? 'off') === 'off' || empty($p['is_active']) || $station === 'receipt' || $prints === 'receipts' || rh_print_station_on($station, $biz)) {
            return '';
        }
        $what = $station === 'all'
            ? 'No station screen is switched on in Modules'
            : (rh_print_station_screen_names()[$station] ?? 'This station') . ' is switched off in Modules';
        return $prints === 'both'
            ? $what . ' - this printer gets no tickets until it is back on. Receipts still print.'
            : $what . ' - this printer gets nothing until it is back on.';
    }
}

if (!function_exists('rh_print_business_error')) {
    /**
     * Business-type rule for a save: a printer cannot be pointed at a station that is switched off, unless that is the station
     * it already has (an old setting stays editable). $saved = the stored row, or null for a new printer. '' = fine.
     */
    function rh_print_business_error(array $fields, ?array $saved, ?array $biz = null): string
    {
        $biz = $biz ?? rh_print_biz();
        $station = (string)($fields['station'] ?? '');
        if (!rh_print_station_on($station, $biz) && (!$saved || (string)$saved['station'] !== $station)) {
            return 'That station is switched off in Modules. Pick one that is on, or switch it on first.';
        }
        return '';
    }
}

if (!function_exists('rh_print_paper_specs')) {
    /**
     * Paper choices. Rolls: print_w is the printable width, cols the characters per line (Font A). Sheets: cols is
     * chosen so the type is larger than on a roll (a kitchen ticket you can read across the pass).
     */
    function rh_print_paper_specs(): array
    {
        return [
            '58' => ['label' => '58 mm roll',  'kind' => 'roll',  'page_w' => 58,  'page_h' => 0,   'margin' => 0,  'print_w' => 48,  'cols' => 32],
            '80' => ['label' => '80 mm roll',  'kind' => 'roll',  'page_w' => 80,  'page_h' => 0,   'margin' => 0,  'print_w' => 72,  'cols' => 48],
            'a6' => ['label' => 'A6 sheet',    'kind' => 'sheet', 'page_w' => 105, 'page_h' => 148, 'margin' => 6,  'print_w' => 93,  'cols' => 40],
            'a5' => ['label' => 'A5 sheet',    'kind' => 'sheet', 'page_w' => 148, 'page_h' => 210, 'margin' => 9,  'print_w' => 130, 'cols' => 46],
            'a4' => ['label' => 'A4 sheet',    'kind' => 'sheet', 'page_w' => 210, 'page_h' => 297, 'margin' => 12, 'print_w' => 186, 'cols' => 52],
        ];
    }
}

if (!function_exists('rh_print_paper_spec')) {
    function rh_print_paper_spec($paper): array
    {
        $specs = rh_print_paper_specs();
        $key = strtolower((string)$paper);
        return $specs[$key] ?? $specs['80'];
    }
}

if (!function_exists('rh_print_cols')) {
    /** Characters per line for a printer row (the override wins when it is sane). */
    function rh_print_cols(array $printer): int
    {
        $spec = rh_print_paper_spec($printer['paper'] ?? '80');
        $o = (int)($printer['cols'] ?? 0);
        return ($o >= 24 && $o <= 64) ? $o : (int)$spec['cols'];
    }
}

if (!function_exists('rh_print_new_token')) {
    function rh_print_new_token(): string
    {
        return bin2hex(random_bytes(24)); // 48 hex characters
    }
}

if (!function_exists('rh_print_ready')) {
    /** Migration 076 applied? Cached for the request; false makes every hook a quiet no-op. */
    function rh_print_ready(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $ready = $pdo->query("SHOW TABLES LIKE 'print_jobs'")->rowCount() > 0
                    && $pdo->query("SHOW TABLES LIKE 'print_printers'")->rowCount() > 0
                    && $pdo->query("SHOW TABLES LIKE 'print_job_items'")->rowCount() > 0;
            } catch (Throwable $e) {
                $ready = false;
            }
        }
        return $ready;
    }
}

if (!function_exists('rh_print_validate_printer')) {
    /**
     * Clean one printer form. Returns [fields, error]; error is '' when fine.
     * Cloud printers are 58/80 mm rolls only (Star / Epson); sheets are for the kitchen-screen device.
     */
    function rh_print_validate_printer(array $in, ?array $biz = null): array
    {
        $dev = rh_print_device_label($biz, true);
        $name = trim((string)($in['name'] ?? ''));
        $mode = (string)($in['mode'] ?? 'off');
        $protocol = (string)($in['protocol'] ?? 'star');
        $starFormat = (string)($in['star_format'] ?? 'markup');
        $station = (string)($in['station'] ?? 'kitchen');
        $paper = strtolower((string)($in['paper'] ?? '80'));
        $prints = (string)($in['prints'] ?? 'tickets');
        $copies = (int)($in['copies'] ?? 1);
        $colsRaw = trim((string)($in['cols'] ?? ''));
        $active = !empty($in['is_active']) ? 1 : 0;

        if ($name === '' || mb_strlen($name) > 80) {
            return [[], 'Give the printer a name (up to 80 characters).'];
        }
        if (!in_array($mode, ['off', 'cloud', 'screen'], true)) {
            return [[], 'Choose how this printer is fed: Off, Cloud printer or ' . $dev . '.'];
        }
        if (!in_array($protocol, ['star', 'epson'], true)) {
            $protocol = 'star';
        }
        if (!in_array($starFormat, ['markup', 'text'], true)) {
            $starFormat = 'markup';
        }
        if (!isset(rh_print_stations()[$station])) {
            return [[], 'Choose the station this printer serves.'];
        }
        if (!isset(rh_print_paper_specs()[$paper])) {
            return [[], 'Choose the paper size.'];
        }
        if ($mode === 'cloud' && rh_print_paper_spec($paper)['kind'] !== 'roll') {
            return [[], 'A cloud printer prints on a 58 mm or 80 mm roll. Use ' . $dev . ' for A6, A5 or A4 paper.'];
        }
        if (!in_array($prints, ['tickets', 'receipts', 'both'], true)) {
            return [[], 'Choose what this printer prints.'];
        }
        if ($station === 'receipt') {
            $prints = 'receipts';
        }
        if ($copies < 1 || $copies > 5) {
            return [[], 'Copies must be between 1 and 5.'];
        }
        $cols = null;
        if ($colsRaw !== '') {
            if (!ctype_digit($colsRaw) || (int)$colsRaw < 24 || (int)$colsRaw > 64) {
                return [[], 'Characters per line must be a number from 24 to 64, or left empty.'];
            }
            $cols = (int)$colsRaw;
        }
        return [[
            'name' => $name, 'mode' => $mode, 'protocol' => $protocol, 'star_format' => $starFormat,
            'station' => $station, 'paper' => $paper, 'cols' => $cols, 'prints' => $prints,
            'copies' => $copies, 'is_active' => $active,
        ], ''];
    }
}

if (!function_exists('rh_print_endpoint_url')) {
    /** The exact address to paste into the printer. */
    function rh_print_endpoint_url(array $printer, string $baseUrl): string
    {
        $file = (($printer['protocol'] ?? 'star') === 'epson') ? 'print-epson.php' : 'print-cloudprnt.php';
        return rtrim($baseUrl, '/') . '/api/' . $file . '?p=' . (int)$printer['id'] . '&t=' . rawurlencode((string)$printer['token']);
    }
}

/* ============================== text utilities ============================== */

if (!function_exists('rh_pr_ascii')) {
    /** Printer-safe text: ASCII only (accents stripped, typographic marks mapped, anything else becomes '?'). */
    function rh_pr_ascii(string $s): string
    {
        static $map = null;
        if ($map === null) {
            $map = [
                "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-', "\u{2018}" => "'", "\u{2019}" => "'",
                "\u{201C}" => '"', "\u{201D}" => '"', "\u{00B7}" => '-', "\u{2022}" => '*', "\u{00D7}" => 'x',
                "\u{2026}" => '...', "\u{00A0}" => ' ', "\u{20AC}" => 'EUR', "\u{00A3}" => 'GBP', "\u{2192}" => '->',
                "\u{00B0}" => 'o', "\u{00E0}" => 'a', "\u{00E1}" => 'a', "\u{00E2}" => 'a', "\u{00E3}" => 'a', "\u{00E4}" => 'a',
                "\u{00E5}" => 'a', "\u{00E7}" => 'c', "\u{00E8}" => 'e', "\u{00E9}" => 'e', "\u{00EA}" => 'e', "\u{00EB}" => 'e',
                "\u{00EC}" => 'i', "\u{00ED}" => 'i', "\u{00EE}" => 'i', "\u{00EF}" => 'i', "\u{00F1}" => 'n', "\u{00F2}" => 'o',
                "\u{00F3}" => 'o', "\u{00F4}" => 'o', "\u{00F5}" => 'o', "\u{00F6}" => 'o', "\u{00F9}" => 'u', "\u{00FA}" => 'u',
                "\u{00FB}" => 'u', "\u{00FC}" => 'u', "\u{00C0}" => 'A', "\u{00C1}" => 'A', "\u{00C2}" => 'A', "\u{00C4}" => 'A',
                "\u{00C7}" => 'C', "\u{00C8}" => 'E', "\u{00C9}" => 'E', "\u{00CA}" => 'E', "\u{00D1}" => 'N', "\u{00D3}" => 'O',
                "\u{00D6}" => 'O', "\u{00DA}" => 'U', "\u{00DC}" => 'U',
            ];
        }
        $s = strtr($s, $map);
        $s = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s);
        return (string)preg_replace('/[^\x20-\x7E]/u', '?', $s);
    }
}

if (!function_exists('rh_pr_len')) {
    function rh_pr_len(string $s): int
    {
        return function_exists('mb_strwidth') ? mb_strwidth($s, 'UTF-8') : strlen($s);
    }
}

if (!function_exists('rh_pr_cut_to')) {
    /** The leading part of $s that fits in $width columns. */
    function rh_pr_cut_to(string $s, int $width): string
    {
        if ($width < 1) {
            return '';
        }
        return function_exists('mb_strimwidth') ? mb_strimwidth($s, 0, $width, '', 'UTF-8') : substr($s, 0, $width);
    }
}

if (!function_exists('rh_pr_wrap')) {
    /**
     * Word-wrap to $width columns. The first line may be $first columns wide (default $width); later lines are
     * $width wide. A word longer than a line is split. Never returns a line wider than allowed.
     * @return string[] at least one element
     */
    function rh_pr_wrap(string $s, int $width, ?int $first = null): array
    {
        $width = max(1, $width);
        $first = $first === null ? $width : max(1, $first);
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));
        if ($s === '') {
            return [''];
        }
        $lines = [];
        $cur = '';
        $limit = function () use (&$lines, $width, $first): int {
            return count($lines) === 0 ? $first : $width;
        };
        foreach (explode(' ', $s) as $word) {
            $cand = $cur === '' ? $word : $cur . ' ' . $word;
            if (rh_pr_len($cand) <= $limit()) {
                $cur = $cand;
                continue;
            }
            if ($cur !== '') {
                $lines[] = $cur;
                $cur = '';
            }
            while (rh_pr_len($word) > $limit()) {
                $part = rh_pr_cut_to($word, $limit());
                if ($part === '') { // a single glyph wider than the line: take it anyway so we always advance
                    $part = mb_substr($word, 0, 1, 'UTF-8');
                }
                $lines[] = $part;
                $word = (string)mb_substr($word, mb_strlen($part, 'UTF-8'), null, 'UTF-8');
            }
            $cur = $word;
        }
        if ($cur !== '' || !$lines) {
            $lines[] = $cur;
        }
        return $lines;
    }
}

if (!function_exists('rh_pr_qty')) {
    function rh_pr_qty($q): string
    {
        $t = rtrim(rtrim(number_format((float)$q, 2, '.', ''), '0'), '.');
        return $t === '' ? '0' : $t;
    }
}

if (!function_exists('rh_pr_money')) {
    /** Same figure format as the existing receipt: currency, space, 1,234.50. */
    function rh_pr_money(string $cur, $v): string
    {
        return trim($cur . ' ' . number_format((float)$v, 2));
    }
}

/* ============================== line model ============================== */
/*
 * A model is a list of lines:
 *   ['t'=>'text','s'=>string,'a'=>'l|c|r','b'=>bool,'z'=>1|2|'auto','inv'=>bool,'ind'=>int,'hang'=>int,'uc'=>bool]
 *   ['t'=>'kv','l'=>string,'r'=>string,'b'=>bool,'hang'=>int]        left text, amount right-aligned
 *   ['t'=>'rule','c'=>'-'|'=']   ['t'=>'blank','n'=>int]   ['t'=>'ruler']   ['t'=>'cut']
 * z 'auto' = double size when the text fits at double size, else normal.
 */

if (!function_exists('rh_pr_text')) {
    function rh_pr_text(string $s, array $o = []): array
    {
        return array_merge(['t' => 'text', 's' => $s, 'a' => 'l', 'b' => false, 'z' => 1, 'inv' => false, 'ind' => 0, 'hang' => 0, 'uc' => false], $o);
    }
}
if (!function_exists('rh_pr_kv')) {
    function rh_pr_kv(string $l, string $r, array $o = []): array
    {
        return array_merge(['t' => 'kv', 'l' => $l, 'r' => $r, 'b' => false, 'hang' => 0], $o);
    }
}

if (!function_exists('rh_print_model_ticket')) {
    /**
     * Kitchen / bar ticket. No prices anywhere. $d: site, station_label, location, ref, service, time, server,
     * guest, note (order note), addon (bool: later round of a tab), items [[qty, name, note]].
     */
    function rh_print_model_ticket(array $d): array
    {
        $m = [];
        $m[] = rh_pr_text((string)($d['site'] ?? ''), ['a' => 'c', 'b' => true]);
        $title = strtoupper((string)($d['station_label'] ?? 'Kitchen')) . (!empty($d['addon']) ? ' - ADD-ON' : ' TICKET');
        $m[] = rh_pr_text($title, ['a' => 'c', 'b' => true]);
        $m[] = ['t' => 'rule', 'c' => '='];
        $m[] = rh_pr_text((string)($d['location'] ?? ''), ['a' => 'c', 'b' => true, 'z' => 'auto', 'uc' => true]);
        $m[] = rh_pr_text('#' . (string)($d['ref'] ?? ''), ['a' => 'c', 'b' => true, 'z' => 'auto']);
        $m[] = ['t' => 'rule', 'c' => '='];
        foreach ([['Time', 'time'], ['Type', 'service'], ['Taken by', 'server'], ['Guest', 'guest']] as $kv) {
            $v = trim((string)($d[$kv[1]] ?? ''));
            if ($v !== '') {
                $m[] = rh_pr_kv($kv[0], $v);
            }
        }
        $note = trim((string)($d['note'] ?? ''));
        if ($note !== '') {
            $m[] = ['t' => 'rule', 'c' => '-'];
            $m[] = rh_pr_text('ORDER NOTE: ' . $note, ['inv' => true, 'b' => true, 'uc' => true, 'ind' => 2, 'hang' => 3]);
        }
        $m[] = ['t' => 'rule', 'c' => '-'];
        $count = 0;
        foreach ((array)($d['items'] ?? []) as $it) {
            $count++;
            $m[] = rh_pr_text(rh_pr_qty($it['qty'] ?? 1) . ' x ' . (string)($it['name'] ?? ''), ['b' => true, 'uc' => true, 'hang' => 4]);
            $n = trim((string)($it['note'] ?? ''));
            if ($n !== '') {
                $m[] = rh_pr_text('>> ' . $n, ['inv' => true, 'b' => true, 'uc' => true, 'ind' => 2, 'hang' => 3]);
            }
        }
        if ($count === 0) {
            $m[] = rh_pr_text('No items', ['a' => 'c']);
        }
        $m[] = ['t' => 'rule', 'c' => '-'];
        $m[] = rh_pr_text($count . ' item' . ($count === 1 ? '' : 's'), ['a' => 'c']);
        $m[] = ['t' => 'blank', 'n' => 2];
        $m[] = ['t' => 'cut'];
        return $m;
    }
}

if (!function_exists('rh_print_model_receipt')) {
    /**
     * Customer receipt from the stored order. The figures are the order's own columns, read exactly as
     * receipt_build_restaurant_email_html_raw() reads them; nothing is recomputed. $ctx: currency, site, address,
     * phone, email, vat_number, footer, cashier, receipt_numbers, receipt_by_leg, split_legs, shop_mode.
     */
    function rh_print_model_receipt(array $order, array $items, array $ctx): array
    {
        $cur = (string)($ctx['currency'] ?? '');
        $m = [];
        $m[] = rh_pr_text((string)($ctx['site'] ?? ''), ['a' => 'c', 'b' => true, 'z' => 'auto']);
        foreach (['address', 'phone'] as $k) {
            $v = trim((string)($ctx[$k] ?? ''));
            if ($v !== '') {
                $m[] = rh_pr_text(($k === 'phone' ? 'Tel: ' : '') . $v, ['a' => 'c']);
            }
        }
        $email = trim((string)($ctx['email'] ?? ''));
        if ($email !== '') {
            $m[] = rh_pr_text($email, ['a' => 'c']);
        }
        $vat = trim((string)($ctx['vat_number'] ?? ''));
        if ($vat !== '') {
            $m[] = rh_pr_text('VAT Reg. No.: ' . $vat, ['a' => 'c']);
        }
        $m[] = ['t' => 'rule', 'c' => '-'];
        $m[] = rh_pr_text(!empty($ctx['shop_mode']) ? 'RECEIPT' : 'RESTAURANT RECEIPT', ['a' => 'c', 'b' => true]);
        $isVoid = in_array((string)($order['status'] ?? ''), ['voided', 'cancelled'], true);
        if ($isVoid) {
            $m[] = rh_pr_text('*** VOID / NOT VALID ***', ['a' => 'c', 'b' => true, 'inv' => true]);
        }
        $m[] = ['t' => 'rule', 'c' => '-'];

        $isRoom = ($order['order_type'] ?? '') === 'room_service';
        $rawTable = (string)($order['table_number'] ?? '');
        $room = trim((string)($order['room_number'] ?? ''));
        if ($isRoom && $room === '' && $rawTable !== '') {
            $room = trim((string)preg_replace('/^Room\s+/i', '', $rawTable));
        }
        $rcp = trim((string)($ctx['receipt_numbers'] ?? ''));
        if ($rcp !== '') {
            $m[] = rh_pr_kv('Receipt #', $rcp);
        }
        $m[] = rh_pr_kv('Order #', (string)($order['reference'] ?? ''));
        if (!empty($order['invoice_number'])) {
            $m[] = rh_pr_kv('Invoice #', (string)$order['invoice_number']);
        }
        $when = !empty($order['paid_at']) ? $order['paid_at'] : ($order['created_at'] ?? '');
        if ($when) {
            $m[] = rh_pr_kv('Date', date('Y-m-d H:i', strtotime((string)$when)));
        }
        $type = ucfirst(str_replace('_', ' ', (string)($order['order_type'] ?? '')));
        $m[] = rh_pr_kv('Order type', $type . (!$isRoom && $rawTable !== '' ? ' - Table ' . $rawTable : ''));
        if ($isRoom) {
            $m[] = rh_pr_kv('Room', $room !== '' ? $room : 'Not linked');
        }
        $cust = trim((string)($order['customer_name'] ?? ''));
        if ($cust !== '') {
            $m[] = rh_pr_kv('Customer', $cust);
        }
        $cashier = trim((string)($ctx['cashier'] ?? ''));
        if ($cashier !== '') {
            $m[] = rh_pr_kv('Cashier', $cashier);
        }
        $m[] = ['t' => 'rule', 'c' => '-'];

        foreach ($items as $it) {
            if (($it['kds_status'] ?? '') === 'void') {
                continue; // a voided line was never charged; the stored subtotal already leaves it out
            }
            $m[] = rh_pr_text((string)($it['item_name'] ?? ''), ['hang' => 2]);
            $n = trim((string)($it['notes'] ?? ''));
            if ($n !== '') {
                $m[] = rh_pr_text('-> ' . $n, ['ind' => 2, 'hang' => 3]);
            }
            $m[] = rh_pr_kv('  ' . rh_pr_qty($it['quantity'] ?? 1) . ' x ' . number_format((float)($it['unit_price'] ?? 0), 2), number_format((float)($it['line_total'] ?? 0), 2));
        }
        $m[] = ['t' => 'rule', 'c' => '-'];

        $subtotal = (float)($order['subtotal'] ?? 0) ?: array_sum(array_map(static function ($i) {
            return (float)($i['line_total'] ?? 0);
        }, $items));
        $discount = (float)($order['discount_amount'] ?? 0);
        $service  = (float)($order['service_charge'] ?? 0);
        $tax      = (float)($order['tax_amount'] ?? 0);
        $total    = (float)($order['total_amount'] ?? 0);
        $tip      = (float)($order['tip_amount'] ?? 0);
        $splitCount = max(1, (int)($order['split_count'] ?? 1));
        $grand    = $total + $tip;

        $m[] = rh_pr_kv('Subtotal', rh_pr_money($cur, $subtotal));
        if ($discount > 0) {
            $reason = trim((string)($order['discount_reason'] ?? ''));
            $m[] = rh_pr_kv('Discount' . ($reason !== '' ? ' (' . $reason . ')' : ''), '-' . rh_pr_money($cur, $discount), ['hang' => 2]);
        }
        if ($service > 0) {
            $m[] = rh_pr_kv('Service charge', rh_pr_money($cur, $service));
        }
        if ($tax > 0) {
            $m[] = rh_pr_kv('Tax', rh_pr_money($cur, $tax));
        }
        if ($tip > 0) {
            $m[] = rh_pr_kv('Tip', '+' . rh_pr_money($cur, $tip));
        }
        $m[] = ['t' => 'rule', 'c' => '='];
        $m[] = rh_pr_kv($tip > 0 ? 'GRAND TOTAL' : 'TOTAL', rh_pr_money($cur, $grand), ['b' => true]);
        $m[] = ['t' => 'rule', 'c' => '='];
        if (!empty($ctx['vat_note'])) {
            $m[] = rh_pr_text((string)$ctx['vat_note'], ['a' => 'c']);
        }
        if ($splitCount > 1) {
            $m[] = rh_pr_text('Split ' . $splitCount . ' ways - ' . rh_pr_money($cur, $total / $splitCount) . ' each', ['a' => 'c']);
        }
        $method = ucwords(str_replace('_', ' ', (string)($order['payment_method'] ?? '')));
        $statusKey = (string)($order['status'] ?? '');
        $statusLabels = ['paid' => 'Paid', 'placed' => 'Open', 'pending' => 'Open', 'confirmed' => 'Open', 'refunded' => 'Refunded', 'voided' => 'Voided', 'cancelled' => 'Cancelled', 'completed' => 'Completed'];
        $m[] = rh_pr_kv('Status', $statusLabels[$statusKey] ?? ucfirst($statusKey));
        if ($statusKey === 'paid' || in_array($statusKey, ['refunded', 'completed'], true)) {
            $m[] = rh_pr_kv('Paid via', $method !== '' ? $method : '-');
        }
        if (isset($order['tendered_amount']) && $order['tendered_amount'] !== null && $order['tendered_amount'] !== '') {
            $m[] = rh_pr_kv('Tendered', rh_pr_money($cur, (float)$order['tendered_amount']));
        }
        if (isset($order['change_due']) && $order['change_due'] !== null && (float)$order['change_due'] > 0) {
            $m[] = rh_pr_kv('Change', rh_pr_money($cur, (float)$order['change_due']));
        }
        if (($order['payment_method'] ?? '') === 'mobile_money' && !empty($order['mobile_wallet_reference'])) {
            $m[] = rh_pr_text('Mobile: ' . (string)($order['mobile_wallet_provider'] ?? '') . ' Ref ' . (string)$order['mobile_wallet_reference']);
        } elseif (($order['payment_method'] ?? '') === 'card_manual' && !empty($order['card_last4'])) {
            $m[] = rh_pr_text('Card: **** ' . (string)$order['card_last4'] . ' Auth ' . (string)($order['card_auth_code'] ?? ''));
        }
        $legs = (array)($ctx['split_legs'] ?? []);
        if ($legs) {
            $byLeg = is_array($ctx['receipt_by_leg'] ?? null) ? $ctx['receipt_by_leg'] : [];
            $m[] = ['t' => 'rule', 'c' => '-'];
            $m[] = rh_pr_text('Split payments', ['b' => true]);
            foreach ($legs as $leg) {
                $amt = (float)($leg['split_amount'] ?? 0) + (float)($leg['tip_amount'] ?? 0);
                $meth = ucwords(str_replace('_', ' ', (string)($leg['payment_method'] ?? '')));
                $n = (int)($leg['split_number'] ?? 0);
                $m[] = rh_pr_kv('#' . $n . ' ' . $meth, rh_pr_money($cur, $amt), ['hang' => 3]);
                $r = (string)($byLeg[$n] ?? '');
                if ($r !== '') {
                    $m[] = rh_pr_text($r, ['ind' => 3]);
                }
            }
        }
        $notes = trim((string)($order['notes'] ?? ''));
        if ($notes !== '') {
            $m[] = ['t' => 'rule', 'c' => '-'];
            $m[] = rh_pr_text('Notes: ' . $notes);
        }
        $m[] = ['t' => 'rule', 'c' => '-'];
        $footer = trim((string)($ctx['footer'] ?? ''));
        if ($footer !== '') {
            $m[] = rh_pr_text($footer, ['a' => 'c']);
        }
        $m[] = ['t' => 'blank', 'n' => 3];
        $m[] = ['t' => 'cut'];
        return $m;
    }
}

if (!function_exists('rh_print_model_test')) {
    /** Test pages: the width ruler on the top and bottom, a sample ticket or receipt in between. */
    function rh_print_model_test(string $which, string $site, string $printerName, string $paperLabel, int $cols): array
    {
        $m = [];
        $m[] = ['t' => 'ruler'];
        $m[] = rh_pr_text($site, ['a' => 'c', 'b' => true]);
        $m[] = rh_pr_text('TEST ' . ($which === 'receipt' ? 'RECEIPT' : 'TICKET'), ['a' => 'c', 'b' => true, 'z' => 'auto']);
        $m[] = rh_pr_kv('Printer', $printerName);
        $m[] = rh_pr_kv('Paper', $paperLabel);
        $m[] = rh_pr_kv('Characters per line', (string)$cols);
        $m[] = ['t' => 'rule', 'c' => '='];
        if ($which === 'receipt') {
            $m[] = rh_pr_text('Grilled chicken with nsima and a very long description to show wrapping', ['hang' => 2]);
            $m[] = rh_pr_kv('  2 x 12,500.00', '25,000.00');
            $m[] = rh_pr_text('Soft drink');
            $m[] = rh_pr_kv('  1 x 2,000.00', '2,000.00');
            $m[] = ['t' => 'rule', 'c' => '-'];
            $m[] = rh_pr_kv('TOTAL', 'MWK 27,000.00', ['b' => true]);
        } else {
            $m[] = rh_pr_text('TABLE 7', ['a' => 'c', 'b' => true, 'z' => 'auto']);
            $m[] = rh_pr_text('2 x GRILLED CHICKEN WITH NSIMA AND A VERY LONG NAME TO SHOW WRAPPING', ['b' => true, 'hang' => 4]);
            $m[] = rh_pr_text('>> NO ONION, EXTRA SAUCE', ['inv' => true, 'b' => true, 'ind' => 2, 'hang' => 3]);
            $m[] = rh_pr_text('1 x SOFT DRINK', ['b' => true, 'hang' => 4]);
        }
        $m[] = ['t' => 'rule', 'c' => '='];
        $m[] = rh_pr_text('If every line above sits inside the ruler, the width is right.', ['a' => 'c']);
        $m[] = ['t' => 'ruler'];
        $m[] = ['t' => 'blank', 'n' => 2];
        $m[] = ['t' => 'cut'];
        return $m;
    }
}

/* ============================== layout: model -> rows ============================== */

if (!function_exists('rh_print_layout')) {
    /**
     * Wrap a model to $cols characters per line. Returns rows:
     *   ['x'=>string (already aligned/padded, never wider than cols/z), 'b'=>bool, 'z'=>1|2, 'inv'=>bool]  or ['cut'=>true]
     * Double-size rows (z=2) hold at most cols/2 characters. $ascii=true makes the text printer-safe (cloud printers).
     */
    function rh_print_layout(array $model, int $cols, bool $ascii = true): array
    {
        $cols = max(16, $cols);
        $rows = [];
        $fix = static function ($s) use ($ascii): string {
            $s = (string)$s;
            return $ascii ? rh_pr_ascii($s) : (string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s);
        };
        $pad = static function (int $n): string {
            return $n > 0 ? str_repeat(' ', $n) : '';
        };
        foreach ($model as $ln) {
            $t = $ln['t'] ?? 'text';
            if ($t === 'cut') {
                $rows[] = ['cut' => true];
            } elseif ($t === 'blank') {
                for ($i = 0, $n = max(1, (int)($ln['n'] ?? 1)); $i < $n; $i++) {
                    $rows[] = ['x' => '', 'b' => false, 'z' => 1, 'inv' => false];
                }
            } elseif ($t === 'rule') {
                $rows[] = ['x' => str_repeat(($ln['c'] ?? '-') === '=' ? '=' : '-', $cols), 'b' => false, 'z' => 1, 'inv' => false];
            } elseif ($t === 'ruler') {
                $r = str_repeat('1234567890', (int)ceil($cols / 10));
                $rows[] = ['x' => substr($r, 0, $cols), 'b' => false, 'z' => 1, 'inv' => false];
                $rows[] = ['x' => '|' . str_repeat('-', max(0, $cols - 2)) . '|', 'b' => false, 'z' => 1, 'inv' => false];
            } elseif ($t === 'kv') {
                $w = $cols;
                $l = trim($fix($ln['l'] ?? ''));
                $r = trim($fix($ln['r'] ?? ''));
                $hang = max(0, (int)($ln['hang'] ?? 0));
                $b = !empty($ln['b']);
                $rw = rh_pr_len($r);
                if ($rw > $w - 4) { // the amount is too long to share a line: left text first, amount on its own line
                    foreach (rh_pr_wrap($l, $w) as $i => $line) {
                        $rows[] = ['x' => ($i > 0 ? $pad($hang) : '') . rh_pr_cut_to($line, $w), 'b' => $b, 'z' => 1, 'inv' => false];
                    }
                    $rows[] = ['x' => $pad($w - $rw) . rh_pr_cut_to($r, $w), 'b' => $b, 'z' => 1, 'inv' => false];
                    continue;
                }
                $avail = $w - $rw - 1;
                $parts = rh_pr_wrap($l, max(1, $avail - 0), max(1, $avail));
                // continuation lines have the whole width but keep the hanging indent
                $lines = [];
                $first = array_shift($parts);
                $lines[] = $first;
                if ($parts) {
                    $rest = trim(implode(' ', $parts));
                    foreach (rh_pr_wrap($rest, max(1, $w - $hang)) as $c) {
                        $lines[] = $pad($hang) . $c;
                    }
                }
                foreach ($lines as $i => $line) {
                    if ($i === 0) {
                        $line = rh_pr_cut_to($line, $avail);
                        $rows[] = ['x' => $line . $pad($w - rh_pr_len($line) - $rw) . $r, 'b' => $b, 'z' => 1, 'inv' => false];
                    } else {
                        $rows[] = ['x' => rh_pr_cut_to($line, $w), 'b' => $b, 'z' => 1, 'inv' => false];
                    }
                }
            } else { // text
                $s = $fix($ln['s'] ?? '');
                if (!empty($ln['uc'])) {
                    $s = strtoupper($s);
                }
                $s = trim($s);
                $z = $ln['z'] ?? 1;
                if ($z === 'auto') {
                    $z = (rh_pr_len($s) * 2 <= $cols) ? 2 : 1;
                }
                $z = ((int)$z === 2) ? 2 : 1;
                $w = intdiv($cols, $z);
                $ind = min(max(0, (int)($ln['ind'] ?? 0)), (int)floor($w / 2));
                $hang = min(max(0, (int)($ln['hang'] ?? 0)), (int)floor($w / 3));
                $a = $ln['a'] ?? 'l';
                $inv = !empty($ln['inv']);
                $b = !empty($ln['b']);
                if ($s === '') {
                    $rows[] = ['x' => '', 'b' => false, 'z' => 1, 'inv' => false];
                    continue;
                }
                $first = $w - $ind;
                $cont = $w - $ind - $hang;
                $lines = rh_pr_wrap($s, max(1, $cont), max(1, $first));
                foreach ($lines as $i => $line) {
                    $lead = $pad($ind + ($i > 0 ? $hang : 0));
                    $len = rh_pr_len($line);
                    if ($a === 'c') {
                        $x = $pad((int)floor(($w - $len) / 2)) . $line;
                    } elseif ($a === 'r') {
                        $x = $pad($w - $len) . $line;
                    } else {
                        $x = $lead . $line;
                    }
                    if ($inv) {
                        $x .= $pad($w - rh_pr_len($x));
                    }
                    $rows[] = ['x' => $x, 'b' => $b, 'z' => $z, 'inv' => $inv];
                }
            }
        }
        return $rows;
    }
}

if (!function_exists('rh_print_rows_for')) {
    /** Layout + copies: each copy ends with a cut. */
    function rh_print_rows_for(array $model, int $cols, int $copies = 1, bool $ascii = true): array
    {
        $one = rh_print_layout($model, $cols, $ascii);
        if (!$one || empty(end($one)['cut'])) {
            $one[] = ['cut' => true];
        }
        $all = [];
        for ($i = 0, $n = max(1, $copies); $i < $n; $i++) {
            $all = array_merge($all, $one);
        }
        return $all;
    }
}

/* ============================== encoders ============================== */

if (!function_exists('rh_print_encode_text')) {
    /** Plain text (Star text/plain). No fonts: the printer's own auto-cut / feed closes each copy. */
    function rh_print_encode_text(array $rows): string
    {
        $out = '';
        foreach ($rows as $r) {
            if (!empty($r['cut'])) {
                $out .= "\n\n\n\n";
                continue;
            }
            $x = rtrim($r['x']);
            if (((int)($r['z'] ?? 1)) === 2) { // the printer cannot enlarge plain text: widen the indent so it stays centred
                $lead = strlen($x) - strlen(ltrim($x));
                $x = str_repeat(' ', $lead * 2) . ltrim($x);
            }
            if (!empty($r['inv'])) {
                $x = '>' . substr($x, 1);
            }
            $out .= $x . "\n";
        }
        return $out;
    }
}

if (!function_exists('rh_print_encode_star_markup')) {
    /** Star Document Markup: bold, double size, inverted note bars and a real cut. */
    function rh_print_encode_star_markup(array $rows): string
    {
        $out = '';
        $bold = false;
        $z = 1;
        $inv = false;
        $esc = static function (string $s): string {
            return str_replace(['[', ']'], ['(', ')'], $s);
        };
        foreach ($rows as $r) {
            if (!empty($r['cut'])) {
                if ($bold) { $out .= "[bold: off]"; $bold = false; }
                if ($z !== 1) { $out .= "[magnify: width 1; height 1]"; $z = 1; }
                if ($inv) { $out .= "[invert: off]"; $inv = false; }
                $out .= "[cut: feed; partial]\n";
                continue;
            }
            $wantBold = !empty($r['b']);
            $wantZ = (int)($r['z'] ?? 1);
            $wantInv = !empty($r['inv']);
            if ($wantBold !== $bold) { $out .= '[bold: ' . ($wantBold ? 'on' : 'off') . ']'; $bold = $wantBold; }
            if ($wantZ !== $z) { $out .= '[magnify: width ' . $wantZ . '; height ' . $wantZ . ']'; $z = $wantZ; }
            if ($wantInv !== $inv) { $out .= '[invert: ' . ($wantInv ? 'on' : 'off') . ']'; $inv = $wantInv; }
            $out .= $esc($wantInv ? $r['x'] : rtrim($r['x'])) . "\n";
        }
        return $out;
    }
}

if (!function_exists('rh_print_encode_epson')) {
    /** The <epos-print> element for Epson ePOS-Print (Server Direct Print wraps it; also usable on its own). */
    function rh_print_encode_epson(array $rows): string
    {
        $x = static function (string $s): string {
            return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };
        $out = '<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">'
            . '<text lang="en"/><text font="font_a"/><text align="left"/><text smooth="true"/>';
        $bold = false;
        $z = 1;
        $inv = false;
        foreach ($rows as $r) {
            if (!empty($r['cut'])) {
                $out .= '<text width="1" height="1"/><text em="false"/><text reverse="false"/>';
                $bold = false; $z = 1; $inv = false;
                $out .= '<feed line="3"/><cut type="feed"/>';
                continue;
            }
            $wantBold = !empty($r['b']);
            $wantZ = (int)($r['z'] ?? 1);
            $wantInv = !empty($r['inv']);
            if ($wantBold !== $bold) { $out .= '<text em="' . ($wantBold ? 'true' : 'false') . '"/>'; $bold = $wantBold; }
            if ($wantZ !== $z) { $out .= '<text width="' . $wantZ . '" height="' . $wantZ . '"/>'; $z = $wantZ; }
            if ($wantInv !== $inv) { $out .= '<text reverse="' . ($wantInv ? 'true' : 'false') . '"/>'; $inv = $wantInv; }
            $out .= '<text>' . $x($wantInv ? $r['x'] : rtrim($r['x'])) . '&#10;</text>';
        }
        return $out . '</epos-print>';
    }
}

if (!function_exists('rh_print_encode_html')) {
    /**
     * A printable HTML page at the printer's paper size: @page size 58/80 mm roll (height auto) or A6/A5/A4,
     * monospace lines laid out at the same character widths as the Star / Epson output. Roll: 1.5 mm per
     * character (48 x 1.5 = 72 mm). Sheet: the printable width is shared across the columns, so the type is
     * bigger. Works with any printer the browser can print to.
     */
    function rh_print_encode_html(array $rows, array $printer, string $title = 'Print', bool $autoprint = false): string
    {
        $spec = rh_print_paper_spec($printer['paper'] ?? '80');
        $cols = rh_print_cols($printer);
        $isRoll = $spec['kind'] === 'roll';
        $charMm = $isRoll ? 1.5 : ($spec['print_w'] / $cols);
        $widthMm = round($charMm * $cols, 2);
        $fontMm = round($charMm / 0.6, 3);
        $lineMm = round($fontMm * 1.28, 3);
        $page = $isRoll
            ? '@page{size:' . (int)$spec['page_w'] . 'mm auto;margin:0}'
            : '@page{size:' . strtoupper((string)($printer['paper'] ?? 'a5')) . ';margin:' . (int)$spec['margin'] . 'mm}';
        $bodyPad = $isRoll ? '0 ' . round(($spec['page_w'] - $widthMm) / 2, 2) . 'mm' : '0';
        $css = '*{box-sizing:border-box}html,body{margin:0;padding:0;background:#fff;color:#000}'
            . 'body{padding:' . $bodyPad . ';font-family:"Courier New",Courier,"Liberation Mono",monospace;font-size:' . $fontMm . 'mm;line-height:' . $lineMm . 'mm}'
            . '.t{width:' . $widthMm . 'mm;margin:0 auto;break-inside:avoid;page-break-inside:avoid}'
            . '.l{white-space:pre;overflow:hidden;height:' . $lineMm . 'mm;width:' . $widthMm . 'mm}'
            . '.l.z2{font-size:' . ($fontMm * 2) . 'mm;line-height:' . ($lineMm * 2) . 'mm;height:' . ($lineMm * 2) . 'mm}'
            . '.l.b{font-weight:700}.l.inv{background:#000;color:#fff}'
            . '.cut{height:' . ($isRoll ? '4mm' : '0') . '}.pb{break-after:page;page-break-after:always}'
            . $page . '@media screen{body{background:#eee}.t{background:#fff;margin:8px auto;padding:' . ($isRoll ? '0' : '4mm') . ';box-shadow:0 1px 4px rgba(0,0,0,.25)}}';
        $h = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><style>' . $css . '</style></head><body>';
        $open = false;
        $cutCount = 0;
        $totalCuts = 0;
        foreach ($rows as $r) {
            if (!empty($r['cut'])) {
                $totalCuts++;
            }
        }
        foreach ($rows as $r) {
            if (!$open) {
                $h .= '<div class="t">';
                $open = true;
            }
            if (!empty($r['cut'])) {
                $cutCount++;
                $h .= '<div class="cut"></div></div>' . ($cutCount < $totalCuts ? '<div class="pb"></div>' : '');
                $open = false;
                continue;
            }
            $cls = 'l' . (((int)($r['z'] ?? 1)) === 2 ? ' z2' : '') . (!empty($r['b']) ? ' b' : '') . (!empty($r['inv']) ? ' inv' : '');
            $x = (string)$r['x'];
            $h .= '<div class="' . $cls . '">' . ($x === '' ? '&nbsp;' : htmlspecialchars($x, ENT_QUOTES, 'UTF-8')) . '</div>';
        }
        if ($open) {
            $h .= '</div>';
        }
        if ($autoprint) {
            $h .= '<script>window.onload=function(){window.print();};</script>';
        }
        return $h . '</body></html>';
    }
}

/* ============================== queue ============================== */

if (!function_exists('rh_print_active_printers')) {
    /**
     * Active printers that should get this kind of job. $kind 'ticket' (station = the order line's station) or
     * 'receipt' (cloud printers only: the till prints its own receipts in the browser).
     */
    function rh_print_active_printers(PDO $pdo, string $kind): array
    {
        $rows = $pdo->query("SELECT * FROM print_printers WHERE is_active = 1 AND mode <> 'off' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        $biz = rh_print_biz();
        foreach ($rows as $p) {
            if ($kind === 'ticket' && in_array($p['prints'], ['tickets', 'both'], true) && $p['station'] !== 'receipt') {
                if (!rh_print_station_on((string)$p['station'], $biz)) {
                    continue; // its station is switched off in Modules: no tickets until it is back on
                }
                $out[] = $p;
            } elseif ($kind === 'receipt' && $p['mode'] === 'cloud' && in_array($p['prints'], ['receipts', 'both'], true)) {
                $out[] = $p;
            }
        }
        return $out;
    }
}

if (!function_exists('rh_print_cloud_receipt_active')) {
    /** Is a cloud printer switched on for customer receipts? (The till then skips its own browser auto-print.) */
    function rh_print_cloud_receipt_active(PDO $pdo): bool
    {
        try {
            return rh_print_ready($pdo) && count(rh_print_active_printers($pdo, 'receipt')) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('rh_print_insert_job')) {
    /** Insert one job; returns its id, or 0 when the dedupe key already exists. */
    function rh_print_insert_job(PDO $pdo, int $printerId, string $kind, ?int $orderId, ?string $ref, string $title, array $model, ?string $dedupe = null): int
    {
        $st = $pdo->prepare("INSERT IGNORE INTO print_jobs (printer_id, kind, order_id, order_ref, title, payload, status, dedupe_key) VALUES (?,?,?,?,?,?, 'queued', ?)");
        $st->execute([$printerId, $kind, $orderId, $ref, mb_substr($title, 0, 120), json_encode(['v' => 1, 'model' => $model], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $dedupe]);
        return $st->rowCount() === 1 ? (int)$pdo->lastInsertId() : 0;
    }
}

if (!function_exists('rh_print_enqueue_kitchen')) {
    /**
     * Put the pending lines of an order on a ticket for every station printer that serves them. Only lines this
     * printer has not had before (print_job_items) are printed, so a re-fire prints nothing and a later round added
     * to a tab prints only the new lines. Runs inside the caller's transaction; never throws. Returns jobs created.
     */
    function rh_print_enqueue_kitchen(PDO $pdo, int $orderId): int
    {
        try {
            if ($orderId <= 0 || !rh_print_ready($pdo)) {
                return 0;
            }
            $printers = rh_print_active_printers($pdo, 'ticket');
            if (!$printers) {
                return 0;
            }
            $o = $pdo->prepare("SELECT so.*, au.full_name AS cashier_name FROM stock_orders so LEFT JOIN admin_users au ON au.id = so.created_by WHERE so.id = ?");
            $o->execute([$orderId]);
            $order = $o->fetch(PDO::FETCH_ASSOC);
            if (!$order || in_array((string)$order['status'], ['cancelled', 'voided'], true)) {
                return 0;
            }
            $it = $pdo->prepare("SELECT id, item_name, quantity, notes, station FROM stock_order_items WHERE order_id = ? AND kds_status = 'pending' ORDER BY id");
            $it->execute([$orderId]);
            $items = $it->fetchAll(PDO::FETCH_ASSOC);
            if (!$items) {
                return 0;
            }
            $labels = rh_print_stations();
            $biz = rh_print_biz();
            $made = 0;
            foreach ($printers as $p) {
                $mine = [];
                foreach ($items as $row) {
                    if (!rh_print_station_on((string)($row['station'] ?: 'kitchen'), $biz)) {
                        continue; // that station is switched off in Modules: no ticket for its lines
                    }
                    if ($p['station'] === 'all' || $p['station'] === ($row['station'] ?: 'kitchen')) {
                        $mine[] = $row;
                    }
                }
                if (!$mine) {
                    continue;
                }
                $ids = array_map(static function ($r) { return (int)$r['id']; }, $mine);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $had = $pdo->prepare("SELECT order_item_id FROM print_job_items WHERE printer_id = ? AND order_item_id IN ($in)");
                $had->execute(array_merge([(int)$p['id']], $ids));
                $done = array_map('intval', $had->fetchAll(PDO::FETCH_COLUMN));
                $fresh = array_values(array_filter($mine, static function ($r) use ($done) {
                    return !in_array((int)$r['id'], $done, true);
                }));
                if (!$fresh) {
                    continue;
                }
                $prev = $pdo->prepare("SELECT COUNT(*) FROM print_jobs WHERE printer_id = ? AND order_id = ? AND kind = 'kitchen_ticket' AND status <> 'cancelled'");
                $prev->execute([(int)$p['id'], $orderId]);
                $addon = ((int)$prev->fetchColumn() > 0);

                $isRoom = ($order['order_type'] ?? '') === 'room_service';
                $roomNo = trim((string)($order['room_number'] ?? ''));
                if ($isRoom && $roomNo === '' && !empty($order['table_number'])) {
                    $roomNo = trim((string)preg_replace('/^Room\s+/i', '', (string)$order['table_number']));
                }
                $service = strtoupper(str_replace('_', ' ', (string)($order['order_type'] ?? 'walk_in')));
                $location = $isRoom
                    ? 'ROOM ' . strtoupper($roomNo !== '' ? $roomNo : 'UNLINKED')
                    : (!empty($order['table_number']) ? 'TABLE ' . strtoupper((string)$order['table_number']) : $service);
                $rawTime = (string)($order['fired_at'] ?: ($order['kitchen_printed_at'] ?: ($order['created_at'] ?: date('Y-m-d H:i:s'))));
                $time = '';
                if (function_exists('rhToUserTime')) {
                    try { $time = rhToUserTime($rawTime); } catch (Throwable $e) { $time = ''; }
                }
                if ($time === '') {
                    $time = substr($rawTime, 0, 16);
                }
                $stationLabel = $p['station'] === 'all'
                    ? ($labels[$fresh[0]['station'] ?: 'kitchen'] ?? 'Kitchen')
                    : ($labels[$p['station']] ?? 'Kitchen');
                $model = rh_print_model_ticket([
                    'site' => (string)getSetting('site_name', ''),
                    'station_label' => $stationLabel,
                    'location' => $location,
                    'ref' => (string)$order['reference'],
                    'service' => ucwords(strtolower($service)),
                    'time' => $time,
                    'server' => (string)($order['cashier_name'] ?? ''),
                    'guest' => (string)($order['customer_name'] ?? ''),
                    'note' => (string)($order['notes'] ?? ''),
                    'addon' => $addon,
                    'items' => array_map(static function ($r) {
                        return ['qty' => $r['quantity'], 'name' => (string)$r['item_name'], 'note' => (string)($r['notes'] ?? '')];
                    }, $fresh),
                ]);
                $jobId = rh_print_insert_job($pdo, (int)$p['id'], 'kitchen_ticket', $orderId, (string)$order['reference'], $location . ' - ' . $order['reference'], $model);
                if ($jobId > 0) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO print_job_items (job_id, printer_id, order_item_id) VALUES (?,?,?)");
                    foreach ($fresh as $r) {
                        $ins->execute([$jobId, (int)$p['id'], (int)$r['id']]);
                    }
                    $made++;
                }
            }
            return $made;
        } catch (Throwable $e) {
            error_log('rh_print_enqueue_kitchen: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('rh_print_receipt_context')) {
    /** The header / footer / cashier / receipt-number context for an order's receipt, from settings and the order. */
    function rh_print_receipt_context(PDO $pdo, array $order): array
    {
        if (!function_exists('receipt_till_footer') && is_file(__DIR__ . '/../config/receipts.php')) {
            require_once __DIR__ . '/../config/receipts.php';
        }
        $splitLegs = [];
        if ((int)($order['split_count'] ?? 1) > 1) {
            try {
                $st = $pdo->prepare('SELECT * FROM stock_order_splits WHERE order_id = ? ORDER BY split_number');
                $st->execute([(int)$order['id']]);
                $splitLegs = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { /* pre-migration */ }
        }
        $rc = function_exists('receipt_restaurant_receipt_ctx') ? receipt_restaurant_receipt_ctx($pdo, (int)$order['id']) : ['receipt_numbers' => '', 'receipt_by_leg' => []];
        return [
            'currency'   => (string)getSetting('currency_symbol', 'MWK'),
            'site'       => (string)(getSetting('site_name') ?: 'Hotel'),
            'address'    => (string)(getSetting('hotel_address') ?: ''),
            'phone'      => (string)(getSetting('hotel_phone') ?: getSetting('phone_main', '')),
            'email'      => (string)(getSetting('hotel_email') ?: ''),
            'vat_number' => trim((string)getSetting('vat_number', '')),
            'vat_note'   => function_exists('vat_document_note') ? vat_document_note() : '',
            'footer'     => function_exists('receipt_till_footer') ? receipt_till_footer() : '',
            'shop_mode'  => function_exists('receipt_shop_mode') ? receipt_shop_mode() : false,
            'cashier'    => (string)($order['cashier_name'] ?? ''),
            'split_legs' => $splitLegs,
        ] + $rc;
    }
}

if (!function_exists('rh_print_load_order_for_receipt')) {
    function rh_print_load_order_for_receipt(PDO $pdo, int $orderId): ?array
    {
        $st = $pdo->prepare('SELECT so.*, au.full_name AS cashier_name, b.booking_reference
            FROM stock_orders so
            LEFT JOIN admin_users au ON au.id = so.created_by
            LEFT JOIN bookings b ON b.id = so.booking_id
            WHERE so.id = ?');
        $st->execute([$orderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM stock_order_items WHERE order_id = ? ORDER BY id');
        $st->execute([$orderId]);
        return ['order' => $order, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)];
    }
}

if (!function_exists('rh_print_enqueue_receipt')) {
    /** Customer receipt to every cloud printer set to print receipts, once per order (dedupe key). Paid orders only. */
    function rh_print_enqueue_receipt(PDO $pdo, int $orderId): int
    {
        try {
            if ($orderId <= 0 || !rh_print_ready($pdo)) {
                return 0;
            }
            $printers = rh_print_active_printers($pdo, 'receipt');
            if (!$printers) {
                return 0;
            }
            $loaded = rh_print_load_order_for_receipt($pdo, $orderId);
            if (!$loaded || (string)$loaded['order']['status'] !== 'paid') {
                return 0;
            }
            $model = rh_print_model_receipt($loaded['order'], $loaded['items'], rh_print_receipt_context($pdo, $loaded['order']));
            $made = 0;
            foreach ($printers as $p) {
                if (rh_print_insert_job($pdo, (int)$p['id'], 'receipt', $orderId, (string)$loaded['order']['reference'], 'Receipt - ' . $loaded['order']['reference'], $model, 'r' . $orderId) > 0) {
                    $made++;
                }
            }
            return $made;
        } catch (Throwable $e) {
            error_log('rh_print_enqueue_receipt: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('rh_print_enqueue_test')) {
    /** A sample ticket and a sample receipt on one printer, so staff can see the width fits (a shop gets the receipt only). Returns job ids. */
    function rh_print_enqueue_test(PDO $pdo, array $printer, ?array $biz = null): array
    {
        $biz = $biz ?? rh_print_biz();
        $site = (string)(getSetting('site_name') ?: 'Hotel');
        $spec = rh_print_paper_spec($printer['paper'] ?? '80');
        $cols = rh_print_cols($printer);
        $ids = [];
        foreach ($biz['shop'] ? ['receipt'] : ['ticket', 'receipt'] as $which) {
            $model = rh_print_model_test($which, $site, (string)$printer['name'], $spec['label'], $cols);
            $ids[] = rh_print_insert_job($pdo, (int)$printer['id'], 'test', null, null, 'Test ' . $which . ' - ' . $printer['name'], $model);
        }
        return $ids;
    }
}

if (!function_exists('rh_print_test_html')) {
    /**
     * The test pages for a screen-device / till-device printer, printed straight from the Printers page (no queued job, so
     * no station screen can print it a second time). One document at the printer's paper size, built by the same renderer
     * as real jobs: the sample ticket (not at a shop) then the sample receipt, a page break between them.
     */
    function rh_print_test_html(array $printer, ?array $biz = null, bool $autoprint = true): string
    {
        $biz = $biz ?? rh_print_biz();
        $site = (string)(getSetting('site_name') ?: 'Hotel');
        $spec = rh_print_paper_spec($printer['paper'] ?? '80');
        $cols = rh_print_cols($printer);
        $rows = [];
        foreach ($biz['shop'] ? ['receipt'] : ['ticket', 'receipt'] as $which) {
            $rows = array_merge($rows, rh_print_rows_for(rh_print_model_test($which, $site, (string)($printer['name'] ?? 'Printer'), $spec['label'], $cols), $cols, 1, false));
        }
        return rh_print_encode_html($rows, $printer, 'Test print - ' . (string)($printer['name'] ?? 'Printer'), $autoprint);
    }
}

if (!function_exists('rh_print_test_job_status')) {
    /** Live status of queued test jobs (and only test jobs): [{id, title, status, last_error}]. Backs the Test print status on Printers. */
    function rh_print_test_job_status(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($i) { return $i > 0; })));
        if (!$ids) {
            return [];
        }
        $ids = array_slice($ids, 0, 10);
        $st = $pdo->prepare("SELECT id, title, status, last_error FROM print_jobs WHERE kind = 'test' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
        $st->execute($ids);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('rh_print_cancel_order_jobs')) {
    /** Linked data: an order that is cancelled / voided must not leave tickets or receipts waiting to print. */
    function rh_print_cancel_order_jobs(PDO $pdo, int $orderId): int
    {
        try {
            if ($orderId <= 0 || !rh_print_ready($pdo)) {
                return 0;
            }
            $st = $pdo->prepare("UPDATE print_jobs SET status = 'cancelled', last_error = 'Order cancelled or voided' WHERE order_id = ? AND status IN ('queued','sent','failed')");
            $st->execute([$orderId]);
            return $st->rowCount();
        } catch (Throwable $e) {
            error_log('rh_print_cancel_order_jobs: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('rh_print_purge_order_jobs')) {
    /** An order that is deleted outright: its jobs and the line markers go with it. */
    function rh_print_purge_order_jobs(PDO $pdo, int $orderId): void
    {
        try {
            if ($orderId <= 0 || !rh_print_ready($pdo)) {
                return;
            }
            $pdo->prepare("DELETE i FROM print_job_items i JOIN print_jobs j ON j.id = i.job_id WHERE j.order_id = ?")->execute([$orderId]);
            $pdo->prepare("DELETE FROM print_jobs WHERE order_id = ?")->execute([$orderId]);
        } catch (Throwable $e) {
            error_log('rh_print_purge_order_jobs: ' . $e->getMessage());
        }
    }
}

if (!function_exists('rh_print_next_job')) {
    /** Oldest job this printer should print now: queued, or handed out but never confirmed for a while. */
    function rh_print_next_job(PDO $pdo, int $printerId): ?array
    {
        $pdo->prepare("UPDATE print_jobs SET status = 'failed', last_error = 'The printer did not confirm after several tries' WHERE printer_id = ? AND status = 'sent' AND attempts >= " . (int)RH_PRINT_MAX_ATTEMPTS . " AND claimed_at < DATE_SUB(NOW(), INTERVAL " . (int)RH_PRINT_RESEND_AFTER . " SECOND)")
            ->execute([$printerId]);
        $st = $pdo->prepare("SELECT * FROM print_jobs WHERE printer_id = ? AND (status = 'queued' OR (status = 'sent' AND attempts < " . (int)RH_PRINT_MAX_ATTEMPTS . " AND claimed_at < DATE_SUB(NOW(), INTERVAL " . (int)RH_PRINT_RESEND_AFTER . " SECOND))) ORDER BY id LIMIT 1");
        $st->execute([$printerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('rh_print_claim_job')) {
    /** Hand a job out: atomic, so only one caller wins. */
    function rh_print_claim_job(PDO $pdo, int $jobId, int $printerId): bool
    {
        $st = $pdo->prepare("UPDATE print_jobs SET status = 'sent', attempts = attempts + 1, claimed_at = NOW()
            WHERE id = ? AND printer_id = ? AND (status = 'queued' OR (status = 'sent' AND attempts < " . (int)RH_PRINT_MAX_ATTEMPTS . " AND claimed_at < DATE_SUB(NOW(), INTERVAL " . (int)RH_PRINT_RESEND_AFTER . " SECOND)))");
        $st->execute([$jobId, $printerId]);
        return $st->rowCount() === 1;
    }
}

if (!function_exists('rh_print_finish_job')) {
    /** The printer / device reports back. A reported fault goes back to the queue for a couple of tries, then it shows as failed. */
    function rh_print_finish_job(PDO $pdo, int $jobId, int $printerId, bool $ok, string $err = ''): bool
    {
        if ($ok) {
            $st = $pdo->prepare("UPDATE print_jobs SET status = 'printed', printed_at = NOW(), last_error = NULL WHERE id = ? AND printer_id = ? AND status = 'sent'");
            $st->execute([$jobId, $printerId]);
        } else {
            $st = $pdo->prepare("UPDATE print_jobs SET status = IF(attempts < 3, 'queued', 'failed'), last_error = ? WHERE id = ? AND printer_id = ? AND status = 'sent'");
            $st->execute([mb_substr($err !== '' ? $err : 'The printer reported a fault', 0, 160), $jobId, $printerId]);
        }
        return $st->rowCount() === 1;
    }
}

if (!function_exists('rh_print_reprint_job')) {
    function rh_print_reprint_job(PDO $pdo, int $jobId): bool
    {
        $st = $pdo->prepare("UPDATE print_jobs SET status = 'queued', attempts = 0, last_error = NULL, printed_at = NULL, claimed_at = NULL WHERE id = ? AND status IN ('printed','failed','sent','queued')");
        $st->execute([$jobId]);
        return $st->rowCount() === 1;
    }
}

if (!function_exists('rh_print_job_model')) {
    function rh_print_job_model(array $job): array
    {
        $p = json_decode((string)$job['payload'], true);
        return is_array($p['model'] ?? null) ? $p['model'] : [];
    }
}

if (!function_exists('rh_print_render_job')) {
    /** Render a stored job for a printer. $format: star_markup | text | epson | html. Test jobs print once. */
    function rh_print_render_job(array $job, array $printer, string $format, bool $autoprint = false): string
    {
        $model = rh_print_job_model($job);
        $copies = ($job['kind'] ?? '') === 'test' ? 1 : max(1, (int)($printer['copies'] ?? 1));
        $cols = rh_print_cols($printer);
        if ($format === 'html') {
            return rh_print_encode_html(rh_print_rows_for($model, $cols, $copies, false), $printer, (string)($job['title'] ?? 'Print'), $autoprint);
        }
        $rows = rh_print_rows_for($model, $cols, $copies, true);
        if ($format === 'epson') {
            return rh_print_encode_epson($rows);
        }
        if ($format === 'star_markup') {
            return rh_print_encode_star_markup($rows);
        }
        return rh_print_encode_text($rows);
    }
}

/* ============================== device endpoints (cloud printers) ============================== */
/*
 * The two cloud endpoints are called by the printer itself, not by a person: there is no session and no CSRF token
 * (there are no cookies for a forged page to ride on). A request is accepted only over HTTPS, for a known printer
 * id, with that printer's long random token compared with hash_equals; the token is never logged. A printer's poll
 * rate and bad-token attempts per address are limited. These functions return [status, content-type, body] so the
 * endpoint files stay thin and the tests can call them directly.
 */

if (!function_exists('rh_print_https_ok')) {
    function rh_print_https_ok(array $req): bool
    {
        if (!empty($req['https'])) {
            return true;
        }
        // Local development only: the caller is on this machine AND asked for localhost. A cPanel site behind a local
        // proxy also shows a loopback address, but its Host is the real domain, so plain HTTP stays refused there.
        $ip = (string)($req['ip'] ?? '');
        $host = strtolower((string)preg_replace('/:\d+$/', '', (string)($req['host'] ?? '')));
        return ($ip === '127.0.0.1' || $ip === '::1') && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }
}

if (!function_exists('rh_print_rate')) {
    /** Sliding-window counter (APCu when present, else a small temp file). True while the caller is within $limit. */
    function rh_print_rate(string $key, int $limit, int $window, bool $consume = true): bool
    {
        try {
            if (function_exists('apcu_enabled') && apcu_enabled() && function_exists('apcu_inc')) {
                $k = 'rh_pr_' . $key . '_' . (int)floor(time() / $window);
                if (!$consume) {
                    return (int)apcu_fetch($k) < $limit;
                }
                $ok = false;
                $c = apcu_inc($k, 1, $ok, $window + 5);
                return $c === false ? true : $c <= $limit;
            }
            $file = sys_get_temp_dir() . '/rh_pr_' . hash('sha256', $key) . '.txt';
            $now = time();
            $hits = [];
            if (is_file($file)) {
                foreach ((array)@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $t) {
                    if ((int)$t >= $now - $window) {
                        $hits[] = (int)$t;
                    }
                }
            }
            if ($consume) {
                $hits[] = $now;
                @file_put_contents($file, implode("\n", $hits));
                return count($hits) <= $limit;
            }
            return count($hits) < $limit;
        } catch (Throwable $e) {
            return true; // a broken limiter must not stop the kitchen printing
        }
    }
}

if (!function_exists('rh_print_authenticate')) {
    /** The printer row for id + token, or null. Looked up by id, token compared with hash_equals. */
    function rh_print_authenticate(PDO $pdo, int $id, string $token): ?array
    {
        if ($id <= 0 || $token === '') {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM print_printers WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || !hash_equals((string)$p['token'], $token)) {
            return null;
        }
        return $p;
    }
}

if (!function_exists('rh_print_device_gate')) {
    /**
     * Shared front door of both cloud endpoints. Returns [printer|null, response|null]; when response is set, send it.
     * $protocol is 'star' or 'epson'.
     */
    function rh_print_device_gate(PDO $pdo, array $req, string $protocol): array
    {
        $deny = static function (int $code, string $msg): array {
            return [null, ['status' => $code, 'type' => 'text/plain; charset=utf-8', 'body' => $msg]];
        };
        if (!rh_print_https_ok($req)) {
            return $deny(403, 'HTTPS required');
        }
        $ip = (string)($req['ip'] ?? 'unknown');
        $badKey = 'bad_' . $ip;
        if (!rh_print_rate($badKey, 20, 600, false)) {
            return $deny(429, 'Too many attempts');
        }
        if (!rh_print_ready($pdo)) {
            return $deny(503, 'Printing is not set up');
        }
        $printer = rh_print_authenticate($pdo, (int)($req['get']['p'] ?? 0), (string)($req['get']['t'] ?? ''));
        if (!$printer || $printer['mode'] !== 'cloud' || $printer['protocol'] !== $protocol) {
            rh_print_rate($badKey, 20, 600, true);
            return $deny(404, 'Not found'); // same answer for a wrong id, a wrong token or a printer set up another way
        }
        if (!rh_print_rate('poll_' . (int)$printer['id'], 90, 60, true)) {
            return $deny(429, 'Polling too fast');
        }
        return [$printer, null];
    }
}

if (!function_exists('rh_print_touch')) {
    function rh_print_touch(PDO $pdo, int $printerId, string $status): void
    {
        $pdo->prepare('UPDATE print_printers SET last_seen_at = NOW(), last_status = ? WHERE id = ?')->execute([mb_substr($status, 0, 80), $printerId]);
    }
}

if (!function_exists('rh_print_star_types')) {
    /** Media types offered to a Star printer, best first; it picks the first one it supports. */
    function rh_print_star_types(array $printer): array
    {
        return (($printer['star_format'] ?? 'markup') === 'text') ? ['text/plain'] : ['text/vnd.star.markup', 'text/plain'];
    }
}

if (!function_exists('rh_print_handle_cloudprnt')) {
    /**
     * Star CloudPRNT. POST = the printer polls (JSON: status); we answer jobReady + mediaTypes + jobToken.
     * GET ?type=...&token=<job> = it fetches the job; DELETE ?token=<job>&code=OK|error = it confirms.
     * $req: method, get, post, raw, ip, https.
     */
    function rh_print_handle_cloudprnt(PDO $pdo, array $req): array
    {
        [$printer, $resp] = rh_print_device_gate($pdo, $req, 'star');
        if ($resp) {
            return $resp;
        }
        $pid = (int)$printer['id'];
        $json = static function (array $a): array {
            return ['status' => 200, 'type' => 'application/json; charset=utf-8', 'body' => json_encode($a)];
        };
        $method = strtoupper((string)($req['method'] ?? 'GET'));
        $get = (array)($req['get'] ?? []);
        $live = ($printer['is_active'] ?? 1) && $printer['mode'] === 'cloud';

        if ($method === 'POST') {
            $in = json_decode((string)($req['raw'] ?? ''), true);
            $status = is_array($in) ? (string)($in['statusCode'] ?? $in['status'] ?? 'ok') : 'ok';
            rh_print_touch($pdo, $pid, $status);
            $job = $live ? rh_print_next_job($pdo, $pid) : null;
            if (!$job) {
                return $json(['jobReady' => false]);
            }
            return $json(['jobReady' => true, 'mediaTypes' => rh_print_star_types($printer), 'jobToken' => (string)$job['id']]);
        }

        $jobId = (int)($get['token'] ?? 0);
        if ($method === 'DELETE' || isset($get['delete'])) {
            $code = (string)($get['code'] ?? 'OK');
            $ok = (bool)preg_match('/^(OK|2\d\d)/i', $code);
            rh_print_finish_job($pdo, $jobId, $pid, $ok, $ok ? '' : 'Printer: ' . $code);
            return ['status' => 200, 'type' => 'text/plain; charset=utf-8', 'body' => ''];
        }

        // GET: hand the job to the printer - a paused or Off printer gets nothing
        if (!$live) {
            return ['status' => 404, 'type' => 'text/plain; charset=utf-8', 'body' => 'No such job'];
        }
        $st = $pdo->prepare('SELECT * FROM print_jobs WHERE id = ? AND printer_id = ?');
        $st->execute([$jobId, $pid]);
        $job = $st->fetch(PDO::FETCH_ASSOC);
        if (!$job || !in_array($job['status'], ['queued', 'sent'], true)) {
            return ['status' => 404, 'type' => 'text/plain; charset=utf-8', 'body' => 'No such job'];
        }
        // Always try to claim: attempts and claimed_at move, so a job the printer never confirms (a host that
        // blocks DELETE, say) hits the 5-try cap instead of being offered for ever. If the claim fails the only
        // legitimate case is an immediate retry of a GET for a job handed out moments ago.
        if (!rh_print_claim_job($pdo, $jobId, $pid)) {
            $recent = $pdo->prepare("SELECT COUNT(*) FROM print_jobs WHERE id = ? AND printer_id = ? AND status = 'sent' AND claimed_at >= DATE_SUB(NOW(), INTERVAL " . (int)RH_PRINT_RESEND_AFTER . " SECOND)");
            $recent->execute([$jobId, $pid]);
            if ((int)$recent->fetchColumn() !== 1) {
                return ['status' => 404, 'type' => 'text/plain; charset=utf-8', 'body' => 'No such job'];
            }
        }
        $type = (string)($get['type'] ?? 'text/plain');
        if (!in_array($type, rh_print_star_types($printer), true)) {
            return ['status' => 415, 'type' => 'text/plain; charset=utf-8', 'body' => 'Unsupported type'];
        }
        $body = rh_print_render_job($job, $printer, $type === 'text/plain' ? 'text' : 'star_markup');
        return ['status' => 200, 'type' => $type, 'body' => $body];
    }
}

if (!function_exists('rh_print_handle_epson')) {
    /**
     * Epson Server Direct Print. POST ConnectionType=GetRequest = the printer polls; we answer with a
     * PrintRequestInfo document (or an empty 200 when there is nothing to print). POST ConnectionType=SetResponse
     * carries the result (ResponseFile with success="true|false" and the printjobid we sent).
     */
    function rh_print_handle_epson(PDO $pdo, array $req): array
    {
        [$printer, $resp] = rh_print_device_gate($pdo, $req, 'epson');
        if ($resp) {
            return $resp;
        }
        $pid = (int)$printer['id'];
        $empty = ['status' => 200, 'type' => 'text/xml; charset=utf-8', 'body' => ''];
        if (strtoupper((string)($req['method'] ?? 'POST')) !== 'POST') {
            return ['status' => 405, 'type' => 'text/plain; charset=utf-8', 'body' => 'POST only'];
        }
        $post = (array)($req['post'] ?? []);
        $type = (string)($post['ConnectionType'] ?? '');
        if ($type === 'GetRequest') {
            rh_print_touch($pdo, $pid, 'polling');
            $live = ($printer['is_active'] ?? 1) && $printer['mode'] === 'cloud';
            $job = $live ? rh_print_next_job($pdo, $pid) : null;
            if (!$job || !rh_print_claim_job($pdo, (int)$job['id'], $pid)) {
                return $empty;
            }
            $xml = rh_print_render_job($job, $printer, 'epson');
            $body = '<?xml version="1.0" encoding="utf-8"?>'
                . '<PrintRequestInfo Version="2.00"><ePOSPrint><Parameter><devid>local_printer</devid><timeout>10000</timeout>'
                . '<printjobid>' . (int)$job['id'] . '</printjobid></Parameter><PrintData>' . $xml . '</PrintData></ePOSPrint></PrintRequestInfo>';
            return ['status' => 200, 'type' => 'text/xml; charset=utf-8', 'body' => $body];
        }
        if ($type === 'SetResponse') {
            $file = (string)($post['ResponseFile'] ?? '');
            $jobId = preg_match('/<printjobid>\s*(\d+)\s*<\/printjobid>/i', $file, $m) ? (int)$m[1] : 0;
            $ok = (bool)preg_match('/success\s*=\s*["\']true["\']/i', $file);
            $code = preg_match('/code\s*=\s*["\']([^"\']*)["\']/i', $file, $c) ? $c[1] : '';
            if ($jobId > 0) {
                rh_print_finish_job($pdo, $jobId, $pid, $ok, $ok ? '' : 'Printer: ' . ($code !== '' ? $code : 'fault'));
            }
            rh_print_touch($pdo, $pid, $ok ? 'last job ok' : 'last job failed');
            return $empty;
        }
        return $empty;
    }
}

if (!function_exists('rh_print_send')) {
    /** Emit a handler response from an endpoint file. */
    function rh_print_send(array $r): void
    {
        http_response_code((int)$r['status']);
        header('Content-Type: ' . $r['type']);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $r['body'];
    }
}

if (!function_exists('rh_print_request_from_globals')) {
    function rh_print_request_from_globals(): array
    {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
        return [
            'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'get'    => $_GET,
            'post'   => $_POST,
            'raw'    => (string)file_get_contents('php://input'),
            'ip'     => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'host'   => (string)($_SERVER['HTTP_HOST'] ?? ''),
            'https'  => $https,
        ];
    }
}
