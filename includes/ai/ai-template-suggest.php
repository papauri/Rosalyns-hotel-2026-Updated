<?php
/**
 * Email Templates: "Suggest wording" (owner 2026-11-25: "Enable the AI assistant in the Templates to help with coming
 * up with a few templates ONLY if AI is connected but remember we can default back to default messages. AI can also
 * pick up tags and come up with a few suggestions per template.").
 *
 * Rules:
 *  - Shown ONLY while the Assistant is connected (kill switch on, chat tables, cloud on and not blocked by NO_AI_SPEND,
 *    a provider key, a Fast or Primary model) AND the person has ai_assistant_use + edit_templates. Otherwise nothing
 *    is printed (no button, no script data) and the endpoint answers 404. Load Default is never touched.
 *  - Email templates only (no PDF documents). payment_receipt is left out too: its email body is laid out
 *    automatically, so new wording would never reach the guest.
 *  - Wording only, layout kept. The current HTML is read with a small tag scanner that records where each visible
 *    text block starts and ends; the new HTML is the original string with only those text ranges replaced (never a
 *    DOM re-serialisation, which would turn {{tags}} inside links into %7B%7B..%7D%7D). <head>, <style>, <script>,
 *    comments and hidden parts (the preheader) are skipped. Links, images, styled spans and anything that looks like
 *    an email address, web address or phone number become [[kN]] markers: kept as they are, never sent.
 *  - What the model sees: the template's key and purpose, the hotel name, the subject, the numbered text blocks and
 *    the tags this template fills with their meanings. Never a guest, a booking or a figure from the database.
 *  - Every version is checked: shape, block numbers, only this template's {{tags}} (an unknown tag drops the whole
 *    version), every [[kN]] kept once, no links, no new numbers, length caps, plain text with <strong> and <br>
 *    only (everything else is escaped). A version that fails is dropped; none left = "No suggestions this time".
 *  - Cost: Fast model (Primary when there is no Fast), one try, short timeout, usage recorded like every call,
 *    about ai_tpl_suggest_limit requests per person per 10 minutes, the same input answered from the session for
 *    a short time.
 *  - Tone (owner 2026-11-25: "a few options on what sort of email you want, maybe firm, concise, friendly, etc to pick
 *    from. Make sure tags are accurate."): a fixed list (rh_ai_ts_tones()); anything else is Mixed. One tone = 3
 *    versions in that tone; Mixed = 3 different tones, each card labelled with its (whitelisted) tone. The tone goes
 *    in the message, never in the system prompt. No tone may add threats, deadlines or consequences the original
 *    does not state (checked in code: rh_ai_ts_threats()).
 *  - Tags: a version may only use the tags this template's sender really fills (includes/email-template-tags.php).
 *    Tags that print HTML or mislead in a sentence are not offered; they may stay only in the block they were in.
 */

require_once __DIR__ . '/ai-core.php';
require_once __DIR__ . '/ai-providers.php';
require_once __DIR__ . '/ai-attention-note.php'; // rh_ai_an_engine, rh_ai_an_model (Fast, else Primary)
require_once dirname(__DIR__) . '/email-template-tags.php';

if (!defined('RH_AI_TS_SESSION')) {
    define('RH_AI_TS_SESSION', 'rh_tpl_suggest');
}
if (!defined('RH_AI_TS_WINDOW')) {
    define('RH_AI_TS_WINDOW', 600); // the rate window in seconds (10 minutes)
}
if (!defined('RH_AI_TS_CACHE_TTL')) {
    define('RH_AI_TS_CACHE_TTL', 900); // the same input is answered from the session for 15 minutes
}
if (!defined('RH_AI_TS_MAX_BLOCKS')) {
    define('RH_AI_TS_MAX_BLOCKS', 50);
}
if (!defined('RH_AI_TS_MAX_CHARS')) {
    define('RH_AI_TS_MAX_CHARS', 6000); // total text sent; blocks after this stay as they are
}
if (!defined('RH_AI_TS_BLOCK_MAX')) {
    define('RH_AI_TS_BLOCK_MAX', 1200); // one block, before or after
}
if (!defined('RH_AI_TS_SUBJECT_MAX')) {
    define('RH_AI_TS_SUBJECT_MAX', 150);
}
if (!defined('RH_AI_TS_HTML_MAX')) {
    define('RH_AI_TS_HTML_MAX', 200000);
}

// ── Which templates ──────────────────────────────────────────────────────────

/** Email template key => what it is for (same sentences as the page). Only these keys get suggestions. */
function rh_ai_ts_purposes(): array
{
    return [
        'booking_received' => 'Sent to the guest when their booking request is received.',
        'booking_confirmed' => 'Sent to the guest when their booking is confirmed.',
        'booking_reminder' => 'Sent to the guest as a reminder before they check in.',
        'booking_cancelled' => 'Sent to the guest when their booking is cancelled.',
        'payment_invoice' => 'Sent with the invoice for a room booking.',
        'conference_invoice' => 'Sent with the invoice for a conference booking.',
        'tentative_booking_created' => 'Sent when a tentative (held) booking is created.',
        'tentative_booking_reminder' => 'Sent to remind the guest that a held booking is about to expire.',
        'tentative_booking_expired' => 'Sent when a held booking expires.',
        'tentative_booking_converted' => 'Sent when a held booking is confirmed.',
        'tentative_quotation' => 'Sent with a quotation for a room booking.',
        'conference_quotation' => 'Sent with a quotation for a conference.',
        'event_quotation' => 'Sent with a quotation for an event.',
        'credit_note' => 'Sent when a credit note is issued.',
        'refund_notification' => 'Sent when a refund is made.',
        'payment_reminder_1' => 'First reminder about an unpaid balance.',
        'payment_reminder_2' => 'Second reminder about an unpaid balance.',
        'payment_reminder_3' => 'Final reminder about an unpaid balance.',
        'quotation_expiry_reminder' => 'Sent to remind a client that their quotation is about to expire.',
        'prearrival_payment_reminder' => 'Sent before arrival to a guest whose confirmed booking still has a balance to pay.',
    ];
}

