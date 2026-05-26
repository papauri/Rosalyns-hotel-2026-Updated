<?php
/**
 * Migration 011: Fix Production Schema Issues
 * - Create missing blocked_dates table
 * - Create missing conference_bookings table
 * - Fix cancellation_log.booking_id type mismatch
 */

require_once __DIR__ . '/../../config/database.php';

try {
    echo "Starting production schema fixes...\n\n";
    
    // 1. Create blocked_dates table if not exists
    echo "Creating blocked_dates table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS blocked_dates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            room_id INT UNSIGNED NULL,
            block_date DATE NOT NULL,
            reason VARCHAR(255) NULL,
            blocked_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_room_id (room_id),
            INDEX idx_block_date (block_date),
            INDEX idx_room_date (room_id, block_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "  blocked_dates table created.\n";
    
    // 2. Create conference_bookings table if not exists
    echo "Creating conference_bookings table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conference_bookings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_reference VARCHAR(50) NOT NULL,
            conference_room_id INT UNSIGNED NULL,
            organization_name VARCHAR(255) NOT NULL,
            contact_name VARCHAR(255) NOT NULL,
            contact_email VARCHAR(255) NOT NULL,
            contact_phone VARCHAR(50) NOT NULL,
            event_name VARCHAR(255) NULL,
            event_type VARCHAR(100) NULL,
            event_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            number_of_attendees INT NOT NULL DEFAULT 1,
            setup_requirements TEXT NULL,
            catering_required TINYINT(1) DEFAULT 0,
            catering_details TEXT NULL,
            av_requirements TEXT NULL,
            special_requests TEXT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_status ENUM('unpaid','partial','paid') DEFAULT 'unpaid',
            status ENUM('pending','tentative','confirmed','cancelled','completed') NOT NULL DEFAULT 'pending',
            is_tentative TINYINT(1) DEFAULT 0,
            tentative_expires_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_booking_reference (booking_reference),
            INDEX idx_event_date (event_date),
            INDEX idx_status (status),
            INDEX idx_contact_email (contact_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "  conference_bookings table created.\n";
    
    // 3. Fix cancellation_log.booking_id type - need to drop foreign key first
    echo "Fixing cancellation_log.booking_id type...\n";
    
    // Check if foreign key exists
    $fkCheck = $pdo->query("
        SELECT CONSTRAINT_NAME 
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
        WHERE TABLE_NAME = 'cancellation_log' 
        AND COLUMN_NAME = 'booking_id'
        AND TABLE_SCHEMA = DATABASE()
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $fk = $fkCheck->fetch(PDO::FETCH_ASSOC);
    
    if ($fk) {
        $pdo->exec("ALTER TABLE cancellation_log DROP FOREIGN KEY {$fk['CONSTRAINT_NAME']}");
        echo "  Dropped existing foreign key.\n";
    }
    
    // Modify column type
    $pdo->exec("ALTER TABLE cancellation_log MODIFY COLUMN booking_id INT UNSIGNED NOT NULL");
    echo "  Modified booking_id to INT UNSIGNED.\n";
    
    // Re-add foreign key
    $pdo->exec("
        ALTER TABLE cancellation_log 
        ADD CONSTRAINT fk_cancellation_log_booking 
        FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
    ");
    echo "  Re-added foreign key constraint.\n";
    
    // 4. Fix cancellation_log.id type for consistency
    $pdo->exec("ALTER TABLE cancellation_log MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT");
    echo "  Fixed cancellation_log.id to INT UNSIGNED.\n";
    
    // 5. Fix booking_timeline_logs.id type
    $pdo->exec("ALTER TABLE booking_timeline_logs MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT");
    echo "  Fixed booking_timeline_logs.id to INT UNSIGNED.\n";
    
    // 6. Fix booking_payments.id type
    $pdo->exec("ALTER TABLE booking_payments MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT");
    echo "  Fixed booking_payments.id to INT UNSIGNED.\n";
    
    echo "\n=== Migration 011 completed successfully! ===\n";
    echo "All production schema issues have been fixed.\n";
    
} catch (PDOException $e) {
    echo "Migration 011 failed: " . $e->getMessage() . "\n";
    exit(1);
}