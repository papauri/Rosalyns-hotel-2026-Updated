<?php
/**
 * deploy.php — Trigger a cPanel Git Version Control pull on the live server.
 *
 * Usage (CLI):  php deploy.php
 * Reads CPANEL_HOST, CPANEL_PORT, CPANEL_USER, CPANEL_PASS, CPANEL_REPO_PATH from .env
 *
 * Uses the cPanel UAPI VersionControlDeployment::create endpoint to queue a
 * "git pull" on the live server, exactly as clicking "Update from Remote" in
 * the cPanel Version Control UI does.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    die("Run from CLI only: php deploy.php\n");
}

// Load .env
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

echo "Deploying to live server...\n";
echo "  Host: {$host}:{$port}\n";
echo "  Repo: {$repoPath}\n\n";

$url = "https://{$host}:{$port}/execute/VersionControlDeployment/create";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query(['repository_root' => $repoPath]),
    CURLOPT_USERPWD        => "{$user}:{$pass}",
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo "CURL error: {$curlErr}\n";
    exit(1);
}

$data = json_decode($response, true);

if ($httpCode === 200 && isset($data['status']) && $data['status'] == 1) {
    $task = $data['data']['deploy_id'] ?? $data['data']['task_id'] ?? 'queued';
    echo "Deploy queued successfully (task: {$task})\n";
    echo "The live server is now pulling from GitHub.\n";
    exit(0);
} else {
    echo "Deploy failed (HTTP {$httpCode})\n";
    echo "Response: " . ($response ?: '(empty)') . "\n";
    if (!empty($data['errors'])) {
        echo "Errors: " . implode(', ', (array)$data['errors']) . "\n";
    }
    exit(1);
}
