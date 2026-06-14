<?php
/**
 * One-time migration trigger — Migration 046: barcode column on menu_items.
 * Open this URL once in the browser while logged in as admin, then it self-deletes.
 */
require_once 'admin-init.php';
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); exit('Admin only.'); }

$results = [];
$success = true;

function m046_col_exists(PDO $pdo, string $table, string $col): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$table, $col]);
    return (int)$s->fetchColumn() > 0;
}

try {
    if (m046_col_exists($pdo, 'menu_items', 'barcode')) {
        $results[] = ['ok', 'menu_items.barcode already exists — nothing to do'];
    } else {
        $pdo->exec("ALTER TABLE menu_items ADD COLUMN barcode VARCHAR(100) UNIQUE NULL AFTER item_name");
        $results[] = ['ok', 'Added menu_items.barcode VARCHAR(100) UNIQUE NULL'];
    }

    $idxRows = $pdo->query("SHOW INDEX FROM menu_items WHERE Key_name = 'idx_mi_barcode'")->fetchAll();
    if (empty($idxRows)) {
        $pdo->exec("CREATE INDEX idx_mi_barcode ON menu_items (barcode)");
        $results[] = ['ok', 'Created index idx_mi_barcode'];
    } else {
        $results[] = ['skip', 'Index idx_mi_barcode already exists'];
    }

    // Verify
    $cols = array_column($pdo->query("SHOW COLUMNS FROM menu_items")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $results[] = in_array('barcode', $cols)
        ? ['ok', 'Verified: barcode column confirmed in menu_items']
        : ['fail', 'Verification failed — barcode column NOT found'];

} catch (Throwable $e) {
    $results[] = ['fail', 'Error: ' . $e->getMessage()];
    $success = false;
}

// Self-delete after success
if ($success) {
    @unlink(__FILE__);
    $results[] = ['ok', 'Migration trigger file self-deleted'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration 046</title>
<style>body{font-family:monospace;background:#111;color:#eee;padding:32px;line-height:1.8}
.ok{color:#4ade80}.skip{color:#94a3b8}.fail{color:#f87171}
a{color:#60a5fa}</style></head>
<body>
<h2 style="color:#f1f5f9">Migration 046 — Barcode on menu_items</h2>
<?php foreach($results as [$tag,$msg]): ?>
<div class="<?php echo $tag;?>"><?php echo $tag==='ok'?'✓':($tag==='skip'?'·':'✗'); ?> &nbsp;<?php echo htmlspecialchars($msg);?></div>
<?php endforeach; ?>
<br>
<?php if($success): ?>
<div class="ok" style="font-size:1.1em;font-weight:bold">✓ Migration complete. This file has been deleted.</div>
<?php else: ?>
<div class="fail">Migration failed — check errors above. File was NOT deleted.</div>
<?php endif; ?>
<br><a href="pos.php">← Back to POS</a>
</body></html>
