<?php
/**
 * Code index generator (CLI only).
 *
 * Usage: php scripts/code-index.php
 *
 * Writes .claude/CODE_INDEX.md: for every .php/.js file over 300 lines under
 * admin/, api/, includes/, config/, js/ it lists functions, action handlers,
 * modal/overlay ids and permission keys with line numbers, so agents can use
 * Read offset/limit instead of reading whole large files.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

const CI_DIRS = ['admin', 'api', 'includes', 'config', 'js'];
const CI_EXCLUDE_SEGMENTS = ['vendor', 'phpmailer', 'logs', 'cache', 'backups', 'images', 'database', 'node_modules'];
const CI_MIN_LINES = 301; // "more than 300 lines"
const CI_TARGET_BYTES = 61440; // 60 KB

/** Category order and short labels used in the output. */
const CI_CATEGORIES = [
    'fn'      => 'PHP functions',
    'actions' => '$action === handlers',
    'cases'   => 'action switch cases',
    'post'    => '$_POST *_action keys',
    'ajax'    => '?ajax= handlers',
    'js'      => 'top-level JS functions',
    'modals'  => 'modal/overlay ids',
    'perms'   => 'hasPermission keys (first line)',
];

$root = dirname(__DIR__);
$outFile = $root . '/.claude/CODE_INDEX.md';

/**
 * Remove string literals and // comments so braces inside them are not counted.
 */
function ci_strip(string $line): string
{
    $line = preg_replace('/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"|`(?:\\\\.|[^`\\\\])*`/', '""', $line) ?? $line;
    $line = $line ?? '';
    return preg_replace('#//.*$#', '', $line) ?? $line; // PCRE error on a huge line: keep it
}

/**
 * Record one entry in a category. Dedupes per category by name (first line wins)
 * unless $dedupe is false.
 */
function ci_add(array &$idx, string $cat, string $name, int $line, bool $dedupe = true): void
{
    $key = $cat . "\0" . $name;
    if ($dedupe && isset($idx['seen'][$key])) {
        return;
    }
    $idx['seen'][$key] = true;
    $idx['cats'][$cat][] = $name . ':' . $line;
}

/**
 * Process one line of JavaScript code at (or below) top level.
 * $depth tracks brace nesting across the block.
 */
function ci_js_line(array &$idx, string $code, int $ln, int &$depth): void
{
    if ($depth === 0) {
        if (preg_match('/^\s*(?:async\s+)?function\s+\*?\s*([A-Za-z_$][\w$]*)\s*\(/', $code, $m)
            || preg_match('/^\s*(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s+)?(?:\([^)]*\)|[A-Za-z_$][\w$]*)\s*=>/', $code, $m)) {
            ci_add($idx, 'js', $m[1], $ln, false);
        }
    }
    $depth += substr_count($code, '{') - substr_count($code, '}');
    if ($depth < 0) {
        $depth = 0;
    }
}

/**
 * Build the category lists for one file.
 */
