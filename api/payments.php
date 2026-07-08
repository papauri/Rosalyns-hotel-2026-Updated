<?php

/**
 * Payments API Endpoint
 *
 * Handles payment operations for room bookings and conference inquiries
 *
 * Endpoints:
 * - GET /api/payments - List all payments (with filters)
 * - POST /api/payments - Create a new payment
 * - GET /api/payments/{id} - Get payment details
 * - PUT /api/payments/{id} - Update payment
 * - DELETE /api/payments/{id} - Delete payment (soft delete)
 *
 * Permissions:
 * - payments.view - View payments
 * - payments.create - Create payments
 * - payments.edit - Edit payments
 * - payments.delete - Delete payments
 *
 * SECURITY: This file must only be accessed through api/index.php
 * Direct access is blocked to prevent authentication bypass
 */

// Prevent direct access - must be accessed through api/index.php router
if (!defined('API_ACCESS_ALLOWED') || !isset($auth) || !isset($client)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Direct access to this endpoint is not allowed',
        'code' => 403,
        'message' => 'Please use the API router at /api/payments'
    ]);
    exit;
}

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Extract payment ID from path if present
$pathParts = explode('/', trim($path, '/'));
$paymentId = null;
if (count($pathParts) >= 3 && is_numeric($pathParts[2])) {
    $paymentId = (int)$pathParts[2];
}

require_once __DIR__ . '/../includes/finance-sequences.php';
finance_ensure_sequence_tables($pdo);

try {
    switch ($method) {
        case 'GET':
            if ($paymentId) {
                // Get single payment
                if (!$auth->checkPermission($client, 'payments.view')) {
                    ApiResponse::error('Permission denied: payments.view', 403);
                }
                getPayment($pdo, $paymentId);
            } else {
                // List payments
                if (!$auth->checkPermission($client, 'payments.view')) {
                    ApiResponse::error('Permission denied: payments.view', 403);
                }
                listPayments($pdo);
            }
            break;

        case 'POST':
            if (!$auth->checkPermission($client, 'payments.create')) {
                ApiResponse::error('Permission denied: payments.create', 403);
            }
            createPayment($pdo);
            break;

        case 'PUT':
            if (!$paymentId) {
                ApiResponse::error('Payment ID is required for update', 400);
            }
            if (!$auth->checkPermission($client, 'payments.edit')) {
                ApiResponse::error('Permission denied: payments.edit', 403);
            }
            updatePayment($pdo, $paymentId);
            break;

        case 'DELETE':
            if (!$paymentId) {
                ApiResponse::error('Payment ID is required for deletion', 400);
            }
            if (!$auth->checkPermission($client, 'payments.delete')) {
                ApiResponse::error('Permission denied: payments.delete', 403);
            }
            deletePayment($pdo, $paymentId);
            break;

        default:
            ApiResponse::error('Method not allowed', 405);
    }
} catch (PDOException $e) {
    error_log("Payments API Database Error: " . $e->getMessage());
    ApiResponse::error('Database error occurred', 500);
} catch (Exception $e) {
    error_log("Payments API Error: " . $e->getMessage());
    ApiResponse::error('Failed to process request: ' . $e->getMessage(), 500);
}

/**
 * List all payments with optional filters
 */