/** How a template usually sounds: added to the purpose only for Mixed (a picked tone replaces it). */
function rh_ai_ts_purpose_styles(): array
{
    return [
        'payment_reminder_1' => 'Usually friendly wording.',
        'payment_reminder_2' => 'Usually firmer wording than the first reminder.',
    ];
}

/** The purpose line the model gets: with the usual style for Mixed, without it when a tone is picked. */
function rh_ai_ts_purpose(string $key, string $tone): string
{
    $p = rh_ai_ts_purposes()[$key] ?? '';
    $style = rh_ai_ts_purpose_styles()[$key] ?? '';
    return ($tone === 'mixed' && $style !== '') ? $p . ' ' . $style : $p;
}

// ── Tone ─────────────────────────────────────────────────────────────────────

/** Tone key => label (the picker's options, in order). Mixed = today's behaviour: 3 different tones. */
function rh_ai_ts_tones(): array
{
    return [
        'mixed'      => 'Mixed',
        'friendly'   => 'Friendly',
        'concise'    => 'Concise',
        'firm'       => 'Firm',
        'formal'     => 'Formal',
        'apologetic' => 'Apologetic',
    ];
}

/** Tone key => what it means (sent to the model with the tone; fixed text, never the person's input). */
function rh_ai_ts_tone_notes(): array
{
    return [
        'friendly'   => 'Warm and personal, still professional.',
        'concise'    => 'Shorter and to the point. Every fact and every tag kept.',
        'firm'       => 'Clear and direct, still polite. No threats, deadlines, consequences or legal wording the original does not state.',
        'formal'     => 'Courteous and formal, no casual phrases.',
        'apologetic' => 'Sorry for any trouble where it fits, without admitting fault or offering anything the original does not.',
    ];
}

/** The posted tone, whitelisted: a known key, anything else (missing, unknown, odd case) is 'mixed'. */
function rh_ai_ts_tone($raw): string
{
    $t = is_string($raw) ? strtolower(trim($raw)) : '';
    return isset(rh_ai_ts_tones()[$t]) ? $t : 'mixed';
}

/** The card label of a version's tone: a named tone's label, '' for anything else (Mixed itself included). */
function rh_ai_ts_tone_label($raw): string
{
    $t = is_string($raw) ? strtolower(trim($raw)) : '';
    return ($t !== 'mixed' && isset(rh_ai_ts_tones()[$t])) ? rh_ai_ts_tones()[$t] : '';
}

/**
 * Threat and consequence words found in a text (stems). A version that brings in one the original email does not
 * already use is dropped, whatever the tone (Firm stays polite).
 */
function rh_ai_ts_threats(string $t): array
{
    $t = strtolower(strip_tags((string)preg_replace('/\{\{[^{}]*\}\}|\[\[[a-z]\d+\]\]/', ' ', $t)));
    $stems = [
        'cancel'   => '/\bcancel/',
        'legal'    => '/\blegal|\blawyer|\bsolicitor|\battorney|\bcourt\b|\bsue\b|\bprosecut|\blitigat/',
        'collect'  => '/\bdebt collect|\bcollection agenc/',
        'penalty'  => '/\bpenalt|\bsurcharge|\blate (payment )?(fee|charge)|\binterest (charge|will|at)\b/',
        'suspend'  => '/\bsuspen|\bterminat|\bblacklist|\bwithdraw|\brevok|\bforfeit/',
        'action'   => '/\bfurther action|\baction will be taken|\btake action|\bconsequence/',
        'deadline' => '/\bdeadline|\bwithin (the next )?\w+ (hours?|days?|weeks?)|\bby (tomorrow|today|tonight|the end of)|\bimmediately\b|\bfinal (notice|warning)/',
    ];
    $hit = [];
    foreach ($stems as $k => $re) {
        if (preg_match($re, $t)) {
            $hit[] = $k;
        }
    }
    return $hit;
}

/** May this template get suggestions: a known email template (never a PDF document, never the receipt email). */
function rh_ai_ts_template_ok(string $key): bool
{
    if ($key === '' || substr($key, -9) === '_document' || $key === 'payment_receipt') {
        return false;
    }
    return isset(rh_ai_ts_purposes()[$key]) && isset(rh_email_template_tag_map()[$key]);
}

/**
 * Tags that print HTML (a paragraph, a list, a box) without the _html / _block / _link ending: they cannot sit
 * inside a sentence. urgency_notice: a <p> (config/email.php sendBookingReminderEmail); payment_policy, pay_instructions,
 * cancellation_policy_text: paragraphs; quotation_notes: the staff note with line breaks.
 */
function rh_ai_ts_unsafe_tags(): array
{
    return ['urgency_notice', 'payment_policy', 'pay_instructions', 'cancellation_policy_text', 'quotation_notes'];
}

/**
 * Tags a template fills but that would mislead in new wording, so they are not offered (kept only where they were):
 * booking_reminder days_overdue = days past check-in / check-out (0 for an upcoming stay), not a late payment;
 * tentative_quotation vat_rate is rounded to a whole number (17.5 prints as 18); payment_invoice total_amount = the
 * invoice's balance (0.00 when paid), not its total (invoice_total_amount and balance_due_amount say it plainly).
 */
function rh_ai_ts_held_tags(): array
{
    return [
        'booking_reminder'    => ['days_overdue'],
        'tentative_quotation' => ['vat_rate'],
        'payment_invoice'     => ['total_amount'],
    ];
}

/**
 * The tags the model may use for this template: tag => meaning. Only the tags its sender really fills; tags that
 * print a block of HTML, a link or a button (…_html, …_block, …_link and rh_ai_ts_unsafe_tags()) and the held ones
 * are left out.
 */
