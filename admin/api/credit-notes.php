<?php

/**
 * API — Credit Notes (admin-side)
 *
 * Actions:
 *   get_history    — fetch redemption history for a CN
 *   search_booking — search room/conference bookings for the apply modal
 */

require_once __DIR__ . '/api-init.php';
/** @var array $user */
/** @var PDO $pdo */
header('Content-Type: application/json');

requireApiPermission('invoices');

$action = trim($_GET['action'] ?? '');

// ── POST actions (regenerate_pdf, resend_email) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $postAction = trim((string)($body['action'] ?? ''));
    if (!validateCsrfToken((string)($body['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.', 'code' => 403]);
        exit;
    }
    require_once __DIR__ . '/../../config/credit-notes.php';
    try {
        if ($postAction === 'regenerate_pdf') {
            $cnId = (int)($body['credit_note_id'] ?? 0);
            if ($cnId <= 0) {
                throw new RuntimeException('Invalid credit_note_id.');
            }
            $result = generateCreditNotePDF($pdo, $cnId);
            if (!$result) {
                throw new RuntimeException('PDF generation failed.');
            }
            echo json_encode(['success' => true, 'message' => 'PDF regenerated.']);
            exit;
        }
        if ($postAction === 'resend_email') {
            $cnId = (int)($body['credit_note_id'] ?? 0);
            if ($cnId <= 0) {
                throw new RuntimeException('Invalid credit_note_id.');
            }
            $result = sendCreditNoteEmail($pdo, $cnId);
            if (!$result['success']) {
                throw new RuntimeException($result['message'] ?? 'Email failed.');
            }
            echo json_encode(['success' => true, 'message' => $result['message']]);
            exit;
        }
        throw new RuntimeException('Unknown POST action.');
    } catch (Throwable $e) {
        error_log('[api/credit-notes POST] ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => 400]);
        exit;
    }
}

try {
    // ── get_history ──────────────────────────────────────────────────────────
    if ($action === 'get_history') {
        $cnId = (int)($_GET['credit_note_id'] ?? 0);
        if ($cnId <= 0) {
            throw new RuntimeException('Invalid credit_note_id.');
        }

        $cn = $pdo->prepare("SELECT id, credit_note_number, original_amount, amount_used, balance, status FROM credit_notes WHERE id = ?");
        $cn->execute([$cnId]);
        $cn = $cn->fetch(PDO::FETCH_ASSOC);
        if (!$cn) {
            throw new RuntimeException('Credit note not found.');
        }

        $appStmt = $pdo->prepare("
            SELECT cna.*, au.full_name AS applied_by_name
            FROM credit_note_applications cna
            LEFT JOIN admin_users au ON au.id = cna.applied_by
            WHERE cna.credit_note_id = ?
            ORDER BY cna.applied_at ASC
        ");
        $appStmt->execute([$cnId]);
        $apps = $appStmt->fetchAll(PDO::FETCH_ASSOC);

        $currencySymbol = getSetting('currency_symbol') ?: 'MWK';

        // Format for JS
        $formatted = array_map(function (array $a) use ($currencySymbol): array {
            return [
                'id'                           => (int)$a['id'],
                'applied_at'                   => date('d M Y', strtotime((string)$a['applied_at'])),
                'applied_to_booking_reference' => htmlspecialchars((string)($a['applied_to_booking_reference'] ?? '')),
                'applied_to_booking_type'      => htmlspecialchars((string)$a['applied_to_booking_type']),
                'amount_applied'               => htmlspecialchars($currencySymbol) . ' ' . number_format((float)$a['amount_applied'], 2),
                'applied_by_name'              => htmlspecialchars((string)($a['applied_by_name'] ?? 'Admin')),
            ];
        }, $apps);

        echo json_encode([
            'success' => true,
            'data'    => [
                'credit_note'  => [
                    'number'          => $cn['credit_note_number'],
                    'original_amount' => (float)$cn['original_amount'],
                    'amount_used'     => (float)$cn['amount_used'],
                    'balance'         => (float)$cn['balance'],
                    'status'          => $cn['status'],
                ],
                'applications' => $formatted,
            ],
        ]);
        exit;
    }

    // ── search_booking ───────────────────────────────────────────────────────
    if ($action === 'search_booking') {
        $q           = trim($_GET['q']            ?? '');
        $bookingType = trim($_GET['booking_type'] ?? 'room');

        if (mb_strlen($q) < 2) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }
        if (!in_array($bookingType, ['room', 'conference'], true)) {
            throw new RuntimeException('Invalid booking type.');
        }

        $like            = '%' . $q . '%';
        $currencySymbol  = getSetting('currency_symbol') ?: 'MWK';
        $results         = [];

        if ($bookingType === 'room') {
            $stmt = $pdo->prepare("
                SELECT id, booking_reference, guest_name, guest_email, guest_phone,
                       amount_due, check_in_date, check_out_date, status
                FROM bookings
                WHERE status NOT IN ('cancelled','expired','no-show')
                  AND (booking_reference LIKE ? OR guest_name LIKE ? OR guest_email LIKE ? OR guest_phone LIKE ?)
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$like, $like, $like, $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $results[] = [
                    'id'         => (int)$row['id'],
                    'reference'  => htmlspecialchars((string)$row['booking_reference']),
                    'guest_name' => htmlspecialchars((string)$row['guest_name']),
                    'guest_email' => htmlspecialchars((string)$row['guest_email']),
                    'balance'    => htmlspecialchars($currencySymbol) . ' ' . number_format((float)$row['amount_due'], 2),
                    'meta'       => htmlspecialchars(date('d M Y', strtotime((string)$row['check_in_date'])) . ' → ' . date('d M Y', strtotime((string)$row['check_out_date']))),
                ];
            }
        } else {
            // Conference — try common column names gracefully
            try {
                $stmt = $pdo->prepare("
                    SELECT id,
                           COALESCE(reference_number, enquiry_reference, id) AS booking_reference,
                           COALESCE(company_name, contact_name, id)          AS guest_name,
                           COALESCE(email, contact_email, '')                 AS guest_email,
                           COALESCE(amount_due, 0)                           AS amount_due
                    FROM conference_inquiries
                    WHERE (
                        COALESCE(reference_number,'') LIKE ?
                        OR COALESCE(company_name,'') LIKE ?
                        OR COALESCE(email,'') LIKE ?
                        OR COALESCE(enquiry_reference,'') LIKE ?
                        OR COALESCE(contact_name,'') LIKE ?
                    )
                    ORDER BY id DESC
                    LIMIT 10
                ");
                $stmt->execute([$like, $like, $like, $like, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $results[] = [
                        'id'         => (int)$row['id'],
                        'reference'  => htmlspecialchars((string)$row['booking_reference']),
                        'guest_name' => htmlspecialchars((string)$row['guest_name']),
                        'guest_email' => htmlspecialchars((string)($row['guest_email'] ?? '')),
                        'balance'    => htmlspecialchars($currencySymbol) . ' ' . number_format((float)$row['amount_due'], 2),
                        'meta'       => '',
                    ];
                }
            } catch (Throwable $ignored) {
                // Table or column may not exist in this project — return empty
            }
        }

        echo json_encode(['success' => true, 'data' => $results]);
        exit;
    }

    // ── lookup ───────────────────────────────────────────────────────────────
    if ($action === 'lookup') {
        $cnNumber = trim($_GET['cn_number'] ?? '');
        if ($cnNumber === '') {
            throw new RuntimeException('cn_number is required.');
        }
        $row = $pdo->prepare("SELECT id, credit_note_number, balance, expires_at, status FROM credit_notes WHERE credit_note_number = ?");
        $row->execute([$cnNumber]);
        $row = $row->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Credit note not found.', 'code' => 404]);
            exit;
        }
        if (!in_array($row['status'], ['active', 'partially_applied'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Credit note is ' . $row['status'] . '.', 'code' => 400]);
            exit;
        }
        echo json_encode(['success' => true, 'data' => [
            'id'         => (int)$row['id'],
            'balance'    => (float)$row['balance'],
            'expires_at' => $row['expires_at'] ? date('d M Y', strtotime((string)$row['expires_at'])) : null,
            'status'     => $row['status'],
        ]]);
        exit;
    }

    // ── view_pdf ─────────────────────────────────────────────────────────────
    if ($action === 'view_pdf') {
        $cnId = (int)($_GET['id'] ?? 0);
        if ($cnId <= 0) {
            throw new RuntimeException('Invalid id.');
        }
        $row = $pdo->prepare("SELECT credit_note_number, pdf_path, pdf_generated FROM credit_notes WHERE id = ?");
        $row->execute([$cnId]);
        $row = $row->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Credit note not found.');
        }

        $pdfRelative = (string)($row['pdf_path'] ?? '');
        // Strip any .html fallback suffix so we attempt the real .pdf path
        $pdfRelative = preg_replace('/\.html$/', '', $pdfRelative);
        $fullPath = realpath(__DIR__ . '/../../' . ltrim($pdfRelative, '/'));

        if (!$fullPath || !is_file($fullPath) || pathinfo($fullPath, PATHINFO_EXTENSION) !== 'pdf') {
            // Try to regenerate
            require_once __DIR__ . '/../../config/credit-notes.php';
            $result = generateCreditNotePDF($pdo, $cnId);
            if (!$result) {
                throw new RuntimeException('PDF not available and regeneration failed.');
            }
            $fullPath = (string)($result['pdf_path'] ?? '');
        }

        if (!is_file((string)$fullPath)) {
            throw new RuntimeException('PDF file not found on disk.');
        }

        // Stream PDF — remove the JSON header and send binary
        header_remove('Content-Type');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename((string)$fullPath) . '"');
        header('Content-Length: ' . filesize((string)$fullPath));
        header('Cache-Control: private, max-age=300');
        readfile((string)$fullPath);
        exit;
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    error_log('[api/credit-notes] ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => 400]);
}