function listPayments(PDO $pdo)
{
    // Get query parameters for filtering
    $bookingType = isset($_GET['booking_type']) ? trim($_GET['booking_type']) : null;
    $bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : null;
    $status = isset($_GET['status']) ? trim($_GET['status']) : null;
    $paymentMethod = isset($_GET['payment_method']) ? trim($_GET['payment_method']) : null;
    $startDate = isset($_GET['start_date']) ? trim($_GET['start_date']) : null;
    $endDate = isset($_GET['end_date']) ? trim($_GET['end_date']) : null;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
    $offset = ($page - 1) * $limit;

    if ($bookingId && !$bookingType) {
        ApiResponse::error('booking_type is required when booking_id is provided', 400);
    }

    // Build query
    // Get dynamic conference field names for compatibility
    $conferenceFields = finance_conference_fields($pdo);

    $sql = "
        SELECT
            p.*,
            CASE
                WHEN p.booking_type = 'room' THEN CONCAT('Room Booking - ', b.guest_name)
                WHEN p.booking_type = 'conference' THEN CONCAT('Conference - ', ci.{$conferenceFields['company']})
                ELSE p.booking_type
            END as booking_description,
            CASE
                WHEN p.booking_type = 'room' THEN b.booking_reference
                WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['reference']}
                ELSE NULL
            END as booking_reference,
            CASE
                WHEN p.booking_type = 'room' THEN b.guest_email
                WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['email']}
                ELSE NULL
            END as contact_email
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        WHERE p.deleted_at IS NULL
    ";

    $params = [];

    if ($bookingType) {
        $sql .= " AND p.booking_type = ?";
        $params[] = $bookingType;
    }

    if ($bookingId) {
        $sql .= " AND p.booking_id = ?";
        $params[] = $bookingId;
    }

    if ($status) {
        $sql .= " AND p.payment_status = ?";
        $params[] = $status;
    }

    if ($paymentMethod) {
        $sql .= " AND p.payment_method = ?";
        $params[] = $paymentMethod;
    }

    if ($startDate) {
        $sql .= " AND p.payment_date >= ?";
        $params[] = $startDate;
    }

    if ($endDate) {
        $sql .= " AND p.payment_date <= ?";
        $params[] = $endDate;
    }

    // Get total count — extract FROM…WHERE directly to avoid leaving non-aggregate
    // CASE expressions in the SELECT clause (which breaks strict SQL mode).
    $fromPos = stripos($sql, 'FROM payments p');
    $countSql = 'SELECT COUNT(*) as total ' . substr($sql, $fromPos);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    // Add ordering and pagination
    $sql .= " ORDER BY p.payment_date DESC, p.created_at DESC";
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate summary statistics - use consistent payment status check
    $summarySql = "
        SELECT
            COUNT(*) as total_payments,
            SUM(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END) as total_collected,
            SUM(CASE WHEN payment_status IN ('pending', 'partial', 'partially_refunded') AND COALESCE(payment_type, '') != 'refund' THEN total_amount ELSE 0 END) as total_pending,
            SUM(CASE WHEN COALESCE(payment_type, '') = 'refund' THEN COALESCE(refund_amount, total_amount) ELSE 0 END) as total_refunded,
            (
                SUM(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN vat_amount ELSE 0 END)
                - SUM(CASE WHEN COALESCE(payment_type, '') = 'refund' AND refund_status IN ('completed','processing') THEN vat_amount ELSE 0 END)
            ) as total_vat_collected
        FROM payments
        WHERE deleted_at IS NULL
    ";

    $summaryParams = [];
    if ($bookingType) {
        $summarySql .= " AND booking_type = ?";
        $summaryParams[] = $bookingType;
    }
    if ($bookingId) {
        $summarySql .= " AND booking_id = ?";
        $summaryParams[] = $bookingId;
    }
    if ($startDate) {
        $summarySql .= " AND payment_date >= ?";
        $summaryParams[] = $startDate;
    }
    if ($endDate) {
        $summarySql .= " AND payment_date <= ?";
        $summaryParams[] = $endDate;
    }

    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute($summaryParams);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    $response = [
        'payments' => array_map(function ($payment) {
            return [
                'id' => (int)$payment['id'],
                'payment_reference' => $payment['payment_reference'],
                'booking_type' => $payment['booking_type'],
                'booking_id' => (int)$payment['booking_id'],
                'booking_description' => $payment['booking_description'],
                'booking_reference' => $payment['booking_reference'],
                'contact_email' => $payment['contact_email'],
                'payment_date' => $payment['payment_date'],
                'amount' => [
                    'subtotal' => (float)$payment['payment_amount'],
                    'vat_rate' => (float)$payment['vat_rate'],
                    'vat_amount' => (float)$payment['vat_amount'],
                    'total' => (float)$payment['total_amount']
                ],
                'payment_method' => $payment['payment_method'],
                'status' => $payment['payment_status'],
                'transaction_reference' => $payment['transaction_reference'],
                'receipt_number' => $payment['receipt_number'],
                'invoice_number' => $payment['invoice_number'],
                'notes' => $payment['notes'],
                'created_at' => $payment['created_at'],
                'updated_at' => $payment['updated_at']
            ];
        }, $payments),
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ],
        'summary' => [
            'total_payments' => (int)$summary['total_payments'],
            'total_collected' => (float)$summary['total_collected'],
            'total_pending' => (float)$summary['total_pending'],
            'total_refunded' => (float)$summary['total_refunded'],
            'total_vat_collected' => (float)$summary['total_vat_collected'],
            'currency' => getSetting('currency_symbol')
        ]
    ];

    ApiResponse::success($response, 'Payments retrieved successfully');
}