function rh_ai_ts_allowed_tags(string $key): array
{
    $map = rh_email_template_tag_map();
    if (!isset($map[$key])) {
        return [];
    }
    $defs = rh_email_tag_definitions();
    $skip = array_merge(rh_ai_ts_unsafe_tags(), rh_ai_ts_held_tags()[$key] ?? []);
    $out = [];
    foreach (array_values(array_unique($map[$key])) as $tag) {
        if (preg_match('/(_html|_block|_link)$/', $tag) || in_array($tag, $skip, true)) {
            continue;
        }
        $out[$tag] = (string)($defs[$tag][1] ?? '');
    }
    return $out;
}

/**
 * The tags one piece of new text may hold: the offered ones, plus any tag this template's sender fills that the same
 * piece already held in the original (an HTML or held tag may stay where it was). A tag the sender does not fill is
 * never allowed, even when the original holds it.
 */
function rh_ai_ts_piece_tags(string $key, string $orig): array
{
    $filled = array_values(array_unique(rh_email_template_tag_map()[$key] ?? []));
    $kept = array_values(array_intersect(rh_ai_ts_tags_in($orig), $filled));
    return array_values(array_unique(array_merge(array_keys(rh_ai_ts_allowed_tags($key)), $kept)));
}

// ── Gate ─────────────────────────────────────────────────────────────────────

/**
 * The system part (no person): kill switch, chat tables, cloud on, NO_AI_SPEND, provider, key, a Fast or Primary
 * model. The same checks as the Dashboard note and the End of Day brief. Never throws.
 */
function rh_ai_ts_available(?PDO $db): bool
{
    try {
        if (!$db instanceof PDO || !rh_ai_enabled($db) || !rh_ai_chat_ready($db)) {
            return false;
        }
        if (rh_ai_setting('ai_cloud_enabled', '1') === '0' || rh_ai_no_spend_blocks()) {
            return false;
        }
        $prov = rh_ai_active_provider();
        if ($prov === '' || !rh_ai_is_provider($prov)) {
            return false;
        }
        $st = $db->prepare("SELECT
                (SELECT COUNT(*) FROM ai_providers WHERE provider = ? AND api_key_enc IS NOT NULL AND api_key_enc <> '') AS has_key,
                (SELECT COUNT(*) FROM ai_models WHERE provider = ? AND is_selected = 1 AND role IN ('fast', 'primary') AND COALESCE(source, '') <> 'gone') AS models");
        $st->execute([$prov, $prov]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r) || (int)$r['models'] < 1) {
            return false;
        }
        return (int)$r['has_key'] > 0 || rh_ai_fake_name() !== '';
    } catch (Throwable $e) {
        return false;
    }
}

/** The person part: ai_assistant_use + edit_templates ($can = hasPermission for this person). Never throws. */
function rh_ai_ts_person_ok(int $uid, callable $can): bool
{
    try {
        return $uid > 0 && $can('ai_assistant_use') && $can('edit_templates');
    } catch (Throwable $e) {
        return false;
    }
}

/** The whole gate the page uses: system part + person part. */
function rh_ai_ts_gate(?PDO $db, int $uid, callable $can): bool
{
    return rh_ai_ts_person_ok($uid, $can) && rh_ai_ts_available($db);
}

/** hasPermission for one person. */
function rh_ai_ts_can_for(int $uid): callable
{
    return static function (string $k) use ($uid): bool {
        if (!function_exists('hasPermission')) {
            require_once dirname(__DIR__, 2) . '/admin/includes/permissions.php';
        }
        return (bool)hasPermission($uid, $k);
    };
}

