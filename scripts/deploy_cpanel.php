<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web

/**
 * Trigger cPanel Git Version Control to pull origin/main into the live repository.
 *
 *   php scripts/deploy_cpanel.php --check   read-only: show what cPanel has (branch, head commit)
 *   php scripts/deploy_cpanel.php           deploy: VersionControl::update (pulls the branch)
 *
 * Settings come from the project's .env (or the environment):
 *   CPANEL_HOST, CPANEL_PORT (2083), CPANEL_USER, CPANEL_REPO_PATH,
 *   CPANEL_TOKEN  - preferred: cPanel > Security > Manage API Tokens
 *   CPANEL_PASS   - fallback: account password (refused when 2FA is on)
 */

// Load the project's .env (same rules as config/database.local.php: the file wins).
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        putenv(trim($k) . '=' . $v);
    }
}

$host  = getenv('CPANEL_HOST') ?: '';
$port  = getenv('CPANEL_PORT') ?: '2083';
$user  = getenv('CPANEL_USER') ?: '';
$token = getenv('CPANEL_TOKEN') ?: '';
$pass  = getenv('CPANEL_PASS') ?: '';
$repo  = getenv('CPANEL_REPO_PATH') ?: '';
$check = in_array('--check', $argv ?? [], true);

if ($host === '' || $user === '' || ($token === '' && $pass === '') || $repo === '') {
    echo "Missing CPANEL_HOST / CPANEL_USER / CPANEL_TOKEN (or CPANEL_PASS) / CPANEL_REPO_PATH in .env" . PHP_EOL;
    exit(1);
}

function cpanel_call(string $url, string $user, string $token, string $pass, ?array $post = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
    ];
    if (is_file(__DIR__ . '/../config/cacert.pem')) {
        $opts[CURLOPT_CAINFO] = __DIR__ . '/../config/cacert.pem';
    }
    if ($token !== '') {
        $opts[CURLOPT_HTTPHEADER] = ["Authorization: cpanel {$user}:{$token}"];
    } else {
        $opts[CURLOPT_USERPWD] = "{$user}:{$pass}";
    }
    if ($post !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $err, is_string($resp) ? json_decode($resp, true) : null];
}

$base = "https://{$host}:{$port}/execute/VersionControl";
$auth = $token !== '' ? 'API token' : 'password';

if ($check) {
    [$code, $err, $data] = cpanel_call("{$base}/retrieve", $user, $token, $pass);
} else {
    [$code, $err, $data] = cpanel_call("{$base}/update", $user, $token, $pass, ['repository_root' => $repo]);
}

if ($err !== '') {
    echo "cURL error: {$err}" . PHP_EOL;
    exit(1);
}
echo "HTTP {$code} (auth: {$auth})" . PHP_EOL;
if ($code === 401 || $code === 403 || !is_array($data)) {
    echo "cPanel refused the request. Create an API token (cPanel > Security > Manage API Tokens)," . PHP_EOL
       . "put it in .env as CPANEL_TOKEN, and check CPANEL_USER / CPANEL_HOST." . PHP_EOL;
    exit(1);
}
echo "status: " . ($data['status'] ?? '?') . PHP_EOL;
foreach ((array)($data['errors'] ?? []) as $e) {
    echo "error: {$e}" . PHP_EOL;
}

if ($check) {
    $found = false;
    foreach ((array)($data['data'] ?? []) as $r) {
        if (($r['repository_root'] ?? '') !== $repo) {
            continue;
        }
        $found = true;
        echo "repo {$repo}: branch " . ($r['branch'] ?? '?')
            . ", head " . substr((string)($r['last_update']['identifier'] ?? '?'), 0, 7)
            . " '" . ($r['last_update']['message'] ?? '') . "'" . PHP_EOL;
    }
    if (!$found) {
        echo "cPanel has no repository at CPANEL_REPO_PATH={$repo}" . PHP_EOL;
        exit(1);
    }
    exit(0);
}

foreach ((array)($data['messages'] ?? []) as $msg) {
    echo 'msg: ' . (is_array($msg) ? implode(' ', $msg) : $msg) . PHP_EOL;
}
exit(($data['status'] ?? 0) == 1 ? 0 : 1);
