<?php
/**
 * Edit an email's wording before it goes (owner 2026-11-26): FOH may add or change the words of one booking email
 * for one send. The stored template (Email & PDF templates) stays the default and is never changed. Prices, totals
 * and payment details are {{tokens}} filled from the booking: they can be moved with their sentence but never
 * removed, duplicated, reordered or typed by hand. Financial emails (invoices, receipts, refunds, credit notes)
 * keep fixed wording.
 *
 * Flow: admin/email-wording-data.php gives the editor the default body; the editor posts email_wording_key +
 * email_wording_html with the action; the handler calls rh_email_wording_from_post() BEFORE it changes anything,
 * then sends through rh_email_wording_send(), which sets the one-off override that getBookingEmailTemplateConfig()
 * (config/database.php) returns for that single send and clears it afterwards.
 */

if (!function_exists('rh_email_wording_keys')) {
    /** The booking emails whose wording may be edited before sending. */
    function rh_email_wording_keys(): array
    {
        return [
            'booking_received',
            'booking_confirmed',
            'booking_cancelled',
            'booking_reminder',
            'tentative_booking_created',
            'tentative_booking_converted',
            'booking_modified',
        ];
    }
}

if (!function_exists('rh_email_wording_is_editable_key')) {
    function rh_email_wording_is_editable_key(string $key): bool
    {
        return in_array($key, rh_email_wording_keys(), true);
    }
}

if (!function_exists('rh_email_wording_key_for_resend_type')) {
    /** Resend Email modal email_type -> template key ('' = fixed wording, e.g. the invoice). */
    function rh_email_wording_key_for_resend_type(string $emailType): string
    {
        $map = [
            'booking_received' => 'booking_received',
            'booking_confirmed' => 'booking_confirmed',
            'tentative_confirmed' => 'tentative_booking_created',
            'tentative_converted' => 'tentative_booking_converted',
            'booking_cancelled' => 'booking_cancelled',
            'booking_reminder' => 'booking_reminder',
        ];
        return $map[$emailType] ?? '';
    }
}

if (!function_exists('rh_email_wording_for_key')) {
    /** PURE. The checked wording when it is for $key, else null (that email goes with its default wording). */
    function rh_email_wording_for_key(?array $wording, string $key): ?array
    {
        if (empty($wording['ok']) || !isset($wording['html']) || $key === '' || (string)($wording['key'] ?? '') !== $key) {
            return null;
        }
        return $wording;
    }
}

if (!function_exists('rh_email_wording_bulk_allowed_keys')) {
    /** PURE. The emails a bulk action sends whose wording may be edited once for the whole batch ([] = none). */
    function rh_email_wording_bulk_allowed_keys(string $op): array
    {
        $map = [
            'reminder' => ['booking_reminder'],
            'confirmation' => ['booking_received', 'tentative_booking_created', 'booking_confirmed'],
            'confirm' => ['booking_confirmed', 'tentative_booking_converted'],
            'cancel' => ['booking_cancelled'],
        ];
        return $map[$op] ?? [];
    }
}

if (!function_exists('rh_email_wording_bulk_key')) {
    /**
     * PURE. The template one booking ($b: status, is_tentative) gets from a bulk action; '' = no editable email.
     * Same choices as the actions: Resend confirmation follows the status (rh_bk_bulk_confirmation_type), Confirm
     * converts a real tentative hold (status tentative AND is_tentative 1), else confirms.
     */
    function rh_email_wording_bulk_key(string $op, array $b): string
    {
        $status = strtolower(trim((string)($b['status'] ?? '')));
        switch ($op) {
            case 'reminder':
                return 'booking_reminder';
            case 'confirmation':
                $map = ['pending' => 'booking_received', 'tentative' => 'tentative_booking_created', 'confirmed' => 'booking_confirmed', 'checked-in' => 'booking_confirmed'];
                return $map[$status] ?? '';
            case 'confirm':
                return ($status === 'tentative' && (int)($b['is_tentative'] ?? 0) === 1) ? 'tentative_booking_converted' : 'booking_confirmed';
            case 'cancel':
                return 'booking_cancelled';
        }
        return '';
    }
}