function ci_index_file(string $rel, string $src): array
{
    $lines = preg_split('/\r\n|\r|\n/', $src);
    if (end($lines) === '') {
        array_pop($lines);
    }

    $idx = ['cats' => [], 'seen' => []];
    $isPhp = strtolower(substr($rel, -4)) === '.php';

    $switchActive = false;
    $sDepth = 0;
    $sOpened = false;

    $inScript = false;
    $jsDepth = 0;

    foreach ($lines as $i => $raw) {
        $ln = $i + 1;

        // Modal / overlay element ids (HTML or JS-built markup).
        if (preg_match_all('/(?<![\w-])id\s*=\s*[\'"]([\w-]*(?:[Mm]odal|[Oo]verlay)[\w-]*)[\'"]/', $raw, $m)) {
            foreach ($m[1] as $id) {
                ci_add($idx, 'modals', $id, $ln);
            }
        }

        if (!$isPhp) {
            // Whole .js file is code.
            ci_js_line($idx, ci_strip($raw), $ln, $jsDepth);
            continue;
        }

        // PHP function definitions.
        if (preg_match('/^\s*(?:(?:public|private|protected|static|final|abstract)\s+)*function\s+&?([A-Za-z_]\w*)\s*\(/', $raw, $m)) {
            ci_add($idx, 'fn', $m[1], $ln, false);
        }

        // Action handlers.
        if (preg_match_all('/\$action\s*===\s*[\'"]([^\'"]+)[\'"]/', $raw, $m)) {
            foreach ($m[1] as $name) {
                ci_add($idx, 'actions', $name, $ln);
            }
        }
        if (preg_match_all('/\$_(?:POST|GET|REQUEST)\[\s*[\'"]([^\'"]*_action)[\'"]\s*\]/', $raw, $m)) {
            foreach ($m[1] as $name) {
                ci_add($idx, 'post', $name, $ln);
            }
        }
        if (preg_match_all('/\$_(?:GET|REQUEST|POST)\[\s*[\'"]ajax[\'"]\s*\]\s*={2,3}\s*[\'"]([^\'"]+)[\'"]/', $raw, $m)) {
            foreach ($m[1] as $name) {
                ci_add($idx, 'ajax', $name, $ln);
            }
        }

        // Cases inside a switch whose subject is action/ajax.
        if (!$switchActive && preg_match('/\bswitch\s*\(.*\b(action|ajax)/i', $raw)) {
            $switchActive = true;
            $sDepth = 0;
            $sOpened = false;
        }
        if ($switchActive) {
            if (preg_match('/^\s*case\s+[\'"]([^\'"]+)[\'"]\s*:/', $raw, $m)) {
                ci_add($idx, 'cases', $m[1], $ln);
            }
            $code = ci_strip($raw);
            $open = substr_count($code, '{');
            if ($open > 0) {
                $sOpened = true;
            }
            $sDepth += $open - substr_count($code, '}');
            if ($sOpened && $sDepth <= 0) {
                $switchActive = false;
            }
        }

        // Permission keys: last quoted argument of hasPermission(...).
        if (preg_match_all('/hasPermission\s*\(([^()]*)\)/', $raw, $pm, PREG_SET_ORDER)) {
            foreach ($pm as $p) {
                if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $p[1], $sm) && $sm[1]) {
                    ci_add($idx, 'perms', end($sm[1]), $ln);
                }
            }
        }

        // Top-level JS inside inline <script> blocks (no src attribute).
        $chunk = null;
        if (!$inScript && preg_match('/<script\b(?![^>]*\bsrc\s*=)[^>]*>/i', $raw, $om, PREG_OFFSET_CAPTURE)) {
            $inScript = true;
            $jsDepth = 0;
            $chunk = substr($raw, $om[0][1] + strlen($om[0][0]));
        } elseif ($inScript) {
            $chunk = $raw;
        }
        if ($chunk !== null) {
            $closing = false;
            if (preg_match('#</script\s*>#i', $chunk, $cm, PREG_OFFSET_CAPTURE)) {
                $chunk = substr($chunk, 0, $cm[0][1]);
                $closing = true;
            }
            ci_js_line($idx, ci_strip($chunk), $ln, $jsDepth);
            if ($closing) {
                $inScript = false;
            }
        }
    }

    return $idx['cats'];
}

// ---- Collect files -------------------------------------------------------

$files = [];
foreach (CI_DIRS as $dir) {
    $base = $root . '/' . $dir;
    if (!is_dir($base)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $ext = strtolower($file->getExtension());
        if ($ext !== 'php' && $ext !== 'js') {
            continue;
        }
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $segments = array_map('strtolower', explode('/', $rel));
        array_pop($segments); // drop the file name; only directories are excluded
        if (array_intersect($segments, CI_EXCLUDE_SEGMENTS)) {
            continue;
        }
        $files[$rel] = $file->getPathname();
    }
}
ksort($files);

// ---- Build the markdown --------------------------------------------------

$out = [];
$out[] = '# Code index';
$out[] = '';
$out[] = 'Generated by `php scripts/code-index.php`. Files over 300 lines in admin/, api/, includes/, config/, js/. Line numbers are for Read offset/limit. Do not hand-edit.';
$out[] = '';
$out[] = 'Keys: fn=PHP functions, actions=$action handlers, cases=action switch cases, post=$_POST *_action, ajax=?ajax handlers, js=top-level JS, modals=modal/overlay ids, perms=hasPermission keys.';
$out[] = '';

$indexed = 0;
$sourceBytes = 0;
foreach ($files as $rel => $path) {
    $src = file_get_contents($path);
    if ($src === false) {
        continue;
    }
    $lineCount = substr_count($src, "\n") + (($src !== '' && substr($src, -1) !== "\n") ? 1 : 0);
    if ($lineCount < CI_MIN_LINES) {
        continue;
    }
    $indexed++;
    $sourceBytes += strlen($src);

    $cats = ci_index_file($rel, $src);

    $out[] = '## ' . $rel . ' (' . $lineCount . ' lines)';
    foreach (array_keys(CI_CATEGORIES) as $cat) {
        if (!empty($cats[$cat])) {
            $out[] = $cat . ': ' . implode(',', $cats[$cat]);
        }
    }
    $out[] = '';
}

$md = implode("\n", $out);
$written = strlen($md);

$dir = dirname($outFile);
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
if (file_put_contents($outFile, $md) === false) {
    fwrite(STDERR, "code-index: could not write $outFile\n");
    exit(1);
}

$note = $written > CI_TARGET_BYTES ? ' (over 60 KB target)' : '';
echo sprintf(
    "code-index: %d files indexed, index %d bytes%s, source %d bytes -> %s\n",
    $indexed,
    $written,
    $note,
    $sourceBytes,
    '.claude/CODE_INDEX.md'
);
