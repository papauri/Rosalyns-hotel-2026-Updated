<?php

/**
 * Admin API — Test post to Facebook Page
 * Used by the real-time test form on admin/facebook-settings.php
 */

require_once __DIR__ . '/api-init.php';
require_once __DIR__ . '/../../includes/facebook-functions.php';

/** @var array $user */
/** @var string $csrf_token */

sendSecurityHeaders();
header('Content-Type: application/json');

requireApiPermission('facebook_settings');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token.']);
    exit;
}

if (!isFacebookPostingEnabled()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Facebook posting is not enabled or credentials are missing.']);
    exit;
}

$message = trim($_POST['message'] ?? '');
if ($message === '') {
    $message = 'Test post from ' . (getSetting('site_name') ?: 'hotel admin') . '. Sent at: ' . date('Y-m-d H:i:s');
}

try {
    $result = postFacebookFeed($message);

    rh_log_event('facebook_settings', $result['success'] ? 'info' : 'warning', 'Facebook test post', [
        'success'    => $result['success'],
        'message'    => $result['message'],
        'posted_by'  => $user['username'] ?? 'unknown',
    ]);

    if ($result['success']) {
        $pageId  = getSetting('facebook_page_id', '');
        $postUrl = '';
        if ($pageId !== '' && !empty($result['post_id'])) {
            $parts   = explode('_', $result['post_id']);
            $postUrl = 'https://www.facebook.com/' . $pageId . '/posts/' . ($parts[1] ?? $result['post_id']);
        }
        echo json_encode([
            'success'  => true,
            'message'  => 'Test post published successfully.',
            'post_id'  => $result['post_id'],
            'post_url' => $postUrl,
        ]);
    } else {
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => $result['message']]);
    }
} catch (Throwable $e) {
    error_log('[admin/api/facebook-test-post] ' . $e->getMessage());
    rh_log_event('facebook', 'error', 'Exception in test post endpoint', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