if (!function_exists('rh_email_wording_bulk_check')) {
    /**
     * The one edited wording posted with a bulk action ($in: email_wording_key, email_wording_html, email_guest), checked
     * ONCE before any booking is touched. null = none posted; ['ok'=>false,'error'] = stop, nothing done;
     * ['ok'=>true,'key','html'] = used for every booking in the batch that gets that email.
     */
    function rh_email_wording_bulk_check(string $op, array $in): ?array
    {
        $key = trim((string)($in['email_wording_key'] ?? ''));
        $post = ['email_wording_key' => $key, 'email_wording_html' => (string)($in['email_wording_html'] ?? '')];
        if ($key === '' && trim($post['email_wording_html']) === '') {
            return null;
        }
        if (!in_array($key, rh_email_wording_bulk_allowed_keys($op), true)) {
            return ['ok' => false, 'error' => 'The edited wording belongs to a different email. Open "Edit wording" again.'];
        }
        if ($op === 'cancel' && (string)($in['email_guest'] ?? '0') !== '1') {
            return ['ok' => false, 'error' => 'You edited the cancellation email but unticked "Email the guest". Tick it, or close the editor without changes.'];
        }
        return rh_email_wording_from_post($key, $post);
    }
}

if (!function_exists('rh_email_wording_create_key')) {
    /**
     * PURE. The email New booking (admin/create-booking.php) sends for the chosen status: paid -> confirmed / received,
     * tentative -> tentative created, else confirmed / received (a checked-in walk-in gets the received email).
     */
    function rh_email_wording_create_key(string $status, bool $paidNow): string
    {
        if (!$paidNow && $status === 'tentative') {
            return 'tentative_booking_created';
        }
        return $status === 'confirmed' ? 'booking_confirmed' : 'booking_received';
    }
}

