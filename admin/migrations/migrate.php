<?php

/**
 * Migration runner (CLI only).
 *
 * Replaces the lazy `CREATE TABLE` statements that used to sit inside application
 * code. Those ran on a normal page request, inside a try/catch, so a failure was
 * invisible — which is exactly how `room_inspections` came to be missing while the
 * code that needed it carried on silently returning empty results.
 *
 * Usage:
 *   php admin/migrations/migrate.php            # dry run — lists what would apply
 *   php admin/migrations/migrate.php --run      # applies pending migrations
 *   php admin/migrations/migrate.php --status   # shows applied/pending only
 *
 * Each migration file returns an array:
 *   ['name' => string, 'check' => fn(PDO): bool, 'up' => fn(PDO): void]
 * `check` returns true when the migration is ALREADY applied, so re-running is safe.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("migrate.php is CLI-only.\n");
}

$root = dirname(__DIR__, 2);

/**
 * Deliberately does NOT include config/database.php.
 *
 * That file runs eleven ensure*() schema functions at connection time, so merely
 * including it issues information_schema probes and DDL against whatever database
 * it resolves. A migration runner must be the only thing changing the schema when
 * it runs, so it builds its own connection from the same credentials instead.
 */
$dbCfg = ['host' => '', 'name' => '', 'user' => '', 'pass' => '', 'port' => '3306'];
$envFile = $root . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $map = ['DB_HOST' => 'host', 'DB_NAME' => 'name', 'DB_USER' => 'user', 'DB_PASS' => 'pass', 'DB_PORT' => 'port'];
        $k = trim($k);
        if (isset($map[$k])) {
            $dbCfg[$map[$k]] = trim(trim($v), "\"'");
        }
    }
}
foreach (['DB_HOST' => 'host', 'DB_NAME' => 'name', 'DB_USER' => 'user', 'DB_PASS' => 'pass', 'DB_PORT' => 'port'] as $env => $key) {
    $fromEnv = getenv($env);
    if ($dbCfg[$key] === '' && $fromEnv !== false) {
        $dbCfg[$key] = $fromEnv;
    }
}
if ($dbCfg['host'] === '' || $dbCfg['name'] === '') {
    fwrite(STDERR, "No database credentials found (.env or environment).\n");
    exit(1);
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbCfg['host'], $dbCfg['port'] ?: '3306', $dbCfg['name']),
        $dbCfg['user'],
        $dbCfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, 'Connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

printf("Database: %s@%s\n", $dbCfg['name'], $dbCfg['host']);

$apply  = in_array('--run', $argv, true);
$status = in_array('--status', $argv, true);

$files = glob(__DIR__ . '/[0-9]*.php') ?: [];
sort($files);
if (!$files) {
    echo "No migration files found.\n";
    exit(0);
}

echo $apply ? "Applying migrations\n" : "DRY RUN — nothing will be changed (pass --run to apply)\n";
echo str_repeat('-', 62) . "\n";

$pending = 0;
$applied = 0;

foreach ($files as $file) {
    $m = require $file;
    $label = basename($file);

    if (!is_array($m) || !isset($m['name'], $m['check'], $m['up'])) {
        printf("  %-40s MALFORMED — skipped\n", $label);
        continue;
    }

    try {
        $already = (bool)($m['check'])($pdo);
    } catch (Throwable $e) {
        printf("  %-40s CHECK FAILED: %s\n", $label, $e->getMessage());
        continue;
    }

    if ($already) {
        printf("  %-40s already applied\n", $label);
        continue;
    }

    $pending++;

    if (!$apply || $status) {
        printf("  %-40s PENDING\n", $label);
        continue;
    }

    try {
        ($m['up'])($pdo);

        // Record it, consistent with the rows already in migration_log.
        $nextId = (int)$pdo->query("SELECT COALESCE(MAX(migration_id), 0) + 1 FROM migration_log")->fetchColumn();
        $log = $pdo->prepare(
            "INSERT INTO migration_log (migration_id, migration_name, migration_date, status, created_at)
             VALUES (?, ?, NOW(), 'completed', NOW())"
        );
        $log->execute([$nextId, $m['name']]);

        $applied++;
        printf("  %-40s APPLIED\n", $label);
    } catch (Throwable $e) {
        printf("  %-40s FAILED: %s\n", $label, $e->getMessage());
        exit(1);
    }
}

echo str_repeat('-', 62) . "\n";
if ($apply) {
    printf("%d applied, %d pending remaining.\n", $applied, max(0, $pending - $applied));
} else {
    printf("%d pending. Re-run with --run to apply.\n", $pending);
}