/** The button for one template's mount ('' when the gate is off or the template gets no suggestions). */
function rh_ai_ts_button(string $key, bool $on): string
{
    if (!$on || !rh_ai_ts_template_ok($key)) {
        return '';
    }
    $k = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
    $opts = '';
    foreach (rh_ai_ts_tones() as $tk => $label) {
        $opts .= '<option value="' . htmlspecialchars($tk, ENT_QUOTES, 'UTF-8') . '"' . ($tk === 'mixed' ? ' selected' : '') . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return '<span class="tpl-assist__tone">'
        . '<label class="tpl-assist__tone-label" for="tpl-assist-tone-' . $k . '">Tone</label>'
        . '<select class="tpl-assist__tone-select" id="tpl-assist-tone-' . $k . '" data-tpl-tone="' . $k . '">' . $opts . '</select>'
        . '<i class="help" data-tip="Pick how the email should sound; Mixed gives three different styles">?</i>'
        . '</span>'
        . '<button type="button" class="tpl-assist__btn" data-tpl-suggest="' . $k . '" aria-controls="tpl-assist-out-' . $k . '">'
        . '<i class="fas fa-pen-to-square" aria-hidden="true"></i> Suggest wording</button>'
        . '<i class="help" data-tip="Ideas from the Assistant: up to 3 new wordings for this email, same layout and tags. '
        . 'Only the wording, the subject and the tag names are sent; never a guest, a booking or a link. Nothing is saved until you press Save.">?</i>';
}

// ── Reading the HTML ─────────────────────────────────────────────────────────

/** Elements that end a sentence: a block holding one of these is walked into instead. */
function rh_ai_ts_blockish(string $n): bool
{
    return in_array($n, ['p', 'div', 'td', 'th', 'tr', 'table', 'tbody', 'thead', 'tfoot', 'ul', 'ol', 'li', 'h1', 'h2',
        'h3', 'h4', 'h5', 'h6', 'blockquote', 'center', 'section', 'article', 'header', 'footer', 'hr', 'html', 'body',
        'head', 'main', 'nav', 'dl', 'dt', 'dd', 'pre', 'form', 'caption'], true);
}

/** Elements whose text may be rewritten (as one block). */
function rh_ai_ts_candidate(string $n): bool
{
    return in_array($n, ['p', 'div', 'td', 'th', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'center', 'span',
        'a', 'font', 'strong', 'b', 'em', 'i', 'small', 'dd', 'dt', 'caption'], true);
}

function rh_ai_ts_hidden(string $attrs): bool
{
    if (preg_match('/display\s*:\s*none|mso-hide\s*:\s*all|visibility\s*:\s*hidden|aria-hidden\s*=\s*["\']?true/i', $attrs)) {
        return true;
    }
    // The bare "hidden" attribute (quoted values taken out first, so "overflow: hidden" does not count)
    return (bool)preg_match('/(?:^|\s)hidden(?:\s|=|\/|$)/i', (string)preg_replace('/"[^"]*"|\'[^\']*\'/', '""', $attrs));
}

/**
 * A light tree of the HTML with byte offsets. Element: [t=>'el', n, attrs, os (open start), oe (open end),
 * cs (close start), ce (close end), closed (explicit end tag), kids]. Text: [t=>'tx', s, e]. Comment: [t=>'cm', s, e].
 */
function rh_ai_ts_tree(string $html): array
{
    $void = ['br', 'img', 'hr', 'meta', 'link', 'input', 'col', 'area', 'base', 'wbr', 'source', 'param', 'embed', 'track'];
    $raw = ['script', 'style', 'title', 'textarea', 'xmp'];
    $root = ['t' => 'el', 'n' => '#root', 'attrs' => '', 'os' => 0, 'oe' => 0, 'cs' => strlen($html), 'ce' => strlen($html), 'closed' => true, 'kids' => []];
    $stack = [$root];
    $len = strlen($html);
    $pos = 0;
    $addKid = static function (array &$stack, array $node): void {
        $stack[count($stack) - 1]['kids'][] = $node;
    };
    $closeTop = static function (array &$stack, int $cs, int $ce, bool $explicit): void {
        $node = array_pop($stack);
        $node['cs'] = $cs;
        $node['ce'] = $ce;
        $node['closed'] = $explicit;
        $stack[count($stack) - 1]['kids'][] = $node;
    };
    $re = '/<!--.*?-->|<![^>]*>|<\?.*?\?>|<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>/s';
    while ($pos < $len && preg_match($re, $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
        $s = (int)$m[0][1];
        $tok = (string)$m[0][0];
        $e = $s + strlen($tok);
        if ($s > $pos) {
            $addKid($stack, ['t' => 'tx', 's' => $pos, 'e' => $s]);
        }
        $pos = $e;
        if (!isset($m[2]) || $m[2][1] < 0 || $m[2][0] === '') {
            $addKid($stack, ['t' => 'cm', 's' => $s, 'e' => $e]); // comment, doctype, conditional
            continue;
        }
        $name = strtolower((string)$m[2][0]);
        $attrs = (string)($m[3][0] ?? '');
        if ($m[1][0] === '/') {
            $at = -1;
            for ($i = count($stack) - 1; $i >= 1; $i--) {
                if ($stack[$i]['n'] === $name) {
                    $at = $i;
                    break;
                }
            }
            if ($at < 0) {
                $addKid($stack, ['t' => 'cm', 's' => $s, 'e' => $e]); // a stray end tag: kept as it is
                continue;
            }
            while (count($stack) - 1 > $at) {
                $closeTop($stack, $s, $s, false); // closed without its own end tag
            }
            $closeTop($stack, $s, $e, true);
            continue;
        }
        $node = ['t' => 'el', 'n' => $name, 'attrs' => $attrs, 'os' => $s, 'oe' => $e, 'cs' => $e, 'ce' => $e, 'closed' => true, 'kids' => []];
        if (in_array($name, $void, true) || substr(rtrim($attrs), -1) === '/') {
            $addKid($stack, $node);
            continue;
        }
        if (in_array($name, $raw, true)) {
            $endAt = stripos($html, '</' . $name, $e);
            if ($endAt === false) {
                $node['cs'] = $node['ce'] = $len;
                $node['closed'] = false;
                $pos = $len;
            } else {
                $gt = strpos($html, '>', $endAt);
                $gt = $gt === false ? $len : $gt + 1;
                $node['cs'] = $endAt;
                $node['ce'] = $gt;
                $pos = $gt;
            }
            $node['kids'] = [];
            $node['raw'] = true;
            $addKid($stack, $node);
            continue;
        }
        $stack[] = $node;
    }
    if ($pos < $len) {
        $addKid($stack, ['t' => 'tx', 's' => $pos, 'e' => $len]);
    }
    while (count($stack) > 1) {
        $closeTop($stack, $len, $len, false);
    }
    return $stack[0];
}

/** True when every element in this subtree had its own end tag (its range can be kept or replaced safely). */
function rh_ai_ts_well_closed(array $node): bool
{
    if ($node['t'] !== 'el') {
        return true;
    }
    if (!$node['closed']) {
        return false;
    }
    foreach ($node['kids'] as $k) {
        if (!rh_ai_ts_well_closed($k)) {
            return false;
        }
    }
    return true;
}

function rh_ai_ts_has_blockish(array $node): bool
{
    foreach ($node['kids'] as $k) {
        if ($k['t'] === 'el' && (rh_ai_ts_blockish($k['n']) || rh_ai_ts_has_blockish($k))) {
            return true;
        }
    }
    return false;
}

/** Plain text of a raw HTML text run: entities decoded, spaces (and no-break spaces) folded. */
function rh_ai_ts_plain(string $raw): string
{
    $t = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return (string)preg_replace('/[\s\x{00A0}]+/u', ' ', $t);
}

/**
 * Hide anything that could be personal or a link in plain text: email addresses, web addresses, phone numbers.
 * Each becomes a [[kN]] marker holding the original raw HTML (so it comes back exactly).
 */
function rh_ai_ts_scrub(string $text, array &$markers, int &$next, bool $isHtml = true): string
{
    $re = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}|(?:https?:\/\/|www\.)[^\s<>"\']+|\+?\d[\d\s().\-]{6,}\d/iu';
    return (string)preg_replace_callback($re, static function (array $m) use (&$markers, &$next, $isHtml): string {
        $id = 'k' . $next++;
        $markers[$id] = $isHtml ? htmlspecialchars($m[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $m[0];
        return '[[' . $id . ']]';
    }, $text);
}

/**
 * One element's inner content as block text, or null when it cannot be one block (a sentence-ending child, a
 * child without its own end tag, a <strong> with attributes is kept as a marker instead).
 */
function rh_ai_ts_inline_text(string $html, array $node, array &$markers, int &$next): ?string
{
    $out = '';
    foreach ($node['kids'] as $k) {
        if ($k['t'] === 'tx') {
            $raw = substr($html, $k['s'], $k['e'] - $k['s']);
            $out .= rh_ai_ts_scrub(rh_ai_ts_plain($raw), $markers, $next);
            continue;
        }
        if ($k['t'] === 'cm') {
            $id = 'k' . $next++;
            $markers[$id] = substr($html, $k['s'], $k['e'] - $k['s']);
            $out .= '[[' . $id . ']]';
            continue;
        }
        if (rh_ai_ts_blockish($k['n']) || rh_ai_ts_has_blockish($k) || !rh_ai_ts_well_closed($k)) {
            return null;
        }
        if ($k['n'] === 'br') {
            $out .= '<br>';
            continue;
        }
        if (($k['n'] === 'strong' || $k['n'] === 'b') && trim($k['attrs']) === '') {
            $inner = rh_ai_ts_inline_text($html, $k, $markers, $next);
            if ($inner === null) {
                return null;
            }
            $out .= '<strong>' . $inner . '</strong>';
            continue;
        }
        $id = 'k' . $next++;
        $markers[$id] = substr($html, $k['os'], $k['ce'] - $k['os']);
        $out .= '[[' . $id . ']]';
    }
    return $out;
}

/** Does the text hold a letter once tags, markers and markup are taken out? */
function rh_ai_ts_has_words(string $t): bool
{
    $t = (string)preg_replace('/\{\{[^{}]*\}\}|\[\[k\d+\]\]|<\/?strong>|<br>/', ' ', $t);
    return (bool)preg_match('/\p{L}/u', $t);
}

/**
 * The visible text blocks of a template, in page order.
 * @return array{blocks: list<array{n:int, s:int, e:int, text:string, markers:array<string,string>}>, truncated: bool}
 * s/e = the byte range of the block's inner HTML in the original string; text = what the model sees.
 */
function rh_ai_ts_extract(string $html): array
{
    $tree = rh_ai_ts_tree($html);
    $blocks = [];
    $next = 1;
    $total = 0;
    $truncated = false;
    $walk = static function (array $node) use (&$walk, &$blocks, &$next, &$total, &$truncated, $html): void {
        foreach ($node['kids'] as $k) {
            if ($k['t'] !== 'el' || !empty($k['raw'])) {
                continue;
            }
            if (in_array($k['n'], ['head', 'style', 'script', 'title', 'noscript', 'template', 'svg', 'xml', 'select', 'option'], true)
                || rh_ai_ts_hidden($k['attrs'])) {
                continue;
            }
            if (rh_ai_ts_candidate($k['n']) && $k['closed'] && $k['kids']) {
                $markers = [];
                $n0 = $next;
                $text = rh_ai_ts_inline_text($html, $k, $markers, $next);
                if ($text !== null) {
                    $text = trim((string)preg_replace('/ {2,}/', ' ', $text));
                    if (rh_ai_ts_has_words($text)) {
                        $len = mb_strlen($text);
                        if (count($blocks) >= RH_AI_TS_MAX_BLOCKS || $len > RH_AI_TS_BLOCK_MAX || $total + $len > RH_AI_TS_MAX_CHARS) {
                            $truncated = true; // left exactly as it is
                        } else {
                            $total += $len;
                            $blocks[] = ['n' => count($blocks) + 1, 's' => $k['oe'], 'e' => $k['cs'], 'text' => $text, 'markers' => $markers];
                        }
                        continue;
                    }
                }
                $next = $n0; // nothing taken from here: the walk goes inside instead
            }
            $walk($k);
        }
    };
    $walk($tree);
    return ['blocks' => $blocks, 'truncated' => $truncated];
}

/** The subject as the model sees it: email addresses, links and phone numbers hidden as [[sN]]. */
function rh_ai_ts_subject(string $subject): array
{
    $subject = trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $subject));
    $markers = [];
    $next = 1;
    $t = rh_ai_ts_scrub($subject, $markers, $next, false);
    $t2 = (string)preg_replace('/\[\[k(\d+)\]\]/', '[[s$1]]', $t);
    $m2 = [];
    foreach ($markers as $id => $v) {
        $m2['s' . substr($id, 1)] = $v;
    }
    return ['text' => $t2, 'markers' => $m2];
}

// ── What is sent ─────────────────────────────────────────────────────────────

/**
 * The one message the model gets (JSON): purpose, hotel name, subject, numbered blocks, allowed tags, tone.
 * $tone is a whitelisted key (rh_ai_ts_tone()); its meaning is fixed text from rh_ai_ts_tone_notes().
 */
function rh_ai_ts_payload(string $key, string $hotel, array $subject, array $blocks, string $tone = 'mixed'): string
{
    $tone = rh_ai_ts_tone($tone);
    $b = [];
    foreach ($blocks as $blk) {
        $b[(string)$blk['n']] = $blk['text'];
    }
    $tags = [];
    foreach (rh_ai_ts_allowed_tags($key) as $tag => $meaning) {
        $tags['{{' . $tag . '}}'] = $meaning;
    }
    if ($tone === 'mixed') {
        $notes = rh_ai_ts_tone_notes();
    } else {
        $notes = [$tone => rh_ai_ts_tone_notes()[$tone]];
    }
    // Tags in the current wording that this template's sender does not fill (they reach the guest as "{{tag}}")
    $all = $subject['text'];
    foreach ($blocks as $blk) {
        $all .= ' ' . $blk['text'];
    }
    $remove = array_values(array_diff(rh_ai_ts_tags_in($all), rh_email_template_tag_map()[$key] ?? []));
    $msg = [
        'template'     => $key,
        'purpose'      => rh_ai_ts_purpose($key, $tone),
        'hotel_name'   => mb_substr(trim($hotel), 0, 80),
        'subject'      => $subject['text'],
        'blocks'       => (object)$b,
        'allowed_tags' => (object)$tags,
        'tone'         => $tone,
        'tone_notes'   => (object)$notes,
    ];
    if ($remove) {
        $msg['remove_tags'] = array_map(static fn(string $t): string => '{{' . $t . '}}', $remove);
    }
    return (string)json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function rh_ai_ts_prompt(): string
{
    return "You suggest new wording for one email that a hotel sends to its guests or clients. You get JSON: the template "
        . "name and what it is for, the hotel name, the subject and the email's text blocks, numbered in page order, the "
        . "{{tags}} this email may use and what each one prints, and the tone wanted with what each tone means.\n"
        . "Write exactly 3 versions. If \"tone\" is \"mixed\", each version uses a different tone from tone_notes and says which "
        . "in \"tone\". Otherwise all 3 versions use that one tone and differ only in phrasing. The tone wanted decides how "
        . "the email sounds, whatever the purpose or the current wording suggests.\n"
        . "Reply with JSON only, no other text: {\"versions\":[{\"tone\":\"...\",\"subject\":\"...\",\"blocks\":{\"1\":\"...\",\"2\":\"...\"}}]}\n"
        . "Rules for every version:\n"
        . "- Every block number from the input, each one once, no new numbers. One block stays one block: a greeting stays a "
        . "greeting, a short label (like \"Check-in\") stays a short label, a sign-off stays a sign-off.\n"
        . "- Keep the meaning and the facts. Use the tags listed in allowed_tags, written exactly like {{tag}}. A block may "
        . "also keep a tag it already has, in that block only, unless the tag is listed in remove_tags (those print nothing "
        . "for this email: leave them out). Keep the tags a block uses where you can.\n"
        . "- Keep every marker like [[k3]] or [[s1]] exactly once, in the same block (or the subject) it came from.\n"
        . "- Plain text. You may use <strong>...</strong> and <br>, nothing else.\n"
        . "- No links, no new figures, prices, dates or times, no promises the original does not make.\n"
        . "- Never add a threat, a deadline, a consequence (such as cancelling or further action) or legal wording that the "
        . "original does not already state. A firm tone stays polite.\n"
        . "- Clear British English, short sentences. Do not say how the text was written.";
}

// ── Reading the answer ───────────────────────────────────────────────────────

/** The versions list from the reply text (code fences and chatter around the JSON are ignored). */
function rh_ai_ts_parse(string $reply): array
{
    $t = trim($reply);
    $a = strpos($t, '{');
    $b = strrpos($t, '}');
    if ($a === false || $b === false || $b <= $a) {
        return [];
    }
    $d = json_decode(substr($t, $a, $b - $a + 1), true);
    if (!is_array($d) || !isset($d['versions']) || !is_array($d['versions'])) {
        return [];
    }
    return array_slice(array_values(array_filter($d['versions'], 'is_array')), 0, 3);
}

/** Digit runs in a text, ignoring {{tags}} and markers. */
function rh_ai_ts_digits(string $t): array
{
    $t = (string)preg_replace('/\{\{[^{}]*\}\}|\[\[[a-z]\d+\]\]/', ' ', $t);
    preg_match_all('/\d+/', $t, $m);
    return array_values(array_unique($m[0]));
}

/** {{tag}} names used in a text. */
function rh_ai_ts_tags_in(string $t): array
{
    preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $t, $m);
    return array_values(array_unique($m[1]));
}

/**
 * One piece of new text checked against its original: null = drop the whole version.
 * $allowed = tag names this email may use; $digits = digit runs anywhere in the original email.
 */
function rh_ai_ts_check_text(string $new, string $orig, array $markerIds, array $allowed, array $digits, int $max): ?string
{
    $new = html_entity_decode($new, ENT_QUOTES | ENT_HTML5, 'UTF-8'); // the model's text is plain: "&amp;" means "&"
    $new = trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $new));
    $new = (string)preg_replace('/<br\s*\/?>/i', '<br>', $new);
    if ($new === '' || mb_strlen($new) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $new)) {
        return null;
    }
    // Tags: only this email's, written exactly {{tag}}; no stray braces left
    foreach (rh_ai_ts_tags_in($new) as $tag) {
        if (!in_array($tag, $allowed, true)) {
            return null; // an unknown tag would reach the guest as "{{tag}}": the version is dropped, not patched
        }
    }
    $rest = (string)preg_replace('/\{\{[A-Za-z0-9_]+\}\}/', '', $new);
    if (strpos($rest, '{') !== false || strpos($rest, '}') !== false) {
        return null;
    }
    // Markers: each of this block's exactly once, no others
    preg_match_all('/\[\[([a-z]\d+)\]\]/', $new, $mm);
    $found = $mm[1];
    sort($found);
    $want = $markerIds;
    sort($want);
    if ($found !== $want) {
        return null;
    }
    $rest2 = (string)preg_replace('/\[\[[a-z]\d+\]\]/', '', $rest);
    if (strpos($rest2, '[[') !== false || strpos($rest2, ']]') !== false) {
        return null;
    }
    // No links or addresses of its own
    if (preg_match('/https?:|www\.|mailto:|[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $rest2)) {
        return null;
    }
    // No new figures
    foreach (rh_ai_ts_digits($new) as $d) {
        if (!in_array($d, $digits, true)) {
            return null;
        }
    }
    // The shared wording rule (sounds artificial or names MRA), unless the original already says it
    rh_ai_an_engine();
    if (rh_dec_words_forbidden(strip_tags($rest2)) && !rh_dec_words_forbidden(strip_tags($orig))) {
        return null;
    }
    return $new;
}

