<?php
/**
 * Pure test (no database): the optional hotel letters on document numbers
 * (setting document_number_prefix, includes/finance-sequences.php).
 *   - empty prefix = exactly today's numbers for invoice / receipt / credit note / refund
 *   - 'LSH' = LSH-INV-2026-000001, LSH-RCP-2026-000001, LSH-CN-2026-000001, LSH-REF-2026-000001,
 *     LSH-CONF-INV-…, LSH-GYM-INV-…, LSH-EVT-INV-…
 *   - numbering starts at the start number and sequence keys never include the hotel letters.
 * Uses an in-memory stand-in for PDO; never connects to any database.
 * Run: php scripts/tests/document_number_prefix_test.php
 */

declare(strict_types=1);

$GLOBALS['dnp_settings'] = [];
function getSetting($key, $default = null)
{
    return array_key_exists($key, $GLOBALS['dnp_settings']) ? $GLOBALS['dnp_settings'][$key] : $default;
}

final class DnpStmt
{
    private $db;
    private $sql;
    private $result = null;
    public function __construct(DnpPdo $db, string $sql) { $this->db = $db; $this->sql = $sql; }
    public function execute(array $p = []): bool { $this->result = $this->db->run($this->sql, $p); return true; }
    public function fetchColumn() { return $this->result; }
}

final class DnpPdo extends PDO
{
    public $seq = [];      // "name|scope" => next_number
    public $taken = [];    // numbers already used on rows
    private $tx = false;
    public function __construct() {}
    #[\ReturnTypeWillChange] public function prepare($sql, $options = []) { return new DnpStmt($this, (string)$sql); }
    #[\ReturnTypeWillChange] public function inTransaction() { return $this->tx; }
    #[\ReturnTypeWillChange] public function beginTransaction() { $this->tx = true; return true; }
    #[\ReturnTypeWillChange] public function commit() { $this->tx = false; return true; }
    #[\ReturnTypeWillChange] public function rollBack() { $this->tx = false; return true; }
    public function run(string $sql, array $p)
    {
        if (strpos($sql, 'information_schema') !== false) {
            return 1;
        }
        if (strpos($sql, 'INSERT INTO finance_sequences') !== false) {
            $k = $p[0] . '|' . $p[1];
            if (!isset($this->seq[$k])) {
                $this->seq[$k] = (int)$p[2];
            }
            return null;
        }
        if (strpos($sql, 'SELECT next_number') !== false) {
            return $this->seq[$p[0] . '|' . $p[1]];
        }
        if (strpos($sql, 'UPDATE finance_sequences') !== false) {
            $this->seq[$p[1] . '|' . $p[2]] = (int)$p[0];
            return null;
        }
        if (strpos($sql, 'SELECT COUNT(*)') !== false) {
            return in_array($p[0], $this->taken, true) ? 1 : 0;
        }
        throw new RuntimeException('unexpected SQL in test: ' . $sql);
    }
}

require_once __DIR__ . '/../../includes/finance-sequences.php';

$pass = 0;
$fail = 0;
function ok(bool $cond, string $label, string $got = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $label\n"; }
    else { $fail++; echo "FAIL  $label" . ($got !== '' ? "  got: $got" : '') . "\n"; }
}

$date = '2026-11-10';

/** Allocate one of each kind on a fresh store with the given settings. */
function dnp_all(array $settings, int $invoiceStart): array
{
    $GLOBALS['dnp_settings'] = $settings;
    $pdo = new DnpPdo();
    $d = '2026-11-10';
    $out = [
        'room'    => finance_next_invoice_number($pdo, (string)getSetting('invoice_prefix', 'INV'), $invoiceStart, $d, 'room'),
        'conf'    => finance_next_invoice_number($pdo, 'CONF-' . getSetting('invoice_prefix', 'INV'), $invoiceStart, $d, 'conference'),
        'gym'     => finance_next_invoice_number($pdo, 'GYM-' . getSetting('invoice_prefix', 'INV'), $invoiceStart, $d, 'gym'),
        'event'   => finance_next_invoice_number($pdo, 'EVT-' . getSetting('invoice_prefix', 'INV'), $invoiceStart, $d, 'event'),
        'receipt' => finance_next_receipt_number($pdo, $d),
        'cn'      => finance_next_credit_note_number($pdo, $d),
        'refund'  => finance_next_refund_reference($pdo, $d),
        'room2'   => finance_next_invoice_number($pdo, (string)getSetting('invoice_prefix', 'INV'), $invoiceStart, $d, 'room'),
        'receipt2'=> finance_next_receipt_number($pdo, $d),
    ];
    $out['keys'] = array_keys($pdo->seq);
    return $out;
}

