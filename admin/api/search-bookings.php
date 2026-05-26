<?php

/**
 * API Endpoint: Search Bookings
 * Provides booking search/autocomplete functionality for payment-add.php
 */

// Enable error logging for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();

// Check authentication
if (!isset($_SESSION['admin_user_id'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// DIAGNOSTIC: Log to help identify the issue
error_log("search-bookings.php: Starting - Request: " . $_SERVER['REQUEST_URI']);

require_once '../../config/database.php';
require_once '../includes/finance-schema.php';

$conferenceFields = finance_conference_fields($pdo);

// DIAGNOSTIC: Verify PDO is available
if (!isset($pdo)) {
    error_log("search-bookings.php: ERROR - PDO not defined after including database.php");
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed', 'debug' => 'PDO not defined', 'bookings' => []]);
    exit;
}
error_log("search-bookings.php: PDO loaded successfully");

header('Content-Type: application/json');

// Get request parameters
$type = $_GET['type'] ?? '';
$searchTerm = $_GET['q'] ?? '';
$recent = isset($_GET['recent']) ? (int)$_GET['recent'] : 0;

// Validate booking type
if (!in_array($type, ['room', 'conference'])) {
    echo json_encode(['error' => 'Invalid booking type', 'bookings' => []]);
    exit;
}

try {
    $bookings = [];

    if ($type === 'room') {
        // Search room bookings
        if ($recent) {
            // Get recent bookings (last 30 days)
            $stmt = $pdo->prepare("
                SELECT
                    b.id,
                    b.booking_reference,
                    b.guest_name,
                    b.guest_email,
                    b.number_of_guests,
                    b.adult_guests,
                    b.child_guests,
                    b.child_supplement_total,
                    b.check_in_date,
                    b.check_out_date,
                    b.total_amount,
                    b.amount_paid,
                    b.amount_due,
                    b.guest_phone,
                    r.name as room_name
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE b.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY b.created_at DESC
                LIMIT 10
            ");
            $stmt->execute();
        } else {
            // Search by booking reference, guest name, or ID
            $searchTerm = '%' . $searchTerm . '%';
            $stmt = $pdo->prepare("
                SELECT
                    b.id,
                    b.booking_reference,
                    b.guest_name,
                    b.guest_email,
                    b.number_of_guests,
                    b.adult_guests,
                    b.child_guests,
                    b.child_supplement_total,
                    b.check_in_date,
                    b.check_out_date,
                    b.total_amount,
                    b.amount_paid,
                    b.amount_due,
                    b.guest_phone,
                    r.name as room_name
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE (
                    b.booking_reference LIKE ?
                    OR b.guest_name LIKE ?
                    OR b.id LIKE ?
                    OR b.guest_email LIKE ?
                    OR b.guest_phone LIKE ?
                )
                ORDER BY b.check_in_date DESC
                LIMIT 20
            ");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }

        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($type === 'conference') {
        // Search conference bookings
        if ($recent) {
            // Get recent enquiries (last 30 days)
            $stmt = $pdo->prepare("
                SELECT
                    ci.id,
                    ci.{$conferenceFields['reference']} as enquiry_reference,
                    ci.{$conferenceFields['company']} as organization_name,
                    ci.{$conferenceFields['contact_name']} as contact_name,
                    ci.{$conferenceFields['email']} as contact_email,
                    ci.{$conferenceFields['start_date']} as start_date,
                    ci.{$conferenceFields['end_date']} as end_date,
                    ci.total_amount,
                    ci.amount_paid,
                    ci.amount_due,
                    ci.{$conferenceFields['phone']} as contact_phone
                FROM conference_inquiries ci
                WHERE ci.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY ci.created_at DESC
                LIMIT 10
            ");
            $stmt->execute();
        } else {
            // Search by enquiry reference, organization, contact name, or ID
            $searchTerm = '%' . $searchTerm . '%';
            $stmt = $pdo->prepare("
                SELECT
                    ci.id,
                    ci.{$conferenceFields['reference']} as enquiry_reference,
                    ci.{$conferenceFields['company']} as organization_name,
                    ci.{$conferenceFields['contact_name']} as contact_name,
                    ci.{$conferenceFields['email']} as contact_email,
                    ci.{$conferenceFields['start_date']} as start_date,
                    ci.{$conferenceFields['end_date']} as end_date,
                    ci.total_amount,
                    ci.amount_paid,
                    ci.amount_due,
                    ci.{$conferenceFields['phone']} as contact_phone
                FROM conference_inquiries ci
                WHERE (
                    ci.{$conferenceFields['reference']} LIKE ?
                    OR ci.{$conferenceFields['company']} LIKE ?
                    OR ci.{$conferenceFields['contact_name']} LIKE ?
                    OR ci.id LIKE ?
                    OR ci.{$conferenceFields['email']} LIKE ?
                    OR ci.{$conferenceFields['phone']} LIKE ?
                )
                ORDER BY ci.{$conferenceFields['start_date']} DESC
                LIMIT 20
            ");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }

        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Format dates for display
    foreach ($bookings as &$booking) {
        if (isset($booking['check_in_date'])) {
            $booking['check_in_date'] = date('M j, Y', strtotime($booking['check_in_date']));
        }
        if (isset($booking['check_out_date'])) {
            $booking['check_out_date'] = date('M j, Y', strtotime($booking['check_out_date']));
        }
        if (isset($booking['start_date'])) {
            $booking['start_date'] = date('M j, Y', strtotime($booking['start_date']));
        }
        if (isset($booking['end_date'])) {
            $booking['end_date'] = date('M j, Y', strtotime($booking['end_date']));
        }
        // Ensure numeric values
        $booking['total_amount'] = (float)($booking['total_amount'] ?? 0);
        $booking['amount_paid'] = (float)($booking['amount_paid'] ?? 0);
        $booking['amount_due'] = (float)($booking['amount_due'] ?? 0);
        if (isset($booking['number_of_guests'])) {
            $booking['number_of_guests'] = (int)$booking['number_of_guests'];
            $booking['child_guests'] = (int)($booking['child_guests'] ?? 0);
            $booking['adult_guests'] = (int)($booking['adult_guests'] ?? max(1, $booking['number_of_guests'] - $booking['child_guests']));
            $booking['child_supplement_total'] = (float)($booking['child_supplement_total'] ?? 0);
        }
    }

    echo json_encode(['success' => true, 'bookings' => $bookings]);
} catch (PDOException $e) {
    error_log("Search Bookings API Error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error', 'bookings' => []]);
}