/** The safe HTML for one block's new text: everything escaped, then exactly <strong>, </strong>, <br> allowed back. */
function rh_ai_ts_render(string $text, array $markers): string
{
    $h = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $h = str_replace(['&lt;br&gt;', '&lt;strong&gt;', '&lt;/strong&gt;'], ['<br>', "\x01", "\x02"], $h);
    // <strong> must pair up in order; otherwise none are kept
    $depth = 0;
    $ok = true;
    $nChars = strlen($h);
    for ($i = 0; $i < $nChars; $i++) {
        if ($h[$i] === "\x01") {
            if (++$depth > 1) {
                $ok = false;
                break;
            }
        } elseif ($h[$i] === "\x02") {
            if (--$depth < 0) {
                $ok = false;
                break;
            }
        }
    }
    $ok = $ok && $depth === 0;
    $h = str_replace(["\x01", "\x02"], $ok ? ['<strong>', '</strong>'] : ['', ''], $h);
    return (string)preg_replace_callback('/\[\[([a-z]\d+)\]\]/', static fn(array $m): string => (string)($markers[$m[1]] ?? ''), $h);
}

/**
 * Check every version and build its HTML from the original string. Unchanged blocks keep their exact bytes.
 * $tone: the whitelisted tone asked for. Its label goes on every card; for Mixed the model's own "tone" is used when
 * it is one of the named tones, otherwise the card has no label.
 * @return list<array{subject:string, html:string, preview_text:string, missing_tags:list<string>, tone:string}>
 */