// 1. No hotel letters: byte-for-byte today's shapes (today's settings at Rosalyn's style: start 1001).
foreach ([[], ['document_number_prefix' => ''], ['document_number_prefix' => '  '], ['document_number_prefix' => '--']] as $i => $s) {
    $a = dnp_all($s + ['invoice_prefix' => 'INV'], 1001);
    ok($a['room'] === 'INV-2026-001001', "empty prefix #$i: room invoice unchanged", $a['room']);
    ok($a['conf'] === 'CONF-INV-2026-001001', "empty prefix #$i: conference invoice unchanged", $a['conf']);
    ok($a['gym'] === 'GYM-INV-2026-001001', "empty prefix #$i: gym invoice unchanged", $a['gym']);
    ok($a['event'] === 'EVT-INV-2026-001001', "empty prefix #$i: event invoice unchanged", $a['event']);
    ok($a['receipt'] === 'RCP2026000001', "empty prefix #$i: receipt unchanged (no dashes)", $a['receipt']);
    ok($a['cn'] === 'CN-2026-000001', "empty prefix #$i: credit note unchanged", $a['cn']);
    ok($a['refund'] === 'REF-2026-000001', "empty prefix #$i: refund unchanged", $a['refund']);
}

// 2. LSH: the owner's approved shapes, starting at 1.
$keysPlain = dnp_all(['invoice_prefix' => 'INV'], 1)['keys'];
$l = dnp_all(['invoice_prefix' => 'INV', 'document_number_prefix' => 'LSH'], 1);
ok($l['room'] === 'LSH-INV-2026-000001', 'LSH room invoice', $l['room']);
ok($l['receipt'] === 'LSH-RCP-2026-000001', 'LSH receipt', $l['receipt']);
ok($l['cn'] === 'LSH-CN-2026-000001', 'LSH credit note', $l['cn']);
ok($l['refund'] === 'LSH-REF-2026-000001', 'LSH refund', $l['refund']);
ok($l['conf'] === 'LSH-CONF-INV-2026-000001', 'LSH conference invoice', $l['conf']);
ok($l['gym'] === 'LSH-GYM-INV-2026-000001', 'LSH gym invoice', $l['gym']);
ok($l['event'] === 'LSH-EVT-INV-2026-000001', 'LSH event invoice', $l['event']);
ok($l['room2'] === 'LSH-INV-2026-000002' && $l['receipt2'] === 'LSH-RCP-2026-000002', 'LSH numbers run on with no gap', $l['room2'] . ' ' . $l['receipt2']);
ok($l['keys'] === $keysPlain, 'sequence keys identical with and without the hotel letters', json_encode($l['keys']));
foreach ($l['keys'] as $k) {
    ok(strpos($k, 'LSH') === false, "sequence key has no hotel letters: $k");
}

// 3. Clean-up of the setting and odd base prefixes.
$x = dnp_all(['invoice_prefix' => 'INV', 'document_number_prefix' => ' lsh- '], 1);
ok($x['room'] === 'LSH-INV-2026-000001', 'prefix is trimmed, upper-cased, letters/digits only', $x['room']);
$x = dnp_all(['invoice_prefix' => 'LSH-INV', 'document_number_prefix' => 'LSH'], 1);
ok($x['room'] === 'LSH-INV-2026-000001', 'never LSH twice when invoice_prefix already starts with it', $x['room']);
$x = dnp_all(['invoice_prefix' => 'INV', 'receipt_prefix' => 'RCP-', 'document_number_prefix' => 'LSH'], 1);
ok($x['receipt'] === 'LSH-RCP-2026-000001', 'receipt prefix with a trailing dash gives no double dash', $x['receipt']);

// 4. Start number respected; a taken number is skipped, not reused.
$GLOBALS['dnp_settings'] = ['document_number_prefix' => 'LSH', 'receipt_start_number' => '5'];
$pdo = new DnpPdo();
ok(finance_next_receipt_number($pdo, $date) === 'LSH-RCP-2026-000005', 'receipt starts at receipt_start_number');
$pdo->taken[] = 'LSH-RCP-2026-000006';
ok(finance_next_receipt_number($pdo, $date) === 'LSH-RCP-2026-000007', 'a number already on a row is skipped');
$GLOBALS['dnp_settings'] = ['document_number_prefix' => 'LSH'];
ok(finance_next_invoice_number(new DnpPdo(), 'INV', 1001, $date, 'room') === 'LSH-INV-2026-001001', 'invoice starts at invoice_start_number');

// 5. Pure formatter directly.
ok(finance_format_document_number('receipt', 'RCP', '2026', 42, '') === 'RCP2026000042', 'formatter: receipt, no prefix');
ok(finance_format_document_number('credit_note', 'CN', '2026', 42, 'LSH') === 'LSH-CN-2026-000042', 'formatter: credit note, LSH');
ok(finance_apply_document_prefix('X-1', '') === 'X-1' && finance_apply_document_prefix('X-1', 'LSH') === 'LSH-X-1', 'apply prefix helper');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
