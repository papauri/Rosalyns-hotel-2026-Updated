<?php
/**
 * Migration 010: Booking Timeline Logs Table
 * Tracks all booking activities from creation to completion
 */

require_once __DIR__ . '/../../config/database.php';

try {
    // Create booking_timeline_logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS booking_timeline_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT UNSIGNED NOT NULL,
            booking_reference VARCHAR(50) NOT NULL,
            action VARCHAR(100) NOT NULL,
            action_type ENUM('create', 'update', 'status_change', 'payment', 'cancellation', 'email', 'check_in', 'check_out', 'conversion', 'reminder', 'expiry', 'note') NOT NULL,
            description TEXT,
            old_value TEXT,
            new_value TEXT,
            performed_by_type ENUM('guest', 'admin', 'system', 'api') NOT NULL DEFAULT 'system',
            performed_by_id INT NULL,
            performed_by_name VARCHAR(255) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            metadata JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_booking_id (booking_id),
            INDEX idx_booking_reference (booking_reference),
            INDEX idx_action_type (action_type),
            INDEX idx_created_at (created_at),
            INDEX idx_performed_by (performed_by_type, performed_by_id),
            FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    
    // Create cancellation_log table (for detailed cancellation tracking with payment reconciliation)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cancellation_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT UNSIGNED NOT NULL,
            booking_reference VARCHAR(50) NOT NULL,
            booking_type ENUM('room', 'conference') NOT NULL DEFAULT 'room',
            guest_email VARCHAR(254) NOT NULL,
            guest_name VARCHAR(255) NOT NULL,
            cancelled_by_type ENUM('guest', 'admin', 'system') NOT NULL DEFAULT 'admin',
            cancelled_by_id INT NULL,
            cancelled_by_name VARCHAR(255) NULL,
            cancellation_reason TEXT,
            cancellation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            -- Payment reconciliation fields
            total_amount DECIMAL(12, 2) DEFAULT 0.00,
            amount_paid DECIMAL(12, 2) DEFAULT 0.00,
            refund_amount DECIMAL(12, 2) DEFAULT 0.00,
            refund_status ENUM('not_required', 'pending', 'processing', 'completed', 'failed') DEFAULT 'not_required',
            refund_method VARCHAR(50) NULL,
            refund_reference VARCHAR(100) NULL,
            refund_processed_at TIMESTAMP NULL,
            refund_processed_by INT NULL,
            
            -- Notification tracking
            email_sent TINYINT(1) DEFAULT 0,
            email_status VARCHAR(255) NULL,
            whatsapp_sent TINYINT(1) DEFAULT 0,
            
            -- Metadata
            ip_address VARCHAR(45) NULL,
            metadata JSON NULL,
            
            INDEX idx_booking_id (booking_id),
            INDEX idx_booking_reference (booking_reference),
            INDEX idx_cancellation_date (cancellation_date),
            INDEX idx_refund_status (refund_status),
            FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    
    // Create booking_payments table for payment tracking
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS booking_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT UNSIGNED NOT NULL,
            booking_reference VARCHAR(50) NOT NULL,
            payment_type ENUM('deposit', 'full', 'partial', 'refund') NOT NULL,
            amount DECIMAL(12, 2) NOT NULL,
            currency VARCHAR(10) DEFAULT 'MWK',
            payment_method VARCHAR(50) NOT NULL,
            payment_reference VARCHAR(100) NULL,
            payment_status ENUM('pending', 'processing', 'completed', 'failed', 'cancelled', 'refunded') DEFAULT 'pending',
            payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_by INT NULL,
            notes TEXT NULL,
            metadata JSON NULL,
            
            INDEX idx_booking_id (booking_id),
            INDEX idx_booking_reference (booking_reference),
            INDEX idx_payment_status (payment_status),
            INDEX idx_payment_date (payment_date),
            FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    
    echo "Migration 010 completed: booking_timeline_logs, cancellation_log, and booking_payments tables created successfully.\n";
    
} catch (PDOException $e) {
    echo "Migration 010 failed: " . $e->getMessage() . "\n";
    exit(1);
}