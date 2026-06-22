<?php
declare(strict_types=1);

$host = getenv('CPANEL_HOST');
$port = getenv('CPANEL_PORT') ?: '2083';
$user = getenv('CPANEL_USER');
$pass = getenv('CPANEL_PASS');
$repo = getenv('CPANEL_REPO_PATH');

if (!$host || !$user || !$pass || !$repo) {
    echo "Missing CPANEL_HOST / CPANEL_USER / CPANEL_PASS / CPANEL_REPO_PATH env vars." . PHP_EOL;
    exit(1);
}

$url  = "https://{$host}:{$port}/execute/VersionControl/update";
$body = http_build_query(['repository_root' => $repo]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => "{$user}:{$pass}",
    CURLOPT_CAINFO         => __DIR__ . '/../config/cacert.pem',
    CURLOPT_TIMEOUT        => 60,
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) {
    echo "cURL error: {$err}" . PHP_EOL;
    exit(1);
}

$data = json_decode($resp, true);
echo "HTTP: {$httpCode}" . PHP_EOL;
echo "status: " . ($data['status'] ?? '?') . PHP_EOL;
if (!empty($data['errors'])) {
    echo "errors: " . implode('; ', $data['errors']) . PHP_EOL;
}
if (!empty($data['messages'])) {
    foreach ($data['messages'] as $msg) {
        if (is_array($msg)) {
            echo "msg: " . implode(' ', $msg) . PHP_EOL;
        } else {
            echo "msg: {$msg}" . PHP_EOL;
        }
    }
}
exit(($data['status'] ?? 0) === 1 ? 0 : 1);