/**
 * Get single payment details
 */
function getPayment(PDO $pdo, int $paymentId)
{
    $conferenceFields = finance_conference_fields($pdo);

    $stmt = $pdo->prepare("
        SELECT
            p.*,
            CASE
                WHEN p.booking_type = 'room' THEN CONCAT('Room Booking - ', b.guest_name)
                WHEN p.booking_type = 'conference' THEN CONCAT('Conference - ', ci.{$conferenceFields['company']})
                ELSE p.booking_type
            END as booking_description,
            CASE
                WHEN p.booking_type = 'room' THEN b.booking_reference
                WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['reference']}
                ELSE NULL
            END as booking_reference,
            CASE
                WHEN p.booking_type = 'room' THEN b.guest_name
                WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['contact_name']}
                ELSE NULL
            END as customer_name,
            CASE
                WHEN p.booking_type = 'room' THEN b.guest_email
                WHEN p.booking_type = 'conference' THEN ci.{$conferenceFields['email']}
                ELSE NULL
            END as customer_email
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        WHERE p.id = ? AND p.deleted_at IS NULL
    ");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        ApiResponse::error('Payment not found. It may have been deleted or does not exist.', 404);
    }

    // Get booking details
    $bookingDetails = null;
    if ($payment['booking_type'] === 'room') {
        $bookingStmt = $pdo->prepare("
            SELECT
                b.*,
                r.name as room_name,
                r.price_per_night
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.id = ?
        ");
        $bookingStmt->execute([$payment['booking_id']]);
        $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

        if ($booking) {
            $bookingDetails = [
                'type' => 'room',
                'id' => (int)$booking['id'],
                'reference' => $booking['booking_reference'],
                'room' => [
                    'id' => (int)$booking['room_id'],
                    'name' => $booking['room_name'],
                    'price_per_night' => (float)$booking['price_per_night']
                ],
                'guest' => [
                    'name' => $booking['guest_name'],
                    'email' => $booking['guest_email'],
                    'phone' => $booking['guest_phone']
                ],
                'dates' => [
                    'check_in' => $booking['check_in_date'],
                    'check_out' => $booking['check_out_date'],
                    'nights' => (int)$booking['number_of_nights']
                ],
                'amounts' => [
                    'total_amount' => (float)$booking['total_amount'],
                    'amount_paid' => (float)$booking['amount_paid'],
                    'amount_due' => (float)$booking['amount_due'],
                    'vat_rate' => (float)$booking['vat_rate'],
                    'vat_amount' => (float)$booking['vat_amount'],
                    'total_with_vat' => (float)$booking['total_with_vat']
                ],
                'status' => $booking['status']
            ];
        }
    } elseif ($payment['booking_type'] === 'conference') {
        $conferenceFields = finance_conference_fields($pdo);
        $confStmt = $pdo->prepare("
            SELECT * FROM conference_inquiries WHERE id = ?
        ");
        $confStmt->execute([$payment['booking_id']]);
        $enquiry = $confStmt->fetch(PDO::FETCH_ASSOC);

        if ($enquiry) {
            $bookingDetails = [
                'type' => 'conference',
                'id' => (int)$enquiry['id'],
                'reference' => $enquiry[$conferenceFields['reference']] ?? '',
                'organization' => [
                    'name' => $enquiry[$conferenceFields['company']] ?? '',
                    'contact_person' => $enquiry[$conferenceFields['contact_name']] ?? '',
                    'email' => $enquiry[$conferenceFields['email']] ?? '',
                    'phone' => $enquiry[$conferenceFields['phone']] ?? ''
                ],
                'event' => [
                    'type' => $enquiry['event_type'],
                    'start_date' => $enquiry[$conferenceFields['start_date']] ?? null,
                    'end_date' => $enquiry[$conferenceFields['end_date']] ?? null,
                    'expected_attendees' => (int)($enquiry[$conferenceFields['expected_attendees']] ?? 0)
                ],
                'amounts' => [
                    'total_amount' => (float)$enquiry['total_amount'],
                    'amount_paid' => (float)$enquiry['amount_paid'],
                    'amount_due' => (float)$enquiry['amount_due'],
                    'vat_rate' => (float)$enquiry['vat_rate'],
                    'vat_amount' => (float)$enquiry['vat_amount'],
                    'total_with_vat' => (float)$enquiry['total_with_vat'],
                    'deposit_required' => (float)$enquiry['deposit_required'],
                    'deposit_amount' => (float)$enquiry['deposit_amount'],
                    'deposit_paid' => (float)$enquiry['deposit_paid']
                ],
                'status' => $enquiry['status']
            ];
        }
    }

    $response = [
        'payment' => [
            'id' => (int)$payment['id'],
            'payment_reference' => $payment['payment_reference'],
            'booking_type' => $payment['booking_type'],
            'booking_id' => (int)$payment['booking_id'],
            'booking_details' => $bookingDetails,
            'payment_date' => $payment['payment_date'],
            'amount' => [
                'subtotal' => (float)$payment['payment_amount'],
                'vat_rate' => (float)$payment['vat_rate'],
                'vat_amount' => (float)$payment['vat_amount'],
                'total' => (float)$payment['total_amount']
            ],
            'payment_method' => $payment['payment_method'],
            'status' => $payment['payment_status'],
            'transaction_reference' => $payment['transaction_reference'],
            'receipt_number' => $payment['receipt_number'],
            'processed_by' => $payment['processed_by'],
            'notes' => $payment['notes'],
            'created_at' => $payment['created_at'],
            'updated_at' => $payment['updated_at']
        ]
    ];

    ApiResponse::success($response, 'Payment details retrieved successfully');
}

/**
 * Create a new payment
 */
function createPayment(PDO $pdo)
{
    require_once __DIR__ . '/../includes/idempotency.php';
    // Get request body
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!$input) {
        ApiResponse::error('Invalid JSON request body', 400);
    }

    // Idempotency: API clients pass `client_uuid`. Replays return the original payment
    // record — critical for offline-queue replays and retry-on-timeout integrations.
    $__incomingClientUuid = isset($input['client_uuid']) ? (string)$input['client_uuid'] : null;
    if ($__existingPayment = idem_find_existing_payment($pdo, $__incomingClientUuid)) {
        ApiResponse::success([
            'payment_id' => (int)$__existingPayment['id'],
            'payment_reference' => $__existingPayment['payment_reference'],
            'idempotent_replay' => true,
        ], 'Payment already exists for this client_uuid', 200);
    }

    // Validate required fields
    $requiredFields = [
        'booking_type',
        'booking_id',
        'payment_amount',
        'payment_method',
        'payment_status'
    ];

    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            $missingFields[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
        }
    }

    if (!empty($missingFields)) {
        ApiResponse::validationError($missingFields);
    }

    // Validate booking type
    if (!in_array($input['booking_type'], ['room', 'conference'])) {
        ApiResponse::validationError(['booking_type' => 'Must be either "room" or "conference"']);
    }

    // Validate payment method
    $validMethods = ['cash', 'bank_transfer', 'credit_card', 'debit_card', 'mobile_money', 'cheque', 'other'];
    if (!in_array($input['payment_method'], $validMethods)) {
        ApiResponse::validationError(['payment_method' => 'Invalid payment method']);
    }

    // Validate payment status
    // 'paid' and 'partial' can be set by the POS sync internally; allow them in API too for consistency.
    $validStatuses = ['pending', 'partial', 'completed', 'paid', 'failed', 'refunded', 'partially_refunded'];
    if (!in_array($input['payment_status'], $validStatuses)) {
        ApiResponse::validationError(['payment_status' => 'Invalid payment status']);
    }

    // Get VAT settings
    $vatEnabled = getSetting('vat_enabled') === '1';
    $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;

    // Validate payment amount
    $paymentAmount = (float)$input['payment_amount'];
    if ($paymentAmount <= 0) {
        ApiResponse::validationError(['payment_amount' => 'Payment amount must be greater than zero']);
    }

    // payment_amount is the gross (VAT-inclusive) amount — extract VAT portion.
    $vatRate = isset($input['vat_rate']) ? (float)$input['vat_rate'] : $vatRate;
    $vatAmount = $vatRate > 0 ? round($paymentAmount * ($vatRate / (100 + $vatRate)), 2) : 0.0;
    $totalAmount = $paymentAmount;

    // Validate booking exists
    if ($input['booking_type'] === 'room') {
        $bookingStmt = $pdo->prepare("SELECT id, status FROM bookings WHERE id = ?");
        $bookingStmt->execute([(int)$input['booking_id']]);
        if (!$bookingStmt->fetch()) {
            ApiResponse::error('Room booking not found', 404);
        }
    } else {
        $enquiryStmt = $pdo->prepare("SELECT id, status FROM conference_inquiries WHERE id = ?");
        $enquiryStmt->execute([(int)$input['booking_id']]);
        if (!$enquiryStmt->fetch()) {
            ApiResponse::error('Conference enquiry not found', 404);
        }
    }

    // Generate unique payment reference
    do {
        $paymentRef = 'PAY' . date('Ym') . strtoupper(substr(uniqid(), -6));
        $refCheck = $pdo->prepare("SELECT COUNT(*) as count FROM payments WHERE payment_reference = ?");
        $refCheck->execute([$paymentRef]);
        $refExists = $refCheck->fetch(PDO::FETCH_ASSOC)['count'] > 0;
    } while ($refExists);

    // Start transaction
    $pdo->beginTransaction();

    try {
        $paymentDate = isset($input['payment_date']) ? $input['payment_date'] : date('Y-m-d');
        $receiptNumber = in_array($input['payment_status'], ['completed', 'paid'], true)
            ? finance_next_receipt_number($pdo, $paymentDate)
            : null;

        // Insert payment
        $insertStmt = $pdo->prepare("
            INSERT INTO payments (
                payment_reference, booking_type, booking_id, payment_date,
                payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_status, transaction_reference,
                receipt_number, processed_by, notes, client_uuid
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $__paymentClientUuid = idem_normalize_uuid($__incomingClientUuid ?? null);
        $insertStmt->execute([
            $paymentRef,
            $input['booking_type'],
            (int)$input['booking_id'],
            $paymentDate,
            $paymentAmount,
            $vatRate,
            $vatAmount,
            $totalAmount,
            $input['payment_method'],
            $input['payment_status'],
            isset($input['transaction_reference']) ? trim($input['transaction_reference']) : null,
            $receiptNumber,
            isset($input['processed_by']) ? trim($input['processed_by']) : null,
            isset($input['notes']) ? trim($input['notes']) : null,
            $__paymentClientUuid
        ]);

        $paymentId = $pdo->lastInsertId();

        // Update booking payment totals
        if ($input['booking_type'] === 'room') {
            updateRoomBookingPayments($pdo, (int)$input['booking_id']);
        } else {
            updateConferenceEnquiryPayments($pdo, (int)$input['booking_id']);
        }

        $pdo->commit();

        // Send receipt email automatically for completed/paid payments
        if (in_array($input['payment_status'], ['completed', 'paid'], true)) {
            try {
                require_once __DIR__ . '/../config/receipts.php';
                receipt_auto_send($pdo, (int)$paymentId, null);
            } catch (Throwable $receiptEx) {
                error_log('Auto receipt email failed for payment ' . $paymentId . ': ' . $receiptEx->getMessage());
            }
        }

        // Fetch created payment
        $fetchStmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $fetchStmt->execute([$paymentId]);
        $payment = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        $response = [
            'payment' => [
                'id' => (int)$payment['id'],
                'payment_reference' => $payment['payment_reference'],
                'receipt_number' => $payment['receipt_number'],
                'booking_type' => $payment['booking_type'],
                'booking_id' => (int)$payment['booking_id'],
                'amount' => [
                    'subtotal' => (float)$payment['payment_amount'],
                    'vat_rate' => (float)$payment['vat_rate'],
                    'vat_amount' => (float)$payment['vat_amount'],
                    'total' => (float)$payment['total_amount']
                ],
                'payment_method' => $payment['payment_method'],
                'status' => $payment['payment_status'],
                'created_at' => $payment['created_at']
            ]
        ];

        ApiResponse::success($response, 'Payment created successfully', 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Update an existing payment
 */
function updatePayment(PDO $pdo, int $paymentId)
{
    // Check if payment exists
    $checkStmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND deleted_at IS NULL");
    $checkStmt->execute([$paymentId]);
    $existingPayment = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingPayment) {
        ApiResponse::error('Payment not found. It may have been deleted or does not exist.', 404);
    }

    // Get request body
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!$input) {
        ApiResponse::error('Invalid JSON request body', 400);
    }

    // Build update data
    $updateFields = [];
    $params = [];

    $allowedFields = [
        'payment_date',
        'payment_amount',
        'payment_method',
        'payment_status',
        'transaction_reference',
        'notes',
        'processed_by'
    ];

    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updateFields[] = "$field = ?";
            $params[] = $input[$field];
        }
    }

    if (empty($updateFields)) {
        ApiResponse::error('No valid fields to update', 400);
    }

    // Recalculate VAT if amount changed
    if (isset($input['payment_amount'])) {
        $newAmount = (float)$input['payment_amount'];
        $vatRate = isset($input['vat_rate']) ? (float)$input['vat_rate'] : (float)$existingPayment['vat_rate'];
        $vatAmount = $vatRate > 0 ? round($newAmount * ($vatRate / (100 + $vatRate)), 2) : 0.0;
        $totalAmount = $newAmount; // gross = what was entered

        $updateFields[] = "vat_rate = ?";
        $params[] = $vatRate;
        $updateFields[] = "vat_amount = ?";
        $params[] = $vatAmount;
        $updateFields[] = "total_amount = ?";
        $params[] = $totalAmount;
    }

    $needsReceiptNumber = isset($input['payment_status'])
        && in_array($input['payment_status'], ['completed', 'paid'], true)
        && !in_array((string)$existingPayment['payment_status'], ['completed', 'paid'], true)
        && !$existingPayment['receipt_number'];

    // Start transaction
    $pdo->beginTransaction();

    try {
        if ($needsReceiptNumber) {
            $updateFields[] = "receipt_number = ?";
            $params[] = finance_next_receipt_number($pdo, isset($input['payment_date']) ? (string)$input['payment_date'] : date('Y-m-d'));
        }

        $params[] = $paymentId;

        $sql = "UPDATE payments SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Update booking payment totals
        if ($existingPayment['booking_type'] === 'room') {
            updateRoomBookingPayments($pdo, $existingPayment['booking_id']);
        } elseif ($existingPayment['booking_type'] === 'conference') {
            updateConferenceEnquiryPayments($pdo, $existingPayment['booking_id']);
        }

        $pdo->commit();

        // Fetch updated payment
        $fetchStmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $fetchStmt->execute([$paymentId]);
        $payment = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        $response = [
            'payment' => [
                'id' => (int)$payment['id'],
                'payment_reference' => $payment['payment_reference'],
                'receipt_number' => $payment['receipt_number'],
                'amount' => [
                    'subtotal' => (float)$payment['payment_amount'],
                    'vat_rate' => (float)$payment['vat_rate'],
                    'vat_amount' => (float)$payment['vat_amount'],
                    'total' => (float)$payment['total_amount']
                ],
                'payment_method' => $payment['payment_method'],
                'status' => $payment['payment_status'],
                'updated_at' => $payment['updated_at']
            ]
        ];

        ApiResponse::success($response, 'Payment updated successfully');
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Soft delete a payment
 */
function deletePayment(PDO $pdo, int $paymentId)
{
    // Check if payment exists
    $checkStmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND deleted_at IS NULL");
    $checkStmt->execute([$paymentId]);
    $payment = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        ApiResponse::error('Payment not found. It may have been deleted or does not exist.', 404);
    }

    // Start transaction
    $pdo->beginTransaction();

    try {
        // Soft delete
        $stmt = $pdo->prepare("UPDATE payments SET deleted_at = NOW() WHERE id = ?");
        $stmt->execute([$paymentId]);

        // Update booking payment totals
        if ($payment['booking_type'] === 'room') {
            updateRoomBookingPayments($pdo, $payment['booking_id']);
        } elseif ($payment['booking_type'] === 'conference') {
            updateConferenceEnquiryPayments($pdo, $payment['booking_id']);
        }

        $pdo->commit();

        ApiResponse::success(['id' => $paymentId], 'Payment deleted successfully');
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Update room booking payment totals
 */
function updateRoomBookingPayments(PDO $pdo, int $bookingId)
{
    if (function_exists('recalculateBookingFinancials')) {
        recalculateBookingFinancials($bookingId);
        return;
    }

    // Get booking total
    $bookingStmt = $pdo->prepare("SELECT total_amount FROM bookings WHERE id = ?");
    $bookingStmt->execute([$bookingId]);
    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        return;
    }

    $totalAmount = (float)$booking['total_amount'];

    // Calculate paid amounts
    $paidStmt = $pdo->prepare("
        SELECT
            SUM(CASE
                WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount
                WHEN COALESCE(payment_type, '') = 'refund' AND refund_status IN ('completed','processing') THEN -COALESCE(refund_amount, total_amount)
                ELSE 0
            END) as paid,
            SUM(CASE
                WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN vat_amount
                WHEN COALESCE(payment_type, '') = 'refund' AND refund_status IN ('completed','processing') THEN -vat_amount
                ELSE 0
            END) as vat_paid
        FROM payments
        WHERE booking_type = 'room'
        AND booking_id = ?
        AND deleted_at IS NULL
    ");
    $paidStmt->execute([$bookingId]);
    $paid = $paidStmt->fetch(PDO::FETCH_ASSOC);

    $amountPaid = (float)($paid['paid'] ?? 0);
    $vatPaid = (float)($paid['vat_paid'] ?? 0);
    $amountDue = max(0, $totalAmount - $amountPaid);

    // Get last payment date
    $lastPaymentStmt = $pdo->prepare("
        SELECT MAX(payment_date) as last_payment_date
        FROM payments
        WHERE booking_type = 'room'
        AND booking_id = ?
        AND payment_status IN ('completed', 'paid')
        AND COALESCE(payment_type, '') != 'refund'
        AND deleted_at IS NULL
    ");
    $lastPaymentStmt->execute([$bookingId]);
    $lastPayment = $lastPaymentStmt->fetch(PDO::FETCH_ASSOC);

    // VAT per installation mode (exclusive on top / inclusive extracted / off).
    $vatParts = vat_components($totalAmount);
    $vatRate = $vatParts['rate'];
    $vatAmount = $vatParts['vat'];
    $totalWithVat = $vatParts['total'];

    // Update booking
    $updateStmt = $pdo->prepare("
        UPDATE bookings
        SET amount_paid = ?,
            amount_due = ?,
            vat_rate = ?,
            vat_amount = ?,
            total_with_vat = ?,
            last_payment_date = ?
        WHERE id = ?
    ");
    $updateStmt->execute([
        $amountPaid,
        $amountDue,
        $vatRate,
        $vatAmount,
        $totalWithVat,
        $lastPayment['last_payment_date'],
        $bookingId
    ]);
}

/**
 * Update conference enquiry payment totals
 */
function updateConferenceEnquiryPayments(PDO $pdo, int $enquiryId)
{
    // Delegated to the single source of truth. The previous implementation here
    // computed amount_due against the NET total_amount (understating balances
    // when VAT is exclusive) and re-based total_with_vat to the current rate.
    // syncConferenceInquiryPaymentSnapshot uses the gross/locked model shared
    // with rooms/gym/events so every collection path produces identical balances.
    require_once __DIR__ . '/../admin/includes/finance-account-sync.php';
    syncConferenceInquiryPaymentSnapshot($pdo, $enquiryId);
}
