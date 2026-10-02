<?php

/**
 * Shared document theme + PDF kit.
 *
 * ONE place that decides how every emailed PDF looks. It reuses the palette of the
 * premium email shell (hotel_premium_email_html) so attachments match email bodies:
 *   page #d5cfc4 (sand) · card #f5f2eb · text #3e3930 · muted #9b8f7e / #6d6455 ·
 *   rule #d3cbc0 · accent/CTA #524b3f · serif headings.
 *
 * Editable without code (Admin > Settings, site_settings keys, all optional):
 *   brand_primary_color   hex  -> accent (table headers, total bar, title band)
 *   brand_accent_color    hex  -> highlight (small labels, rules)
 *   document_footer_text  text -> line printed in every PDF footer
 *   document_terms_text   text -> note printed under the totals of receipts
 * Everything else (name, address, phone, email, VAT no., logo, currency) is read from the
 * existing site settings.
 *
 * Kit functions return TCPDF-safe HTML: tables with explicit % widths, bgcolor on cells,
 * cellpadding attributes, no border-radius / overflow / min-width / flex.
 * Core PDF fonts (helvetica / times) are used, so every text string is passed through
 * rh_pdf_clean() which replaces glyphs those fonts cannot draw (U+2212, arrows, icons ...).
 */

if (!function_exists('rh_theme_hex')) {
    /** Return a valid #rrggbb or the fallback. */
    function rh_theme_hex($value, string $fallback): string
    {
        $value = trim((string)$value);
        if (preg_match('/^#?([0-9a-fA-F]{6})$/', $value, $m)) {
            return '#' . strtolower($m[1]);
        }
        if (preg_match('/^#?([0-9a-fA-F]{3})$/', $value, $m)) {
            $c = strtolower($m[1]);
            return '#' . $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
        }
        return $fallback;
    }
}