function rh_ai_ts_validate(array $versions, string $key, string $html, array $subject, array $blocks, string $tone = 'mixed'): array
{
    $tone = rh_ai_ts_tone($tone);
    $origAll = $subject['text'];
    foreach ($blocks as $b) {
        $origAll .= ' ' . $b['text'];
    }
    $digits = rh_ai_ts_digits($origAll);
    $filled = rh_email_template_tag_map()[$key] ?? [];
    $origTags = array_values(array_intersect(rh_ai_ts_tags_in($origAll), $filled)); // an unfilled tag left out is not "missing"
    $origThreats = rh_ai_ts_threats($origAll);
    $out = [];
    $seen = [];
    foreach ($versions as $v) {
        if (!is_array($v) || !isset($v['blocks']) || !is_array($v['blocks'])) {
            continue;
        }
        $vb = $v['blocks'];
        if ($vb && array_keys($vb) === range(0, count($vb) - 1)) {
            $vb = array_combine(array_map('strval', range(1, count($vb))), $vb); // a plain list: numbered in order
        }
        $keys = array_map('strval', array_keys($vb));
        $want = array_map(static fn(array $b): string => (string)$b['n'], $blocks);
        sort($keys);
        sort($want);
        if ($keys !== $want) {
            continue; // a block missing, added or renumbered
        }
        // Subject: plain text, one line
        $subj = is_string($v['subject'] ?? null) ? $v['subject'] : '';
        $subj = trim((string)preg_replace('/[\r\n\t]+/', ' ', strip_tags(html_entity_decode($subj, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        if ($subject['text'] === '' && $subj === '') {
            $subj = ''; // no subject before, none now
        } else {
            $subj = rh_ai_ts_check_text($subj, $subject['text'], array_keys($subject['markers']), rh_ai_ts_piece_tags($key, $subject['text']), $digits, RH_AI_TS_SUBJECT_MAX);
        }
        if ($subj === null) {
            continue;
        }
        $new = [];
        $okAll = true;
        foreach ($blocks as $b) {
            $t = $vb[(string)$b['n']] ?? null;
            if (!is_string($t)) {
                $okAll = false;
                break;
            }
            $cap = min(RH_AI_TS_BLOCK_MAX, max((int)ceil(mb_strlen($b['text']) * 2.5), mb_strlen($b['text']) + 200));
            $c = rh_ai_ts_check_text($t, $b['text'], array_keys($b['markers']), rh_ai_ts_piece_tags($key, $b['text']), $digits, $cap);
            if ($c === null) {
                $okAll = false;
                break;
            }
            $new[$b['n']] = $c;
        }
        if (!$okAll) {
            continue;
        }
        // Rebuild: replace only the changed blocks' inner ranges, last first so earlier offsets stay right
        $rebuilt = $html;
        $changed = [];
        foreach (array_reverse($blocks) as $b) {
            if ($new[$b['n']] === $b['text']) {
                continue;
            }
            $changed[] = $new[$b['n']];
            $rebuilt = substr($rebuilt, 0, $b['s']) . rh_ai_ts_render($new[$b['n']], $b['markers']) . substr($rebuilt, $b['e']);
        }
        $subjOut = (string)preg_replace_callback('/\[\[(s\d+)\]\]/', static fn(array $m): string => (string)($subject['markers'][$m[1]] ?? ''), $subj);
        if (!$changed && $subj === $subject['text']) {
            continue; // nothing new
        }
        $sig = hash('sha256', $subjOut . "\0" . $rebuilt);
        if (isset($seen[$sig])) {
            continue;
        }
        $seen[$sig] = true;
        $allNew = $subj;
        foreach ($new as $t) {
            $allNew .= ' ' . $t;
        }
        if (array_diff(rh_ai_ts_threats($allNew), $origThreats)) {
            continue; // a threat, deadline or consequence the original does not state
        }
        $missing = array_values(array_diff($origTags, rh_ai_ts_tags_in($allNew)));
        $prev = array_reverse($changed);
        $preview = trim((string)preg_replace('/\s+/', ' ', strip_tags(str_replace('<br>', ' ', (string)preg_replace('/\[\[[a-z]\d+\]\]/', '…', implode(' ', $prev))))));
        $out[] = [
            'subject'      => $subjOut,
            'html'         => $rebuilt,
            'preview_text' => mb_strlen($preview) > 180 ? mb_substr($preview, 0, 177) . '…' : $preview,
            'missing_tags' => array_map(static fn(string $t): string => '{{' . $t . '}}', $missing),
            'tone'         => $tone === 'mixed' ? rh_ai_ts_tone_label($v['tone'] ?? '') : rh_ai_ts_tone_label($tone),
        ];
    }
    return $out;
}

// ── Rate and cache (session) ─────────────────────────────────────────────────

/**
 * May one more request go out: at most $max in the last $window seconds. Pure.
 * @return array{ok:bool, stamps:list<int>, wait:int}
 */
function rh_ai_ts_rate(array $stamps, int $now, int $max, int $window = RH_AI_TS_WINDOW): array
{
    $keep = array_values(array_filter(array_map('intval', $stamps), static fn(int $t): bool => $t > $now - $window && $t <= $now));
    if (count($keep) >= max(1, $max)) {
        sort($keep);
        return ['ok' => false, 'stamps' => $keep, 'wait' => max(1, $keep[0] + $window - $now)];
    }
    $keep[] = $now;
    return ['ok' => true, 'stamps' => $keep, 'wait' => 0];
}

/** The per-person limit (setting ai_tpl_suggest_limit, 1-30, default 10). */
function rh_ai_ts_limit(): int
{
    $n = (int)rh_ai_setting('ai_tpl_suggest_limit', '10');
    return $n < 1 ? 10 : min(30, $n);
}

function rh_ai_ts_cache_key(int $uid, string $key, string $subject, string $html, string $tone = 'mixed'): string
{
    return hash('sha256', $uid . '|' . $key . '|' . rh_ai_ts_tone($tone) . '|' . $subject . '|' . $html);
}

/** A cached answer from the session store, or null. */
function rh_ai_ts_cache_get(array $store, string $ck, int $now): ?array
{
    $e = $store[$ck] ?? null;
    if (!is_array($e) || (int)($e['at'] ?? 0) <= $now - RH_AI_TS_CACHE_TTL || !is_array($e['versions'] ?? null)) {
        return null;
    }
    return $e['versions'];
}

/** The session store with one answer added (old ones dropped, at most 3 kept). */
function rh_ai_ts_cache_put(array $store, string $ck, array $versions, int $now): array
{
    $store = array_filter($store, static fn($e): bool => is_array($e) && (int)($e['at'] ?? 0) > $now - RH_AI_TS_CACHE_TTL);
    $store[$ck] = ['at' => $now, 'versions' => $versions];
    uasort($store, static fn(array $a, array $b): int => (int)$b['at'] <=> (int)$a['at']);
    return array_slice($store, 0, 3, true);
}

// ── The call ─────────────────────────────────────────────────────────────────

/**
 * Up to 3 checked versions for one template. Never throws.
 * @return array{versions:list<array>, reason:string, called:bool}
 * reason: ok | template | nothing | model | budget | error | dropped
 */
function rh_ai_ts_suggest(PDO $db, int $uid, string $key, string $subject, string $html, string $hotel, string $tone = 'mixed'): array
{
    $out = static fn(array $v, string $r, bool $c): array => ['versions' => $v, 'reason' => $r, 'called' => $c];
    try {
        $tone = rh_ai_ts_tone($tone);
        if (!rh_ai_ts_template_ok($key)) {
            return $out([], 'template', false);
        }
        if (strlen($html) > RH_AI_TS_HTML_MAX) {
            return $out([], 'nothing', false);
        }
        $ex = rh_ai_ts_extract($html);
        if (!$ex['blocks']) {
            return $out([], 'nothing', false);
        }
        $subj = rh_ai_ts_subject(mb_substr($subject, 0, 300));
        $sel = rh_ai_an_model($db);
        if ($sel === null) {
            return $out([], 'model', false);
        }
        if (rh_ai_budget_problem($db, $uid) !== '') {
            return $out([], 'budget', false);
        }
        $model = (string)$sel['model']['model_id'];
        $tokens = max(800, min(8000, (int)rh_ai_setting('ai_tpl_suggest_tokens', '4000')));
        $r = rh_ai_chat(['provider' => $sel['provider'], 'model' => $model, 'max_tokens' => $tokens,
            'effort' => 'minimal', 'timeout' => 30, 'system_frozen' => rh_ai_ts_prompt(),
            'messages' => [['role' => 'user', 'content' => rh_ai_ts_payload($key, $hotel, $subj, $ex['blocks'], $tone)]], 'tools' => []]);
        $usage = (array)($r['usage'] ?? []);
        if ((int)($usage['in'] ?? 0) + (int)($usage['out'] ?? 0) > 0) {
            $used = (string)($r['model'] ?? $model);
            [$pi, $po] = rh_ai_model_price($db, $sel['provider'], $used);
            rh_ai_record_usage($db, $uid, $sel['provider'], $used, $usage, rh_ai_cost_usd($pi, $po, $usage));
        }
        if (($r['error'] ?? '') !== '') {
            return $out([], 'error', true);
        }
        $v = rh_ai_ts_validate(rh_ai_ts_parse((string)($r['text'] ?? '')), $key, $html, $subj, $ex['blocks'], $tone);
        return $out($v, $v ? 'ok' : 'dropped', true);
    } catch (Throwable $e) {
        error_log('Template wording suggestions: ' . $e->getMessage());
        return $out([], 'error', false);
    }
}
