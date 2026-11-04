<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Child of import_engine_test.php: runs under a LOW memory_limit (php -d memory_limit=64M ...) and prints ONE json line.
 *   php -d memory_limit=64M [-d disable_functions=ini_set] import_memory_child.php check <csv>   the pre-flight check only
 *   php -d memory_limit=128M import_memory_child.php run <csv>                                    check, then the whole upload + review work
 * A fatal "Allowed memory size exhausted" would leave no json line at all: the parent treats that as a failure.
 */
$root = dirname(__DIR__, 2);
require $root . '/includes/import/default-aliases.php';
require $root . '/includes/import/memory.php';
[$x, $mode, $csv] = $argv;
$al = import_default_aliases();
$out = ['limit' => ini_get('memory_limit')];
$chk = import_mem_check($csv, basename($csv), $al);
$out['ok'] = $chk['ok'];
$out['message'] = $chk['message'];
$out['rows'] = $chk['rows'];
$out['need_mb'] = round($chk['need'] / 1048576);
$out['limit_after'] = ini_get('memory_limit');
if ($mode === 'run' && $chk['ok']) {
    $an = import_analyze_file($csv, basename($csv), $al, ['max_bytes' => 50 << 20]);
    $js = json_encode($an);
    unset($an);
    $an = json_decode($js, true);
    unset($js);
    $state = ['analysis' => $an, 'res' => null, 'ctx' => ['existing' => [], 'unit_options' => ['each'], 'corrections' => []]];
    unset($an);
    $state['row_src'] = import_review_row_sources($state['analysis']);
    $state['uid_cat'] = import_review_uid_categories($state['analysis']);
    $state['res'] = import_resolve_inplace($state['analysis'], [], $state['ctx'], true);
    $V = import_rv_build($state, []);
    $out['items'] = count($state['res']['items']);
    $out['entries'] = count($V['entries']);
}
$out['peak_mb'] = round(memory_get_peak_usage() / 1048576, 1);
$out['peak_real_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
echo json_encode($out), "\n";