if (!function_exists('rh_email_wording_is_financial_token')) {
    /** PURE. A token that carries money or payment terms: locked (same count and order as the default). */
    function rh_email_wording_is_financial_token(string $name): bool
    {
        $name = strtolower($name);
        // Every *_html / *_block token is a ready-made block (a list, a table, a logo): locked as a whole.
        if (preg_match('/_(html|block)$/', $name)) {
            return true;
        }
        $full = ['packages_html', 'pay_instructions', 'payment_policy', 'booked_rate_block', 'group_rooms_block',
            'cancellation_policy_text', 'cancellation_fee_charged', 'bank_details'];
        if (in_array($name, $full, true)) {
            return true;
        }
        $segments = ['amount', 'amounts', 'total', 'totals', 'balance', 'vat', 'levy', 'rate', 'rates', 'price', 'prices',
            'subtotal', 'deposit', 'refund', 'refunded', 'fee', 'fees', 'paid', 'due', 'invoice', 'receipt', 'currency',
            'credit', 'discount', 'surcharge', 'package', 'packages', 'payment', 'payments', 'cost', 'costs', 'charge',
            'charges', 'supplement', 'multiplier', 'tax', 'money', 'bank', 'nightly'];
        foreach (explode('_', $name) as $seg) {
            if (in_array($seg, $segments, true)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('rh_email_wording_token_label')) {
    /** PURE. "check_in_date_formatted" -> "Check in date". */
    function rh_email_wording_token_label(string $name): string
    {
        $labels = ['total_amount_formatted' => 'Total', 'currency_symbol' => 'Currency', 'packages_html' => 'Extras and prices',
            'payment_policy' => 'Payment terms', 'logo_html' => 'Logo', 'site_name' => 'Hotel name', 'guest_name' => 'Guest name',
            'booking_reference' => 'Reference', 'urgency_notice' => 'Reminder notice', 'changes_html' => 'What changed'];
        if (isset($labels[$name])) {
            return $labels[$name];
        }
        $l = preg_replace('/_(formatted|html)$/', '', $name);
        return ucfirst(str_replace('_', ' ', (string)$l));
    }
}

if (!function_exists('rh_email_wording_tokens')) {
    /** PURE. Every {{token}} name in $html, in order (repeats kept). */
    function rh_email_wording_tokens(string $html): array
    {
        preg_match_all('/\{\{([a-z0-9_]+)\}\}/i', $html, $m);
        return $m[1];
    }
}

if (!function_exists('rh_email_wording_split')) {
    /** PURE. [prefix up to and including <body ...>, body inner, suffix from </body>]; no <body> = ['', all, '']. */
    function rh_email_wording_split(string $full): array
    {
        if (preg_match('/<body\b[^>]*>/i', $full, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $end = strripos($full, '</body>');
            if ($end === false || $end < $start) {
                $end = strlen($full);
            }
            return [substr($full, 0, $start), substr($full, $start, $end - $start), substr($full, $end)];
        }
        return ['', $full, ''];
    }
}

if (!function_exists('rh_email_wording_url_tokens')) {
    /** PURE. Tokens the default itself places inside an href/src (e.g. mailto:{{contact_email}}): the only ones a link may use. */
    function rh_email_wording_url_tokens(string $html): array
    {
        $out = [];
        if (preg_match_all('/\b(?:href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                foreach (rh_email_wording_tokens(($hit[1] ?? '') . ($hit[2] ?? '')) as $t) {
                    $out[$t] = true;
                }
            }
        }
        return array_keys($out);
    }
}

if (!function_exists('rh_email_wording_url_ok')) {
    /** PURE. A link (href) or image (src) value that is safe in an email; any field in it must be one the default links. */
    function rh_email_wording_url_ok(string $value, bool $isSrc, array $urlTokens): bool
    {
        $v = trim($value);
        if ($v === '' || preg_match('/[\x00-\x1F\x7F]/', $v)) {
            return false;
        }
        foreach (rh_email_wording_tokens($v) as $t) {
            if (!in_array($t, $urlTokens, true)) {
                return false;
            }
        }
        if (preg_match('/^\{\{([a-z0-9_]+)\}\}/i', $v)) {
            return true;
        }
        return $isSrc ? (bool)preg_match('#^(https://|cid:)#i', $v) : (bool)preg_match('#^(https?://|mailto:)#i', $v);
    }
}

if (!function_exists('rh_email_wording_style_ok')) {
    /** PURE. An inline style with nothing that loads or runs anything (and no CSS backslash escapes). */
    function rh_email_wording_style_ok(string $style): bool
    {
        if (strpos($style, '\\') !== false) {
            return false;
        }
        $v = strtolower((string)preg_replace('#/\*.*?\*/|\s+#s', '', $style));
        foreach (['{{', '%7b%7b', 'expression(', 'url(', 'javascript:', 'vbscript:', 'behavior:', '-moz-binding', '@import', 'image-set('] as $bad) {
            if (strpos($v, $bad) !== false) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('rh_email_wording_style_hides')) {
    /** PURE. True when a style can hide or shrink what it wraps (display:none, opacity, font-size 0, clip, ...). */
    function rh_email_wording_style_hides(string $style): bool
    {
        $v = strtolower((string)preg_replace('#/\*.*?\*/|\s+#s', '', $style));
        $patterns = [
            '/(^|;)display:none/', '/(^|;)visibility:(hidden|collapse)/', '/(^|;)opacity:(0|0?\.[0-4])/', '/(^|;)mso-hide:/',
            '/(^|;)font-size:(0|0?\.\d+|[0-5](\.\d+)?)(px|pt|em|rem|%)?(;|$)/', '/(^|;)(max-)?(height|width):0(px|pt|em|%)?(;|$)/',
            '/(^|;)line-height:0(px|pt|em|%)?(;|$)/', '/(^|;)overflow:/', '/(^|;)position:/', '/(^|;)clip(-path)?:/',
            '/(^|;)text-indent:/', '/(^|;)transform:/', '/(^|;)color:transparent/', '/(^|;)color:rgba\([^)]*,0?\.?0*\)/',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $v)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('rh_email_wording_norm_attr')) {
    /** PURE. Compare form of an attribute value (case, spaces and a trailing ";" do not count). */
    function rh_email_wording_norm_attr(string $value): string
    {
        return rtrim(strtolower((string)preg_replace('/\s+/', '', $value)), ';');
    }
}

if (!function_exists('rh_email_wording_load')) {
    /** PURE. Parse an HTML fragment into [DOMDocument, root element holding it]. */
    function rh_email_wording_load(string $html): array
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><html><body><div id="rh-ew-root">' . $html . '</div></body></html>', LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = null;
        foreach ($doc->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('id') === 'rh-ew-root') {
                $root = $div;
                break;
            }
        }
        return [$doc, $root];
    }
}

if (!function_exists('rh_email_wording_policy')) {
    /**
     * PURE. What an edit of this default may use: the fields the default links (href/src) and the exact style / width /
     * height / bgcolor values the default itself uses. Anything else in those attributes is dropped.
     * @return array{url:string[], vals:array<string,array<string,bool>>}
     */
    function rh_email_wording_policy(string $defaultInner): array
    {
        $vals = ['style' => [], 'width' => [], 'height' => [], 'bgcolor' => []];
        if (class_exists('DOMDocument')) {
            [$doc, $root] = rh_email_wording_load($defaultInner);
            if ($root) {
                foreach ((new DOMXPath($doc))->query('.//*', $root) as $el) {
                    foreach (array_keys($vals) as $name) {
                        if ($el->hasAttribute($name)) {
                            $raw = (string)$el->getAttribute($name);
                            if (strpos($raw, '{{') === false && ($name !== 'style' || rh_email_wording_style_ok($raw))) {
                                $vals[$name][rh_email_wording_norm_attr($raw)] = true;
                            }
                        }
                    }
                }
            }
        }
        return ['url' => rh_email_wording_url_tokens($defaultInner), 'vals' => $vals];
    }
}

if (!function_exists('rh_email_wording_save')) {
    /**
     * PURE. Serialise the root's children. libxml percent-encodes "{{" inside href/src only; the fields there are swapped
     * for a one-time marker before saving and put back after, so nothing else is ever turned into a field.
     */
    function rh_email_wording_save(DOMDocument $doc, DOMElement $root): string
    {
        $nonce = 'ewt' . bin2hex(random_bytes(8));
        foreach ((new DOMXPath($doc))->query('.//*[@href or @src]', $root) as $el) {
            foreach (['href', 'src'] as $name) {
                if ($el->hasAttribute($name)) {
                    $el->setAttribute($name, (string)preg_replace('/\{\{([a-z0-9_]+)\}\}/i', $nonce . 'o$1' . $nonce . 'c', $el->getAttribute($name)));
                }
            }
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        $out = (string)preg_replace('/' . $nonce . 'o([a-z0-9_]+)' . $nonce . 'c/i', '{{$1}}', $out);
        // Keep the entity spellings the stored templates use (rh_email_drop_empty_phone() matches on them).
        return strtr($out, ["\u{00A0}" => '&nbsp;', "\u{00B7}" => '&middot;']);
    }
}

if (!function_exists('rh_email_wording_clean_node')) {
    /** PURE. Whitelist walk: drops scripts/styles/frames with their content, unwraps unknown tags, strips attributes. */
    function rh_email_wording_clean_node(DOMNode $node, array $policy): void
    {
        $drop = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'textarea',
            'select', 'option', 'button', 'link', 'meta', 'base', 'svg', 'math', 'noscript', 'template', 'title', 'head',
            'audio', 'video', 'canvas', 'xml'];
        $allowed = ['p', 'br', 'div', 'span', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'table', 'thead', 'tbody',
            'tfoot', 'tr', 'td', 'th', 'h1', 'h2', 'h3', 'h4', 'hr', 'a', 'img'];
        $common = ['style', 'align', 'valign', 'width', 'height', 'role', 'bgcolor', 'dir'];
        $perTag = ['table' => ['cellpadding', 'cellspacing', 'border'], 'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'],
            'a' => ['href'], 'img' => ['src', 'alt', 'border']];
        $fromDefault = $policy['vals'] ?? [];
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMCdataSection) {
                $node->removeChild($child);
                continue;
            }
            if ($child instanceof DOMText) {
                continue;
            }
            if (!($child instanceof DOMElement)) {
                $node->removeChild($child); // comments, processing instructions
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, $drop, true)) {
                $node->removeChild($child);
                continue;
            }
            rh_email_wording_clean_node($child, $policy);
            if (!in_array($tag, $allowed, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            $keep = array_merge($common, $perTag[$tag] ?? []);
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                $value = (string)$attr->value;
                $ok = in_array($name, $keep, true);
                if ($ok && ($name === 'href' || $name === 'src')) {
                    // A field typed percent-encoded is read as the field, then checked like any other.
                    $value = (string)preg_replace('/%7B%7B([a-z0-9_]+)%7D%7D/i', '{{$1}}', $value);
                    $ok = rh_email_wording_url_ok($value, $name === 'src', (array)($policy['url'] ?? []));
                    if ($ok) {
                        $child->setAttribute($attr->name, $value);
                    }
                } elseif ($ok && isset($fromDefault[$name])) {
                    // style / width / height / bgcolor: only a value the default itself uses (no new hiding or sizing).
                    $ok = isset($fromDefault[$name][rh_email_wording_norm_attr($value)])
                        && ($name !== 'style' || rh_email_wording_style_ok($value));
                } elseif ($ok) {
                    $ok = !preg_match('/[<>]|javascript:|\{\{|\}\}|%7B%7B/i', $value);
                }
                if (!$ok) {
                    $child->removeAttribute($attr->name);
                }
            }
        }
    }
}

if (!function_exists('rh_email_wording_sanitize')) {
    /** PURE. Clean a body fragment under the default's policy (rh_email_wording_policy()). */
    function rh_email_wording_sanitize(string $html, array $policy): string
    {
        if (!class_exists('DOMDocument')) {
            return '';
        }
        [$doc, $root] = rh_email_wording_load($html);
        if (!$root) {
            return '';
        }
        rh_email_wording_clean_node($root, $policy);
        return rh_email_wording_save($doc, $root);
    }
}

if (!function_exists('rh_email_wording_text_fields')) {
    /**
     * PURE. Fields that sit in visible text (not in attributes), in order: [[token, where]], where = the chain of
     * wrapping elements up to the body (tag + its attributes), so a locked field moved or re-wrapped shows up.
     * Also returns whether any wrapping element of a money field carries a hiding style.
     * @return array{fields:array<int,array{0:string,1:string}>, hidden:string[]}
     */
    function rh_email_wording_text_fields(string $cleanHtml): array
    {
        $fields = [];
        $hidden = [];
        if (!class_exists('DOMDocument')) {
            return ['fields' => $fields, 'hidden' => $hidden];
        }
        [$doc, $root] = rh_email_wording_load($cleanHtml);
        if (!$root) {
            return ['fields' => $fields, 'hidden' => $hidden];
        }
        foreach ((new DOMXPath($doc))->query('.//text()', $root) as $t) {
            $tokens = rh_email_wording_tokens((string)$t->nodeValue);
            if (!$tokens) {
                continue;
            }
            $chain = [];
            $hides = false;
            for ($p = $t->parentNode; $p instanceof DOMElement && $p !== $root; $p = $p->parentNode) {
                $attrs = [];
                foreach ($p->attributes as $a) {
                    $attrs[strtolower($a->name)] = rh_email_wording_norm_attr((string)$a->value);
                }
                ksort($attrs);
                if (isset($attrs['style']) && rh_email_wording_style_hides($attrs['style'])) {
                    $hides = true;
                }
                $tag = strtolower($p->tagName);
                if (!in_array($tag, ['tbody', 'thead', 'tfoot'], true) || $attrs !== []) {
                    $chain[] = $tag . json_encode($attrs); // a bare <tbody> the browser adds does not count
                }
            }
            $where = implode('<', $chain);
            foreach ($tokens as $tok) {
                $fields[] = [$tok, $where];
                if ($hides && rh_email_wording_is_financial_token($tok)) {
                    $hidden[] = $tok;
                }
            }
        }
        return ['fields' => $fields, 'hidden' => $hidden];
    }
}

if (!function_exists('rh_email_wording_plain')) {
    /** PURE. Visible text of a fragment (plus image alt text), for the typed-amount check. */
    function rh_email_wording_plain(string $html): string
    {
        $alts = '';
        if (preg_match_all('/\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $alts .= ' ' . ($hit[1] ?? '') . ($hit[2] ?? '');
            }
        }
        $html = (string)preg_replace('#<(br|/p|/div|/td|/th|/tr|/li|/h[1-6])\b[^>]*>#i', ' $0', $html);
        return html_entity_decode(strip_tags($html) . $alts, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('rh_email_wording_money_count')) {
    /** PURE. How many typed amounts ("MWK 5,000", "MK 3,000", "$20", "K500", "{{currency_symbol}} 500", "500 USD") a text holds. */
    function rh_email_wording_money_count(string $plain, string $currencySymbol = ''): int
    {
        // Invisible characters (zero-width, joiners, direction marks) out; odd spaces become plain spaces.
        $plain = (string)preg_replace('/\p{Cf}+/u', '', $plain);
        $plain = (string)preg_replace('/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $plain);
        $gap = '[\s:.\-]*';
        $syms = ['\{\{currency_symbol\}\}', '\$', '£', '€', '(?<![A-Za-z])(?:MWK|MK|USD|ZAR|EUR|GBP)'];
        $sym = trim($currencySymbol);
        if ($sym !== '') {
            $syms[] = preg_match('/^[A-Za-z]+$/', $sym) ? '(?<![A-Za-z])' . preg_quote($sym, '/') : preg_quote($sym, '/');
        }
        $n = preg_match_all('/(?:' . implode('|', $syms) . ')' . $gap . '\d/iu', $plain);
        // "K" as a word before digits counts in upper case only ("ok 5 minutes" is not money).
        $n += preg_match_all('/(?<![A-Za-z])K' . $gap . '\d/u', $plain);
        $n += preg_match_all('/\d[\d,.]*\s*(?:MWK|MK|USD|ZAR|EUR|GBP)(?![A-Za-z])/iu', $plain);
        return max(0, (int)$n);
    }
}

if (!function_exists('rh_email_wording_validate')) {
    /**
     * PURE. Check FOH's edited body against the stored default (a full HTML document or a fragment).
     * $editedHtml is the edited <body> inner only; the result 'html' is the default's own head + the cleaned body.
     * @return array{ok:bool, html:string, errors:string[]}
     */
    function rh_email_wording_validate(string $key, string $editedHtml, string $defaultHtml, string $currencySymbol = ''): array
    {
        $fail = static function (string $msg): array {
            return ['ok' => false, 'html' => '', 'errors' => [$msg]];
        };
        if (!rh_email_wording_is_editable_key($key)) {
            return $fail('This email uses fixed wording and cannot be edited here.');
        }
        [$prefix, $defaultInner, $suffix] = rh_email_wording_split($defaultHtml);
        if (trim($defaultInner) === '') {
            return $fail('This email uses fixed wording; change it in Email & PDF templates.');
        }
        if (strlen($editedHtml) > 100000) {
            return $fail('The edited email is too long.');
        }
        $policy = rh_email_wording_policy($defaultInner);
        $clean = rh_email_wording_sanitize($editedHtml, $policy);
        $cleanDefault = rh_email_wording_sanitize($defaultInner, $policy);
        $cleanTokens = rh_email_wording_tokens($clean);
        if (trim(rh_email_wording_plain($clean)) === '' && $cleanTokens === []) {
            return $fail('The email is empty.');
        }

        $errors = [];
        $defaultTokens = rh_email_wording_tokens($defaultInner);
        $unknown = array_values(array_unique(array_diff($cleanTokens, $defaultTokens)));
        if ($unknown) {
            $errors[] = 'Unknown field ' . implode(', ', array_map(static function ($t) { return '{{' . $t . '}}'; }, $unknown)) . ': use only the grey fields from the default.';
        }
        $withoutTokens = (string)preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $clean);
        if (strpos($withoutTokens, '{{') !== false || strpos($withoutTokens, '}}') !== false) {
            $errors[] = 'Remove the text in double curly brackets; fields come from the booking.';
        }
        // Money fields: the same ones, once each, in the same order, in visible text, wrapped exactly as in the default.
        $fin = static function (array $fields): array {
            return array_values(array_filter($fields, static function ($f) { return rh_email_wording_is_financial_token($f[0]); }));
        };
        $finAll = static function (string $h): array {
            return array_values(array_filter(rh_email_wording_tokens($h), 'rh_email_wording_is_financial_token'));
        };
        $editText = rh_email_wording_text_fields($clean);
        $defaultText = rh_email_wording_text_fields($cleanDefault);
        if ($finAll($clean) !== $finAll($cleanDefault)
            || array_column($fin($editText['fields']), 0) !== array_column($fin($defaultText['fields']), 0)) {
            $errors[] = 'Prices, totals and payment details must stay as they are (each locked field once, in its place).';
        } elseif ($fin($editText['fields']) !== $fin($defaultText['fields'])) {
            $errors[] = 'Prices, totals and payment details must keep their place and look; use "Reset to default" and edit the words around them.';
        }
        if (count($editText['hidden']) > count($defaultText['hidden'])) {
            $errors[] = 'A price or payment detail would be hidden; use "Reset to default" and try again.';
        }
        if (rh_email_wording_money_count(rh_email_wording_plain($clean), $currencySymbol)
            > rh_email_wording_money_count(rh_email_wording_plain($defaultInner), $currencySymbol)) {
            $errors[] = 'Amounts come from the booking; do not type prices.';
        }
        if ($errors) {
            return ['ok' => false, 'html' => '', 'errors' => $errors];
        }
        return ['ok' => true, 'html' => $prefix . $clean . $suffix, 'errors' => []];
    }
}

if (!function_exists('rh_email_wording_editor_html')) {
    /**
     * PURE. The default body for the editor: cleaned like an edit, and a field that sits between table rows
     * (e.g. {{packages_html}}) wrapped in <tr data-ew-block> so the browser keeps it in its place.
     */
    function rh_email_wording_editor_html(string $defaultHtml): string
    {
        [, $inner] = rh_email_wording_split($defaultHtml);
        $clean = rh_email_wording_sanitize($inner, rh_email_wording_policy($inner));
        [$doc, $root] = rh_email_wording_load($clean);
        if (!$root) {
            return '';
        }
        $tableParents = ['table', 'thead', 'tbody', 'tfoot', 'tr'];
        $texts = [];
        foreach ((new DOMXPath($doc))->query('.//text()', $root) as $t) {
            $p = $t->parentNode;
            if ($p instanceof DOMElement && in_array(strtolower($p->tagName), $tableParents, true) && trim($t->nodeValue) !== '') {
                $texts[] = $t;
            }
        }
        foreach ($texts as $t) {
            $tr = $doc->createElement('tr');
            $tr->setAttribute('data-ew-block', '1');
            $td = $doc->createElement('td');
            $td->appendChild($doc->createTextNode(trim($t->nodeValue)));
            $tr->appendChild($td);
            $t->parentNode->replaceChild($tr, $t);
        }
        return rh_email_wording_save($doc, $root);
    }
}

if (!function_exists('rh_email_wording_default')) {
    /** The stored, active template row for an editable key, or null (fixed wording / missing / switched off). */
    function rh_email_wording_default(string $key): ?array
    {
        if (!rh_email_wording_is_editable_key($key) || !function_exists('getBookingEmailTemplateConfig')) {
            return null;
        }
        $saved = $GLOBALS['rh_email_wording_override'][$key] ?? null;
        unset($GLOBALS['rh_email_wording_override'][$key]);
        try {
            $row = getBookingEmailTemplateConfig($key, []);
        } finally {
            if ($saved !== null) {
                $GLOBALS['rh_email_wording_override'][$key] = $saved;
            }
        }
        if (trim((string)($row['subject'] ?? '')) === '' || trim((string)($row['html_body'] ?? '')) === '' || (int)($row['is_active'] ?? 0) !== 1) {
            return null;
        }
        return $row;
    }
}

if (!function_exists('rh_email_wording_from_post')) {
    /**
     * The edited wording posted with an action, checked BEFORE anything changes.
     * null = nothing posted (the default wording goes, as before); ['ok'=>false,'error'] = stop, send nothing;
     * ['ok'=>true,'key','html'] = pass to rh_email_wording_send().
     */
    function rh_email_wording_from_post(string $expectedKey, array $post): ?array
    {
        $postedKey = trim((string)($post['email_wording_key'] ?? ''));
        $postedHtml = (string)($post['email_wording_html'] ?? '');
        if ($postedKey === '' && trim($postedHtml) === '') {
            return null;
        }
        if ($postedKey !== $expectedKey) {
            return ['ok' => false, 'error' => 'The edited wording belongs to a different email. Open "Edit wording" again.'];
        }
        $tpl = rh_email_wording_default($expectedKey);
        if ($tpl === null) {
            return ['ok' => false, 'error' => 'This email uses fixed wording; change it in Email & PDF templates.'];
        }
        $currency = function_exists('getSetting') ? (string)getSetting('currency_symbol', '') : '';
        $v = rh_email_wording_validate($expectedKey, $postedHtml, (string)$tpl['html_body'], $currency);
        if (!$v['ok']) {
            return ['ok' => false, 'error' => 'Email wording not accepted: ' . implode(' ', $v['errors'])];
        }
        return ['ok' => true, 'key' => $expectedKey, 'html' => $v['html']];
    }
}

if (!function_exists('rh_email_wording_apply')) {
    function rh_email_wording_apply(string $key, string $html): void
    {
        $GLOBALS['rh_email_wording_override'][$key] = $html;
    }
}

if (!function_exists('rh_email_wording_clear')) {
    function rh_email_wording_clear(?string $key = null): void
    {
        if ($key === null) {
            unset($GLOBALS['rh_email_wording_override']);
        } else {
            unset($GLOBALS['rh_email_wording_override'][$key]);
        }
    }
}

if (!function_exists('rh_email_wording_send')) {
    /**
     * Run one send with the edited wording (or as-is when $wording is null) and clear it afterwards whatever happens.
     * A successful send with edited wording is written to the booking's history.
     */
    function rh_email_wording_send(?array $wording, callable $send, int $bookingId = 0, ?string $bookingRef = null, array $user = [])
    {
        if (empty($wording['ok']) || empty($wording['key']) || !isset($wording['html'])) {
            return $send();
        }
        rh_email_wording_apply((string)$wording['key'], (string)$wording['html']);
        try {
            $result = $send();
        } finally {
            rh_email_wording_clear((string)$wording['key']);
        }
        if ($bookingId > 0 && !empty($result['success']) && function_exists('logBookingAudit')) {
            $who = (string)($user['full_name'] ?? ($user['username'] ?? 'staff'));
            logBookingAudit($bookingId, 'email_sent', null, null,
                'Email wording edited before sending by ' . $who . ' (' . $wording['key'] . ')', $bookingRef);
        }
        return $result;
    }
}