if (!function_exists('rh_theme_rgb')) {
    /** @return int[] [r,g,b] */
    function rh_theme_rgb(string $hex): array
    {
        $hex = ltrim(rh_theme_hex($hex, '#000000'), '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}

if (!function_exists('hotel_brand_tokens')) {
    /**
     * Brand tokens shared by every document. Cached per request.
     *
     * @return array{colors:array<string,string>,fonts:array<string,string>,hotel:array<string,string>,logo:string,footer_text:string,terms_text:string}
     */
    function hotel_brand_tokens(bool $refresh = false): array
    {
        static $cache = null;
        if ($cache !== null && !$refresh) {
            return $cache;
        }

        $accent = rh_theme_hex(getSetting('brand_primary_color', ''), '#524b3f');
        $highlight = rh_theme_hex(getSetting('brand_accent_color', ''), '#9b8f7e');

        $address = trim((string)getSetting('hotel_address', getSetting('address', '')));
        // Settings store multi-line addresses; documents print them on one line.
        $address = (string)preg_replace('/\s*[\r\n]+\s*/', ', ', $address);
        $address = trim((string)preg_replace('/,(\s*,)+/', ',', $address), " ,");
        $address = (string)preg_replace('/,(?=\S)/', ', ', $address);
        $email = trim((string)getSetting('hotel_email', ''));
        if ($email === '' && function_exists('getEmailSetting')) {
            $email = trim((string)(getEmailSetting('email_from_email', '') ?: getEmailSetting('smtp_username', '')));
        }
        $name = trim((string)getSetting('site_name', 'Hotel')) ?: 'Hotel';

        $logo = function_exists('hotel_invoice_logo_src') ? (string)hotel_invoice_logo_src() : '';
        if (strpos($logo, 'data:image/') !== 0) {
            $logo = ''; // never let TCPDF fetch remote URLs (slow, can hang cron)
        }

        $logoMm = (float)getSetting('document_logo_height_mm', '');
        if ($logoMm < 10 || $logoMm > 40) {
            $logoMm = 24.0;
        }

        $cache = [
            'logo_height_mm' => $logoMm,
            'colors' => [
                'page'      => '#d5cfc4',
                'card'      => '#f5f2eb',
                'text'      => '#3e3930',
                'muted'     => '#9b8f7e',
                'muted_dark' => '#6d6455',
                'rule'      => '#d3cbc0',
                'panel'     => '#ece7dc',
                'accent'    => $accent,
                'accent_text' => '#ffffff',
                'highlight' => $highlight,
                'success'   => '#3f6b4a',
                'danger'    => '#a63a32',
                'danger_bg' => '#f3dcd8',
            ],
            'fonts' => [
                'body'    => 'helvetica',
                'heading' => 'times',
            ],
            'hotel' => [
                'name'      => $name,
                'address'   => $address,
                'phone'     => trim((string)(getSetting('hotel_phone', '') ?: getSetting('phone_main', ''))),
                'email'     => $email,
                'vat_number' => trim((string)getSetting('vat_number', '')),
                'currency'  => trim((string)getSetting('currency_symbol', 'MWK')) ?: 'MWK',
                'website'   => trim((string)getSetting('site_url', '')),
            ],
            'logo'        => $logo,
            'footer_text' => trim((string)getSetting('document_footer_text', '')),
            'terms_text'  => trim((string)getSetting('document_terms_text', '')),
        ];
        return $cache;
    }
}

if (!function_exists('rh_pdf_clean')) {
    /**
     * Make a string (text or HTML) safe for TCPDF core fonts: map the glyphs they cannot draw,
     * drop icon-font tags and anything outside Windows-1252.
     */
    function rh_pdf_clean(string $s): string
    {
        $s = preg_replace('#<i\b[^>]*class="[^"]*\bfa[a-z]?\b[^"]*"[^>]*>\s*</i>#i', '', $s) ?? $s;
        $s = str_replace(
            ["\u{2212}", "\u{2192}", "\u{00B7}\u{00B7}\u{00B7}\u{00B7}", "\u{2022}\u{2022}\u{2022}\u{2022}", "\u{2713}", "\u{2714}", "\u{00A0}", "\u{2009}", "\u{202F}", "\u{200B}"],
            ['-', '»', '****', '****', 'OK', 'OK', ' ', ' ', ' ', ''],
            $s
        );
        return (string)preg_replace_callback('/[^\x00-\x7F]/u', static function (array $m): string {
            $converted = function_exists('mb_convert_encoding') ? mb_convert_encoding($m[0], 'Windows-1252', 'UTF-8') : '?';
            return $converted === '?' ? '' : $m[0];
        }, $s);
    }
}

if (!function_exists('rh_pdf_e')) {
    /** Escape + clean a plain-text value for the PDF. */
    function rh_pdf_e($value): string
    {
        return htmlspecialchars(rh_pdf_clean((string)$value), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rh_pdf_money')) {
    function rh_pdf_money(float $amount, ?string $currency = null): string
    {
        $currency = $currency ?? hotel_brand_tokens()['hotel']['currency'];
        $sign = $amount < -0.004 ? '-' : '';
        return $sign . trim($currency) . ' ' . number_format(abs($amount), 2);
    }
}

if (!function_exists('rh_pdf_spacer')) {
    /** Vertical gap in mm. */
    function rh_pdf_spacer(float $mm = 4): string
    {
        return '<table cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td style="font-size:1pt;line-height:' . max(1, round($mm * 2.835, 1)) . 'pt;">&nbsp;</td></tr></table>';
    }
}

if (!function_exists('rh_pdf_section_title')) {
    /**
     * Section heading. The leading keep-marker makes bookingRenderPdfFromHtml() start a new page
     * when fewer than $keepMm millimetres remain, so a title never sits alone at a page bottom.
     */
    function rh_pdf_section_title(string $text, int $keepMm = 30): string
    {
        $t = hotel_brand_tokens()['colors'];
        return '<!--rh-keep=' . max(10, $keepMm) . '-->'
            . '<table cellpadding="0" cellspacing="0" border="0" width="100%"><tr>'
            . '<td style="color:' . $t['muted'] . ';font-size:7.5pt;font-weight:bold;letter-spacing:1.5pt;border-bottom:0.5px solid ' . $t['rule'] . ';">' . strtoupper(rh_pdf_e($text)) . '</td>'
            . '</tr></table>';
    }
}

if (!function_exists('rh_pdf_meta_table')) {
    /**
     * Label/value grid, three cells per row, every cell two lines (small label above, value below).
     * Row: [label, value] or [label, value, true] for a full-width row.
     * Values are plain text (escaped here) unless the row has a 4th element === 'html'.
     */
    function rh_pdf_meta_table(array $rows): string
    {
        $t = hotel_brand_tokens()['colors'];
        $cell = static function (string $label, string $html, int $span) use ($t): string {
            $w = $span === 3 ? '100%' : '33.33%';
            return '<td width="' . $w . '"' . ($span === 3 ? ' colspan="3"' : '') . ' style="color:' . $t['text'] . ';font-size:9pt;">'
                . '<span style="color:' . $t['muted'] . ';font-size:6.5pt;font-weight:bold;letter-spacing:0.8pt;">' . strtoupper(rh_pdf_e($label)) . '</span><br />'
                . $html . '</td>';
        };
        $items = [];
        foreach ($rows as $row) {
            $label = (string)($row[0] ?? '');
            $value = (string)($row[1] ?? '');
            if ($value === '') {
                continue;
            }
            $html = (($row[3] ?? '') === 'html') ? rh_pdf_clean($value) : rh_pdf_e($value);
            $items[] = ['label' => $label, 'html' => $html, 'full' => !empty($row[2])];
        }
        $out = '';
        $line = [];
        $flush = static function () use (&$line, &$out, $cell): void {
            if (!$line) {
                return;
            }
            $row = '<tr>';
            foreach ($line as $it) {
                $row .= $cell($it['label'], $it['html'], 1);
            }
            for ($i = count($line); $i < 3; $i++) {
                $row .= '<td width="33.33%">&nbsp;</td>';
            }
            $out .= $row . '</tr>';
            $line = [];
        };
        foreach ($items as $it) {
            if ($it['full']) {
                $flush();
                $out .= '<tr>' . $cell($it['label'], $it['html'], 3) . '</tr>';
                continue;
            }
            $line[] = $it;
            if (count($line) === 3) {
                $flush();
            }
        }
        $flush();
        if ($out === '') {
            return '';
        }
        return '<table width="100%" cellpadding="5" cellspacing="0" border="0" bgcolor="' . $t['panel'] . '">' . $out . '</table>';
    }
}

if (!function_exists('rh_pdf_items_table')) {
    /**
     * Itemised table. $cols: [['label'=>'Item','width'=>52,'align'=>'left'], ...] (widths sum to 100).
     * $rows: list of rows; each row is a list of cell HTML strings (already escaped; use rh_pdf_e()).
     *
     * Output is a header table followed by one small table per row, each preceded by a keep-marker, and
     * the header is registered with <!--rh-th=...-->. bookingRenderPdfFromHtml() then starts a new page
     * before a row that does not fit and re-prints the header there, so a long table never loses its
     * column headings. The colour sits on the table (not the cells) so no hairline seams appear.
     */
    function rh_pdf_items_table(array $cols, array $rows): string
    {
        $t = hotel_brand_tokens()['colors'];
        $head = '';
        foreach ($cols as $c) {
            $head .= '<td width="' . (float)$c['width'] . '%" align="' . ($c['align'] ?? 'left') . '" style="color:' . $t['accent_text'] . ';font-size:7.5pt;font-weight:bold;letter-spacing:0.8pt;">' . strtoupper(rh_pdf_e($c['label'])) . '</td>';
        }
        $headTable = '<table width="100%" cellpadding="6" cellspacing="0" border="0" bgcolor="' . $t['accent'] . '"><tr>' . $head . '</tr></table>';

        $out = '<!--rh-th=' . base64_encode($headTable) . '-->' . $headTable;
        foreach ($rows as $i => $row) {
            $bg = ($i % 2 === 1) ? ' bgcolor="' . $t['panel'] . '"' : '';
            $cells = '';
            foreach ($cols as $k => $c) {
                $cells .= '<td width="' . (float)$c['width'] . '%" align="' . ($c['align'] ?? 'left') . '"'
                    . ' style="color:' . $t['text'] . ';font-size:9pt;border-bottom:0.5px solid ' . $t['rule'] . ';">' . (string)($row[$k] ?? '') . '</td>';
            }
            $out .= '<!--rh-keep=14--><table width="100%" cellpadding="6" cellspacing="0" border="0"' . $bg . '><tr>' . $cells . '</tr></table>';
        }
        if (!$rows) {
            $out .= '<table width="100%" cellpadding="6" cellspacing="0" border="0"><tr><td align="center" style="color:' . $t['muted'] . ';font-size:9pt;">No items</td></tr></table>';
        }
        return $out . '<!--rh-th-end-->';
    }
}
if (!function_exists('rh_pdf_totals_table')) {
    /**
     * Right-aligned totals block. Row: ['label'=>..., 'value'=>..., 'type'=>normal|total|danger|success|note|muted].
     * 'subtotal','discount','service','vat','total','paid','balance' are accepted as type aliases.
     * Labels/values are plain text (escaped here).
     */
    function rh_pdf_totals_table(array $rows): string
    {
        $t = hotel_brand_tokens()['colors'];
        $out = '';
        foreach ($rows as $r) {
            $type = (string)($r['type'] ?? 'normal');
            $label = rh_pdf_e($r['label'] ?? '');
            $value = rh_pdf_e($r['value'] ?? '');
            if ($type === 'note') {
                $out .= '<tr><td width="46%">&nbsp;</td><td width="54%" colspan="2" align="right" style="color:' . $t['muted_dark'] . ';font-size:7.5pt;">' . $label . '</td></tr>';
                continue;
            }
            if ($type === 'total' || $type === 'balance_due') {
                $out .= '<tr nobr="true"><td width="46%">&nbsp;</td>'
                    . '<td width="28%" bgcolor="' . $t['accent'] . '" style="color:' . $t['accent_text'] . ';font-size:9pt;font-weight:bold;letter-spacing:0.6pt;">' . strtoupper($label) . '</td>'
                    . '<td width="26%" bgcolor="' . $t['accent'] . '" align="right" style="color:' . $t['accent_text'] . ';font-size:10.5pt;font-weight:bold;">' . $value . '</td></tr>';
                continue;
            }
            $color = $t['text'];
            $weight = 'normal';
            if ($type === 'danger' || $type === 'discount') {
                $color = $t['danger'];
            } elseif ($type === 'success' || $type === 'paid') {
                $color = $t['success'];
            } elseif ($type === 'muted' || $type === 'vat') {
                $color = $t['muted_dark'];
            } elseif ($type === 'strong' || $type === 'balance') {
                $weight = 'bold';
            }
            $out .= '<tr nobr="true"><td width="46%">&nbsp;</td>'
                . '<td width="28%" style="color:' . $t['muted_dark'] . ';font-size:9pt;border-bottom:0.5px solid ' . $t['rule'] . ';">' . $label . '</td>'
                . '<td width="26%" align="right" style="color:' . $color . ';font-size:9pt;font-weight:' . $weight . ';border-bottom:0.5px solid ' . $t['rule'] . ';">' . $value . '</td></tr>';
        }
        return '<table width="100%" cellpadding="5" cellspacing="0" border="0">' . $out . '</table>';
    }
}

if (!function_exists('rh_pdf_note')) {
    /** Callout box. $tone: neutral | danger | success. Text is escaped. */
    function rh_pdf_note(string $text, string $tone = 'neutral'): string
    {
        $t = hotel_brand_tokens()['colors'];
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $bg = $t['panel'];
        $fg = $t['muted_dark'];
        if ($tone === 'danger') {
            $bg = $t['danger_bg'];
            $fg = $t['danger'];
        } elseif ($tone === 'success') {
            $fg = $t['success'];
        }
        return '<table nobr="true" width="100%" cellpadding="7" cellspacing="0" border="0"><tr><td bgcolor="' . $bg . '" style="color:' . $fg . ';font-size:8.5pt;">'
            . nl2br(rh_pdf_e($text), false) . '</td></tr></table>';
    }
}

if (!function_exists('rh_pdf_document_shell')) {
    /**
     * Full themed document body for bookingRenderPdfFromHtml().
     *
     * @param string $title    Document title, e.g. "Restaurant Receipt".
     * @param array  $metaRows See rh_pdf_meta_table().
     * @param string $bodyHtml Kit HTML (items table, totals, notes...).
     * @param array  $opts     'banner' => ['text'=>'VOID','tone'=>'danger'], 'subtitle' => string,
     *                         'logo_height_mm' => mm (default: Document branding setting, 24)
     */
    function rh_pdf_document_shell(string $title, array $metaRows, string $bodyHtml, array $opts = []): string
    {
        $tok = hotel_brand_tokens();
        $t = $tok['colors'];
        $h = $tok['hotel'];

        $logo = '';
        if ($tok['logo'] !== '') {
            $logoMm = (float)($opts['logo_height_mm'] ?? $tok['logo_height_mm']);
            $logo = '<tr><td align="center"><img src="' . $tok['logo'] . '" height="' . $logoMm . 'mm" /></td></tr>';
        }
        $contact = [];
        if ($h['address'] !== '') {
            $contact[] = rh_pdf_e($h['address']);
        }
        $line2 = [];
        if ($h['phone'] !== '') {
            $line2[] = 'Tel: ' . rh_pdf_e($h['phone']);
        }
        if ($h['email'] !== '') {
            $line2[] = rh_pdf_e($h['email']);
        }
        if ($line2) {
            $contact[] = implode('  |  ', $line2);
        }
        if ($h['vat_number'] !== '') {
            $contact[] = 'VAT Reg. No.: ' . rh_pdf_e($h['vat_number']);
        }

        $out = '<!--rh-pdf-kit-->';
        $out .= '<table width="100%" cellpadding="2" cellspacing="0" border="0">' . $logo
            . '<tr><td align="center" style="font-family:' . $tok['fonts']['heading'] . ';font-size:19pt;color:' . $t['text'] . ';letter-spacing:1pt;">' . rh_pdf_e($h['name']) . '</td></tr>';
        foreach ($contact as $line) {
            $out .= '<tr><td align="center" style="color:' . $t['muted_dark'] . ';font-size:8pt;">' . $line . '</td></tr>';
        }
        $out .= '</table>';
        $out .= rh_pdf_spacer(3);
        $out .= '<table width="100%" cellpadding="6" cellspacing="0" border="0"><tr><td align="center" bgcolor="' . $t['accent'] . '" style="color:' . $t['accent_text'] . ';font-size:9.5pt;font-weight:bold;letter-spacing:2.5pt;">'
            . strtoupper(rh_pdf_e($title)) . '</td></tr></table>';

        if (!empty($opts['banner']['text'])) {
            $tone = (string)($opts['banner']['tone'] ?? 'danger');
            $out .= rh_pdf_spacer(2);
            $bannerBg = $tone === 'danger' ? $t['danger_bg'] : $t['panel'];
            $bannerFg = $tone === 'danger' ? $t['danger'] : $t['muted_dark'];
            $out .= '<table width="100%" cellpadding="6" cellspacing="0" border="0"><tr><td align="center" bgcolor="' . $bannerBg . '" style="color:' . $bannerFg . ';font-size:10pt;font-weight:bold;letter-spacing:2pt;">'
                . strtoupper(rh_pdf_e($opts['banner']['text'])) . '</td></tr></table>';
        }

        $out .= rh_pdf_spacer(4);
        $meta = rh_pdf_meta_table($metaRows);
        if ($meta !== '') {
            $out .= $meta . rh_pdf_spacer(5);
        }
        $out .= $bodyHtml;
        return $out;
    }
}

if (!function_exists('rh_pdf_vat_rows')) {
    /**
     * VAT presentation for customer documents, honouring vat_mode():
     *   exclusive -> "VAT (x%)" amount row
     *   inclusive -> rate-only note (never an amount)
     *   off       -> nothing
     *
     * @return array<int,array<string,string>> rows for rh_pdf_totals_table()
     */
    function rh_pdf_vat_rows(float $vatAmount, float $ratePct, string $currency, ?callable $fmt = null): array
    {
        $money = $fmt ?? static function (float $v) use ($currency): string {
            return rh_pdf_money($v, $currency);
        };
        if (!function_exists('vat_mode')) {
            return $vatAmount > 0 ? [['label' => 'VAT', 'value' => $money($vatAmount), 'type' => 'vat']] : [];
        }
        $mode = vat_mode();
        $rate = $ratePct > 0 ? $ratePct : (float)getSetting('vat_rate', 0);
        $rateLabel = rtrim(rtrim(number_format($rate, 2), '0'), '.');
        if ($mode === 'exclusive' && $vatAmount > 0) {
            return [['label' => 'VAT (' . $rateLabel . '%)', 'value' => $money($vatAmount), 'type' => 'vat']];
        }
        if ($mode === 'inclusive' && $rate > 0) {
            return [['label' => 'All prices include VAT at ' . $rateLabel . '%', 'value' => '', 'type' => 'note']];
        }
        return [];
    }
}

if (!function_exists('rh_pdf_template_is_stock')) {
    /**
     * True when a saved *_document template is just a stored copy of the code default: same words in the
     * same order, only the (older, TCPDF-unfriendly) styling differs. Such copies are ignored so the
     * current code default renders; a template whose wording was edited is "customised" and honoured.
     */
    function rh_pdf_template_is_stock(string $savedHtml, string $defaultHtml): bool
    {
        if ($defaultHtml === '') {
            return false;
        }
        $norm = static function (string $h): string {
            $text = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return (string)preg_replace('/[^a-z0-9{}_]+/', '', strtolower($text));
        };
        return $norm($savedHtml) === $norm($defaultHtml);
    }
}

if (!function_exists('rh_pdf_custom_template_html')) {
    /**
     * Rendered HTML of an admin-customised *_document template (Admin > Booking settings > templates),
     * or null when none is saved, it is inactive, or it is only a stored copy of the code default
     * ($defaultHtml) - callers then build the kit layout, which is the code default.
     *
     * @param array<string,string> $vars placeholder => already-escaped value
     */
    function rh_pdf_custom_template_html(string $templateKey, array $vars, string $defaultHtml = ''): ?string
    {
        if (!function_exists('getBookingEmailTemplateConfig') || !function_exists('bookingTemplateReplaceMap')) {
            return null;
        }
        $template = getBookingEmailTemplateConfig($templateKey, []);
        if (empty($template['html_body']) || (int)($template['is_active'] ?? 1) !== 1) {
            return null;
        }
        if (rh_pdf_template_is_stock((string)$template['html_body'], $defaultHtml)) {
            return null;
        }
        return strtr((string)$template['html_body'], bookingTemplateReplaceMap($vars));
    }
}
