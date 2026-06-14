<?php
/**
 * deploy.php — Push live: fetch from GitHub then deploy on the cPanel server.
 *
 * Mirrors the two-button flow in cPanel Version Control:
 *   1. "Update from Remote"  → VersionControl::update   (git fetch from GitHub)
 *   2. "Deploy HEAD Commit"  → VersionControlDeployment::create (copy to public_html)
 *
 * Usage: php deploy.php
 * Credentials read from .env (CPANEL_HOST / CPANEL_PORT / CPANEL_USER / CPANEL_PASS / CPANEL_REPO_PATH)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    die("Run from CLI only: php deploy.php\n");
}

// ── Load .env ────────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) die(".env not found\n");
foreach (file($envFile) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    if (str_contains($line, '=')) {
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

$host     = $_ENV['CPANEL_HOST']      ?? '';
$port     = $_ENV['CPANEL_PORT']      ?? '2083';
$user     = $_ENV['CPANEL_USER']      ?? '';
$pass     = $_ENV['CPANEL_PASS']      ?? '';
$repoPath = $_ENV['CPANEL_REPO_PATH'] ?? '';

if (!$host || !$user || !$pass || !$repoPath) {
    die("Missing CPANEL_HOST / CPANEL_USER / CPANEL_PASS / CPANEL_REPO_PATH in .env\n");
}

echo "=== Live deploy: {$host} ===\n";
echo "Repo: {$repoPath}\n\n";

// ── Helper: one cPanel UAPI call ─────────────────────────────────────────────
function cpanel_call(string $host, string $port, string $user, string $pass, string $module, string $func, array $params = []): array
{
    $url = "https://{$host}:{$port}/execute/{$module}/{$func}";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_USERPWD        => "{$user}:{$pass}",
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['ok' => false, 'error' => "CURL: {$curlErr}", 'http' => 0];
    $data = json_decode($response, true) ?? [];
    $ok   = $httpCode === 200 && ($data['status'] ?? 0) == 1;
    return ['ok' => $ok, 'http' => $httpCode, 'data' => $data, 'raw' => $response];
}

// ── Step 1: Update from Remote (git fetch GitHub → server) ───────────────────
echo "[1/2] Fetching from GitHub (Update from Remote)... ";
$fetch = cpanel_call($host, $port, $user, $pass, 'VersionControl', 'update', [
    'repository_root' => $repoPath,
]);
if (!$fetch['ok']) {
    echo "FAILED\n";
    echo "HTTP {$fetch['http']}: " . ($fetch['raw'] ?? '') . "\n";
    exit(1);
}
echo "OK\n";

// ── Step 2: Deploy HEAD commit (copy files to public_html) ───────────────────
echo "[2/2] Deploying HEAD commit... ";
$deploy = cpanel_call($host, $port, $user, $pass, 'VersionControlDeployment', 'create', [
    'repository_root' => $repoPath,
]);
if (!$deploy['ok']) {
    echo "FAILED\n";
    echo "HTTP {$deploy['http']}: " . ($deploy['raw'] ?? '') . "\n";
    exit(1);
}
echo "OK\n\n";

echo "Live server is up to date.\n";
exit(0);

