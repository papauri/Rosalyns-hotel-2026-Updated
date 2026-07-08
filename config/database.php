<?php

/**
 * Database Configuration
 * Hotel Website - Database Connection
 * Supports both LOCAL and PRODUCTION environments
 */

// Include caching system first
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/../includes/system-logger.php';

// Database configuration — load from .env / environment variables.
// database.local.php (committed, no credentials) reads the .env file at
// the project root and populates $db_* via getenv().  As a safety net,
// getenv() is also read here in case the .env loader is skipped.

// Step 1: include the env loader if present
if (file_exists(__DIR__ . '/database.local.php')) {
    include __DIR__ . '/database.local.php';
}

// Step 2: fall through to getenv() for anything not set by the loader
// (covers the case where database.local.php is absent, or env vars were
//  set at the OS / cPanel level rather than via .env)
$db_host    = ($db_host    ?? getenv('DB_HOST'))    ?: '';
$db_name    = ($db_name    ?? getenv('DB_NAME'))    ?: '';
$db_user    = ($db_user    ?? getenv('DB_USER'))    ?: '';
$db_pass    = ($db_pass    ?? getenv('DB_PASS'))    ?: '';
$db_port    = ($db_port    ?? getenv('DB_PORT'))    ?: '3306';
$db_charset = $db_charset ?? 'utf8mb4';

// Validate that credentials are set
if (empty($db_host) || empty($db_name) || empty($db_user)) {
    $envFile = dirname(__DIR__) . '/.env';
    $hint = file_exists($envFile)
        ? 'A .env file was found but DB_HOST / DB_NAME / DB_USER appear to be empty inside it.'
        : 'No .env file found at the project root (' . dirname(__DIR__) . '). '
        . 'Create one from .env.example and fill in your credentials, or set '
        . 'DB_HOST / DB_NAME / DB_USER / DB_PASS as server environment variables.';
    die('Database credentials not configured. ' . $hint);
}

// Define database constants
define('DB_HOST', $db_host);
define('DB_PORT', $db_port);
define('DB_NAME', $db_name);
define('DB_USER', $db_user);
define('DB_PASS', $db_pass);
define('DB_CHARSET', $db_charset);

// Monetary comparison tolerance — amounts within this range of zero are
// treated as settled/zero to avoid floating-point rounding artifacts.
// Use this constant everywhere balance-due and payment-status logic compares
// a computed amount against zero. Never compare raw float == 0.
if (!defined('BALANCE_TOLERANCE')) {
    define('BALANCE_TOLERANCE', 0.01);
}

// Opt-in connection diagnostics. These logged the host/db/user on EVERY request,
// which floods the production error log with noise (and repeats the credentials
// hostname each hit). Set DB_DEBUG=1 in the environment to re-enable while
// troubleshooting. Connection FAILURES are always logged below regardless.
$dbDebug = in_array(strtolower((string)getenv('DB_DEBUG')), ['1', 'true', 'on', 'yes'], true);

// Create PDO connection with performance optimizations
try {
    // Diagnostic logging (opt-in only)
    if ($dbDebug) {
        error_log("Database Connection Attempt:");
        error_log("  Host: " . DB_HOST);
        error_log("  Port: " . DB_PORT);
        error_log("  Database: " . DB_NAME);
        error_log("  User: " . DB_USER);
        error_log("  Environment Variables Set: " . (getenv('DB_HOST') ? 'YES' : 'NO'));
    }

    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false, // Disabled for remote DB to prevent connection pooling issues
        PDO::ATTR_TIMEOUT => 10, // Connection timeout in seconds (increased for remote DB)
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, // Buffer results for better performance
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // Set timezone after connection
    $pdo->exec("SET time_zone = '+00:00'");

    // Ensure child-pricing columns exist for backward-compatible deployments
    ensureChildPricingColumns($pdo);

    // Ensure housekeeping + maintenance operational tables/columns exist
    ensureOperationsSupportTables($pdo);

    // Ensure occupancy/children policy columns exist for room type + individual room overrides
    ensureOccupancyPolicyColumns($pdo);

    // Ensure per-room capacity overrides and joined-room booking ledger exist
    ensureRoomCombinationSchema($pdo);

    // Ensure external API key tables exist and have retrievable storage support
    ensureApiTables($pdo);
    ensureApiKeyRetrievableColumn($pdo);

    // Ensure individual room blocked dates table exists
    ensureIndividualRoomBlockedDatesTable($pdo);

    // Ensure housekeeping enhancements columns exist (migration 004)
    ensureHousekeepingEnhancementsColumns($pdo);

    // Ensure audit log tables exist for housekeeping and maintenance (migration 006)
    ensureAuditLogTables($pdo);

    // Auto-expire stale tentative bookings on every request so that:
    // (a) the availability check inside checkRoomAvailability's WHERE clause
    //     can rely on status = 'expired' for reporting, and
    // (b) admin pages other than tentative-bookings.php see current state.
    // Runs at most once per PHP process (static guard prevents re-run if
    // database.php is included more than once via require_once).
    _expireStaleTentativeBookings($pdo);

    if ($dbDebug) {
        error_log("Database Connection Successful!");
    }
} catch (PDOException $e) {
    // Always show a beautiful custom error page (sleeping bear)
    $errorMsg = htmlspecialchars($e->getMessage());
    error_log("Database Connection Error: " . $e->getMessage());
    error_log("Error Code: " . $e->getCode());
    include_once __DIR__ . '/../includes/db-error.php';
    exit;
}

/**
 * Silently expire all tentative bookings whose expiry time has passed.
 * Called once per PHP process immediately after the PDO connection is made,
 * ensuring every page (public and admin) sees up-to-date booking states
 * without relying on a cron job or a specific admin page being visited.
 *
 * Uses a static flag so that repeated require_once calls in the same request
 * never fire the UPDATE more than once.
 */
function _expireStaleTentativeBookings(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    try {
        $stmt = $pdo->prepare("
            UPDATE bookings
            SET    status = 'expired',
                   is_tentative = 0,
                   expired_at   = NOW()
            WHERE  is_tentative          = 1
              AND  status                = 'tentative'
              AND  tentative_expires_at IS NOT NULL
              AND  tentative_expires_at  < NOW()
        ");
        $stmt->execute();
        $count = $stmt->rowCount();
        if ($count > 0) {
            error_log("[tentative] Auto-expired {$count} tentative booking(s) on page load.");
        }
    } catch (PDOException $e) {
        // Non-fatal — log and continue; availability queries already filter
        // expired tentatives inline via the NOT(...) clause.
        error_log('[tentative] Auto-expire sweep failed: ' . $e->getMessage());
    }
}

/**
 * Ensure child guest/pricing columns exist in rooms + bookings tables.
 * This keeps older databases compatible with new booking logic.
 */
function ensureChildPricingColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $columnExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");

        $ensure = function (string $table, string $column, string $alterSql) use ($pdo, $columnExistsStmt): void {
            $columnExistsStmt->execute([$table, $column]);
            $exists = (int)$columnExistsStmt->fetchColumn() > 0;
            if (!$exists) {
                $pdo->exec($alterSql);
            }
        };

        // Rooms table: source-of-truth for child pricing multiplier
        $ensure(
            'rooms',
            'child_price_multiplier',
            "ALTER TABLE rooms ADD COLUMN child_price_multiplier DECIMAL(5,2) NOT NULL DEFAULT 50.00 AFTER price_triple_occupancy"
        );

        // Individual rooms: optional override for specific room child pricing
        $ensure(
            'individual_rooms',
            'child_price_multiplier',
            "ALTER TABLE individual_rooms ADD COLUMN child_price_multiplier DECIMAL(5,2) NULL DEFAULT NULL AFTER status"
        );

        // Bookings table: store guest split + calculated child supplement
        $ensure(
            'bookings',
            'adult_guests',
            "ALTER TABLE bookings ADD COLUMN adult_guests INT NOT NULL DEFAULT 1 AFTER number_of_guests"
        );
        $ensure(
            'bookings',
            'child_guests',
            "ALTER TABLE bookings ADD COLUMN child_guests INT NOT NULL DEFAULT 0 AFTER adult_guests"
        );
        $ensure(
            'bookings',
            'child_price_multiplier',
            "ALTER TABLE bookings ADD COLUMN child_price_multiplier DECIMAL(5,2) NOT NULL DEFAULT 50.00 AFTER child_guests"
        );
        $ensure(
            'bookings',
            'child_supplement_total',
            "ALTER TABLE bookings ADD COLUMN child_supplement_total DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_amount"
        );

        // Backfill bookings guest split from legacy total guests data
        $pdo->exec("UPDATE bookings SET adult_guests = CASE WHEN number_of_guests < 1 THEN 1 ELSE number_of_guests END WHERE adult_guests IS NULL OR adult_guests < 1");
        $pdo->exec("UPDATE bookings SET child_guests = 0 WHERE child_guests IS NULL OR child_guests < 0");

        // Backfill booking multiplier from rooms where possible
        $pdo->exec("UPDATE bookings b LEFT JOIN rooms r ON b.room_id = r.id SET b.child_price_multiplier = COALESCE(r.child_price_multiplier, b.child_price_multiplier, 50.00) WHERE b.child_price_multiplier IS NULL OR b.child_price_multiplier <= 0");
    } catch (Throwable $e) {
        error_log('ensureChildPricingColumns warning: ' . $e->getMessage());
    }
}

/**
 * Ensure housekeeping + room-maintenance tables/columns exist for operational flows.
 */
function ensureOperationsSupportTables(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $tableExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $columnExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");

        $tableExists = function (string $table) use ($tableExistsStmt): bool {
            $tableExistsStmt->execute([$table]);
            return (int)$tableExistsStmt->fetchColumn() > 0;
        };

        $ensureColumn = function (string $table, string $column, string $alterSql) use ($columnExistsStmt, $pdo): void {
            $columnExistsStmt->execute([$table, $column]);
            $exists = (int)$columnExistsStmt->fetchColumn() > 0;
            if (!$exists) {
                $pdo->exec($alterSql);
            }
        };

        if (!$tableExists('housekeeping_assignments')) {
            $pdo->exec("CREATE TABLE housekeeping_assignments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                individual_room_id INT UNSIGNED NOT NULL,
                status ENUM('pending','in_progress','completed','blocked') DEFAULT 'pending',
                due_date DATE DEFAULT NULL,
                assigned_to INT UNSIGNED DEFAULT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                notes TEXT NULL,
                completed_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_housekeeping_room (individual_room_id),
                KEY idx_housekeeping_status_due (status, due_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!$tableExists('room_maintenance_schedules')) {
            $pdo->exec("CREATE TABLE room_maintenance_schedules (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                individual_room_id INT UNSIGNED NOT NULL,
                title VARCHAR(150) NOT NULL,
                description TEXT NULL,
                status ENUM('planned','in_progress','completed','cancelled') DEFAULT 'planned',
                priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
                block_room TINYINT(1) DEFAULT 1,
                start_date DATETIME NOT NULL,
                end_date DATETIME NOT NULL,
                assigned_to INT UNSIGNED DEFAULT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_maintenance_room (individual_room_id),
                KEY idx_maintenance_status_dates (status, start_date, end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!$tableExists('room_maintenance_log')) {
            $pdo->exec("CREATE TABLE room_maintenance_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                individual_room_id INT UNSIGNED NOT NULL,
                status_from VARCHAR(50) NULL,
                status_to VARCHAR(50) NOT NULL,
                reason TEXT NULL,
                performed_by INT UNSIGNED DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_maintenance_log_room (individual_room_id),
                KEY idx_maintenance_log_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Backward-compatible column guards
        if ($tableExists('housekeeping_assignments')) {
            $ensureColumn('housekeeping_assignments', 'completed_at', "ALTER TABLE housekeeping_assignments ADD COLUMN completed_at DATETIME DEFAULT NULL AFTER notes");
        }

        if ($tableExists('individual_rooms')) {
            $ensureColumn('individual_rooms', 'housekeeping_status', "ALTER TABLE individual_rooms ADD COLUMN housekeeping_status ENUM('pending','in_progress','completed') DEFAULT 'pending' AFTER status");
            $ensureColumn('individual_rooms', 'housekeeping_notes', "ALTER TABLE individual_rooms ADD COLUMN housekeeping_notes TEXT NULL AFTER housekeeping_status");
            $ensureColumn('individual_rooms', 'last_cleaned_at', "ALTER TABLE individual_rooms ADD COLUMN last_cleaned_at DATETIME DEFAULT NULL AFTER housekeeping_notes");
        }
    } catch (Throwable $e) {
        error_log('ensureOperationsSupportTables warning: ' . $e->getMessage());
    }
}

/**
 * Ensure occupancy/children policy columns exist on rooms + individual_rooms.
 *
 * Rules:
 * - room.max_guests >= 1 => single occupancy enabled
 * - room.max_guests >= 2 => double occupancy enabled
 * - room.max_guests >= 3 => triple occupancy may be enabled/disabled (manual toggle)
 * - children allowed is explicitly configurable
 * - individual room can optionally override each policy via nullable override fields
 */
function ensureOccupancyPolicyColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $columnExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");

        $ensure = function (string $table, string $column, string $alterSql) use ($pdo, $columnExistsStmt): void {
            $columnExistsStmt->execute([$table, $column]);
            $exists = (int)$columnExistsStmt->fetchColumn() > 0;
            if (!$exists) {
                $pdo->exec($alterSql);
            }
        };

        // Room type policy flags
        $ensure(
            'rooms',
            'single_occupancy_enabled',
            "ALTER TABLE rooms ADD COLUMN single_occupancy_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER max_guests"
        );
        $ensure(
            'rooms',
            'double_occupancy_enabled',
            "ALTER TABLE rooms ADD COLUMN double_occupancy_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER single_occupancy_enabled"
        );
        $ensure(
            'rooms',
            'triple_occupancy_enabled',
            "ALTER TABLE rooms ADD COLUMN triple_occupancy_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER double_occupancy_enabled"
        );
        $ensure(
            'rooms',
            'children_allowed',
            "ALTER TABLE rooms ADD COLUMN children_allowed TINYINT(1) NOT NULL DEFAULT 1 AFTER triple_occupancy_enabled"
        );

        // Individual room override flags (nullable = inherit room type)
        $ensure(
            'individual_rooms',
            'single_occupancy_enabled_override',
            "ALTER TABLE individual_rooms ADD COLUMN single_occupancy_enabled_override TINYINT(1) NULL DEFAULT NULL AFTER child_price_multiplier"
        );
        $ensure(
            'individual_rooms',
            'double_occupancy_enabled_override',
            "ALTER TABLE individual_rooms ADD COLUMN double_occupancy_enabled_override TINYINT(1) NULL DEFAULT NULL AFTER single_occupancy_enabled_override"
        );
        $ensure(
            'individual_rooms',
            'triple_occupancy_enabled_override',
            "ALTER TABLE individual_rooms ADD COLUMN triple_occupancy_enabled_override TINYINT(1) NULL DEFAULT NULL AFTER double_occupancy_enabled_override"
        );
        $ensure(
            'individual_rooms',
            'children_allowed_override',
            "ALTER TABLE individual_rooms ADD COLUMN children_allowed_override TINYINT(1) NULL DEFAULT NULL AFTER triple_occupancy_enabled_override"
        );

        // Backfill/enforce baseline occupancy behavior from max_guests
        $pdo->exec("UPDATE rooms SET single_occupancy_enabled = 1 WHERE max_guests >= 1");
        $pdo->exec("UPDATE rooms SET single_occupancy_enabled = 0 WHERE max_guests < 1");
        $pdo->exec("UPDATE rooms SET double_occupancy_enabled = 1 WHERE max_guests >= 2");
        $pdo->exec("UPDATE rooms SET double_occupancy_enabled = 0 WHERE max_guests < 2");
        $pdo->exec("UPDATE rooms SET triple_occupancy_enabled = 0 WHERE max_guests < 3");
    } catch (Throwable $e) {
        error_log('ensureOccupancyPolicyColumns warning: ' . $e->getMessage());
    }
}

/**
 * Ensure individual room capacity overrides and joined-room booking tables exist.
 */
function ensureRoomCombinationSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $tableExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $columnExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $indexExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");

        $tableExists = function (string $table) use ($tableExistsStmt): bool {
            $tableExistsStmt->execute([$table]);
            return (int)$tableExistsStmt->fetchColumn() > 0;
        };

        $ensureColumn = function (string $table, string $column, string $alterSql) use ($columnExistsStmt, $pdo): void {
            $columnExistsStmt->execute([$table, $column]);
            if ((int)$columnExistsStmt->fetchColumn() === 0) {
                $pdo->exec($alterSql);
            }
        };

        $ensureIndex = function (string $table, string $index, string $createSql) use ($indexExistsStmt, $pdo): void {
            $indexExistsStmt->execute([$table, $index]);
            if ((int)$indexExistsStmt->fetchColumn() === 0) {
                $pdo->exec($createSql);
            }
        };

        $ensureColumn(
            'individual_rooms',
            'max_guests_override',
            "ALTER TABLE individual_rooms ADD COLUMN max_guests_override INT UNSIGNED NULL DEFAULT NULL AFTER child_price_multiplier"
        );

        $ensureColumn(
            'bookings',
            'room_combination_id',
            "ALTER TABLE bookings ADD COLUMN room_combination_id INT UNSIGNED NULL DEFAULT NULL AFTER individual_room_id"
        );

        if (!$tableExists('room_combinations')) {
            $pdo->exec("CREATE TABLE room_combinations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                combined_name VARCHAR(120) NOT NULL,
                combined_room_type_id INT NOT NULL,
                room_a_id INT UNSIGNED NOT NULL,
                room_b_id INT UNSIGNED NOT NULL,
                price_override DECIMAL(10,2) NULL DEFAULT NULL,
                max_guests_combined INT UNSIGNED NOT NULL DEFAULT 4,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                notes TEXT NULL,
                created_by INT UNSIGNED NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_room_combination_pair (room_a_id, room_b_id),
                KEY idx_room_combinations_type_active (combined_room_type_id, is_active),
                KEY idx_room_combinations_room_a (room_a_id),
                KEY idx_room_combinations_room_b (room_b_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!$tableExists('booking_rooms')) {
            $pdo->exec("CREATE TABLE booking_rooms (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_id INT UNSIGNED NOT NULL,
                individual_room_id INT UNSIGNED NOT NULL,
                room_combination_id INT UNSIGNED NULL DEFAULT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                released_at DATETIME NULL DEFAULT NULL,
                status_snapshot VARCHAR(40) NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_booking_room (booking_id, individual_room_id),
                KEY idx_booking_rooms_booking (booking_id),
                KEY idx_booking_rooms_room (individual_room_id),
                KEY idx_booking_rooms_combo (room_combination_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        $ensureIndex('bookings', 'idx_bookings_room_combination', 'CREATE INDEX idx_bookings_room_combination ON bookings (room_combination_id)');
        $ensureIndex('individual_rooms', 'idx_individual_rooms_capacity_override', 'CREATE INDEX idx_individual_rooms_capacity_override ON individual_rooms (max_guests_override)');

        $pdo->exec("INSERT IGNORE INTO booking_rooms (booking_id, individual_room_id, room_combination_id, is_primary, assigned_at, status_snapshot)
            SELECT id, individual_room_id, room_combination_id, 1, COALESCE(updated_at, created_at, NOW()), status
            FROM bookings
            WHERE individual_room_id IS NOT NULL");
    } catch (Throwable $e) {
        error_log('ensureRoomCombinationSchema warning: ' . $e->getMessage());
    }
}

/**
 * Ensure individual room blocked dates table exists for dual-layer blocking.
 * This allows blocking specific individual rooms on specific dates,
 * separate from room-type level blocks.
 */
function ensureIndividualRoomBlockedDatesTable(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $tableExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $columnExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");

        $tableExists = function (string $table) use ($tableExistsStmt): bool {
            $tableExistsStmt->execute([$table]);
            return (int)$tableExistsStmt->fetchColumn() > 0;
        };

        $ensureColumn = function (string $table, string $column, string $alterSql) use ($columnExistsStmt, $pdo): void {
            $columnExistsStmt->execute([$table, $column]);
            $exists = (int)$columnExistsStmt->fetchColumn() > 0;
            if (!$exists) {
                $pdo->exec($alterSql);
            }
        };

        // Create individual_room_blocked_dates table if not exists
        if (!$tableExists('individual_room_blocked_dates')) {
            $pdo->exec("CREATE TABLE individual_room_blocked_dates (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                individual_room_id INT UNSIGNED NOT NULL,
                block_date DATE NOT NULL,
                block_type ENUM('manual', 'maintenance', 'event', 'full') DEFAULT 'manual',
                reason TEXT NULL,
                blocked_by INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY idx_individual_room_date (individual_room_id, block_date),
                KEY idx_individual_room_block_date (block_date),
                KEY idx_individual_room_block_type (block_type),
                KEY idx_individual_room_blocked_by (blocked_by),
                CONSTRAINT fk_irbd_individual_room FOREIGN KEY (individual_room_id) REFERENCES individual_rooms (id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_irbd_blocked_by FOREIGN KEY (blocked_by) REFERENCES admin_users (id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Ensure block_type column exists in blocked_dates table for consistency
        if ($tableExists('blocked_dates')) {
            $ensureColumn(
                'blocked_dates',
                'block_type',
                "ALTER TABLE blocked_dates ADD COLUMN block_type ENUM('manual', 'maintenance', 'event', 'full') DEFAULT 'manual' AFTER block_date"
            );

            // Ensure block_type index exists (check if index exists before creating)
            try {
                $indexCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
                $indexCheckStmt->execute(['blocked_dates', 'idx_blocked_dates_block_type']);
                $indexExists = (int)$indexCheckStmt->fetchColumn() > 0;
                if (!$indexExists) {
                    $pdo->exec("ALTER TABLE blocked_dates ADD INDEX idx_blocked_dates_block_type (block_type)");
                }
            } catch (Throwable $e) {
                // Ignore index creation errors
            }
        }
    } catch (Throwable $e) {
        error_log('ensureIndividualRoomBlockedDatesTable warning: ' . $e->getMessage());
    }
}

/**
 * Ensure housekeeping enhancements columns exist (migration 004).
 * This adds priority, assignment_type, recurring settings, and verification workflow to housekeeping.
 * The function is idempotent - safe to run multiple times.
 */
function ensureHousekeepingEnhancementsColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $tableExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $columnExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $indexExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $constraintExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?");

        $tableExists = function (string $table) use ($tableExistsStmt): bool {
            $tableExistsStmt->execute([$table]);
            return (int)$tableExistsStmt->fetchColumn() > 0;
        };

        $ensureColumn = function (string $table, string $column, string $alterSql) use ($columnExistsStmt, $pdo): void {
            $columnExistsStmt->execute([$table, $column]);
            $exists = (int)$columnExistsStmt->fetchColumn() > 0;
            if (!$exists) {
                $pdo->exec($alterSql);
            }
        };

        $ensureIndex = function (string $table, string $index, string $createSql) use ($indexExistsStmt, $pdo): void {
            $indexExistsStmt->execute([$table, $index]);
            $exists = (int)$indexExistsStmt->fetchColumn() > 0;
            if (!$exists) {
                $pdo->exec($createSql);
            }
        };

        $ensureConstraint = function (string $table, string $constraint, string $alterSql) use ($constraintExistsStmt, $pdo): void {
            $constraintExistsStmt->execute([$table, $constraint]);
            $exists = (int)$constraintExistsStmt->fetchColumn() > 0;
            if (!$exists) {
                $pdo->exec($alterSql);
            }
        };

        // Only proceed if housekeeping_assignments table exists
        if (!$tableExists('housekeeping_assignments')) {
            return;
        }

        // Add priority column (high, medium, low)
        $ensureColumn(
            'housekeeping_assignments',
            'priority',
            "ALTER TABLE housekeeping_assignments ADD COLUMN priority ENUM('high', 'medium', 'low') DEFAULT 'medium'"
        );

        // Add assignment_type column (checkout_cleanup, regular_cleaning, maintenance, deep_clean, turn_down)
        $ensureColumn(
            'housekeeping_assignments',
            'assignment_type',
            "ALTER TABLE housekeeping_assignments ADD COLUMN assignment_type ENUM('checkout_cleanup', 'regular_cleaning', 'maintenance', 'deep_clean', 'turn_down') DEFAULT 'regular_cleaning'"
        );

        // Add is_recurring column for recurring tasks
        $ensureColumn(
            'housekeeping_assignments',
            'is_recurring',
            "ALTER TABLE housekeeping_assignments ADD COLUMN is_recurring TINYINT(1) DEFAULT 0"
        );

        // Add recurring_pattern column (daily, weekly, monthly)
        $ensureColumn(
            'housekeeping_assignments',
            'recurring_pattern',
            "ALTER TABLE housekeeping_assignments ADD COLUMN recurring_pattern ENUM('daily', 'weekly', 'monthly') DEFAULT NULL"
        );

        // Add recurring_end_date for when recurring tasks should stop
        $ensureColumn(
            'housekeeping_assignments',
            'recurring_end_date',
            "ALTER TABLE housekeeping_assignments ADD COLUMN recurring_end_date DATE DEFAULT NULL"
        );

        // Add verified_by column for supervisor verification
        $ensureColumn(
            'housekeeping_assignments',
            'verified_by',
            "ALTER TABLE housekeeping_assignments ADD COLUMN verified_by INT DEFAULT NULL"
        );

        // Add verified_at column for verification timestamp
        $ensureColumn(
            'housekeeping_assignments',
            'verified_at',
            "ALTER TABLE housekeeping_assignments ADD COLUMN verified_at DATETIME DEFAULT NULL"
        );

        // Add estimated_duration in minutes
        $ensureColumn(
            'housekeeping_assignments',
            'estimated_duration',
            "ALTER TABLE housekeeping_assignments ADD COLUMN estimated_duration INT DEFAULT 30 COMMENT 'Estimated duration in minutes'"
        );

        // Add actual_duration in minutes
        $ensureColumn(
            'housekeeping_assignments',
            'actual_duration',
            "ALTER TABLE housekeeping_assignments ADD COLUMN actual_duration INT DEFAULT NULL COMMENT 'Actual duration in minutes'"
        );

        // Add auto_created flag for automatically created assignments (e.g., checkout cleanup)
        $ensureColumn(
            'housekeeping_assignments',
            'auto_created',
            "ALTER TABLE housekeeping_assignments ADD COLUMN auto_created TINYINT(1) DEFAULT 0"
        );

        // Add linked_booking_id for checkout cleanup assignments
        $ensureColumn(
            'housekeeping_assignments',
            'linked_booking_id',
            "ALTER TABLE housekeeping_assignments ADD COLUMN linked_booking_id BIGINT DEFAULT NULL"
        );

        // Add indexes for better query performance
        $ensureIndex(
            'housekeeping_assignments',
            'idx_housekeeping_priority',
            "CREATE INDEX idx_housekeeping_priority ON housekeeping_assignments(priority)"
        );
        $ensureIndex(
            'housekeeping_assignments',
            'idx_housekeeping_status_priority',
            "CREATE INDEX idx_housekeeping_status_priority ON housekeeping_assignments(status, priority)"
        );
        $ensureIndex(
            'housekeeping_assignments',
            'idx_housekeeping_assigned_to',
            "CREATE INDEX idx_housekeeping_assigned_to ON housekeeping_assignments(assigned_to)"
        );
        $ensureIndex(
            'housekeeping_assignments',
            'idx_housekeeping_due_date',
            "CREATE INDEX idx_housekeeping_due_date ON housekeeping_assignments(due_date)"
        );
        $ensureIndex(
            'housekeeping_assignments',
            'idx_housekeeping_type',
            "CREATE INDEX idx_housekeeping_type ON housekeeping_assignments(assignment_type)"
        );

        // Add foreign key constraint for verified_by
        $ensureConstraint(
            'housekeeping_assignments',
            'fk_housekeeping_verified_by',
            "ALTER TABLE housekeeping_assignments ADD CONSTRAINT fk_housekeeping_verified_by FOREIGN KEY (verified_by) REFERENCES admin_users(id) ON DELETE SET NULL"
        );

        // Add foreign key constraint for linked_booking_id
        $ensureConstraint(
            'housekeeping_assignments',
            'fk_housekeeping_booking',
            "ALTER TABLE housekeeping_assignments ADD CONSTRAINT fk_housekeeping_booking FOREIGN KEY (linked_booking_id) REFERENCES bookings(id) ON DELETE SET NULL"
        );

        // Update existing records to have default priority
        $pdo->exec("UPDATE housekeeping_assignments SET priority = 'medium' WHERE priority IS NULL");

        // Update existing records to have default assignment type
        $pdo->exec("UPDATE housekeeping_assignments SET assignment_type = 'regular_cleaning' WHERE assignment_type IS NULL");
    } catch (Throwable $e) {
        error_log('ensureHousekeepingEnhancementsColumns warning: ' . $e->getMessage());
    }
}

/**
 * Resolve effective occupancy + children policy for a room type (and optional individual room override).
 */
function resolveOccupancyPolicy(array $room, ?array $individualRoom = null): array
{
    $maxGuests = max(0, (int)($room['max_guests'] ?? 0));
    if ($individualRoom !== null && array_key_exists('max_guests_override', $individualRoom) && $individualRoom['max_guests_override'] !== null && $individualRoom['max_guests_override'] !== '') {
        $maxGuests = max(0, (int)$individualRoom['max_guests_override']);
    }

    $single = (int)($room['single_occupancy_enabled'] ?? 1);
    $double = (int)($room['double_occupancy_enabled'] ?? 1);
    $triple = (int)($room['triple_occupancy_enabled'] ?? 1);
    $childrenAllowed = (int)($room['children_allowed'] ?? 1);

    if ($individualRoom !== null) {
        if (array_key_exists('single_occupancy_enabled_override', $individualRoom) && $individualRoom['single_occupancy_enabled_override'] !== null) {
            $single = (int)$individualRoom['single_occupancy_enabled_override'];
        }
        if (array_key_exists('double_occupancy_enabled_override', $individualRoom) && $individualRoom['double_occupancy_enabled_override'] !== null) {
            $double = (int)$individualRoom['double_occupancy_enabled_override'];
        }
        if (array_key_exists('triple_occupancy_enabled_override', $individualRoom) && $individualRoom['triple_occupancy_enabled_override'] !== null) {
            $triple = (int)$individualRoom['triple_occupancy_enabled_override'];
        }
        if (array_key_exists('children_allowed_override', $individualRoom) && $individualRoom['children_allowed_override'] !== null) {
            $childrenAllowed = (int)$individualRoom['children_allowed_override'];
        }
    }

    // Capacity always takes precedence
    if ($maxGuests < 1) {
        $single = 0;
    }
    if ($maxGuests < 2) {
        $double = 0;
    }
    if ($maxGuests < 3) {
        $triple = 0;
    }

    // Pricing policy: If occupancy pricing is NULL, use base price (allows booking up to max_guests)
    // Only disable occupancy if explicitly set to 0 (not NULL)
    if (array_key_exists('price_double_occupancy', $room)) {
        if ($room['price_double_occupancy'] === '0' || $room['price_double_occupancy'] === 0) {
            // Explicitly disabled
            $double = 0;
        }
        // NULL or positive value means enabled (NULL will use base price as fallback)
    }
    if (array_key_exists('price_triple_occupancy', $room)) {
        if ($room['price_triple_occupancy'] === '0' || $room['price_triple_occupancy'] === 0) {
            // Explicitly disabled
            $triple = 0;
        }
        // NULL or positive value means enabled (NULL will use base price as fallback)
    }

    return [
        'max_guests' => $maxGuests,
        'single_enabled' => $single ? 1 : 0,
        'double_enabled' => $double ? 1 : 0,
        'triple_enabled' => $triple ? 1 : 0,
        'children_allowed' => $childrenAllowed ? 1 : 0,
    ];
}

// Settings cache to avoid repeated queries
$_SITE_SETTINGS = [];

/**
 * Helper function to get setting value with file-based caching
 * DRAMATICALLY reduces database queries and remote connection overhead
 */
function getSetting(string $key, mixed $default = '')
{
    global $pdo, $_SITE_SETTINGS;

    // Check in-memory cache first (fastest)
    if (isset($_SITE_SETTINGS[$key])) {
        return $_SITE_SETTINGS[$key];
    }

    // Check file cache (much faster than database query)
    $cachedValue = getCache("setting_{$key}", null);
    if ($cachedValue !== null) {
        $_SITE_SETTINGS[$key] = $cachedValue;
        return $cachedValue;
    }

    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        $value = $result ? $result['setting_value'] : $default;

        // Cache in memory
        $_SITE_SETTINGS[$key] = $value;

        // Cache in file for next request (1 hour TTL)
        setCache("setting_{$key}", $value, 3600);

        return $value;
    } catch (PDOException $e) {
        error_log("Error fetching setting: " . $e->getMessage());
        return $default;
    }
}

if (!function_exists('vat_mode')) {
    /**
     * Resolve the installation-wide VAT mode.
     *   'off'       — VAT disabled (or rate 0): no VAT anywhere.
     *   'inclusive' — prices already contain VAT; totals never inflate and
     *                 customer documents show only the rate, never an amount.
     *   'exclusive' — classic add-on-top VAT (default / legacy behaviour).
     */
    function vat_mode(): string
    {
        $enabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
        if (!$enabled || (float)getSetting('vat_rate', 0) <= 0) {
            return 'off';
        }
        return getSetting('vat_pricing_mode', 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';
    }
}

if (!function_exists('vat_components')) {
    /**
     * Split a priced amount into net / VAT / total according to vat_mode().
     * $base is the priced amount (sum of item/room prices before any add-on VAT).
     *
     * off:       net = base, vat = 0,                    total = base
     * inclusive: net = base/(1+r/100), vat = base - net, total = base
     * exclusive: net = base, vat = base*r/100,           total = base + vat
     *
     * @return array{mode:string, rate:float, net:float, vat:float, total:float}
     */
    function vat_components(float $base): array
    {
        $mode = vat_mode();
        $rate = $mode === 'off' ? 0.0 : (float)getSetting('vat_rate', 0);
        if ($mode === 'inclusive' && $rate > 0) {
            $net = round($base / (1 + ($rate / 100)), 2);
            $vat = round($base - $net, 2);
            return ['mode' => $mode, 'rate' => $rate, 'net' => $net, 'vat' => $vat, 'total' => round($base, 2)];
        }
        if ($mode === 'exclusive' && $rate > 0) {
            $vat = round($base * ($rate / 100), 2);
            return ['mode' => $mode, 'rate' => $rate, 'net' => round($base, 2), 'vat' => $vat, 'total' => round($base + $vat, 2)];
        }
        return ['mode' => 'off', 'rate' => 0.0, 'net' => round($base, 2), 'vat' => 0.0, 'total' => round($base, 2)];
    }
}

if (!function_exists('vat_shows_amount')) {
    /**
     * Whether customer-facing documents (invoices, receipts, quotations,
     * emails) should print a VAT amount line. Only exclusive mode itemises
     * the amount; inclusive mode shows just the rate note; off shows nothing.
     */
    function vat_shows_amount(): bool
    {
        return vat_mode() === 'exclusive';
    }
}

if (!function_exists('vat_document_value')) {
    /**
     * The value to print next to a "VAT" label on customer documents.
     * exclusive → the formatted amount (e.g. "MWK 13,200.00")
     * inclusive → rate-only wording (e.g. "Included at 16.5%") — never the amount
     * off       → em dash
     *
     * @param string $formattedAmount Amount already formatted with currency.
     */
    function vat_document_value(string $formattedAmount): string
    {
        $mode = vat_mode();
        if ($mode === 'exclusive') {
            return $formattedAmount;
        }
        if ($mode === 'inclusive') {
            $rate = (float)getSetting('vat_rate', 0);
            $rateLabel = rtrim(rtrim(number_format($rate, 2), '0'), '.');
            return 'Included at ' . $rateLabel . '%';
        }
        return '—';
    }
}

if (!function_exists('vat_document_note')) {
    /**
     * Rate-only wording for customer documents in inclusive mode
     * (e.g. "All prices are inclusive of VAT (16.5%).").
     * Empty string in exclusive/off modes.
     */
    function vat_document_note(): string
    {
        if (vat_mode() !== 'inclusive') {
            return '';
        }
        $rate = (float)getSetting('vat_rate', 0);
        $rateLabel = rtrim(rtrim(number_format($rate, 2), '0'), '.');
        return 'All prices are inclusive of VAT (' . $rateLabel . '%).';
    }
}

if (!function_exists('moduleEnabled')) {
    /**
     * Returns true if a feature module is enabled for this installation.
     * Fails open — if the table doesn't exist yet, every module is considered on.
     * Results are cached per request via static array.
     *
     * Module keys: bookings, housekeeping, pos, stock, conference, gym, finance, website_cms
     * Station sub-keys (require pos=on): station_kds, station_bds, station_cds, station_room_service
     */
    function moduleEnabled(string $module): bool
    {
        static $cache = null;
        static $tableExists = null;

        global $pdo;

        if ($tableExists === null) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enabled_modules'");
                $stmt->execute();
                $tableExists = ((int)$stmt->fetchColumn() > 0);

                if (!$tableExists) {
                    // Create and seed the table on first use
                    $pdo->exec("CREATE TABLE IF NOT EXISTS enabled_modules (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        module_key VARCHAR(50) NOT NULL UNIQUE,
                        module_name VARCHAR(100) NOT NULL,
                        description VARCHAR(255) NOT NULL DEFAULT '',
                        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                        sort_order INT NOT NULL DEFAULT 0,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    $modules = [
                        ['bookings',             'Bookings & Reservations',  'Room bookings, check-in/out, calendar, rate plans', 1],
                        ['housekeeping',         'Housekeeping',              'Cleaning schedules, room maintenance, room status', 2],
                        ['pos',                  'Point of Sale',             'Restaurant, bar, KDS/BDS/CDS, menu management',    3],
                        ['stock',                'Stock & Inventory',         'Ingredients, recipes, stock orders, wastage',      4],
                        ['conference',           'Conference & Events',       'Conference rooms, event bookings, quotations',     5],
                        ['gym',                  'Gym & Fitness',             'Gym management, packages, inquiries',              6],
                        ['finance',              'Finance & Payments',        'Invoices, credit notes, reports, accounting',      7],
                        ['website_cms',          'Website & CMS',             'Gallery, pages, deals, reviews, social settings',  8],
                        // Station sub-modules (only relevant when pos=1)
                        ['station_kds',          'Kitchen Display (KDS)',     'Kitchen order display and ticket management',      31],
                        ['station_bds',          'Bar Display (BDS)',         'Bar order display and drink ticket management',    32],
                        ['station_cds',          'Coffee Bar Display (CDS)',  'Coffee bar order display and management',          33],
                        ['station_room_service', 'Room Service Station',      'In-room dining display and order management',      34],
                    ];

                    $ins = $pdo->prepare("INSERT IGNORE INTO enabled_modules (module_key, module_name, description, is_enabled, sort_order) VALUES (?,?,?,1,?)");
                    foreach ($modules as [$key, $name, $desc, $order]) {
                        $ins->execute([$key, $name, $desc, $order]);
                    }
                    $tableExists = true;
                }
            } catch (Throwable $e) {
                error_log('moduleEnabled table check failed: ' . $e->getMessage());
                $tableExists = false;
            }
        }

        // Table doesn't exist — fail open so nothing breaks
        if (!$tableExists) {
            return true;
        }

        // Load all modules into cache on first real call
        if ($cache === null) {
            $cache = [];
            try {
                $rows = $pdo->query("SELECT module_key, is_enabled FROM enabled_modules")->fetchAll(PDO::FETCH_KEY_PAIR);
                foreach ($rows as $key => $val) {
                    $cache[(string)$key] = (bool)(int)$val;
                }
            } catch (Throwable $e) {
                error_log('moduleEnabled load failed: ' . $e->getMessage());
                return true; // fail open
            }
        }

        // Unknown module keys default to enabled
        return $cache[$module] ?? true;
    }
}

if (!function_exists('getEnabledModules')) {
    /**
     * Returns array of all modules with their enabled state.
     * Used by the module settings admin page.
     */
    function getEnabledModules(): array
    {
        global $pdo;
        try {
            // Ensure table exists by calling moduleEnabled first
            moduleEnabled('bookings');
            return $pdo->query("SELECT module_key, module_name, description, is_enabled, sort_order FROM enabled_modules ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('getEnabledModules failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('rh_is_public_frontend_request')) {
    function rh_is_public_frontend_request(): bool
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return false;
        }

        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? $scriptName);
        $requestPath = str_replace('\\', '/', $requestPath);

        $targets = [strtolower($scriptName), strtolower($requestPath)];
        foreach ($targets as $target) {
            if (preg_match('#(^|/)(admin|api|api_key|vendor|scripts)(/|$)#', $target)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('rh_setting_enabled')) {
    function rh_setting_enabled(string $key, bool $default = false): bool
    {
        $fallback = $default ? '1' : '0';
        $value = strtolower(trim((string)getSetting($key, $fallback)));
        return in_array($value, ['1', 'true', 'on', 'yes'], true);
    }
}

if (!function_exists('rh_render_frontend_maintenance_response')) {
    function rh_render_frontend_maintenance_response(string $siteName, string $maintenanceMessage): void
    {
        $siteNameEsc = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
        $messageEsc = nl2br(htmlspecialchars($maintenanceMessage, ENT_QUOTES, 'UTF-8'));
        echo '<!DOCTYPE html>'
            . '<html lang="en">'
            . '<head>'
            . '<meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Maintenance - ' . $siteNameEsc . '</title>'
            . '<style>'
            . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f7f3ee;color:#2A2723;font-family:"Jost",sans-serif;padding:1.5rem;}'
            . '.maintenance-card{width:min(100%,42rem);background:#fff;border:1px solid #E5DDD4;border-radius:18px;padding:2rem;box-shadow:0 20px 40px rgba(35,31,28,.08);}'
            . '.maintenance-eyebrow{margin:0 0 .8rem;font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;color:#8A775F;font-weight:600;}'
            . '.maintenance-title{margin:0 0 1rem;font-family:"Cormorant Garamond",serif;font-weight:600;font-size:clamp(2rem,4vw,3rem);line-height:1.12;color:#231F1C;}'
            . '.maintenance-copy{margin:0;font-size:clamp(1rem,1.2vw,1.15rem);line-height:1.8;color:#5E554D;}'
            . '</style>'
            . '<link rel="preconnect" href="https://fonts.googleapis.com">'
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">'
            . '</head>'
            . '<body>'
            . '<main class="maintenance-card" aria-live="polite">'
            . '<p class="maintenance-eyebrow">Scheduled Maintenance</p>'
            . '<h1 class="maintenance-title">We will be right back.</h1>'
            . '<p class="maintenance-copy">' . $messageEsc . '</p>'
            . '</main>'
            . '</body>'
            . '</html>';
    }
}

if (!function_exists('rh_enforce_frontend_maintenance_mode')) {
    function rh_enforce_frontend_maintenance_mode(): void
    {
        if (!rh_is_public_frontend_request()) {
            return;
        }

        if (!rh_setting_enabled('site_maintenance_enabled', false)) {
            return;
        }

        $siteName = (string)getSetting('site_name', 'Hotel');
        $maintenanceMessage = trim((string)getSetting(
            'site_maintenance_message',
            'Our website is temporarily unavailable while we complete scheduled maintenance. Please check back shortly.'
        ));
        if ($maintenanceMessage === '') {
            $maintenanceMessage = 'Our website is temporarily unavailable while we complete scheduled maintenance. Please check back shortly.';
        }

        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $requestPath = (string)(parse_url($requestUri, PHP_URL_PATH) ?? '/');
        $acceptHeader = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        $wantsJson = $isAjax || strpos($acceptHeader, 'application/json') !== false;

        $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $logThrottleKey = 'maintenance_block_' . md5($ipAddress . '|' . $requestPath);
        if (getCache($logThrottleKey, null) === null) {
            setCache($logThrottleKey, 1, 600);
            if (function_exists('rh_log_event')) {
                rh_log_event('frontend/maintenance-mode', 'warning', 'Public request blocked by maintenance mode', [
                    'path' => $requestPath,
                    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                    'ip' => $ipAddress,
                ]);
            }
        }

        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 1800');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Content-Type: ' . ($wantsJson ? 'application/json' : 'text/html') . '; charset=UTF-8');
        }

        if ($wantsJson) {
            echo json_encode([
                'success' => false,
                'error' => $maintenanceMessage,
                'code' => 503,
            ]);
            exit;
        }

        rh_render_frontend_maintenance_response($siteName, $maintenanceMessage);
        exit;
    }
}

rh_enforce_frontend_maintenance_mode();

/**
 * Helper function to get email setting value with caching
 * Handles encrypted settings like passwords
 */
function getEmailSetting(string $key, mixed $default = '')
{
    global $pdo;

    try {
        // Check if email_settings table exists (cached)
        $table_exists = getCache("table_email_settings", null);
        if ($table_exists === null) {
            $table_exists = $pdo->query("SHOW TABLES LIKE 'email_settings'")->rowCount() > 0;
            setCache("table_email_settings", $table_exists, 86400); // Cache for 24 hours
        }

        if (!$table_exists) {
            // Fallback to site_settings for backward compatibility
            return getSetting($key, $default);
        }

        // Try file cache first
        $cachedValue = getCache("email_setting_{$key}", null);
        if ($cachedValue !== null) {
            return $cachedValue;
        }

        $stmt = $pdo->prepare("SELECT setting_value, is_encrypted FROM email_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();

        if (!$result) {
            return $default;
        }

        $value = $result['setting_value'];
        $is_encrypted = (bool)$result['is_encrypted'];

        // Handle encrypted values (like passwords)
        if ($is_encrypted && !empty($value)) {
            try {
                // Try to decrypt using database function
                $stmt = $pdo->prepare("SELECT decrypt_setting(?) as decrypted_value");
                $stmt->execute([$value]);
                $decrypted = $stmt->fetch();
                if ($decrypted && !empty($decrypted['decrypted_value'])) {
                    $value = $decrypted['decrypted_value'];
                } else {
                    // Fallback: use raw stored value when DB decrypt function is unavailable
                    // or returns empty unexpectedly. This keeps SMTP credentials usable.
                    $value = $result['setting_value'];
                }
            } catch (Exception $e) {
                // Fallback to raw stored value when decrypt function is missing/invalid.
                $value = $result['setting_value'];
            }
        }

        // Cache the result (1 hour TTL for encrypted, 6 hours for unencrypted)
        $ttl = $is_encrypted ? 3600 : 21600;
        setCache("email_setting_{$key}", $value, $ttl);

        return $value;
    } catch (PDOException $e) {
        error_log("Error fetching email setting: " . $e->getMessage());
        return $default;
    }
}

/**
 * Helper function to get all email settings
 */
function getAllEmailSettings()
{
    global $pdo;

    $settings = [];
    try {
        // Check if email_settings table exists
        $table_exists = $pdo->query("SHOW TABLES LIKE 'email_settings'")->rowCount() > 0;

        if (!$table_exists) {
            return $settings;
        }

        $stmt = $pdo->query("SELECT setting_key, setting_value, is_encrypted, description FROM email_settings ORDER BY setting_group, setting_key");
        $results = $stmt->fetchAll();

        foreach ($results as $row) {
            $key = $row['setting_key'];
            $value = $row['setting_value'];
            $is_encrypted = (bool)$row['is_encrypted'];

            // Handle encrypted values
            if ($is_encrypted && !empty($value)) {
                try {
                    $stmt2 = $pdo->prepare("SELECT decrypt_setting(?) as decrypted_value");
                    $stmt2->execute([$value]);
                    $decrypted = $stmt2->fetch();
                    if ($decrypted && !empty($decrypted['decrypted_value'])) {
                        $value = $decrypted['decrypted_value'];
                    } else {
                        $value = ''; // Don't expose encrypted data
                    }
                } catch (Exception $e) {
                    $value = ''; // Don't expose encrypted data on error
                }
            }

            $settings[$key] = [
                'value' => $value,
                'encrypted' => $is_encrypted,
                'description' => $row['description']
            ];
        }

        return $settings;
    } catch (PDOException $e) {
        error_log("Error fetching all email settings: " . $e->getMessage());
        return $settings;
    }
}

/**
 * Helper function to update email setting
 */
function updateEmailSetting(string $key, mixed $value, ?string $description = null, bool $is_encrypted = false)
{
    global $pdo;

    try {
        // Check if email_settings table exists
        $table_exists = $pdo->query("SHOW TABLES LIKE 'email_settings'")->rowCount() > 0;

        if (!$table_exists) {
            // Fallback to site_settings for backward compatibility
            return updateSetting($key, $value);
        }

        // Handle encryption if needed
        $final_value = $value;
        $final_is_encrypted = $is_encrypted ? 1 : 0;
        if ($is_encrypted && !empty($value)) {
            try {
                $stmt = $pdo->prepare("SELECT encrypt_setting(?) as encrypted_value");
                $stmt->execute([$value]);
                $encrypted = $stmt->fetch();
                if ($encrypted && !empty($encrypted['encrypted_value'])) {
                    $final_value = $encrypted['encrypted_value'];
                    $final_is_encrypted = 1;
                } else {
                    // Encryption function exists but returned empty. Store plaintext to avoid
                    // breaking SMTP authentication flows.
                    $final_value = $value;
                    $final_is_encrypted = 0;
                }
            } catch (Exception $e) {
                // If database encryption functions are unavailable, preserve operability by
                // storing plaintext instead of failing save.
                error_log("Email setting encryption unavailable for {$key}, saving plaintext: " . $e->getMessage());
                $final_value = $value;
                $final_is_encrypted = 0;
            }
        }

        // Update or insert
        $sql = "INSERT INTO email_settings (setting_key, setting_value, is_encrypted, description)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                is_encrypted = VALUES(is_encrypted),
                description = VALUES(description),
                updated_at = CURRENT_TIMESTAMP";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$key, $final_value, $final_is_encrypted, $description]);

        // Clear cache for this setting
        global $_SITE_SETTINGS;
        if (isset($_SITE_SETTINGS[$key])) {
            unset($_SITE_SETTINGS[$key]);
        }

        // Clear file cache entries so new SMTP/email settings apply immediately
        deleteCache("email_setting_{$key}");
        deleteCache("setting_{$key}");

        return true;
    } catch (PDOException $e) {
        error_log("Error updating email setting: " . $e->getMessage());
        return false;
    }
}

/**
 * Helper function to update setting (for backward compatibility)
 */
function updateSetting(string $key, mixed $value)
{
    global $pdo;

    try {
        $sql = "INSERT INTO site_settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_at = CURRENT_TIMESTAMP";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$key, $value]);

        // Clear cache for this setting
        global $_SITE_SETTINGS;
        if (isset($_SITE_SETTINGS[$key])) {
            unset($_SITE_SETTINGS[$key]);
        }

        // Clear file cache copy so getSetting() returns fresh value immediately
        deleteCache("setting_{$key}");

        return true;
    } catch (PDOException $e) {
        error_log("Error updating setting: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if booking email templates table exists (cached)
 */
function bookingEmailTemplatesTableExists()
{
    global $pdo;

    $cacheKey = 'table_booking_email_templates';
    $cached = getCache($cacheKey, null);
    if ($cached !== null) {
        return (bool)$cached;
    }

    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'booking_email_templates'")->rowCount() > 0;
        setCache($cacheKey, $exists ? 1 : 0, 86400);
        return $exists;
    } catch (PDOException $e) {
        error_log("Error checking booking_email_templates table: " . $e->getMessage());
        return false;
    }
}

/**
 * Ensure booking email templates table exists
 */
function ensureBookingEmailTemplatesTable()
{
    global $pdo;

    if (bookingEmailTemplatesTableExists()) {
        return true;
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS booking_email_templates (
            id INT NOT NULL AUTO_INCREMENT,
            template_key VARCHAR(100) NOT NULL,
            template_name VARCHAR(150) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            html_body MEDIUMTEXT NOT NULL,
            text_body TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_template_key (template_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        setCache('table_booking_email_templates', 1, 86400);
        return true;
    } catch (PDOException $e) {
        error_log("Error creating booking_email_templates table: " . $e->getMessage());
        return false;
    }
}

/**
 * Get booking email template configuration
 */
function getBookingEmailTemplateConfig(string $templateKey, array $defaults = [])
{
    global $pdo;

    $fallback = array_merge([
        'template_key' => $templateKey,
        'template_name' => $templateKey,
        'subject' => '',
        'html_body' => '',
        'text_body' => '',
        'is_active' => 1
    ], $defaults);

    if (!bookingEmailTemplatesTableExists()) {
        return $fallback;
    }

    $cacheKey = 'booking_email_template_' . $templateKey;
    $cached = getCache($cacheKey, null);
    if (is_array($cached)) {
        return array_merge($fallback, $cached);
    }

    try {
        $stmt = $pdo->prepare("SELECT template_key, template_name, subject, html_body, text_body, is_active FROM booking_email_templates WHERE template_key = ? LIMIT 1");
        $stmt->execute([$templateKey]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            return $fallback;
        }

        $template = array_merge($fallback, $template);
        setCache($cacheKey, $template, 1800);
        return $template;
    } catch (PDOException $e) {
        error_log("Error fetching booking email template {$templateKey}: " . $e->getMessage());
        return $fallback;
    }
}

/**
 * Insert or update booking email template configuration
 */
function upsertBookingEmailTemplateConfig(string $templateKey, string $templateName, string $subject, string $htmlBody, string $textBody = '', int $isActive = 1)
{
    global $pdo;

    if (!ensureBookingEmailTemplatesTable()) {
        return false;
    }

    try {
        $sql = "INSERT INTO booking_email_templates (template_key, template_name, subject, html_body, text_body, is_active)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    template_name = VALUES(template_name),
                    subject = VALUES(subject),
                    html_body = VALUES(html_body),
                    text_body = VALUES(text_body),
                    is_active = VALUES(is_active),
                    updated_at = CURRENT_TIMESTAMP";

        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute([
            $templateKey,
            $templateName,
            $subject,
            $htmlBody,
            $textBody,
            (int)$isActive
        ]);

        if ($ok) {
            deleteCache('booking_email_template_' . $templateKey);
        }

        return $ok;
    } catch (PDOException $e) {
        error_log("Error upserting booking email template {$templateKey}: " . $e->getMessage());
        return false;
    }
}

/**
 * Preload common settings for better performance
 */
function preloadCommonSettings()
{
    $common_settings = [
        'site_name',
        'site_description',
        'currency_symbol',
        'phone_main',
        'email_reservations',
        'email_info',
        'social_facebook',
        'social_instagram',
        'social_twitter'
    ];

    foreach ($common_settings as $setting) {
        getSetting($setting);
    }
}

// Preload common settings for faster page loads
preloadCommonSettings();

/**
 * Helper function to get all settings by group
 */
function getSettingsByGroup(string $group)
{
    global $pdo;

    // Check cache first
    $cached = getCache("settings_group_{$group}", null);
    if ($cached !== null) {
        return $cached;
    }

    try {
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_group = ?");
        $stmt->execute([$group]);
        $result = $stmt->fetchAll();

        // Cache for 30 minutes
        setCache("settings_group_{$group}", $result, 1800);

        return $result;
    } catch (PDOException $e) {
        error_log("Error fetching settings: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get cached rooms with optional filters
 * Dramatically reduces database queries for room listings
 */
function getCachedRooms(array $filters = [])
{
    global $pdo;

    // Create cache key from filters
    $cacheKey = 'rooms_' . md5(json_encode($filters));
    $cached = getCache($cacheKey, null);
    if ($cached !== null) {
        return $cached;
    }

    try {
        $sql = "SELECT * FROM rooms WHERE is_active = 1";
        $params = [];

        if (!empty($filters['is_featured'])) {
            $sql .= " AND is_featured = 1";
        }

        $sql .= " ORDER BY display_order ASC, id ASC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (mediaTablesAvailable() && !empty($rooms)) {
            foreach ($rooms as &$roomRow) {
                $roomRow = applyManagedMediaOverrides($roomRow, 'rooms', $roomRow['id'] ?? '', ['image_url', 'video_path']);
            }
            unset($roomRow);
        }

        // Cache for 15 minutes
        setCache($cacheKey, $rooms, 900);

        return $rooms;
    } catch (PDOException $e) {
        error_log("Error fetching rooms: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get cached facilities
 */
function getCachedFacilities(array $filters = [])
{
    global $pdo;

    $cacheKey = 'facilities_' . md5(json_encode($filters));
    $cached = getCache($cacheKey, null);
    if ($cached !== null) {
        return $cached;
    }

    try {
        $sql = "SELECT * FROM facilities WHERE is_active = 1";
        $params = [];

        if (!empty($filters['is_featured'])) {
            $sql .= " AND is_featured = 1";
        }

        $sql .= " ORDER BY display_order ASC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cache for 30 minutes
        setCache($cacheKey, $facilities, 1800);

        return $facilities;
    } catch (PDOException $e) {
        error_log("Error fetching facilities: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get cached gallery images
 */
function getCachedGalleryImages()
{
    global $pdo;

    $cacheKey = 'gallery_images';
    $cached = getCache($cacheKey, null);
    if ($cached !== null) {
        return $cached;
    }

    try {
        $stmt = $pdo->query("
            SELECT id, title, description, image_url, video_path, video_type, category, display_order
            FROM hotel_gallery
            WHERE is_active = 1
            ORDER BY display_order ASC
        ");
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (mediaTablesAvailable() && !empty($images)) {
            foreach ($images as &$imageRow) {
                $imageRow = applyManagedMediaOverrides($imageRow, 'hotel_gallery', $imageRow['id'] ?? '', ['image_url', 'video_path']);
            }
            unset($imageRow);
        }

        // Cache for 1 hour
        setCache($cacheKey, $images, 3600);

        return $images;
    } catch (PDOException $e) {
        error_log("Error fetching gallery images: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetch active managed media items by placement key.
 *
 * Backward compatibility:
 * - $group_key remains the public API parameter name.
 * - It maps to managed_media_catalog.placement_key.
 */
function getManagedMediaItems(string $group_key, array $options = []): array
{
    global $pdo;

    $limit = isset($options['limit']) ? (int)$options['limit'] : 0;
    $mediaType = $options['media_type'] ?? null; // image|video|null

    // Unified catalog path (canonical)
    try {
        $sql = "
            SELECT
                c.id,
                NULL AS group_id,
                c.title,
                c.description,
                c.media_type,
                c.source_type,
                CASE WHEN c.source_type = 'upload' THEN c.media_url ELSE NULL END AS file_path,
                CASE WHEN c.source_type = 'url' THEN c.media_url ELSE NULL END AS external_url,
                c.mime_type,
                c.alt_text,
                c.caption,
                c.display_order,
                c.is_active,
                c.placement_key,
                c.page_slug,
                c.section_key,
                c.media_url
            FROM managed_media_catalog c
            WHERE c.placement_key = ?
              AND c.is_active = 1
              AND c.media_url IS NOT NULL
              AND c.media_url <> ''
        ";

        $params = [$group_key];

        if ($mediaType === 'image' || $mediaType === 'video') {
            $sql .= " AND c.media_type = ?";
            $params[] = $mediaType;
        }

        $sql .= " ORDER BY c.display_order ASC, c.id ASC";

        if ($limit > 0) {
            $sql .= " LIMIT " . $limit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("Error fetching managed media ({$group_key}): " . $e->getMessage());
        return [];
    }
}

/**
 * Returns true when unified media mapping tables are available.
 */
function mediaTablesAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    global $pdo;

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'managed_media_links'");
        $hasLinks = (bool)$stmt->fetchColumn();
        $stmt = $pdo->query("SHOW TABLES LIKE 'managed_media_catalog'");
        $hasCatalog = (bool)$stmt->fetchColumn();
        $available = $hasLinks && $hasCatalog;
    } catch (Throwable $e) {
        $available = false;
    }

    return $available;
}

/**
 * Returns true when managed_media_catalog has legacy tracking columns.
 */
function mediaCatalogHasLegacyColumns(): bool
{
    static $hasLegacyColumns = null;
    if ($hasLegacyColumns !== null) {
        return $hasLegacyColumns;
    }

    global $pdo;

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM managed_media_catalog LIKE 'legacy_source'");
        $hasLegacySource = (bool)$stmt->fetchColumn();
        $stmt = $pdo->query("SHOW COLUMNS FROM managed_media_catalog LIKE 'legacy_id'");
        $hasLegacyId = (bool)$stmt->fetchColumn();
        $hasLegacyColumns = $hasLegacySource && $hasLegacyId;
    } catch (Throwable $e) {
        $hasLegacyColumns = false;
    }

    return $hasLegacyColumns;
}

/**
 * Infer media type from source column/path.
 */
function inferMediaTypeFromSource(string $sourceColumn, ?string $mediaUrl = null): string
{
    $column = strtolower($sourceColumn);
    $url = strtolower((string)$mediaUrl);

    if (strpos($column, 'video') !== false) {
        return 'video';
    }

    if (preg_match('/\.(mp4|webm|ogg|mov|m4v)(\?|$)/i', $url)) {
        return 'video';
    }

    return 'image';
}

/**
 * Return source type for a media URL/path.
 */
function inferSourceTypeFromMediaUrl(?string $mediaUrl): string
{
    return preg_match('#^https?://#i', trim((string)$mediaUrl)) ? 'url' : 'upload';
}

/**
 * Upsert canonical media + source mapping.
 *
 * @param string $sourceTable Legacy source table name
 * @param int|string $sourceRecordId Legacy record identifier
 * @param string $sourceColumn Legacy media column name
 * @param string|null $mediaUrl Media path/URL from source
 * @param array $context Optional context keys: title, description, caption, alt_text,
 *                       page_slug, section_key, placement_key, entity_type, entity_id,
 *                       display_order, source_context, use_case, media_type
 */
function upsertManagedMediaForSource(
    string $sourceTable,
    int|string $sourceRecordId,
    string $sourceColumn,
    ?string $mediaUrl,
    array $context = []
): bool {
    global $pdo;

    if (!mediaTablesAvailable()) {
        return false;
    }

    $sourceRecordId = (string)$sourceRecordId;
    $mediaUrl = trim((string)$mediaUrl);
    $sourceContext = trim((string)($context['source_context'] ?? ''));

    try {
        // If source media was removed, keep mapping row but mark inactive.
        if ($mediaUrl === '') {
            $deactivate = $pdo->prepare("UPDATE managed_media_links SET is_active = 0 WHERE source_table = ? AND source_record_id = ? AND source_column = ? AND source_context = ?");
            $deactivate->execute([$sourceTable, $sourceRecordId, $sourceColumn, $sourceContext]);
            return true;
        }

        $mediaType = (($context['media_type'] ?? '') === 'video' || ($context['media_type'] ?? '') === 'image')
            ? $context['media_type']
            : inferMediaTypeFromSource($sourceColumn, $mediaUrl);
        $sourceType = inferSourceTypeFromMediaUrl($mediaUrl);

        $legacySource = $sourceTable . '.' . $sourceColumn;
        $legacyId = ctype_digit($sourceRecordId) ? (int)$sourceRecordId : null;
        $catalogHasLegacyColumns = mediaCatalogHasLegacyColumns();

        $catalogId = null;

        if ($legacyId !== null && $catalogHasLegacyColumns) {
            $lookup = $pdo->prepare("SELECT id FROM managed_media_catalog WHERE legacy_source = ? AND legacy_id = ? LIMIT 1");
            $lookup->execute([$legacySource, $legacyId]);
            $catalogId = (int)($lookup->fetchColumn() ?: 0);
        }

        if ($catalogId <= 0) {
            $lookupByLink = $pdo->prepare("SELECT media_catalog_id FROM managed_media_links WHERE source_table = ? AND source_record_id = ? AND source_column = ? AND source_context = ? LIMIT 1");
            $lookupByLink->execute([$sourceTable, $sourceRecordId, $sourceColumn, $sourceContext]);
            $catalogId = (int)($lookupByLink->fetchColumn() ?: 0);
        }

        if ($catalogId > 0) {
            $updateCatalog = $pdo->prepare("UPDATE managed_media_catalog SET title = ?, description = ?, media_type = ?, source_type = ?, media_url = ?, mime_type = COALESCE(?, mime_type), alt_text = ?, caption = ?, placement_key = ?, page_slug = ?, section_key = ?, entity_type = ?, entity_id = ?, is_active = 1, display_order = ? WHERE id = ?");
            $updateCatalog->execute([
                (string)($context['title'] ?? ucfirst(str_replace('_', ' ', $sourceColumn))),
                (string)($context['description'] ?? ''),
                $mediaType,
                $sourceType,
                $mediaUrl,
                $context['mime_type'] ?? null,
                $context['alt_text'] ?? null,
                $context['caption'] ?? null,
                $context['placement_key'] ?? null,
                $context['page_slug'] ?? null,
                $context['section_key'] ?? null,
                $context['entity_type'] ?? null,
                $context['entity_id'] ?? null,
                (int)($context['display_order'] ?? 0),
                $catalogId,
            ]);
        } else {
            if ($catalogHasLegacyColumns) {
                $insertCatalog = $pdo->prepare("INSERT INTO managed_media_catalog (title, description, media_type, source_type, media_url, mime_type, alt_text, caption, placement_key, page_slug, section_key, entity_type, entity_id, is_active, display_order, legacy_source, legacy_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)");
                $insertCatalog->execute([
                    (string)($context['title'] ?? ucfirst(str_replace('_', ' ', $sourceColumn))),
                    (string)($context['description'] ?? ''),
                    $mediaType,
                    $sourceType,
                    $mediaUrl,
                    $context['mime_type'] ?? null,
                    $context['alt_text'] ?? null,
                    $context['caption'] ?? null,
                    $context['placement_key'] ?? null,
                    $context['page_slug'] ?? null,
                    $context['section_key'] ?? null,
                    $context['entity_type'] ?? null,
                    $context['entity_id'] ?? null,
                    (int)($context['display_order'] ?? 0),
                    $legacySource,
                    $legacyId,
                    $context['created_by'] ?? null,
                ]);
            } else {
                $insertCatalog = $pdo->prepare("INSERT INTO managed_media_catalog (title, description, media_type, source_type, media_url, mime_type, alt_text, caption, placement_key, page_slug, section_key, entity_type, entity_id, is_active, display_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
                $insertCatalog->execute([
                    (string)($context['title'] ?? ucfirst(str_replace('_', ' ', $sourceColumn))),
                    (string)($context['description'] ?? ''),
                    $mediaType,
                    $sourceType,
                    $mediaUrl,
                    $context['mime_type'] ?? null,
                    $context['alt_text'] ?? null,
                    $context['caption'] ?? null,
                    $context['placement_key'] ?? null,
                    $context['page_slug'] ?? null,
                    $context['section_key'] ?? null,
                    $context['entity_type'] ?? null,
                    $context['entity_id'] ?? null,
                    (int)($context['display_order'] ?? 0),
                    $context['created_by'] ?? null,
                ]);
            }
            $catalogId = (int)$pdo->lastInsertId();
        }

        $linkLookup = $pdo->prepare("SELECT id FROM managed_media_links WHERE source_table = ? AND source_record_id = ? AND source_column = ? AND source_context = ? LIMIT 1");
        $linkLookup->execute([$sourceTable, $sourceRecordId, $sourceColumn, $sourceContext]);
        $linkId = (int)($linkLookup->fetchColumn() ?: 0);

        if ($linkId > 0) {
            $updateLink = $pdo->prepare("UPDATE managed_media_links SET media_catalog_id = ?, media_type = ?, placement_key = ?, page_slug = ?, section_key = ?, entity_type = ?, entity_id = ?, use_case = ?, display_order = ?, is_active = 1 WHERE id = ?");
            $updateLink->execute([
                $catalogId,
                $mediaType,
                $context['placement_key'] ?? null,
                $context['page_slug'] ?? null,
                $context['section_key'] ?? null,
                $context['entity_type'] ?? null,
                $context['entity_id'] ?? null,
                $context['use_case'] ?? null,
                (int)($context['display_order'] ?? 0),
                $linkId,
            ]);
        } else {
            $insertLink = $pdo->prepare("INSERT INTO managed_media_links (media_catalog_id, source_table, source_record_id, source_column, source_context, media_type, placement_key, page_slug, section_key, entity_type, entity_id, use_case, display_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $insertLink->execute([
                $catalogId,
                $sourceTable,
                $sourceRecordId,
                $sourceColumn,
                $sourceContext,
                $mediaType,
                $context['placement_key'] ?? null,
                $context['page_slug'] ?? null,
                $context['section_key'] ?? null,
                $context['entity_type'] ?? null,
                $context['entity_id'] ?? null,
                $context['use_case'] ?? null,
                (int)($context['display_order'] ?? 0),
            ]);
        }

        return true;
    } catch (Throwable $e) {
        error_log('upsertManagedMediaForSource failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Fetch media mapping by source table/record keyed by source_column.
 */
function getManagedMediaMapForRecord(string $sourceTable, int|string $sourceRecordId): array
{
    global $pdo;

    if (!mediaTablesAvailable()) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("SELECT l.source_column, l.media_type, c.media_url, c.mime_type FROM managed_media_links l INNER JOIN managed_media_catalog c ON c.id = l.media_catalog_id WHERE l.source_table = ? AND l.source_record_id = ? AND l.is_active = 1 AND c.is_active = 1");
        $stmt->execute([$sourceTable, (string)$sourceRecordId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[$row['source_column']] = $row;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Apply managed media overrides onto a legacy record with fallback.
 */
function applyManagedMediaOverrides(array $record, string $sourceTable, int|string $sourceRecordId, array $columns): array
{
    $map = getManagedMediaMapForRecord($sourceTable, $sourceRecordId);
    if (empty($map)) {
        return $record;
    }

    foreach ($columns as $column) {
        if (!empty($map[$column]['media_url'])) {
            $record[$column] = $map[$column]['media_url'];
        }
    }

    return $record;
}

/**
 * Fetch first active managed media item for a group key.
 */
function getManagedMediaPrimary(string $group_key, ?string $media_type = null): ?array
{
    $items = getManagedMediaItems($group_key, [
        'media_type' => $media_type,
        'limit' => 1,
    ]);
    return $items[0] ?? null;
}

/**
 * Helper function to get cached testimonials
 */
function getCachedTestimonials(int $limit = 3)
{
    global $pdo;

    $cacheKey = "testimonials_{$limit}";
    $cached = getCache($cacheKey, null);
    if ($cached !== null) {
        return $cached;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM testimonials
            WHERE is_featured = 1 AND is_approved = 1
            ORDER BY display_order ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $testimonials = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cache for 30 minutes
        setCache($cacheKey, $testimonials, 1800);

        return $testimonials;
    } catch (PDOException $e) {
        error_log("Error fetching testimonials: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get cached policies
 */
function getCachedPolicies()
{
    global $pdo;

    $cacheKey = 'policies';
    $cached = getCache($cacheKey, null);
    if ($cached !== null) {
        return $cached;
    }

    try {
        $stmt = $pdo->query("
            SELECT slug, title, summary, content
            FROM policies
            WHERE is_active = 1
            ORDER BY display_order ASC, id ASC
        ");
        $policies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cache for 1 hour
        setCache($cacheKey, $policies, 3600);

        return $policies;
    } catch (PDOException $e) {
        error_log("Error fetching policies: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get cached About Us content
 */
function getCachedAboutUs()
{
    global $pdo;

    $cacheKey = 'about_us';
    $cached = getCache($cacheKey, null);
    if ($cached !== null) {
        return $cached;
    }

    try {
        // Get main about content
        $stmt = $pdo->prepare("SELECT * FROM about_us WHERE section_type = 'main' AND is_active = 1 ORDER BY display_order LIMIT 1");
        $stmt->execute();
        $about_content = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($about_content)) {
            $about_content = applyManagedMediaOverrides($about_content, 'about_us', $about_content['id'] ?? '', ['image_url']);
        }

        // Get features
        $stmt = $pdo->prepare("SELECT * FROM about_us WHERE section_type = 'feature' AND is_active = 1 ORDER BY display_order");
        $stmt->execute();
        $about_features = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($about_features)) {
            foreach ($about_features as &$featureRow) {
                $featureRow = applyManagedMediaOverrides($featureRow, 'about_us', $featureRow['id'] ?? '', ['image_url']);
            }
            unset($featureRow);
        }

        // Get stats
        $stmt = $pdo->prepare("SELECT * FROM about_us WHERE section_type = 'stat' AND is_active = 1 ORDER BY display_order");
        $stmt->execute();
        $about_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($about_stats)) {
            foreach ($about_stats as &$statsRow) {
                $statsRow = applyManagedMediaOverrides($statsRow, 'about_us', $statsRow['id'] ?? '', ['image_url']);
            }
            unset($statsRow);
        }

        $result = [
            'content' => $about_content,
            'features' => $about_features,
            'stats' => $about_stats
        ];

        // Cache for 1 hour
        setCache($cacheKey, $result, 3600);

        return $result;
    } catch (PDOException $e) {
        error_log("Error fetching about us content: " . $e->getMessage());
        return ['content' => null, 'features' => [], 'stats' => []];
    }
}

/**
 * Invalidate all data caches when content changes
 */
function invalidateDataCaches()
{
    // Use clearCacheByPattern() which reads each file's JSON key and matches by regex.
    // The old glob(md5(prefix).'*') approach never matched anything because cache
    // filenames use getReadableCacheFilename() not raw md5 hashes.
    $patterns = [
        'rooms_*',
        'facilities_*',
        'gallery_images',
        'page_hero*',
        'testimonials_*',
        'policies',
        'about_us',
        'settings_group_*',
    ];

    foreach ($patterns as $pattern) {
        clearCacheByPattern($pattern);
    }
}

/**
 * Helper: fetch active page hero by page slug.
 * Returns associative array or null.
 */
function getPageHero(string $page_slug): ?array
{
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM page_heroes
            WHERE page_slug = ? AND is_active = 1
            ORDER BY display_order ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute([$page_slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // page_heroes is the canonical source-of-truth for hero media.
        // Avoid managed_media override precedence here so DB edits to page_heroes
        // are reflected immediately and predictably on the frontend.
        return $row ?: null;
    } catch (PDOException $e) {
        error_log("Error fetching page hero ({$page_slug}): " . $e->getMessage());
        return null;
    }
}

/**
 * Helper: fetch active page hero by exact page URL (e.g. /restaurant.php).
 * Returns associative array or null.
 */
function getPageHeroByUrl(string $page_url): ?array
{
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM page_heroes
            WHERE page_url = ? AND is_active = 1
            ORDER BY display_order ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute([$page_url]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // page_heroes is the canonical source-of-truth for hero media.
        // Avoid managed_media override precedence here so DB edits to page_heroes
        // are reflected immediately and predictably on the frontend.
        return $row ?: null;
    } catch (PDOException $e) {
        error_log("Error fetching page hero by url ({$page_url}): " . $e->getMessage());
        return null;
    }
}

/**
 * Helper: get hero for the current request without hardcoding per-page slugs.
 * Strategy:
 *  1) Try exact match on page_url (SCRIPT_NAME).
 *  2) Fallback to slug derived from current filename (basename without .php).
 */
function getCurrentPageHero(): ?array
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($script) {
        $byUrl = getPageHeroByUrl($script);
        if ($byUrl) return $byUrl;
    }

    $path = $_SERVER['SCRIPT_FILENAME'] ?? $script;
    if (!$path) return null;

    $slug = strtolower(pathinfo($path, PATHINFO_FILENAME));
    $slug = str_replace('_', '-', $slug);

    return getPageHero($slug);
}

/**
 * Helper: fetch active page loader subtext by page slug.
 * Returns the subtext string if found and active, null otherwise.
 */
function getPageLoader(string $page_slug): ?string
{
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT subtext
            FROM page_loaders
            WHERE page_slug = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$page_slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['subtext'] : null;
    } catch (PDOException $e) {
        error_log("Error fetching page loader ({$page_slug}): " . $e->getMessage());
        return null;
    }
}

/**
 * Booking Lifecycle Constants and Helper Functions
 *
 * These functions standardize booking status transitions and validation
 * across the entire application to ensure consistent behavior.
 */

/**
 * Get booking statuses that consume room inventory (block availability)
 *
 * For room type availability: includes 'pending' as they hold inventory
 * For individual room availability: only 'confirmed' and 'checked-in' as they have assigned rooms
 *
 * NOTE: active 'tentative' bookings block availability until expiration
 * NOTE: 'cancelled' bookings do NOT block availability (they free up the room)
 *
 * @param bool $forIndividualRoom If true, returns stricter list for individual rooms
 * @return array List of statuses that block availability
 */
function getBookingStatusesThatBlockAvailability(bool $forIndividualRoom = false): array
{
    if ($forIndividualRoom) {
        // Any active booking assigned to a specific physical room blocks it.
        return ['pending', 'tentative', 'confirmed', 'checked-in'];
    }
    // Room type availability considers pending and tentative bookings as blocking
    // (they hold a room from the inventory pool until cancelled, expired, or converted)
    // Cancelled bookings do NOT block - they free up the room
    return ['pending', 'tentative', 'confirmed', 'checked-in'];
}

/**
 * Get booking statuses that are considered "active" (not cancelled, expired, no-show)
 *
 * @return array List of active booking statuses
 */
function getActiveBookingStatuses(): array
{
    return ['pending', 'tentative', 'confirmed', 'checked-in', 'checked-out'];
}

/**
 * Get booking statuses that are considered "terminal" (cannot transition to other states)
 *
 * @return array List of terminal booking statuses
 */
function getTerminalBookingStatuses(): array
{
    return ['cancelled', 'expired', 'no-show', 'checked-out'];
}

/**
 * Validate if a booking status transition is allowed
 *
 * @param string $currentStatus Current booking status
 * @param string $newStatus Desired new status
 * @return array ['allowed' => bool, 'reason' => string]
 */
function validateBookingStatusTransition(string $currentStatus, string $newStatus): array
{
    // Define valid transitions
    // NOTE: 'tentative' is NOT allowed from 'confirmed' - once confirmed or paid, cannot revert to tentative
    $validTransitions = [
        'pending' => ['tentative', 'confirmed', 'cancelled', 'expired'],
        'tentative' => ['confirmed', 'cancelled', 'expired'],
        'confirmed' => ['checked-in', 'cancelled', 'no-show'], // NO 'tentative' - confirmed bookings cannot revert
        'checked-in' => ['checked-out', 'confirmed'], // Can cancel check-in (revert to confirmed)
        'checked-out' => [], // Terminal state
        'cancelled' => [], // Terminal state
        'expired' => [], // Terminal state
        'no-show' => [], // Terminal state
    ];

    // Same status is always allowed (idempotent)
    if ($currentStatus === $newStatus) {
        return ['allowed' => true, 'reason' => ''];
    }

    // Check if transition is valid
    if (!isset($validTransitions[$currentStatus])) {
        return ['allowed' => false, 'reason' => "Unknown current status: {$currentStatus}"];
    }

    if (!in_array($newStatus, $validTransitions[$currentStatus], true)) {
        return ['allowed' => false, 'reason' => "Cannot transition from '{$currentStatus}' to '{$newStatus}'"];
    }

    return ['allowed' => true, 'reason' => ''];
}

/**
 * Validate if a booking can be checked in
 *
 * @param array $booking Booking record (must include status, payment_status, individual_room_id, check_in_date)
 * @return array ['allowed' => bool, 'reason' => string]
 */
function validateCheckIn(array $booking): array
{
    $requiredFields = ['status', 'payment_status', 'check_in_date'];
    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $booking)) {
            return ['allowed' => false, 'reason' => "Missing required field: {$field}"];
        }
    }

    if ($booking['status'] !== 'confirmed') {
        return ['allowed' => false, 'reason' => "Booking must be CONFIRMED to check in (current: {$booking['status']})"];
    }

    // Unpaid bookings cannot check in; partial payment is allowed (balance collected at reception)
    if ($booking['payment_status'] === 'unpaid' || $booking['payment_status'] === '') {
        return ['allowed' => false, 'reason' => "Booking must have at least a partial payment to check in (current: {$booking['payment_status']})"];
    }

    // individual_room_id is NOT a hard requirement — not all properties track physical room numbers,
    // and auto-assigned bookings legitimately have no individual_room_id set.

    // Date-based validation: check-in only allowed on or after check-in date
    $check_in_date = new DateTime((string)$booking['check_in_date']);
    $check_in_date->setTime(0, 0, 0);
    $today = new DateTime('today');

    if ($check_in_date > $today) {
        return ['allowed' => false, 'reason' => "Check-in date has not been reached yet (check-in: {$booking['check_in_date']})"];
    }

    return ['allowed' => true, 'reason' => ''];
}

/**
 * Validate if a booking can be checked out
 *
 * @param array $booking Booking record (must include status, check_out_date)
 * @return array ['allowed' => bool, 'reason' => string]
 */
function validateCheckOut(array $booking): array
{
    $requiredFields = ['status', 'check_out_date'];
    foreach ($requiredFields as $field) {
        if (!isset($booking[$field])) {
            return ['allowed' => false, 'reason' => "Missing required field: {$field}"];
        }
    }

    if ($booking['status'] !== 'checked-in') {
        return ['allowed' => false, 'reason' => "Booking must be CHECKED-IN to check out (current: {$booking['status']})"];
    }

    // Date-based validation: check-out only allowed on or after check-out date
    // (hotel policy may allow early checkout, but date must not be in the future beyond scheduled checkout)
    $check_out_date = new DateTime($booking['check_out_date']);
    $check_out_date->setTime(0, 0, 0);
    $tomorrow = (new DateTime('today'))->modify('+1 day');

    // Allow checkout if today is on or after the check-in date (early checkout is OK)
    // but prevent checkout if check-out date is far in the future (more than 1 day ahead)
    // This allows same-day checkout and early checkout
    if ($check_out_date > $tomorrow) {
        return ['allowed' => false, 'reason' => "Check-out date is too far in the future (scheduled: {$booking['check_out_date']})"];
    }

    return ['allowed' => true, 'reason' => ''];
}

/**
 * Validate if a room can be assigned to a booking
 *
 * @param array $booking Booking record (must include status)
 * @return array ['allowed' => bool, 'reason' => string]
 */
function validateRoomAssignment(array $booking): array
{
    if (!isset($booking['status'])) {
        return ['allowed' => false, 'reason' => "Missing required field: status"];
    }

    if ($booking['status'] !== 'confirmed') {
        return ['allowed' => false, 'reason' => "Rooms can only be assigned to CONFIRMED bookings (current: {$booking['status']})"];
    }

    return ['allowed' => true, 'reason' => ''];
}

function getIndividualRoomEffectivePolicy(int $roomTypeId, int $individualRoomId): ?array
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT
                ir.id,
                ir.room_type_id,
                ir.room_number,
                ir.room_name,
                ir.floor,
                ir.single_occupancy_enabled_override,
                ir.double_occupancy_enabled_override,
                ir.triple_occupancy_enabled_override,
                ir.children_allowed_override,
                ir.max_guests_override,
                r.max_guests,
                r.single_occupancy_enabled,
                r.double_occupancy_enabled,
                r.triple_occupancy_enabled,
                r.children_allowed,
                r.price_double_occupancy,
                r.price_triple_occupancy
            FROM individual_rooms ir
            JOIN rooms r ON r.id = ir.room_type_id
            WHERE ir.id = ? AND ir.room_type_id = ? AND ir.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$individualRoomId, $roomTypeId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            return null;
        }

        return [
            'room' => $room,
            'policy' => resolveOccupancyPolicy($room, $room),
        ];
    } catch (PDOException $e) {
        error_log('Error resolving individual room policy: ' . $e->getMessage());
        return null;
    }
}

function autoAssignConfirmedPaidBooking(int $bookingId): array
{
    global $pdo;

    $result = [
        'success' => false,
        'message' => '',
        'assigned_room_id' => null,
        'assigned_room_number' => null,
    ];

    try {
        $stmt = $pdo->prepare("SELECT status, payment_status, individual_room_id FROM bookings WHERE id = ?");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $result['message'] = 'Booking not found';
            return $result;
        }

        if (!empty($booking['individual_room_id'])) {
            $result['success'] = true;
            $result['message'] = 'Room already assigned';
            $result['assigned_room_id'] = (int)$booking['individual_room_id'];
            return $result;
        }

        if ($booking['status'] !== 'confirmed') {
            $result['message'] = 'Booking must be confirmed before auto-assignment';
            return $result;
        }

        if (!in_array($booking['payment_status'], ['paid', 'completed'], true)) {
            $result['message'] = 'Booking must be paid before auto-assignment';
            return $result;
        }

        return autoAssignIndividualRoom($bookingId);
    } catch (PDOException $e) {
        error_log('Error checking auto-assignment readiness: ' . $e->getMessage());
        $result['message'] = 'Database error during auto-assignment readiness check';
        return $result;
    }
}

/**
 * Validate if a booking can be cancelled
 *
 * Cancellation is only allowed before guest checks in.
 * Once checked-in, booking cannot be cancelled (must use check-out instead).
 *
 * @param array $booking Booking record (must include status)
 * @return array ['allowed' => bool, 'reason' => string]
 */
function validateBookingCancellation(array $booking): array
{
    if (!isset($booking['status'])) {
        return ['allowed' => false, 'reason' => "Missing required field: status"];
    }

    // Block cancellation for checked-in, checked-out, cancelled, no-show bookings
    $nonCancellableStatuses = ['checked-in', 'checked-out', 'cancelled', 'no-show'];
    if (in_array($booking['status'], $nonCancellableStatuses, true)) {
        if ($booking['status'] === 'checked-in') {
            return ['allowed' => false, 'reason' => "Cannot cancel booking: guest has already checked in (use check-out instead)"];
        }
        if ($booking['status'] === 'checked-out') {
            return ['allowed' => false, 'reason' => "Cannot cancel booking: guest has already checked out"];
        }
        if ($booking['status'] === 'cancelled') {
            return ['allowed' => false, 'reason' => "Booking is already cancelled"];
        }
        if ($booking['status'] === 'no-show') {
            return ['allowed' => false, 'reason' => "Cannot cancel booking: marked as no-show"];
        }
    }

    return ['allowed' => true, 'reason' => ''];
}

/**
 * Validate if a booking can be converted to tentative status
 *
 * Business rules:
 * - Only pending bookings can be made tentative (not confirmed, checked-in, checked-out, cancelled)
 * - Bookings with any payment (paid or partial) cannot be made tentative
 * - Once confirmed or paid, a booking cannot revert to tentative
 *
 * @param array $booking Booking record (must include status, payment_status)
 * @return array ['allowed' => bool, 'reason' => string]
 */
function validateTentativeTransition(array $booking): array
{
    $requiredFields = ['status', 'payment_status'];
    foreach ($requiredFields as $field) {
        if (!isset($booking[$field])) {
            return ['allowed' => false, 'reason' => "Missing required field: {$field}"];
        }
    }

    // Only pending bookings can be made tentative
    if ($booking['status'] !== 'pending') {
        $statusMap = [
            'tentative' => 'Booking is already tentative',
            'confirmed' => 'Confirmed bookings cannot be made tentative',
            'checked-in' => 'Checked-in bookings cannot be made tentative',
            'checked-out' => 'Checked-out bookings cannot be made tentative',
            'cancelled' => 'Cancelled bookings cannot be made tentative',
            'no-show' => 'No-show bookings cannot be made tentative',
            'expired' => 'Expired bookings cannot be made tentative',
        ];
        return ['allowed' => false, 'reason' => $statusMap[$booking['status']] ?? "Cannot make booking tentative from current status: {$booking['status']}"];
    }

    // Block if any payment exists (paid or partial)
    if (in_array($booking['payment_status'], ['paid', 'partial'], true)) {
        return ['allowed' => false, 'reason' => "Bookings with payments cannot be made tentative (current payment status: {$booking['payment_status']})"];
    }

    return ['allowed' => true, 'reason' => ''];
}

/**
 * Get user-friendly error message for booking actions
 *
 * @param string $action Action being attempted
 * @param string $reason Technical reason from validation
 * @return string User-friendly error message
 */
function getBookingActionErrorMessage(string $action, string $reason): string
{
    $messages = [
        'check_in' => [
            'status' => 'Cannot check in: Booking must be confirmed first.',
            'payment' => 'Cannot check in: Payment must be completed first.',
            'room' => 'Cannot check in: Please assign a room first.',
            'date' => 'Cannot check in: Check-in date has not been reached yet.',
        ],
        'check_out' => [
            'status' => 'Cannot check out: Guest must be checked in first.',
            'date' => 'Cannot check out: Check-out date is too far in the future.',
        ],
        'cancel' => [
            'status' => 'Cannot cancel booking: Invalid status for cancellation.',
            'checked_in' => 'Cannot cancel booking: Guest has already checked in. Use check-out instead.',
            'checked_out' => 'Cannot cancel booking: Guest has already checked out.',
            'cancelled' => 'Booking is already cancelled.',
            'noshow' => 'Cannot cancel booking: Marked as no-show.',
        ],
        'assign_room' => [
            'status' => 'Cannot assign room: Booking must be confirmed first.',
        ],
        'confirm' => [
            'availability' => 'Cannot confirm: No rooms available for the selected dates.',
        ],
        'make_tentative' => [
            'status' => 'Cannot make tentative: Booking must be in pending status.',
            'payment' => 'Cannot make tentative: Bookings with payments cannot be made tentative.',
            'confirmed' => 'Cannot make tentative: Confirmed bookings cannot revert to tentative status.',
        ],
    ];

    // Parse the reason to determine the error type
    if (strpos($reason, 'CONFIRMED') !== false || strpos($reason, 'confirmed') !== false) {
        return $messages[$action]['status'] ?? $reason;
    }
    if (strpos($reason, 'PAID') !== false || strpos($reason, 'paid') !== false) {
        return $messages[$action]['payment'] ?? $reason;
    }
    if (strpos($reason, 'room') !== false) {
        return $messages[$action]['room'] ?? $reason;
    }
    if (strpos($reason, 'available') !== false) {
        return $messages[$action]['availability'] ?? $reason;
    }

    // Default to the original reason if no specific mapping
    return $reason;
}

/**
 * Check whether an optional availability-related table exists.
 */
function bookingAvailabilityTableExists(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        error_log("Availability table check warning [{$table}]: " . $e->getMessage());
        $cache[$table] = false;
    }

    return $cache[$table];
}

function getBookingRooms(int $bookingId): array
{
    global $pdo;

    if ($bookingId <= 0) {
        return [];
    }

    try {
        if (bookingAvailabilityTableExists('booking_rooms')) {
            $stmt = $pdo->prepare("
                SELECT
                    br.individual_room_id,
                    br.room_combination_id,
                    br.is_primary,
                    ir.room_number,
                    ir.room_name,
                    ir.floor,
                    ir.status,
                    ir.room_type_id,
                    r.name AS room_type_name
                FROM booking_rooms br
                JOIN individual_rooms ir ON ir.id = br.individual_room_id
                LEFT JOIN rooms r ON r.id = ir.room_type_id
                WHERE br.booking_id = ?
                ORDER BY br.is_primary DESC, ir.room_number ASC, br.id ASC
            ");
            $stmt->execute([$bookingId]);
            $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rooms)) {
                return $rooms;
            }
        }

        $fallback = $pdo->prepare("
            SELECT
                b.individual_room_id,
                b.room_combination_id,
                1 AS is_primary,
                ir.room_number,
                ir.room_name,
                ir.floor,
                ir.status,
                ir.room_type_id,
                r.name AS room_type_name
            FROM bookings b
            JOIN individual_rooms ir ON ir.id = b.individual_room_id
            LEFT JOIN rooms r ON r.id = ir.room_type_id
            WHERE b.id = ? AND b.individual_room_id IS NOT NULL
        ");
        $fallback->execute([$bookingId]);
        return $fallback->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('getBookingRooms warning: ' . $e->getMessage());
        return [];
    }
}

function getBookingRoomIds(int $bookingId): array
{
    $ids = [];
    foreach (getBookingRooms($bookingId) as $room) {
        $roomId = (int)($room['individual_room_id'] ?? 0);
        if ($roomId > 0) {
            $ids[] = $roomId;
        }
    }
    return array_values(array_unique($ids));
}

function getBookingRoomLabel(int $bookingId, string $fallback = ''): string
{
    $labels = [];
    foreach (getBookingRooms($bookingId) as $room) {
        $label = trim((string)($room['room_number'] ?? ''));
        if ($label === '') {
            $label = trim((string)($room['room_name'] ?? ''));
        }
        if ($label !== '') {
            $labels[] = $label;
        }
    }

    $labels = array_values(array_unique($labels));
    if (!empty($labels)) {
        return implode(' + ', $labels);
    }

    return $fallback;
}

function syncBookingRooms(int $bookingId, array $individualRoomIds, ?int $roomCombinationId = null): void
{
    global $pdo;

    if ($bookingId <= 0 || empty($individualRoomIds) || !bookingAvailabilityTableExists('booking_rooms')) {
        return;
    }

    $normalizedIds = [];
    foreach ($individualRoomIds as $roomId) {
        $roomId = (int)$roomId;
        if ($roomId > 0 && !in_array($roomId, $normalizedIds, true)) {
            $normalizedIds[] = $roomId;
        }
    }

    if (empty($normalizedIds)) {
        return;
    }

    $pdo->prepare("UPDATE booking_rooms SET released_at = NOW() WHERE booking_id = ? AND released_at IS NULL")->execute([$bookingId]);
    $insert = $pdo->prepare("
        INSERT INTO booking_rooms (booking_id, individual_room_id, room_combination_id, is_primary, assigned_at, status_snapshot)
        VALUES (?, ?, ?, ?, NOW(), 'assigned')
        ON DUPLICATE KEY UPDATE
            room_combination_id = VALUES(room_combination_id),
            is_primary = VALUES(is_primary),
            assigned_at = NOW(),
            released_at = NULL,
            status_snapshot = VALUES(status_snapshot)
    ");

    foreach ($normalizedIds as $index => $roomId) {
        $insert->execute([$bookingId, $roomId, $roomCombinationId, $index === 0 ? 1 : 0]);
    }
}

function updateBookingRoomsStatus(int $bookingId, string $newStatus, string $reason, ?int $performedBy = null): int
{
    global $pdo;

    $updated = 0;
    $roomIds = getBookingRoomIds($bookingId);
    if (empty($roomIds)) {
        return 0;
    }

    if ($pdo->inTransaction()) {
        $statusStmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
        $updateStmt = $pdo->prepare("UPDATE individual_rooms SET status = ? WHERE id = ?");
        $logStmt = $pdo->prepare("
            INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($roomIds as $roomId) {
            $statusStmt->execute([$roomId]);
            $oldStatus = (string)($statusStmt->fetchColumn() ?: 'available');
            $updateStmt->execute([$newStatus, $roomId]);
            $logStmt->execute([$roomId, $oldStatus, $newStatus, $reason, $performedBy]);
            $updated++;
        }
    } else {
        foreach ($roomIds as $roomId) {
            if (updateIndividualRoomStatus($roomId, $newStatus, $reason, $performedBy)) {
                $updated++;
            }
        }
    }

    return $updated;
}

function roomTypeHasActiveCombinations(int $roomTypeId): bool
{
    global $pdo;

    if ($roomTypeId <= 0 || !bookingAvailabilityTableExists('room_combinations')) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM room_combinations WHERE combined_room_type_id = ? AND is_active = 1");
        $stmt->execute([$roomTypeId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('roomTypeHasActiveCombinations warning: ' . $e->getMessage());
        return false;
    }
}

function getAvailableRoomCombinations(int $roomTypeId, string $checkIn, string $checkOut, ?int $excludeBookingId = null): array
{
    global $pdo;

    if ($roomTypeId <= 0 || !bookingAvailabilityTableExists('room_combinations')) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                rc.*,
                a.room_number AS room_a_number,
                a.room_name AS room_a_name,
                a.status AS room_a_status,
                b.room_number AS room_b_number,
                b.room_name AS room_b_name,
                b.status AS room_b_status,
                rt.name AS combined_room_type_name,
                rt.price_per_night AS combined_type_price,
                rt.max_guests AS combined_type_max_guests
            FROM room_combinations rc
            JOIN individual_rooms a ON a.id = rc.room_a_id AND a.is_active = 1
            JOIN individual_rooms b ON b.id = rc.room_b_id AND b.is_active = 1
            JOIN rooms rt ON rt.id = rc.combined_room_type_id AND rt.is_active = 1
            WHERE rc.combined_room_type_id = ? AND rc.is_active = 1
            ORDER BY rc.combined_name ASC, a.room_number ASC, b.room_number ASC
        ");
        $stmt->execute([$roomTypeId]);
        $combinations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $available = [];

        foreach ($combinations as $combination) {
            $roomA = (int)$combination['room_a_id'];
            $roomB = (int)$combination['room_b_id'];
            if ($roomA <= 0 || $roomB <= 0 || $roomA === $roomB) {
                continue;
            }

            $aAvailability = checkIndividualRoomAvailability($roomA, $checkIn, $checkOut, $excludeBookingId);
            if (empty($aAvailability['available'])) {
                continue;
            }

            $bAvailability = checkIndividualRoomAvailability($roomB, $checkIn, $checkOut, $excludeBookingId);
            if (empty($bAvailability['available'])) {
                continue;
            }

            $combination['room_ids'] = [$roomA, $roomB];
            $combination['room_numbers'] = array_values(array_filter([
                (string)($combination['room_a_number'] ?? ''),
                (string)($combination['room_b_number'] ?? ''),
            ]));
            $available[] = $combination;
        }

        return $available;
    } catch (Throwable $e) {
        error_log('getAvailableRoomCombinations warning: ' . $e->getMessage());
        return [];
    }
}

function getRoomCombinationAvailabilitySummary(int $roomTypeId, string $checkIn, string $checkOut, ?int $excludeBookingId = null): ?array
{
    if (!roomTypeHasActiveCombinations($roomTypeId)) {
        return null;
    }

    $available = getAvailableRoomCombinations($roomTypeId, $checkIn, $checkOut, $excludeBookingId);
    $maxGuests = 0;
    foreach ($available as $combination) {
        $maxGuests = max($maxGuests, (int)($combination['max_guests_combined'] ?? $combination['combined_type_max_guests'] ?? 0));
    }

    return [
        'inventory_count' => count($available),
        'remaining_rooms' => count($available),
        'available_combinations' => $available,
        'max_guests' => $maxGuests,
    ];
}

function assignRoomCombinationToBooking(int $bookingId, int $combinationId, ?int $performedBy = null): array
{
    global $pdo;

    $result = [
        'success' => false,
        'message' => '',
        'assigned_room_id' => null,
        'assigned_room_number' => null,
        'room_ids' => [],
        'room_numbers' => [],
    ];

    $ownTransaction = !$pdo->inTransaction();

    try {
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        $bookingStmt = $pdo->prepare("SELECT id, booking_reference, room_id, check_in_date, check_out_date, status FROM bookings WHERE id = ? FOR UPDATE");
        $bookingStmt->execute([$bookingId]);
        $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            throw new RuntimeException('Booking not found');
        }

        $comboStmt = $pdo->prepare("
            SELECT rc.*, a.room_number AS room_a_number, b.room_number AS room_b_number
            FROM room_combinations rc
            JOIN individual_rooms a ON a.id = rc.room_a_id
            JOIN individual_rooms b ON b.id = rc.room_b_id
            WHERE rc.id = ? AND rc.is_active = 1
            LIMIT 1
        ");
        $comboStmt->execute([$combinationId]);
        $combination = $comboStmt->fetch(PDO::FETCH_ASSOC);
        if (!$combination || (int)$combination['combined_room_type_id'] !== (int)$booking['room_id']) {
            throw new RuntimeException('Room combination does not match this booking room type');
        }

        $roomIds = [(int)$combination['room_a_id'], (int)$combination['room_b_id']];
        sort($roomIds);
        $lockSql = 'SELECT id FROM individual_rooms WHERE id IN (' . implode(',', array_fill(0, count($roomIds), '?')) . ') FOR UPDATE';
        $lockStmt = $pdo->prepare($lockSql);
        $lockStmt->execute($roomIds);

        foreach ($roomIds as $roomId) {
            $availability = checkIndividualRoomAvailability($roomId, (string)$booking['check_in_date'], (string)$booking['check_out_date'], $bookingId);
            if (empty($availability['available'])) {
                throw new RuntimeException($availability['error'] ?? 'One of the joined rooms is no longer available');
            }
        }

        $primaryRoomId = (int)$combination['room_a_id'];
        $pdo->prepare("UPDATE bookings SET individual_room_id = ?, room_combination_id = ? WHERE id = ?")
            ->execute([$primaryRoomId, $combinationId, $bookingId]);
        syncBookingRooms($bookingId, [(int)$combination['room_a_id'], (int)$combination['room_b_id']], $combinationId);

        if (in_array((string)$booking['status'], ['confirmed', 'checked-in'], true) && (string)$booking['check_in_date'] <= date('Y-m-d')) {
            $statusStmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
            $updateStmt = $pdo->prepare("UPDATE individual_rooms SET status = 'occupied' WHERE id = ?");
            $logStmt = $pdo->prepare("
                INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by)
                VALUES (?, ?, 'occupied', ?, ?)
            ");
            foreach ($roomIds as $roomId) {
                $statusStmt->execute([$roomId]);
                $oldStatus = (string)($statusStmt->fetchColumn() ?: 'available');
                $updateStmt->execute([$roomId]);
                $logStmt->execute([$roomId, $oldStatus, 'Joined-room booking assigned: ' . ($booking['booking_reference'] ?? $bookingId), $performedBy]);
            }
        }

        if ($ownTransaction) {
            $pdo->commit();
        }

        $roomNumbers = array_values(array_filter([(string)$combination['room_a_number'], (string)$combination['room_b_number']]));
        $result['success'] = true;
        $result['message'] = 'Joined rooms ' . implode(' + ', $roomNumbers) . ' assigned';
        $result['assigned_room_id'] = $primaryRoomId;
        $result['assigned_room_number'] = implode(' + ', $roomNumbers);
        $result['room_ids'] = $roomIds;
        $result['room_numbers'] = $roomNumbers;
        return $result;
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('assignRoomCombinationToBooking warning: ' . $e->getMessage());
        $result['message'] = $e->getMessage();
        return $result;
    }
}

/**
 * Get global or room-type blocked dates only. Individual room blocks must not
 * make the whole room type unavailable unless every physical room is blocked.
 */
function getRoomTypeBlockedDatesOnly(int $room_id, string $check_in_date, string $check_out_date): array
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT
            bd.id,
            bd.room_id,
            r.name AS room_name,
            bd.block_date,
            COALESCE(bd.block_type, 'manual') AS block_type,
            bd.reason,
            'type' AS block_scope
        FROM blocked_dates bd
        LEFT JOIN rooms r ON bd.room_id = r.id
        WHERE (bd.room_id = ? OR bd.room_id IS NULL)
        AND bd.block_date >= ?
        AND bd.block_date < ?
        ORDER BY bd.block_date ASC, bd.room_id ASC
    ");
    $stmt->execute([$room_id, $check_in_date, $check_out_date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Calculate available physical rooms for a room type when individual inventory
 * exists. Unassigned room-type bookings consume one available physical room.
 */
function getRoomTypeIndividualAvailabilitySummary(int $room_id, string $check_in_date, string $check_out_date, ?int $exclude_booking_id = null): ?array
{
    global $pdo;

    $roomsStmt = $pdo->prepare("
        SELECT
            ir.id,
            ir.room_number,
            ir.single_occupancy_enabled_override,
            ir.double_occupancy_enabled_override,
            ir.triple_occupancy_enabled_override,
            ir.children_allowed_override,
            ir.max_guests_override,
            r.max_guests,
            r.single_occupancy_enabled,
            r.double_occupancy_enabled,
            r.triple_occupancy_enabled,
            r.children_allowed,
            r.price_double_occupancy,
            r.price_triple_occupancy
        FROM individual_rooms ir
        JOIN rooms r ON r.id = ir.room_type_id
        WHERE ir.room_type_id = ? AND ir.is_active = 1
        ORDER BY ir.display_order ASC, ir.room_number ASC
    ");
    $roomsStmt->execute([$room_id]);
    $individualRooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($individualRooms)) {
        return null;
    }

    $availableRooms = [];
    $unavailableRooms = [];
    $childEligibleAvailableCount = 0;
    $nonChildEligibleAvailableCount = 0;
    foreach ($individualRooms as $individualRoom) {
        $availability = checkIndividualRoomAvailability((int)$individualRoom['id'], $check_in_date, $check_out_date, $exclude_booking_id);
        if (!empty($availability['available'])) {
            $policy = resolveOccupancyPolicy($individualRoom, $individualRoom);
            $individualRoom['children_allowed'] = (int)$policy['children_allowed'];
            if (!empty($policy['children_allowed'])) {
                $childEligibleAvailableCount++;
            } else {
                $nonChildEligibleAvailableCount++;
            }
            $availableRooms[] = $individualRoom;
        } else {
            $unavailableRooms[] = [
                'id' => (int)$individualRoom['id'],
                'room_number' => $individualRoom['room_number'],
                'reason' => $availability['error'] ?? 'Unavailable'
            ];
        }
    }

    $blockingStatuses = getBookingStatusesThatBlockAvailability(false);
    $placeholders = implode(',', array_fill(0, count($blockingStatuses), '?'));
    $sql = "
        SELECT child_guests
        FROM bookings
        WHERE room_id = ?
        AND individual_room_id IS NULL
        AND status IN ({$placeholders})
        AND NOT (status = 'tentative' AND tentative_expires_at IS NOT NULL AND tentative_expires_at < NOW())
        AND NOT (check_out_date <= ? OR check_in_date >= ?)
    ";
    $params = array_merge([$room_id], $blockingStatuses, [$check_in_date, $check_out_date]);
    if ($exclude_booking_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_booking_id;
    }

    $unassignedStmt = $pdo->prepare($sql);
    $unassignedStmt->execute($params);
    $unassignedBookingsRows = $unassignedStmt->fetchAll(PDO::FETCH_ASSOC);
    $unassignedBookings = count($unassignedBookingsRows);
    $unassignedChildBookings = 0;
    foreach ($unassignedBookingsRows as $unassignedBooking) {
        if ((int)($unassignedBooking['child_guests'] ?? 0) > 0) {
            $unassignedChildBookings++;
        }
    }
    $unassignedAdultBookings = max(0, $unassignedBookings - $unassignedChildBookings);
    $adultOverflowIntoChildRooms = max(0, $unassignedAdultBookings - $nonChildEligibleAvailableCount);
    $availableCount = count($availableRooms);
    $childEligibleRemainingRooms = max(0, $childEligibleAvailableCount - $unassignedChildBookings - $adultOverflowIntoChildRooms);

    return [
        'inventory_count' => count($individualRooms),
        'available_individual_count' => $availableCount,
        'child_eligible_available_count' => $childEligibleAvailableCount,
        'child_eligible_remaining_rooms' => $childEligibleRemainingRooms,
        'non_child_eligible_available_count' => $nonChildEligibleAvailableCount,
        'unavailable_individual_count' => count($unavailableRooms),
        'unassigned_booking_count' => $unassignedBookings,
        'unassigned_child_booking_count' => $unassignedChildBookings,
        'unassigned_adult_booking_count' => $unassignedAdultBookings,
        'remaining_rooms' => max(0, $availableCount - $unassignedBookings),
        'available_rooms' => $availableRooms,
        'unavailable_rooms' => $unavailableRooms
    ];
}

/**
 * Helper function to check room availability
 * Returns true if room is available, false if booked or blocked
 */
function isRoomAvailable(int $room_id, string $check_in_date, string $check_out_date, ?int $exclude_booking_id = null)
{
    try {
        $availability = checkRoomAvailability($room_id, $check_in_date, $check_out_date, $exclude_booking_id);
        return !empty($availability['available']);
    } catch (PDOException $e) {
        error_log("Error checking room availability: " . $e->getMessage());
        return false; // Assume unavailable on error
    } catch (Exception $e) {
        error_log("Error checking room availability: " . $e->getMessage());
        return false;
    }
}

/**
 * Enhanced function to check room availability with detailed conflict information
 * Returns array with availability status and conflict details
 */
function checkRoomAvailability(int $room_id, string $check_in_date, string $check_out_date, ?int $exclude_booking_id = null, int $child_guests = 0, int $child_rooms_needed = 1)
{
    global $pdo;

    $result = [
        'available' => true,
        'conflicts' => [],
        'blocked_dates' => [],
        'room_exists' => false,
        'room' => null
    ];

    try {
        // Check if room exists and get details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ? AND is_active = 1");
        $stmt->execute([$room_id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            $result['room_exists'] = false;
            $result['error'] = 'Room not found or inactive';
            return $result;
        }

        $result['room'] = $room;
        $result['room_exists'] = true;

        // Validate dates
        $check_in = new DateTime($check_in_date);
        $check_out = new DateTime($check_out_date);
        $today = new DateTime('today');

        if ($check_in < $today) {
            $result['available'] = false;
            $result['error'] = 'Check-in date cannot be in the past';
            return $result;
        }

        if ($check_out <= $check_in) {
            $result['available'] = false;
            $result['error'] = 'Check-out date must be after check-in date';
            return $result;
        }

        // Get total capacity
        $total_capacity = (int)($room['total_rooms'] ?? 1);

        // Sanity check for capacity
        if ($total_capacity <= 0) {
            $result['available'] = false;
            $result['error'] = 'No rooms of this type are currently available (capacity is 0)';
            return $result;
        }

        // Check only global/room-type blocks here. Individual room blocks reduce
        // physical-room capacity below; one blocked physical room must not make
        // the whole room type unavailable.
        $all_blocked_dates = getRoomTypeBlockedDatesOnly($room_id, $check_in_date, $check_out_date);

        if (!empty($all_blocked_dates)) {
            $result['available'] = false;
            $result['blocked_dates'] = $all_blocked_dates;
            $result['error'] = 'Selected dates are not available for booking';

            // Build blocked dates message
            $blocked_details = [];
            foreach ($all_blocked_dates as $blocked) {
                $blocked_date = new DateTime($blocked['block_date']);

                if ($blocked['block_scope'] === 'type') {
                    // Room-type level block
                    $room_name = $blocked['room_id'] ? $blocked['room_name'] : 'All rooms';
                    $blocked_details[] = sprintf(
                        "%s on %s (%s)",
                        $room_name,
                        $blocked_date->format('M j, Y'),
                        $blocked['block_type']
                    );
                } else {
                    // Individual room level block
                    $room_name = $blocked['room_name'] ?? 'Unknown room';
                    $room_number = $blocked['individual_room_number'] ?? '';
                    $blocked_details[] = sprintf(
                        "%s %s on %s (%s)",
                        $room_name,
                        $room_number ? '#' . $room_number : '',
                        $blocked_date->format('M j, Y'),
                        $blocked['block_type']
                    );
                }
            }
            $result['blocked_message'] = implode('; ', $blocked_details);
            return $result;
        }

        $combinationAvailability = getRoomCombinationAvailabilitySummary($room_id, $check_in_date, $check_out_date, $exclude_booking_id);
        if ($combinationAvailability !== null) {
            $interval = $check_in->diff($check_out);
            $remainingCombinations = (int)$combinationAvailability['remaining_rooms'];
            $result['nights'] = $interval->days;
            $result['total_capacity'] = (int)$combinationAvailability['inventory_count'];
            $result['remaining_rooms'] = $remainingCombinations;
            $result['available_combinations'] = $combinationAvailability['available_combinations'];
            $result['max_guests'] = max((int)($room['max_guests'] ?? 0), (int)($combinationAvailability['max_guests'] ?? 0));

            if ($remainingCombinations <= 0) {
                $result['available'] = false;
                $result['error'] = 'All joined-room combinations are unavailable for the selected dates';
                $result['conflict_message'] = 'Each joined room pair has at least one physical room booked, blocked, under maintenance, or not ready.';
            }

            return $result;
        }

        $individualAvailability = getRoomTypeIndividualAvailabilitySummary($room_id, $check_in_date, $check_out_date, $exclude_booking_id);
        if ($individualAvailability !== null) {
            $interval = $check_in->diff($check_out);
            $requiresChildrenAllowed = $child_guests > 0;
            $childRoomsNeeded = $requiresChildrenAllowed ? max(1, $child_rooms_needed) : 0;
            $result['nights'] = $interval->days;
            $result['total_capacity'] = (int)$individualAvailability['inventory_count'];
            $result['remaining_rooms'] = (int)$individualAvailability['remaining_rooms'];
            $result['child_eligible_available_count'] = (int)$individualAvailability['child_eligible_available_count'];
            $result['child_eligible_remaining_rooms'] = (int)$individualAvailability['child_eligible_remaining_rooms'];
            $result['child_rooms_needed'] = $childRoomsNeeded;
            $result['available_individual_count'] = (int)$individualAvailability['available_individual_count'];
            $result['unassigned_booking_count'] = (int)$individualAvailability['unassigned_booking_count'];
            $result['unassigned_child_booking_count'] = (int)$individualAvailability['unassigned_child_booking_count'];
            $result['available_rooms'] = $individualAvailability['available_rooms'];
            $result['unavailable_rooms'] = $individualAvailability['unavailable_rooms'];

            if ($requiresChildrenAllowed) {
                $result['children_required'] = true;
            }

            if ($result['remaining_rooms'] <= 0) {
                $result['available'] = false;
                $result['error'] = 'All rooms of this type are unavailable for the selected dates';
                $result['conflict_message'] = 'Every physical room in this room type is either booked, blocked, under maintenance, or held by an unassigned booking.';
            } elseif ($requiresChildrenAllowed && (int)$individualAvailability['child_eligible_remaining_rooms'] < $childRoomsNeeded) {
                $childEligibleRemaining = (int)$individualAvailability['child_eligible_remaining_rooms'];
                $result['available'] = false;
                $result['error'] = "Only {$childEligibleRemaining} child-friendly room" . ($childEligibleRemaining === 1 ? '' : 's') . " available for the selected dates, but this guest allocation needs {$childRoomsNeeded}.";
                $result['conflict_message'] = 'Child-friendly physical rooms in this room type are booked, blocked, under maintenance, or already held by another booking.';
            }

            $max_guests = (int)$room['max_guests'];
            if ($max_guests > 0) {
                $result['max_guests'] = $max_guests;
            }

            return $result;
        }

        if ($child_guests > 0) {
            $roomPolicy = resolveOccupancyPolicy($room, null);
            if (empty($roomPolicy['children_allowed'])) {
                $result['available'] = false;
                $result['remaining_rooms'] = 0;
                $result['children_required'] = true;
                $result['error'] = 'Children are not allowed for this room type';
                return $result;
            }
        }

        // Check for overlapping bookings (use standardized status list)
        $blockingStatuses = getBookingStatusesThatBlockAvailability(false);
        $placeholders = str_repeat('?,', count($blockingStatuses) - 1) . '?';

        $sql = "
            SELECT
                id,
                booking_reference,
                check_in_date,
                check_out_date,
                status,
                guest_name
            FROM bookings
            WHERE room_id = ?
            AND status IN ({$placeholders})
            AND NOT (status = 'tentative' AND tentative_expires_at IS NOT NULL AND tentative_expires_at < NOW())
            AND NOT (check_out_date <= ? OR check_in_date >= ?)
        ";
        $params = array_merge([$room_id], $blockingStatuses, [$check_in_date, $check_out_date]);

        // Exclude specific booking for updates
        if ($exclude_booking_id) {
            $sql .= " AND id != ?";
            $params[] = $exclude_booking_id;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check availability by counting overlapping bookings
        // The rooms_available field is a general inventory count, not specific to requested dates
        // So we need to count actual bookings for the requested dates
        $overlapping_bookings = count($conflicts);

        // Calculate remaining rooms for the requested dates
        $remaining_rooms = $total_capacity - $overlapping_bookings;
        $result['total_capacity'] = $total_capacity;
        $result['remaining_rooms'] = max(0, $remaining_rooms);
        $result['overlapping_booking_count'] = $overlapping_bookings;

        // Room is unavailable if no rooms remain for the requested dates
        if ($remaining_rooms <= 0) {
            $result['available'] = false;
            $result['conflicts'] = $conflicts;
            $result['error'] = 'Room is not available for the selected dates';

            // Build detailed conflict message
            $conflict_details = [];
            foreach ($conflicts as $conflict) {
                $conflict_check_in = new DateTime($conflict['check_in_date']);
                $conflict_check_out = new DateTime($conflict['check_out_date']);
                $conflict_details[] = sprintf(
                    "Booking %s (%s) from %s to %s",
                    $conflict['booking_reference'],
                    $conflict['guest_name'],
                    $conflict_check_in->format('M j, Y'),
                    $conflict_check_out->format('M j, Y')
                );
            }
            $result['conflict_message'] = implode('; ', $conflict_details);
        }

        // Calculate number of nights
        $interval = $check_in->diff($check_out);
        $result['nights'] = $interval->days;

        // Check if room has enough capacity for requested dates
        $max_guests = (int)$room['max_guests'];
        if ($max_guests > 0) {
            $result['max_guests'] = $max_guests;
        }

        return $result;
    } catch (PDOException $e) {
        error_log("Error checking room availability: " . $e->getMessage());
        $result['available'] = false;
        $result['error'] = 'Database error while checking availability';
        return $result;
    } catch (Exception $e) {
        error_log("Error checking room availability: " . $e->getMessage());
        $result['available'] = false;
        $result['error'] = 'Invalid date format';
        return $result;
    }
}

/**
 * Get all booked dates for a room within a date range.
 * Returns dates that are fully booked (no remaining capacity).
 * Uses the same blocking status logic as checkRoomAvailability.
 *
 * @param int $room_id Room ID
 * @param string $start_date Start date (Y-m-d format)
 * @param string $end_date End date (Y-m-d format)
 * @return array Array of booked dates in Y-m-d format
 */
function getBookedDatesForRoom(int $room_id, string $start_date, string $end_date): array
{
    global $pdo;
    $bookedDates = [];

    try {
        if (roomTypeHasActiveCombinations($room_id)) {
            return [];
        }

        // Get room details to check capacity
        $stmt = $pdo->prepare("SELECT total_rooms FROM rooms WHERE id = ? AND is_active = 1");
        $stmt->execute([$room_id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            return [];
        }

        $total_capacity = (int)($room['total_rooms'] ?? 1);

        if ($total_capacity <= 0) {
            // No capacity - all dates are booked
            $current = new DateTime($start_date);
            $end = new DateTime($end_date);
            while ($current < $end) {
                $bookedDates[] = $current->format('Y-m-d');
                $current->modify('+1 day');
            }
            return $bookedDates;
        }

        // Get blocking statuses (includes active tentative holds, excludes cancelled/expired/no-show)
        $blockingStatuses = getBookingStatusesThatBlockAvailability(false);
        $placeholders = str_repeat('?,', count($blockingStatuses) - 1) . '?';

        // Get all overlapping bookings for the date range
        $sql = "
            SELECT
                check_in_date,
                check_out_date
            FROM bookings
            WHERE room_id = ?
            AND status IN ({$placeholders})
            AND NOT (status = 'tentative' AND tentative_expires_at IS NOT NULL AND tentative_expires_at < NOW())
            AND NOT (check_out_date <= ? OR check_in_date >= ?)
            ORDER BY check_in_date ASC
        ";
        $params = array_merge([$room_id], $blockingStatuses, [$start_date, $end_date]);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // For each date in the range, count overlapping bookings
        $current = new DateTime($start_date);
        $end = new DateTime($end_date);

        while ($current < $end) {
            $dateStr = $current->format('Y-m-d');
            $nextDay = clone $current;
            $nextDay->modify('+1 day');

            // Count bookings that overlap with this date
            $overlappingCount = 0;
            foreach ($bookings as $booking) {
                $bookingStart = new DateTime($booking['check_in_date']);
                $bookingEnd = new DateTime($booking['check_out_date']);

                // Check if the date falls within the booking range
                // A date is booked if: date >= check_in AND date < check_out
                if ($current >= $bookingStart && $current < $bookingEnd) {
                    $overlappingCount++;
                }
            }

            // If overlapping bookings >= capacity, the date is fully booked
            if ($overlappingCount >= $total_capacity) {
                $bookedDates[] = $dateStr;
            }

            $current->modify('+1 day');
        }

        return $bookedDates;
    } catch (PDOException $e) {
        error_log("Error getting booked dates for room: " . $e->getMessage());
        return [];
    } catch (Exception $e) {
        error_log("Error getting booked dates for room: " . $e->getMessage());
        return [];
    }
}

/**
 * Function to validate booking data before insertion/update
 * Returns array with validation status and error messages
 */
function validateBookingData(array $data)
{
    $errors = [];

    // Required fields
    $required_fields = ['room_id', 'guest_name', 'guest_email', 'guest_phone', 'check_in_date', 'check_out_date', 'number_of_guests'];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
        }
    }

    // Email validation
    if (!empty($data['guest_email'])) {
        if (!filter_var($data['guest_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['guest_email'] = 'Invalid email address';
        }
    }

    // Phone number validation (basic)
    if (!empty($data['guest_phone'])) {
        $phone = preg_replace('/[^0-9+]/', '', $data['guest_phone']);
        if (strlen($phone) < 8) {
            $errors['guest_phone'] = 'Phone number is too short';
        }
    }

    // Number of guests validation
    if (!empty($data['number_of_guests'])) {
        $guests = (int)$data['number_of_guests'];
        if ($guests < 1) {
            $errors['number_of_guests'] = 'At least 1 guest is required';
        } elseif ($guests > 20) {
            $errors['number_of_guests'] = 'Maximum 20 guests allowed';
        }
    }

    // Date validation
    if (!empty($data['check_in_date']) && !empty($data['check_out_date'])) {
        try {
            $check_in = new DateTime($data['check_in_date']);
            $check_out = new DateTime($data['check_out_date']);
            $today = new DateTime();
            $today->setTime(0, 0, 0);

            if ($check_in < $today) {
                $errors['check_in_date'] = 'Check-in date cannot be in the past';
            }

            if ($check_out <= $check_in) {
                $errors['check_out_date'] = 'Check-out date must be after check-in date';
            }

            // Maximum stay duration (30 nights)
            $nights = (int)$check_in->diff($check_out)->days;
            if ($nights > 30) {
                $errors['check_out_date'] = 'Maximum stay duration is 30 nights';
            }

            // Maximum advance booking days (configurable setting)
            $max_advance_days = (int)getSetting('max_advance_booking_days', 30);
            $max_advance_date = new DateTime();
            $max_advance_date->modify('+' . $max_advance_days . ' days');
            if ($check_in > $max_advance_date) {
                $errors['check_in_date'] = "Bookings can only be made up to {$max_advance_days} days in advance. Please select an earlier check-in date.";
            }
        } catch (Exception $e) {
            $errors['dates'] = 'Invalid date format';
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Function to validate booking with room availability check
 * Combines data validation and availability checking
 */
function validateBookingWithAvailability(array $data, ?int $exclude_booking_id = null)
{
    // First validate data
    $validation = validateBookingData($data);
    if (!$validation['valid']) {
        return [
            'valid' => false,
            'errors' => $validation['errors'],
            'type' => 'validation'
        ];
    }

    $availability = null;

    // If individual room requested, validate against individual room availability
    if (!empty($data['individual_room_id'])) {
        $availability = checkIndividualRoomAvailability(
            $data['individual_room_id'],
            $data['check_in_date'],
            $data['check_out_date'],
            $exclude_booking_id
        );

        if (!$availability['available']) {
            return [
                'valid' => false,
                'errors' => [
                    'availability' => $availability['error'],
                    'conflicts' => $availability['conflict_message'] ?? 'No specific conflicts found'
                ],
                'type' => 'availability',
                'conflicts' => $availability['conflicts'] ?? []
            ];
        }

        if (!empty($availability['room_type_id']) && (int)$availability['room_type_id'] !== (int)$data['room_id']) {
            return [
                'valid' => false,
                'errors' => [
                    'individual_room_id' => 'Selected room does not match the chosen room type.'
                ],
                'type' => 'validation'
            ];
        }

        if ((int)($data['child_guests'] ?? 0) > 0) {
            $policyInfo = getIndividualRoomEffectivePolicy((int)$data['room_id'], (int)$data['individual_room_id']);
            if (!$policyInfo || empty($policyInfo['policy']['children_allowed'])) {
                return [
                    'valid' => false,
                    'errors' => [
                        'child_guests' => 'Selected room number does not allow children.'
                    ],
                    'type' => 'validation'
                ];
            }
        }
    } else {
        // Then check room availability
        $availability = checkRoomAvailability(
            $data['room_id'],
            $data['check_in_date'],
            $data['check_out_date'],
            $exclude_booking_id,
            (int)($data['child_guests'] ?? 0)
        );
    }

    if (!$availability['available']) {
        return [
            'valid' => false,
            'errors' => [
                'availability' => $availability['error'],
                'conflicts' => $availability['conflict_message'] ?? 'No specific conflicts found'
            ],
            'type' => 'availability',
            'conflicts' => $availability['conflicts'] ?? []
        ];
    }

    // Check if number of guests exceeds room capacity
    if (isset($availability['max_guests']) && isset($data['number_of_guests'])) {
        if ((int)$data['number_of_guests'] > (int)$availability['max_guests']) {
            return [
                'valid' => false,
                'errors' => [
                    'number_of_guests' => "Room capacity is {$availability['max_guests']} guests"
                ],
                'type' => 'capacity'
            ];
        }
    }

    return [
        'valid' => true,
        'availability' => $availability
    ];
}

/**
 * Get blocked dates for a specific room type, individual room, or all
 * Supports dual-layer blocking: room-type level and individual-room level
 * Returns array of blocked date records with scope indicator
 *
 * @param int|null $room_id Room type ID (rooms table)
 * @param int|null $individual_room_id Individual room ID (individual_rooms table)
 * @param string|null $start_date Filter by start date
 * @param string|null $end_date Filter by end date
 * @return array Array of blocked date records
 */
function getBlockedDates(?int $room_id = null, ?string $start_date = null, ?string $end_date = null, ?int $individual_room_id = null)
{
    global $pdo;

    try {
        $blocked_dates = [];

        // Get room-type level blocks
        $sql = "
            SELECT
                bd.id,
                bd.room_id,
                NULL as individual_room_id,
                r.name as room_name,
                NULL as individual_room_number,
                bd.block_date,
                COALESCE(bd.block_type, 'manual') as block_type,
                bd.reason,
                bd.blocked_by as created_by,
                au.username as created_by_name,
                bd.created_at,
                'type' as block_scope
            FROM blocked_dates bd
            LEFT JOIN rooms r ON bd.room_id = r.id
            LEFT JOIN admin_users au ON bd.blocked_by = au.id
            WHERE 1=1
        ";
        $params = [];

        if ($room_id !== null) {
            $sql .= " AND (bd.room_id = ? OR bd.room_id IS NULL)";
            $params[] = $room_id;
        }

        if ($start_date !== null) {
            $sql .= " AND bd.block_date >= ?";
            $params[] = $start_date;
        }

        if ($end_date !== null) {
            $sql .= " AND bd.block_date <= ?";
            $params[] = $end_date;
        }

        $sql .= " ORDER BY bd.block_date ASC, bd.room_id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $blocked_dates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get individual-room level blocks:
        // - when individual_room_id is specified directly
        // - when no room_id filter (load all)
        // - when room_id is specified — also pull individual blocks for that room TYPE
        //   so that blocking a specific room prevents a new booking of that type
        if ($individual_room_id !== null || $room_id === null || $room_id !== null) {
            $ir_sql = "
                SELECT
                    irbd.id,
                    NULL as room_id,
                    irbd.individual_room_id,
                    rt.name as room_name,
                    ir.room_number as individual_room_number,
                    irbd.block_date,
                    irbd.block_type,
                    irbd.reason,
                    irbd.blocked_by as created_by,
                    au.username as created_by_name,
                    irbd.created_at,
                    'individual' as block_scope
                FROM individual_room_blocked_dates irbd
                INNER JOIN individual_rooms ir ON irbd.individual_room_id = ir.id
                INNER JOIN rooms rt ON ir.room_type_id = rt.id
                LEFT JOIN admin_users au ON irbd.blocked_by = au.id
                WHERE 1=1
            ";
            $ir_params = [];

            if ($individual_room_id !== null) {
                $ir_sql .= " AND irbd.individual_room_id = ?";
                $ir_params[] = $individual_room_id;
            }

            if ($room_id !== null) {
                $ir_sql .= " AND ir.room_type_id = ?";
                $ir_params[] = $room_id;
            }

            if ($start_date !== null) {
                $ir_sql .= " AND irbd.block_date >= ?";
                $ir_params[] = $start_date;
            }

            if ($end_date !== null) {
                $ir_sql .= " AND irbd.block_date <= ?";
                $ir_params[] = $end_date;
            }

            $ir_sql .= " ORDER BY irbd.block_date ASC, irbd.individual_room_id ASC";

            $ir_stmt = $pdo->prepare($ir_sql);
            $ir_stmt->execute($ir_params);
            $individual_blocked_dates = $ir_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Merge both types of blocks
            $blocked_dates = array_merge($blocked_dates, $individual_blocked_dates);
        }

        // Sort combined results by date
        usort($blocked_dates, function (array $a, array $b) {
            return strcmp($a['block_date'], $b['block_date']);
        });

        return $blocked_dates;
    } catch (PDOException $e) {
        error_log("Error fetching blocked dates: " . $e->getMessage());
        return [];
    }
}

/**
 * Get blocked dates specifically for an individual room
 * Returns array of blocked date records for the individual room
 *
 * @param int $individual_room_id Individual room ID
 * @param string|null $start_date Filter by start date
 * @param string|null $end_date Filter by end date
 * @return array Array of blocked date records
 */
function getIndividualRoomBlockedDates(int $individual_room_id, ?string $start_date = null, ?string $end_date = null)
{
    return getBlockedDates(null, $start_date, $end_date, $individual_room_id);
}

/**
 * Check if a specific individual room is blocked on a given date
 *
 * @param int $individual_room_id Individual room ID
 * @param string $date Date to check (Y-m-d format)
 * @return bool True if blocked, false otherwise
 */
function isIndividualRoomBlocked(int $individual_room_id, string $date)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM individual_room_blocked_dates
            WHERE individual_room_id = ? AND block_date = ?
        ");
        $stmt->execute([$individual_room_id, $date]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Error checking individual room block: " . $e->getMessage());
        return false;
    }
}

/**
 * Get available dates for a specific room within a date range
 * Returns array of available dates
 */
function getAvailableDates(int $room_id, string $start_date, string $end_date)
{
    global $pdo;

    try {
        $available_dates = [];
        $current = new DateTime($start_date);
        $end = new DateTime($end_date);

        // Get room details
        $stmt = $pdo->prepare("SELECT rooms_available FROM rooms WHERE id = ?");
        $stmt->execute([$room_id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room || $room['rooms_available'] <= 0) {
            return [];
        }

        $rooms_available = $room['rooms_available'];

        // Get blocked dates
        $blocked_sql = "
            SELECT block_date
            FROM blocked_dates
            WHERE block_date >= ? AND block_date <= ?
            AND (room_id = ? OR room_id IS NULL)
        ";
        $blocked_stmt = $pdo->prepare($blocked_sql);
        $blocked_stmt->execute([$start_date, $end_date, $room_id]);
        $blocked_dates = $blocked_stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get booked dates
        $booked_sql = "
            SELECT DISTINCT DATE(check_in_date) as date
            FROM bookings
            WHERE room_id = ?
            AND status IN ('pending', 'confirmed', 'checked-in')
            AND check_in_date <= ?
            AND check_out_date > ?
        ";
        $booked_stmt = $pdo->prepare($booked_sql);
        $booked_stmt->execute([$room_id, $end_date, $start_date]);
        $booked_dates = $booked_stmt->fetchAll(PDO::FETCH_COLUMN);

        // Count bookings per date
        $booking_counts = [];
        foreach ($booked_dates as $date) {
            if (!isset($booking_counts[$date])) {
                $booking_counts[$date] = 0;
            }
            $booking_counts[$date]++;
        }

        // Build available dates array
        while ($current <= $end) {
            $date_str = $current->format('Y-m-d');

            // Check if date is blocked
            if (in_array($date_str, $blocked_dates)) {
                $current->modify('+1 day');
                continue;
            }

            // Check if date has available rooms
            $bookings_on_date = isset($booking_counts[$date_str]) ? $booking_counts[$date_str] : 0;

            if ($bookings_on_date < $rooms_available) {
                $available_dates[] = [
                    'date' => $date_str,
                    'available' => true,
                    'rooms_left' => $rooms_available - $bookings_on_date
                ];
            }

            $current->modify('+1 day');
        }

        return $available_dates;
    } catch (PDOException $e) {
        error_log("Error fetching available dates: " . $e->getMessage());
        return [];
    }
}

/**
 * Block a specific date for a room type or all rooms
 * Returns true on success, false on failure
 *
 * @param int|null $room_id Room type ID (null for all rooms)
 * @param string $block_date Date to block (Y-m-d format)
 * @param string $block_type Type of block (manual, maintenance, event, full)
 * @param string|null $reason Optional reason for the block
 * @param int|null $created_by Admin user ID who created the block
 * @return bool True on success, false on failure
 */
function blockRoomDate(?int $room_id, string $block_date, string $block_type = 'manual', ?string $reason = null, ?int $created_by = null)
{
    global $pdo;

    try {
        // Check if date is already blocked
        $check_sql = "
            SELECT id FROM blocked_dates
            WHERE room_id " . ($room_id === null ? "IS NULL" : "= ?") . "
            AND block_date = ?
        ";
        $check_params = $room_id === null ? [$block_date] : [$room_id, $block_date];

        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute($check_params);

        if ($check_stmt->fetch()) {
            // Date already blocked, update instead
            $update_sql = "
                UPDATE blocked_dates
                SET block_type = ?, reason = ?, blocked_by = ?
                WHERE room_id " . ($room_id === null ? "IS NULL" : "= ?") . "
                AND block_date = ?
            ";
            $update_params = [$block_type, $reason, $created_by];
            if ($room_id !== null) {
                $update_params[] = $room_id;
            }
            $update_params[] = $block_date;

            $update_stmt = $pdo->prepare($update_sql);
            return $update_stmt->execute($update_params);
        }

        // Insert new blocked date
        $sql = "
            INSERT INTO blocked_dates (room_id, block_date, block_type, reason, blocked_by)
            VALUES (?, ?, ?, ?, ?)
        ";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$room_id, $block_date, $block_type, $reason, $created_by]);
    } catch (PDOException $e) {
        error_log("Error blocking room date: " . $e->getMessage());
        return false;
    }
}

/**
 * Unblock a specific date for a room type or all rooms
 * Returns true on success, false on failure
 *
 * @param int|null $room_id Room type ID (null for all rooms)
 * @param string $block_date Date to unblock (Y-m-d format)
 * @return bool True on success, false on failure
 */
function unblockRoomDate(?int $room_id, string $block_date)
{
    global $pdo;

    try {
        $sql = "
            DELETE FROM blocked_dates
            WHERE room_id " . ($room_id === null ? "IS NULL" : "= ?") . "
            AND block_date = ?
        ";
        $params = $room_id === null ? [$block_date] : [$room_id, $block_date];

        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        error_log("Error unblocking room date: " . $e->getMessage());
        return false;
    }
}

/**
 * Block multiple dates for a room type or all rooms
 * Returns number of dates blocked
 *
 * @param int|null $room_id Room type ID (null for all rooms)
 * @param array $dates Array of dates to block (Y-m-d format)
 * @param string $block_type Type of block (manual, maintenance, event, full)
 * @param string|null $reason Optional reason for the blocks
 * @param int|null $created_by Admin user ID who created the blocks
 * @return int Number of dates successfully blocked
 */
function blockRoomDates(?int $room_id, array $dates, string $block_type = 'manual', ?string $reason = null, ?int $created_by = null)
{
    $blocked_count = 0;

    foreach ($dates as $date) {
        if (blockRoomDate($room_id, $date, $block_type, $reason, $created_by)) {
            $blocked_count++;
        }
    }

    return $blocked_count;
}

/**
 * Unblock multiple dates for a room type or all rooms
 * Returns number of dates unblocked
 *
 * @param int|null $room_id Room type ID (null for all rooms)
 * @param array $dates Array of dates to unblock (Y-m-d format)
 * @return int Number of dates successfully unblocked
 */
function unblockRoomDates(?int $room_id, array $dates)
{
    $unblocked_count = 0;

    foreach ($dates as $date) {
        if (unblockRoomDate($room_id, $date)) {
            $unblocked_count++;
        }
    }

    return $unblocked_count;
}

/**
 * Block a specific date for an individual room
 * Returns true on success, false on failure
 *
 * @param int $individual_room_id Individual room ID
 * @param string $block_date Date to block (Y-m-d format)
 * @param string $block_type Type of block (manual, maintenance, event, full)
 * @param string|null $reason Optional reason for the block
 * @param int|null $created_by Admin user ID who created the block
 * @return bool True on success, false on failure
 */
function blockIndividualRoomDate(int $individual_room_id, string $block_date, string $block_type = 'manual', ?string $reason = null, ?int $created_by = null)
{
    global $pdo;

    try {
        // Check if date is already blocked
        $check_sql = "
            SELECT id FROM individual_room_blocked_dates
            WHERE individual_room_id = ? AND block_date = ?
        ";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$individual_room_id, $block_date]);

        if ($check_stmt->fetch()) {
            // Date already blocked, update instead
            $update_sql = "
                UPDATE individual_room_blocked_dates
                SET block_type = ?, reason = ?, blocked_by = ?
                WHERE individual_room_id = ? AND block_date = ?
            ";
            $update_stmt = $pdo->prepare($update_sql);
            return $update_stmt->execute([$block_type, $reason, $created_by, $individual_room_id, $block_date]);
        }

        // Insert new blocked date
        $sql = "
            INSERT INTO individual_room_blocked_dates (individual_room_id, block_date, block_type, reason, blocked_by)
            VALUES (?, ?, ?, ?, ?)
        ";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$individual_room_id, $block_date, $block_type, $reason, $created_by]);
    } catch (PDOException $e) {
        error_log("Error blocking individual room date: " . $e->getMessage());
        return false;
    }
}

/**
 * Unblock a specific date for an individual room
 * Returns true on success, false on failure
 *
 * @param int $individual_room_id Individual room ID
 * @param string $block_date Date to unblock (Y-m-d format)
 * @return bool True on success, false on failure
 */
function unblockIndividualRoomDate(int $individual_room_id, string $block_date)
{
    global $pdo;

    try {
        $sql = "
            DELETE FROM individual_room_blocked_dates
            WHERE individual_room_id = ? AND block_date = ?
        ";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$individual_room_id, $block_date]);
    } catch (PDOException $e) {
        error_log("Error unblocking individual room date: " . $e->getMessage());
        return false;
    }
}

/**
 * Block multiple dates for an individual room
 * Returns number of dates blocked
 *
 * @param int $individual_room_id Individual room ID
 * @param array $dates Array of dates to block (Y-m-d format)
 * @param string $block_type Type of block (manual, maintenance, event, full)
 * @param string|null $reason Optional reason for the blocks
 * @param int|null $created_by Admin user ID who created the blocks
 * @return int Number of dates successfully blocked
 */
function blockIndividualRoomDates(int $individual_room_id, array $dates, string $block_type = 'manual', ?string $reason = null, ?int $created_by = null)
{
    $blocked_count = 0;

    foreach ($dates as $date) {
        if (blockIndividualRoomDate($individual_room_id, $date, $block_type, $reason, $created_by)) {
            $blocked_count++;
        }
    }

    return $blocked_count;
}

/**
 * Unblock multiple dates for an individual room
 * Returns number of dates unblocked
 *
 * @param int $individual_room_id Individual room ID
 * @param array $dates Array of dates to unblock (Y-m-d format)
 * @return int Number of dates successfully unblocked
 */
function unblockIndividualRoomDates(int $individual_room_id, array $dates)
{
    $unblocked_count = 0;

    foreach ($dates as $date) {
        if (unblockIndividualRoomDate($individual_room_id, $date)) {
            $unblocked_count++;
        }
    }

    return $unblocked_count;
}

/**
 * ============================================
 * TENTATIVE BOOKING SYSTEM HELPER FUNCTIONS
 * ============================================
 */

/**
 * Convert a tentative booking to a standard booking
 * Returns true on success, false on failure
 */
function convertTentativeBooking(int $booking_id, ?int $admin_user_id = null)
{
    global $pdo;

    try {
        // Start transaction
        $pdo->beginTransaction();

        // Get current booking details
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND status = 'tentative'");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $pdo->rollBack();
            return false;
        }

        // Update booking status to pending
        $update_stmt = $pdo->prepare("
            UPDATE bookings
            SET status = 'pending',
                is_tentative = 0,
                tentative_expires_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update_stmt->execute([$booking_id]);

        // Log the action
        logTentativeBookingAction($booking_id, 'converted', [
            'converted_by' => $admin_user_id,
            'previous_status' => 'tentative',
            'new_status' => 'pending',
            'previous_is_tentative' => 1,
            'new_is_tentative' => 0
        ]);

        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error converting tentative booking: " . $e->getMessage());
        return false;
    }
}

/**
 * Cancel a tentative booking
 * Returns true on success, false on failure
 */
function cancelTentativeBooking(int $booking_id, ?int $admin_user_id = null, ?string $reason = null)
{
    global $pdo;

    try {
        // Start transaction
        $pdo->beginTransaction();

        // Get current booking details
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND status = 'tentative'");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $pdo->rollBack();
            return false;
        }

        // Update booking status to cancelled
        $update_stmt = $pdo->prepare("
            UPDATE bookings
            SET status = 'cancelled',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update_stmt->execute([$booking_id]);

        // Log the action
        logTentativeBookingAction($booking_id, 'cancelled', [
            'cancelled_by' => $admin_user_id,
            'previous_status' => 'tentative',
            'new_status' => 'cancelled',
            'reason' => $reason
        ]);

        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error cancelling tentative booking: " . $e->getMessage());
        return false;
    }
}

/**
 * Get tentative bookings with optional filters
 * Returns array of tentative bookings
 */
function getTentativeBookings(array $filters = [])
{
    global $pdo;

    try {
        $sql = "
            SELECT
                b.*,
                r.name as room_name,
                r.price_per_night,
                au.username as admin_username
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN admin_users au ON b.updated_by = au.id
            WHERE b.is_tentative = 1
        ";
        $params = [];

        // Filter by status
        if (!empty($filters['status'])) {
            $sql .= " AND b.status = ?";
            $params[] = $filters['status'];
        }

        // Filter by room
        if (!empty($filters['room_id'])) {
            $sql .= " AND b.room_id = ?";
            $params[] = $filters['room_id'];
        }

        // Filter by expiration status
        if (!empty($filters['expiration_status'])) {
            $now = date('Y-m-d H:i:s');
            if ($filters['expiration_status'] === 'expired') {
                $sql .= " AND b.tentative_expires_at < ?";
                $params[] = $now;
            } elseif ($filters['expiration_status'] === 'active') {
                $sql .= " AND b.tentative_expires_at >= ?";
                $params[] = $now;
            }
        }

        // Filter by date range
        if (!empty($filters['date_from'])) {
            $sql .= " AND b.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND b.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        // Search by guest name or email
        if (!empty($filters['search'])) {
            $sql .= " AND (b.guest_name LIKE ? OR b.guest_email LIKE ? OR b.booking_reference LIKE ?)";
            $search_term = '%' . $filters['search'] . '%';
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }

        $sql .= " ORDER BY b.created_at DESC";

        // Limit results
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $bookings;
    } catch (PDOException $e) {
        error_log("Error fetching tentative bookings: " . $e->getMessage());
        return [];
    }
}

/**
 * Get bookings expiring within X hours
 * Returns array of bookings expiring soon
 */
function getExpiringTentativeBookings(int $hours = 24)
{
    global $pdo;

    try {
        $now = date('Y-m-d H:i:s');
        $cutoff = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));

        $stmt = $pdo->prepare("
            SELECT
                b.*,
                r.name as room_name,
                TIMESTAMPDIFF(HOUR, NOW(), b.tentative_expires_at) as hours_until_expiration
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.is_tentative = 1
            AND b.status = 'tentative'
            AND b.tentative_expires_at >= ?
            AND b.tentative_expires_at <= ?
            ORDER BY b.tentative_expires_at ASC
        ");
        $stmt->execute([$now, $cutoff]);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $bookings;
    } catch (PDOException $e) {
        error_log("Error fetching expiring tentative bookings: " . $e->getMessage());
        return [];
    }
}

/**
 * Get expired tentative bookings
 * Returns array of expired bookings
 */
function getExpiredTentativeBookings()
{
    global $pdo;

    try {
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            SELECT
                b.*,
                r.name as room_name,
                TIMESTAMPDIFF(HOUR, b.tentative_expires_at, NOW()) as hours_since_expiration
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.is_tentative = 1
            AND b.status = 'tentative'
            AND b.tentative_expires_at < ?
            ORDER BY b.tentative_expires_at ASC
        ");
        $stmt->execute([$now]);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $bookings;
    } catch (PDOException $e) {
        error_log("Error fetching expired tentative bookings: " . $e->getMessage());
        return [];
    }
}

/**
 * Mark a tentative booking as expired
 * Returns true on success, false on failure
 */
function markTentativeBookingExpired(int $booking_id)
{
    global $pdo;

    try {
        // Start transaction
        $pdo->beginTransaction();

        // Get current booking details
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND status = 'tentative'");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $pdo->rollBack();
            return false;
        }

        // Update booking status to expired; clear is_tentative flag so badge counts stay accurate
        $update_stmt = $pdo->prepare("
            UPDATE bookings
            SET status = 'expired',
                is_tentative = 0,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update_stmt->execute([$booking_id]);

        // Log the action
        logTentativeBookingAction($booking_id, 'expired', [
            'previous_status' => 'tentative',
            'new_status' => 'expired',
            'expired_at' => date('Y-m-d H:i:s')
        ]);

        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error marking tentative booking as expired: " . $e->getMessage());
        return false;
    }
}

/**
 * Log an action for a tentative booking
 * Returns true on success, false on failure
 */
function logTentativeBookingAction(int $booking_id, string $action, array $details = [])
{
    global $pdo;

    try {
        // Check if tentative_booking_log table exists
        $table_exists = $pdo->query("SHOW TABLES LIKE 'tentative_booking_log'")->rowCount() > 0;

        if (!$table_exists) {
            // Table doesn't exist, skip logging
            return true;
        }

        $stmt = $pdo->prepare("
            INSERT INTO tentative_booking_log (booking_id, action, performed_by, action_reason)
            VALUES (?, ?, NULL, ?)
        ");
        $stmt->execute([$booking_id, $action, !empty($details) ? json_encode($details) : null]);

        return true;
    } catch (PDOException $e) {
        error_log("Error logging tentative booking action: " . $e->getMessage());
        return false;
    }
}

/**
 * Get tentative booking statistics
 * Returns array with statistics
 */
function getTentativeBookingStatistics()
{
    global $pdo;

    try {
        $now = date('Y-m-d H:i:s');
        $reminder_cutoff = date('Y-m-d H:i:s', strtotime("+24 hours"));

        // Get total tentative bookings
        $stmt = $pdo->query("
            SELECT COUNT(*) as total
            FROM bookings
            WHERE is_tentative = 1
            AND status = 'tentative'
        ");
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Get expiring soon (within 24 hours)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as expiring_soon
            FROM bookings
            WHERE is_tentative = 1
            AND status = 'tentative'
            AND tentative_expires_at >= ?
            AND tentative_expires_at <= ?
        ");
        $stmt->execute([$now, $reminder_cutoff]);
        $expiring_soon = $stmt->fetch(PDO::FETCH_ASSOC)['expiring_soon'];

        // Get expired
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as expired
            FROM bookings
            WHERE is_tentative = 1
            AND status = 'tentative'
            AND tentative_expires_at < ?
        ");
        $stmt->execute([$now]);
        $expired = $stmt->fetch(PDO::FETCH_ASSOC)['expired'];

        // Get converted (standard bookings that were tentative)
        $stmt = $pdo->query("
            SELECT COUNT(*) as converted
            FROM bookings
            WHERE is_tentative = 0
            AND status IN ('pending', 'confirmed', 'checked-in', 'checked-out')
            AND tentative_expires_at IS NOT NULL
        ");
        $converted = $stmt->fetch(PDO::FETCH_ASSOC)['converted'];

        return [
            'total' => (int)$total,
            'expiring_soon' => (int)$expiring_soon,
            'expired' => (int)$expired,
            'converted' => (int)$converted,
            'active' => (int)($total - $expired)
        ];
    } catch (PDOException $e) {
        error_log("Error fetching tentative booking statistics: " . $e->getMessage());
        return [
            'total' => 0,
            'expiring_soon' => 0,
            'expired' => 0,
            'converted' => 0,
            'active' => 0
        ];
    }
}

/**
 * Check if a booking can be converted (is tentative and not expired)
 * Returns array with status and message
 */
function canConvertTentativeBooking(int $booking_id)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            return [
                'can_convert' => false,
                'reason' => 'Booking not found'
            ];
        }

        if ($booking['is_tentative'] != 1) {
            return [
                'can_convert' => false,
                'reason' => 'This is not a tentative booking'
            ];
        }

        if ($booking['status'] === 'expired') {
            return [
                'can_convert' => false,
                'reason' => 'This booking has expired'
            ];
        }

        if ($booking['status'] === 'cancelled') {
            return [
                'can_convert' => false,
                'reason' => 'This booking has been cancelled'
            ];
        }

        if ($booking['status'] !== 'tentative') {
            return [
                'can_convert' => false,
                'reason' => 'Booking has already been converted'
            ];
        }

        // Check if expired
        if ($booking['tentative_expires_at'] && $booking['tentative_expires_at'] < date('Y-m-d H:i:s')) {
            return [
                'can_convert' => false,
                'reason' => 'This booking has expired'
            ];
        }

        return [
            'can_convert' => true,
            'expires_at' => $booking['tentative_expires_at']
        ];
    } catch (PDOException $e) {
        error_log("Error checking if booking can be converted: " . $e->getMessage());
        return [
            'can_convert' => false,
            'reason' => 'Database error'
        ];
    }
}

/**
 * ============================================================================
 * INDIVIDUAL ROOM MANAGEMENT FUNCTIONS
 * ============================================================================
 */

/**
 * Get available individual rooms for a room type and date range
 *
 * @param int $roomTypeId Room type ID
 * @param string $checkIn Check-in date (YYYY-MM-DD)
 * @param string $checkOut Check-out date (YYYY-MM-DD)
 * @param int $excludeBookingId Optional booking ID to exclude from conflicts
 * @return array Available individual rooms
 */
function getAvailableIndividualRooms(int $roomTypeId, string $checkIn, string $checkOut, ?int $excludeBookingId = null, bool $requireChildrenAllowed = false)
{
    global $pdo;

    try {
        // Get all active individual rooms for this type
        $sql = "
            SELECT
                ir.id,
                ir.room_number,
                ir.room_name,
                ir.floor,
                ir.status,
                ir.specific_amenities,
                ir.single_occupancy_enabled_override,
                ir.double_occupancy_enabled_override,
                ir.triple_occupancy_enabled_override,
                ir.children_allowed_override,
                ir.max_guests_override,
                r.max_guests,
                r.single_occupancy_enabled,
                r.double_occupancy_enabled,
                r.triple_occupancy_enabled,
                r.children_allowed,
                r.price_double_occupancy,
                r.price_triple_occupancy
            FROM individual_rooms ir
            JOIN rooms r ON r.id = ir.room_type_id
            WHERE ir.room_type_id = ?
            AND ir.is_active = 1
            AND ir.status = 'available'
            ORDER BY ir.display_order ASC, ir.room_number ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$roomTypeId]);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $availableRooms = [];

        foreach ($rooms as $room) {
            // Check for booking conflicts (use standardized status list for individual rooms)
            $blockingStatuses = getBookingStatusesThatBlockAvailability(true);
            $placeholders = str_repeat('?,', count($blockingStatuses) - 1) . '?';

            $excludeSql = $excludeBookingId ? " AND b.id != ?" : "";
            $conflictSql = "
                SELECT COUNT(*) as count
                FROM (
                    SELECT b.id
                    FROM bookings b
                    WHERE b.individual_room_id = ?
                    AND b.status IN ({$placeholders})
                    AND NOT (b.status = 'tentative' AND b.tentative_expires_at IS NOT NULL AND b.tentative_expires_at < NOW())
                    AND NOT (b.check_out_date <= ? OR b.check_in_date >= ?)
                    {$excludeSql}
                    UNION
                    SELECT b.id
                    FROM booking_rooms br
                    JOIN bookings b ON b.id = br.booking_id
                    WHERE br.individual_room_id = ?
                    AND br.released_at IS NULL
                    AND b.status IN ({$placeholders})
                    AND NOT (b.status = 'tentative' AND b.tentative_expires_at IS NOT NULL AND b.tentative_expires_at < NOW())
                    AND NOT (b.check_out_date <= ? OR b.check_in_date >= ?)
                    {$excludeSql}
                ) conflicts
            ";

            $params = array_merge([$room['id']], $blockingStatuses, [$checkIn, $checkOut]);
            if ($excludeBookingId) {
                $params[] = $excludeBookingId;
            }
            $params = array_merge($params, [$room['id']], $blockingStatuses, [$checkIn, $checkOut]);
            if ($excludeBookingId) {
                $params[] = $excludeBookingId;
            }

            $conflictStmt = $pdo->prepare($conflictSql);
            $conflictStmt->execute($params);
            $hasConflict = $conflictStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

            if (!$hasConflict) {
                $roomAvailability = checkIndividualRoomAvailability((int)$room['id'], $checkIn, $checkOut, $excludeBookingId);
                if (empty($roomAvailability['available'])) {
                    continue;
                }

                $policy = resolveOccupancyPolicy($room, $room);
                if ($requireChildrenAllowed && empty($policy['children_allowed'])) {
                    continue;
                }

                $availableRooms[] = [
                    'id' => $room['id'],
                    'room_number' => $room['room_number'],
                    'room_name' => $room['room_name'],
                    'floor' => $room['floor'],
                    'status' => $room['status'],
                    'children_allowed' => (int)$policy['children_allowed'],
                    'specific_amenities' => $room['specific_amenities'] ? json_decode($room['specific_amenities'], true) : []
                ];
            }
        }

        return $availableRooms;
    } catch (PDOException $e) {
        error_log("Error getting available individual rooms: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if an individual room is available for specific dates
 *
 * @param int $individualRoomId Individual room ID
 * @param string $checkIn Check-in date (YYYY-MM-DD)
 * @param string $checkOut Check-out date (YYYY-MM-DD)
 * @param int $excludeBookingId Optional booking ID to exclude
 * @return bool True if available, false otherwise
 */
function isIndividualRoomAvailable(int $individualRoomId, string $checkIn, string $checkOut, ?int $excludeBookingId = null)
{
    $availability = checkIndividualRoomAvailability($individualRoomId, $checkIn, $checkOut, $excludeBookingId);
    return $availability['available'];
}

/**
 * Explain whether an individual room is blocked from being assigned to a guest
 * because of housekeeping (a pending/in-progress cleanup) or because the room is
 * in a non-bookable physical state (cleaning / maintenance / out of order).
 *
 * This powers the admin-facing assignment messaging: it tells staff exactly WHY
 * a room cannot be assigned and HOW to free it. A room is only assignable once
 * its checkout cleanup is completed (which returns it to 'available').
 *
 * @return array{blocked:bool, reason:string, message:string}|null
 *         null  => no housekeeping/status block (room is free to assign)
 */
function getRoomHousekeepingAssignmentBlock(int $individualRoomId): ?array
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT room_number, room_name, status, housekeeping_status FROM individual_rooms WHERE id = ?");
        $stmt->execute([$individualRoomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$room) {
            return null; // let the caller's existing not-found handling deal with it
        }

        $label = trim((string)($room['room_number'] ?? '')) !== ''
            ? 'Room ' . $room['room_number']
            : (trim((string)($room['room_name'] ?? '')) ?: 'This room');

        // Count open housekeeping assignments — the authoritative blocker.
        $openStmt = $pdo->prepare("
            SELECT COUNT(*) FROM housekeeping_assignments
            WHERE individual_room_id = ? AND status IN ('pending', 'in_progress', 'blocked')
        ");
        $openStmt->execute([$individualRoomId]);
        $openCount = (int)$openStmt->fetchColumn();

        $needsCleaning = $openCount > 0
            || (string)($room['housekeeping_status'] ?? '') === 'pending'
            || (string)($room['status'] ?? '') === 'cleaning';

        if ($needsCleaning) {
            $taskWord = $openCount > 1 ? 'cleanups' : 'cleanup';
            return [
                'blocked' => true,
                'reason'  => 'housekeeping_pending',
                'message' => $label . ' cannot be assigned yet — it has a pending checkout ' . $taskWord . '. '
                    . 'Open Housekeeping and mark the ' . $taskWord . ' as completed to free the room, then assign it.',
            ];
        }

        $physicalStatus = (string)($room['status'] ?? '');
        if (in_array($physicalStatus, ['maintenance', 'out_of_order'], true)) {
            $stateLabel = $physicalStatus === 'out_of_order' ? 'out of order' : 'under maintenance';
            return [
                'blocked' => true,
                'reason'  => 'room_' . $physicalStatus,
                'message' => $label . ' cannot be assigned — it is currently ' . $stateLabel . '. '
                    . 'Clear the room status in Room Management before assigning it.',
            ];
        }

        return null;
    } catch (Throwable $e) {
        error_log('getRoomHousekeepingAssignmentBlock error: ' . $e->getMessage());
        return null; // fail open to the existing availability enforcement
    }
}

/**
 * Enhanced availability check for a specific individual room
 */
function checkIndividualRoomAvailability(int $individualRoomId, string $checkIn, string $checkOut, ?int $excludeBookingId = null)
{
    global $pdo;

    $result = [
        'available' => true,
        'conflicts' => [],
        'maintenance' => [],
        'housekeeping' => [],
        'room' => null,
        'individual_room' => null,
        'room_type_id' => null
    ];

    try {
        // Get room status + room type info
        $stmt = $pdo->prepare("
            SELECT ir.*, r.name as room_type_name, r.max_guests, r.price_per_night
            FROM individual_rooms ir
            JOIN rooms r ON ir.room_type_id = r.id
            WHERE ir.id = ? AND ir.is_active = 1
        ");
        $stmt->execute([$individualRoomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            $result['available'] = false;
            $result['error'] = 'Room not found or inactive';
            return $result;
        }

        $result['individual_room'] = $room;
        $result['room_type_id'] = (int)$room['room_type_id'];
        $result['room'] = [
            'id' => (int)$room['room_type_id'],
            'name' => $room['room_type_name'],
            'price_per_night' => (float)$room['price_per_night'],
            'max_guests' => (int)($room['max_guests_override'] ?? $room['max_guests'])
        ];
        $result['max_guests'] = (int)($room['max_guests_override'] ?? $room['max_guests']);

        // Check if room status allows booking
        if ($room['status'] !== 'available') {
            $result['available'] = false;
            $result['error'] = 'Selected room is not available for booking';
            return $result;
        }

        // Check maintenance schedules blocking this room
        $maintenanceStmt = $pdo->prepare("
            SELECT id, title, start_date, end_date, status
            FROM room_maintenance_schedules
            WHERE individual_room_id = ?
            AND block_room = 1
            AND status IN ('pending', 'in_progress')
            AND NOT (end_date <= ? OR start_date >= ?)
        ");
        $maintenanceStmt->execute([$individualRoomId, $checkIn, $checkOut]);
        $maintenance = $maintenanceStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($maintenance)) {
            $result['available'] = false;
            $result['maintenance'] = $maintenance;
            $result['error'] = 'Selected room is blocked for maintenance during these dates';
            return $result;
        }

        // Check housekeeping assignments blocking this room
        // 'completed' is intentionally excluded — a completed task means the room is clean and ready
        $housekeepingStmt = $pdo->prepare("
            SELECT id, due_date, status
            FROM housekeeping_assignments
            WHERE individual_room_id = ?
            AND status IN ('pending', 'in_progress', 'blocked')
            AND due_date >= ?
            AND due_date < ?
        ");
        $housekeepingStmt->execute([$individualRoomId, $checkIn, $checkOut]);
        $housekeeping = $housekeepingStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($housekeeping)) {
            $result['available'] = false;
            $result['housekeeping'] = $housekeeping;
            $result['error'] = 'Selected room has housekeeping blocks during these dates';
            return $result;
        }

        // Check for individual room blocked dates
        $blockedStmt = $pdo->prepare("
            SELECT
                id,
                individual_room_id,
                block_date,
                block_type,
                reason
            FROM individual_room_blocked_dates
            WHERE individual_room_id = ?
            AND block_date >= ? AND block_date < ?
            ORDER BY block_date ASC
        ");
        $blockedStmt->execute([$individualRoomId, $checkIn, $checkOut]);
        $blockedDates = $blockedStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($blockedDates)) {
            $result['available'] = false;
            $result['blocked_dates'] = $blockedDates;
            $result['error'] = 'Selected room is blocked on the selected dates';

            $blocked_details = [];
            foreach ($blockedDates as $blocked) {
                $blocked_date = new DateTime($blocked['block_date']);
                $blocked_details[] = sprintf(
                    "%s on %s (%s)",
                    $room['room_number'],
                    $blocked_date->format('M j, Y'),
                    $blocked['block_type']
                );
            }
            $result['blocked_message'] = implode('; ', $blocked_details);
            return $result;
        }

        // Check for booking conflicts (use standardized status list for individual rooms)
        $blockingStatuses = getBookingStatusesThatBlockAvailability(true);
        $placeholders = str_repeat('?,', count($blockingStatuses) - 1) . '?';

        $excludeSql = $excludeBookingId ? " AND b.id != ?" : "";
        $sql = "
            SELECT * FROM (
                SELECT
                    b.id,
                    b.booking_reference,
                    b.check_in_date,
                    b.check_out_date,
                    b.status,
                    b.guest_name
                FROM bookings b
                WHERE b.individual_room_id = ?
                AND b.status IN ({$placeholders})
                AND NOT (b.status = 'tentative' AND b.tentative_expires_at IS NOT NULL AND b.tentative_expires_at < NOW())
                AND NOT (b.check_out_date <= ? OR b.check_in_date >= ?)
                {$excludeSql}
                UNION
                SELECT
                    b.id,
                    b.booking_reference,
                    b.check_in_date,
                    b.check_out_date,
                    b.status,
                    b.guest_name
                FROM booking_rooms br
                JOIN bookings b ON b.id = br.booking_id
                WHERE br.individual_room_id = ?
                AND br.released_at IS NULL
                AND b.status IN ({$placeholders})
                AND NOT (b.status = 'tentative' AND b.tentative_expires_at IS NOT NULL AND b.tentative_expires_at < NOW())
                AND NOT (b.check_out_date <= ? OR b.check_in_date >= ?)
                {$excludeSql}
            ) conflicts
            ORDER BY check_in_date ASC
        ";

        $params = array_merge([$individualRoomId], $blockingStatuses, [$checkIn, $checkOut]);
        if ($excludeBookingId) {
            $params[] = $excludeBookingId;
        }
        $params = array_merge($params, [$individualRoomId], $blockingStatuses, [$checkIn, $checkOut]);
        if ($excludeBookingId) {
            $params[] = $excludeBookingId;
        }

        $conflictStmt = $pdo->prepare($sql);
        $conflictStmt->execute($params);
        $conflicts = $conflictStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($conflicts)) {
            $result['available'] = false;
            $result['conflicts'] = $conflicts;
            $result['error'] = 'Selected room is not available for the selected dates';

            $conflict_details = [];
            foreach ($conflicts as $conflict) {
                $conflict_check_in = new DateTime($conflict['check_in_date']);
                $conflict_check_out = new DateTime($conflict['check_out_date']);
                $conflict_details[] = sprintf(
                    "Booking %s (%s) from %s to %s",
                    $conflict['booking_reference'],
                    $conflict['guest_name'],
                    $conflict_check_in->format('M j, Y'),
                    $conflict_check_out->format('M j, Y')
                );
            }
            $result['conflict_message'] = implode('; ', $conflict_details);
            return $result;
        }

        $checkInDate = new DateTime($checkIn);
        $checkOutDate = new DateTime($checkOut);
        $result['nights'] = $checkInDate->diff($checkOutDate)->days;

        return $result;
    } catch (PDOException $e) {
        error_log("Error checking individual room availability: " . $e->getMessage());
        $result['available'] = false;
        $result['error'] = 'Database error while checking availability';
        return $result;
    } catch (Exception $e) {
        error_log("Error checking individual room availability: " . $e->getMessage());
        $result['available'] = false;
        $result['error'] = 'Invalid date format';
        return $result;
    }
}

/**
 * Update individual room status with logging
 *
 * @param int $individualRoomId Individual room ID
 * @param string $newStatus New status
 * @param string $reason Optional reason for status change
 * @param int $performedBy User ID who performed the change
 * @return bool True on success, false on failure
 */
function updateIndividualRoomStatus(int $individualRoomId, string $newStatus, ?string $reason = null, ?int $performedBy = null)
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Get current status
        $stmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
        $stmt->execute([$individualRoomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            $pdo->rollBack();
            return false;
        }

        $oldStatus = $room['status'];

        // Update status
        $updateStmt = $pdo->prepare("UPDATE individual_rooms SET status = ? WHERE id = ?");
        $updateStmt->execute([$newStatus, $individualRoomId]);

        // Log the change
        $logStmt = $pdo->prepare("
            INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $logStmt->execute([
            $individualRoomId,
            $oldStatus,
            $newStatus,
            $reason,
            $performedBy
        ]);

        $pdo->commit();

        // Clear cache
        require_once __DIR__ . '/cache.php';
        clearRoomCache();

        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error updating individual room status: " . $e->getMessage());
        return false;
    }
}

/**
 * Get room type with individual rooms count
 *
 * @param int $roomTypeId Room type ID
 * @return array Room type data with counts
 */
function getRoomTypeWithCounts(int $roomTypeId)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT
                rt.*,
                COUNT(DISTINCT ir.id) as individual_rooms_count,
                SUM(CASE WHEN ir.status = 'available' THEN 1 ELSE 0 END) as available_count,
                SUM(CASE WHEN ir.status = 'occupied' THEN 1 ELSE 0 END) as occupied_count,
                SUM(CASE WHEN ir.status = 'cleaning' THEN 1 ELSE 0 END) as cleaning_count,
                SUM(CASE WHEN ir.status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_count
            FROM rooms rt
            LEFT JOIN individual_rooms ir ON rt.id = ir.room_type_id AND ir.is_active = 1
            WHERE rt.id = ?
            GROUP BY rt.id
        ");
        $stmt->execute([$roomTypeId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            // Decode amenities JSON
            if ($result['amenities']) {
                $result['amenities'] = json_decode($result['amenities'], true);
            } else {
                $result['amenities'] = [];
            }
        }

        return $result;
    } catch (PDOException $e) {
        error_log("Error getting room type with counts: " . $e->getMessage());
        return null;
    }
}

/**
 * Get all room types with individual room counts
 *
 * @param bool $activeOnly Only return active room types
 * @return array Room types with counts
 */
function getAllRoomTypesWithCounts(bool $activeOnly = true)
{
    global $pdo;

    try {
        $sql = "
            SELECT
                rt.id,
                rt.name,
                rt.slug,
                rt.price_per_night,
                rt.image_url,
                rt.is_featured,
                rt.is_active,
                rt.display_order,
                COUNT(DISTINCT ir.id) as individual_rooms_count,
                SUM(CASE WHEN ir.status = 'available' THEN 1 ELSE 0 END) as available_count
            FROM rooms rt
            LEFT JOIN individual_rooms ir ON rt.id = ir.room_type_id AND ir.is_active = 1
        ";

        if ($activeOnly) {
            $sql .= " WHERE rt.is_active = 1";
        }

        $sql .= " GROUP BY rt.id ORDER BY rt.display_order ASC, rt.name ASC";

        $stmt = $pdo->query($sql);
        $roomTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Process amenities
        foreach ($roomTypes as &$type) {
            $type['amenities'] = [];
            $type['available_count'] = (int)($type['available_count'] ?? 0);
            $type['individual_rooms_count'] = (int)($type['individual_rooms_count'] ?? 0);
        }

        return $roomTypes;
    } catch (PDOException $e) {
        error_log("Error getting all room types with counts: " . $e->getMessage());
        return [];
    }
}

/**
 * Assign individual room to booking
 *
 * @param int $bookingId Booking ID
 * @param int $individualRoomId Individual room ID
 * @return bool True on success, false on failure
 */
function assignIndividualRoomToBooking(int $bookingId, int $individualRoomId, bool $allowChildPolicyOverride = false, ?string $childPolicyOverrideNote = null, ?int $performedBy = null)
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Verify booking exists (fetch finance columns so we can preserve levy + packages on total recalc)
        $bookingStmt = $pdo->prepare("SELECT id, booking_reference, room_id, check_in_date, check_out_date, number_of_nights, number_of_guests, child_guests, occupancy_type, total_amount, tourism_levy_percent, package_total, vat_rate, status FROM bookings WHERE id = ?");
        $bookingStmt->execute([$bookingId]);
        $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $pdo->rollBack();
            return false;
        }

        // Verify individual room exists and is available.
        // FOR UPDATE locks this room row for the transaction so two admins
        // assigning the SAME room concurrently serialize: the second blocks here,
        // then its availability re-check below sees the first (now committed)
        // booking and is correctly rejected — closing a double-assignment race.
        $roomStmt = $pdo->prepare("SELECT id, room_type_id, status FROM individual_rooms WHERE id = ? FOR UPDATE");
        $roomStmt->execute([$individualRoomId]);
        $room = $roomStmt->fetch(PDO::FETCH_ASSOC);

        if (!$room || (int)$room['room_type_id'] !== (int)$booking['room_id']) {
            $pdo->rollBack();
            return false;
        }

        $policyInfo = getIndividualRoomEffectivePolicy((int)$booking['room_id'], $individualRoomId);
        if (!$policyInfo) {
            $pdo->rollBack();
            return false;
        }

        $children = max(0, (int)($booking['child_guests'] ?? 0));
        $overrideNote = trim((string)($childPolicyOverrideNote ?? ''));
        if ($children > 0 && empty($policyInfo['policy']['children_allowed'])) {
            if (!$allowChildPolicyOverride || $overrideNote === '') {
                $pdo->rollBack();
                return false;
            }
        }

        // Check if room is available for booking dates
        if (!isIndividualRoomAvailable($individualRoomId, $booking['check_in_date'], $booking['check_out_date'], $bookingId)) {
            $pdo->rollBack();
            return false;
        }

        // Recalculate child pricing based on specific room override (fallback to room type)
        $pricingStmt = $pdo->prepare("
            SELECT
                COALESCE(r.price_single_occupancy, r.price_per_night) AS price_single,
                COALESCE(r.price_double_occupancy, r.price_per_night) AS price_double,
                COALESCE(r.price_triple_occupancy, r.price_per_night) AS price_triple,
                COALESCE(ir.child_price_multiplier, r.child_price_multiplier, 50) AS effective_child_multiplier
            FROM rooms r
            LEFT JOIN individual_rooms ir ON ir.id = ?
            WHERE r.id = ?
            LIMIT 1
        ");
        $pricingStmt->execute([$individualRoomId, (int)$booking['room_id']]);
        $pricing = $pricingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $occupancyType = strtolower((string)($booking['occupancy_type'] ?? 'single'));
        $ratePerNight = (float)($pricing['price_single'] ?? 0);
        if ($occupancyType === 'double') {
            $ratePerNight = (float)($pricing['price_double'] ?? $ratePerNight);
        } elseif ($occupancyType === 'triple') {
            $ratePerNight = (float)($pricing['price_triple'] ?? $ratePerNight);
        }

        $nights = max(1, (int)($booking['number_of_nights'] ?? 1));
        $childMultiplier = max(0, (float)($pricing['effective_child_multiplier'] ?? 50));
        $childSupplement = $children > 0
            ? ($ratePerNight * ($childMultiplier / 100) * $children * $nights)
            : 0.0;
        $baseAmount = $ratePerNight * $nights;

        // Preserve tourism levy and package total from the original booking
        $levyPct       = max(0.0, (float)($booking['tourism_levy_percent'] ?? 0));
        $levyAmount    = $levyPct > 0 ? round(($baseAmount + $childSupplement) * ($levyPct / 100), 2) : 0.0;
        $packageTotal  = max(0.0, (float)($booking['package_total'] ?? 0));
        $newTotal      = $baseAmount + $childSupplement + $levyAmount + $packageTotal;
        // VAT per installation mode: exclusive adds on top, inclusive extracts
        // from the priced total (never inflates), off is zero.
        $vatParts        = vat_components($newTotal);
        $vatAmount       = $vatParts['vat'];
        $newTotalWithVat = $vatParts['total'];

        // Update booking: individual room + all finance columns atomically
        $updateStmt = $pdo->prepare("
            UPDATE bookings
            SET individual_room_id       = ?,
                child_price_multiplier   = ?,
                child_supplement_total   = ?,
                tourism_levy_amount      = ?,
                total_amount             = ?,
                amount_due               = ?,
                total_with_vat           = ?
            WHERE id = ?
        ");
        $updateStmt->execute([
            $individualRoomId,
            $childMultiplier,
            $childSupplement,
            $levyAmount,
            $newTotal,
            $newTotal,
            $newTotalWithVat,
            $bookingId,
        ]);
        syncBookingRooms($bookingId, [$individualRoomId], null);

        // Update individual room status based on timeline-aware logic
        // Only set to 'occupied' if check-in date is today or in the past
        // Future confirmed bookings should keep room as 'available' (reserved but not occupied)
        if (in_array($booking['status'], ['confirmed', 'checked-in'])) {
            $today = date('Y-m-d');
            $checkInDate = $booking['check_in_date'];

            if ($checkInDate <= $today) {
                $currentStatusStmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
                $currentStatusStmt->execute([$individualRoomId]);
                $oldStatus = (string)($currentStatusStmt->fetchColumn() ?: 'available');

                $pdo->prepare("UPDATE individual_rooms SET status = 'occupied' WHERE id = ?")->execute([$individualRoomId]);
                $pdo->prepare("
                    INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by)
                    VALUES (?, ?, 'occupied', ?, ?)
                ")->execute([
                    $individualRoomId,
                    $oldStatus,
                    'Assigned to ' . $booking['status'] . ' booking: ' . $bookingId . ' (check-in: ' . $checkInDate . ')',
                    $performedBy,
                ]);
            } else {
                // Future booking - room remains available (reserved but not occupied)
                // Ensure room is in available state
                $currentStatusStmt = $pdo->prepare("SELECT status FROM individual_rooms WHERE id = ?");
                $currentStatusStmt->execute([$individualRoomId]);
                $currentStatus = $currentStatusStmt->fetchColumn();

                if ($currentStatus === 'occupied') {
                    $pdo->prepare("UPDATE individual_rooms SET status = 'available' WHERE id = ?")->execute([$individualRoomId]);
                    $pdo->prepare("
                        INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by)
                        VALUES (?, 'occupied', 'available', ?, ?)
                    ")->execute([
                        $individualRoomId,
                        'Future booking assigned (check-in: ' . $checkInDate . ') - room available until then',
                        $performedBy,
                    ]);
                }
            }
        }

        $pdo->commit();

        if ($children > 0 && empty($policyInfo['policy']['children_allowed']) && $overrideNote !== '') {
            if (function_exists('logBookingAudit')) {
                logBookingAudit(
                    $bookingId,
                    'room_child_policy_override',
                    null,
                    ['individual_room_id' => $individualRoomId],
                    $overrideNote,
                    $booking['booking_reference'] ?? null
                );
            }
            if (function_exists('rh_log_event')) {
                rh_log_event('room-assignment', 'warning', 'Child room policy override used', [
                    'booking_id' => $bookingId,
                    'individual_room_id' => $individualRoomId,
                    'performed_by' => $performedBy,
                ]);
            }
        }

        // Clear cache
        require_once __DIR__ . '/cache.php';
        clearRoomCache();

        return true;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error assigning individual room to booking: " . $e->getMessage());
        return false;
    }
}

/**
 * Auto-assign an available individual room to a booking
 * Uses deterministic selection: first available room ordered by room number
 *
 * @param int $bookingId Booking ID
 * @return array Result with success status and message
 */
function autoAssignIndividualRoom(int $bookingId)
{
    global $pdo;

    $result = [
        'success' => false,
        'message' => '',
        'assigned_room_id' => null,
        'assigned_room_number' => null
    ];

    try {
        // Get booking details with room type information
        $stmt = $pdo->prepare("
                        SELECT b.id, b.room_id, b.check_in_date, b.check_out_date, b.status, b.payment_status, b.child_guests, b.individual_room_id,
                                     r.name as room_type_name, r.id as room_type_id
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.id = ?
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $result['message'] = 'Booking not found';
            return $result;
        }

        // Skip if already has an individual room assigned
        if (!empty($booking['individual_room_id'])) {
            $result['success'] = true;
            $result['message'] = 'Room already assigned';
            $result['assigned_room_id'] = $booking['individual_room_id'];
            return $result;
        }

        $roomTypeId = (int)($booking['room_type_id'] ?? 0);

        if ($roomTypeId <= 0) {
            $result['message'] = 'Invalid room type for booking';
            return $result;
        }

        $availableCombinations = getAvailableRoomCombinations(
            $roomTypeId,
            $booking['check_in_date'],
            $booking['check_out_date'],
            $bookingId
        );

        if (!empty($availableCombinations)) {
            $combination = $availableCombinations[0];
            return assignRoomCombinationToBooking($bookingId, (int)$combination['id']);
        }

        // Get available individual rooms using existing availability logic
        $availableRooms = getAvailableIndividualRooms(
            $roomTypeId,
            $booking['check_in_date'],
            $booking['check_out_date'],
            $bookingId,
            (int)($booking['child_guests'] ?? 0) > 0
        );

        if (empty($availableRooms)) {
            $result['message'] = ((int)($booking['child_guests'] ?? 0) > 0)
                ? 'No child-friendly rooms available for the selected dates'
                : 'No available rooms for the selected dates';
            return $result;
        }

        // Deterministic selection: first by room_number ASC, then by id ASC
        usort($availableRooms, function (array $a, array $b) {
            $roomCompare = strnatcmp($a['room_number'] ?? '', $b['room_number'] ?? '');
            if ($roomCompare !== 0) {
                return $roomCompare;
            }
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });

        $selectedRoom = $availableRooms[0];

        // Assign the room using existing assignment logic
        $assigned = assignIndividualRoomToBooking($bookingId, $selectedRoom['id']);

        if ($assigned) {
            $result['success'] = true;
            $result['message'] = 'Room ' . $selectedRoom['room_number'] . ' auto-assigned successfully';
            $result['assigned_room_id'] = $selectedRoom['id'];
            $result['assigned_room_number'] = $selectedRoom['room_number'];
        } else {
            $result['message'] = 'Failed to assign room (availability changed)';
        }

        return $result;
    } catch (PDOException $e) {
        error_log("Error auto-assigning individual room: " . $e->getMessage());
        $result['message'] = 'Database error during auto-assignment';
        return $result;
    }
}

/**
 * Get individual room details with booking info
 *
 * @param int $individualRoomId Individual room ID
 * @return array Room details with current/upcoming bookings
 */
function getIndividualRoomDetails(int $individualRoomId)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT
                ir.*,
                rt.name as room_type_name,
                rt.slug as room_type_slug,
                rt.price_per_night,
                rt.amenities as room_type_amenities
            FROM individual_rooms ir
            JOIN rooms rt ON ir.room_type_id = rt.id
            WHERE ir.id = ?
        ");
        $stmt->execute([$individualRoomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            return null;
        }

        // Decode amenities
        $room['specific_amenities'] = $room['specific_amenities'] ? json_decode($room['specific_amenities'], true) : [];
        $room['room_type_amenities'] = $room['room_type_amenities'] ? json_decode($room['room_type_amenities'], true) : [];

        // Get current booking if occupied
        if ($room['status'] === 'occupied') {
            $bookingStmt = $pdo->prepare("
                SELECT id, booking_reference, guest_name, guest_email,
                       guest_phone, check_in_date, check_out_date, status
                FROM bookings
                WHERE individual_room_id = ?
                AND status IN ('confirmed', 'checked-in')
                AND check_out_date >= CURDATE()
                ORDER BY check_in_date DESC
                LIMIT 1
            ");
            $bookingStmt->execute([$individualRoomId]);
            $room['current_booking'] = $bookingStmt->fetch(PDO::FETCH_ASSOC);
        }

        // Get upcoming bookings
        $upcomingStmt = $pdo->prepare("
            SELECT id, booking_reference, guest_name, check_in_date, check_out_date
            FROM bookings
            WHERE individual_room_id = ?
            AND status IN ('confirmed', 'pending')
            AND check_in_date > CURDATE()
            ORDER BY check_in_date ASC
            LIMIT 5
        ");
        $upcomingStmt->execute([$individualRoomId]);
        $room['upcoming_bookings'] = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get maintenance log
        $logStmt = $pdo->prepare("
            SELECT
                rml.*,
                u.username as performed_by_name
            FROM room_maintenance_log rml
            LEFT JOIN admin_users u ON rml.performed_by = u.id
            WHERE rml.individual_room_id = ?
            ORDER BY rml.created_at DESC
            LIMIT 20
        ");
        $logStmt->execute([$individualRoomId]);
        $room['maintenance_log'] = $logStmt->fetchAll(PDO::FETCH_ASSOC);

        return $room;
    } catch (PDOException $e) {
        error_log("Error getting individual room details: " . $e->getMessage());
        return null;
    }
}

/**
 * Get room status summary for a room type
 *
 * @param int $roomTypeId Room type ID
 * @return array Status summary
 */
function getRoomTypeStatusSummary(int $roomTypeId)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT
                status,
                COUNT(*) as count
            FROM individual_rooms
            WHERE room_type_id = ? AND is_active = 1
            GROUP BY status
        ");
        $stmt->execute([$roomTypeId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'available' => 0,
            'occupied' => 0,
            'cleaning' => 0,
            'maintenance' => 0,
            'out_of_order' => 0,
            'total' => 0
        ];

        foreach ($results as $row) {
            $summary[$row['status']] = (int)$row['count'];
            $summary['total'] += (int)$row['count'];
        }


        return $summary;
    } catch (PDOException $e) {
        error_log("Error getting room type status summary: " . $e->getMessage());
        return [
            'available' => 0,
            'occupied' => 0,
            'cleaning' => 0,
            'maintenance' => 0,
            'out_of_order' => 0,
            'total' => 0
        ];
    }
}

/**
 * Ensure API key and usage log tables exist.
 */
function ensureApiTables(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS api_keys (\n                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n                api_key VARCHAR(255) NOT NULL COMMENT 'Hashed API key',\n                api_key_plain TEXT NULL COMMENT 'AES-256 encrypted retrievable API key for admin view',\n                client_name VARCHAR(255) NOT NULL COMMENT 'Name of the client/website using the API',\n                client_website VARCHAR(255) NULL COMMENT 'Website URL of the client',\n                client_email VARCHAR(255) NOT NULL COMMENT 'Contact email for the client',\n                permissions TEXT NULL COMMENT 'JSON array of permissions',\n                rate_limit_per_hour INT NOT NULL DEFAULT 100,\n                is_active TINYINT(1) NOT NULL DEFAULT 1,\n                last_used_at TIMESTAMP NULL DEFAULT NULL,\n                usage_count INT UNSIGNED NOT NULL DEFAULT 0,\n                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n                INDEX idx_api_keys_active (is_active),\n                INDEX idx_api_keys_client (client_name(100))\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n        ");

        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS api_usage_logs (\n                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n                api_key_id INT UNSIGNED NOT NULL,\n                endpoint VARCHAR(255) NOT NULL,\n                method VARCHAR(10) NOT NULL,\n                ip_address VARCHAR(45) NULL,\n                user_agent TEXT NULL,\n                response_code INT NOT NULL,\n                response_time DECIMAL(10,4) NOT NULL DEFAULT 0.0000,\n                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                INDEX idx_api_usage_key_created (api_key_id, created_at),\n                INDEX idx_api_usage_endpoint (endpoint(120)),\n                INDEX idx_api_usage_created (created_at)\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n        ");
    } catch (Throwable $e) {
        error_log('ensureApiTables warning: ' . $e->getMessage());
    }
}

/**
 * Ensure api_keys table has api_key_plain column for retrievable storage.
 * Stores AES-256 encrypted API key for admin view/copy functionality.
 * The api_key column remains hashed for authentication (password_verify).
 */
function ensureApiKeyRetrievableColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $columnExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");

        // Check if api_key_plain column exists
        $columnExistsStmt->execute(['api_keys', 'api_key_plain']);
        $exists = (int)$columnExistsStmt->fetchColumn() > 0;

        if (!$exists) {
            // Add the column for storing encrypted retrievable key
            $pdo->exec("ALTER TABLE api_keys ADD COLUMN api_key_plain TEXT NULL DEFAULT NULL COMMENT 'AES-256 encrypted retrievable API key for admin view' AFTER api_key");
        }
    } catch (Throwable $e) {
        error_log('ensureApiKeyRetrievableColumn warning: ' . $e->getMessage());
    }
}

/**
 * Encrypt API key for storage using AES-256-CBC.
 *
 * @param string $plainKey The plain API key to encrypt
 * @return string Base64-encoded encrypted data with IV
 */
function encryptApiKey(string $plainKey): string
{
    // Salt comes from APP_ENCRYPTION_SALT in .env so each hotel installation is isolated
    $salt = $_ENV['APP_ENCRYPTION_SALT'] ?? getenv('APP_ENCRYPTION_SALT') ?: 'DEFAULT_SALT_CHANGE_IN_ENV';
    $encryptionKey = hash('sha256', $salt . ($_SERVER['SERVER_NAME'] ?? 'localhost'), true);
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($plainKey, 'AES-256-CBC', $encryptionKey, 0, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt API key for admin viewing.
 *
 * @param string $encryptedKey Base64-encoded encrypted data with IV
 * @return string|null Plain API key or null on failure
 */
function decryptApiKey(string $encryptedKey): ?string
{
    if (empty($encryptedKey)) {
        return null;
    }
    $salt = $_ENV['APP_ENCRYPTION_SALT'] ?? getenv('APP_ENCRYPTION_SALT') ?: 'DEFAULT_SALT_CHANGE_IN_ENV';
    $encryptionKey = hash('sha256', $salt . ($_SERVER['SERVER_NAME'] ?? 'localhost'), true);
    $data = base64_decode($encryptedKey);
    if ($data === false || strlen($data) < 16) {
        return null;
    }
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $encryptionKey, 0, $iv);
    return $decrypted !== false ? $decrypted : null;
}

// ============================================================================
// BOOKING CHARGES / FOLIO MANAGEMENT FUNCTIONS
// ============================================================================

/**
 * Ensure booking_charges table exists (migration helper)
 */
function ensureBookingChargesTable(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $tableExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $columnExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");

        $tableExistsStmt->execute(['booking_charges']);
        $tableExists = (int)$tableExistsStmt->fetchColumn() > 0;

        if (!$tableExists) {
            // Create booking_charges table
            $pdo->exec("CREATE TABLE booking_charges (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_id INT UNSIGNED NOT NULL,
                charge_type ENUM('room', 'food', 'drink', 'service', 'minibar', 'custom', 'breakfast', 'room_service', 'laundry', 'other') NOT NULL DEFAULT 'custom',
                source_item_id INT UNSIGNED NULL COMMENT 'FK to menu item ID if applicable',
                description VARCHAR(255) NOT NULL COMMENT 'Snapshot of charge description at time of creation',
                quantity DECIMAL(10,2) NOT NULL DEFAULT 1.000,
                unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Snapshot of unit price at time of creation',
                line_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'quantity * unit_price',
                vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'VAT rate percentage for this line',
                vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'VAT amount for this line',
                line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'line_subtotal + vat_amount',
                posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When charge was posted to folio',
                added_by INT UNSIGNED NULL COMMENT 'Admin user ID who added the charge',
                voided TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether charge is voided/reversed',
                voided_at DATETIME NULL COMMENT 'When charge was voided',
                void_reason VARCHAR(255) NULL COMMENT 'Reason for voiding',
                voided_by INT UNSIGNED NULL COMMENT 'Admin user ID who voided the charge',
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_booking_charges_booking_id (booking_id),
                KEY idx_booking_charges_type (charge_type),
                KEY idx_booking_charges_source (source_item_id),
                KEY idx_booking_charges_voided (voided),
                KEY idx_booking_charges_posted_at (posted_at),
                CONSTRAINT fk_booking_charges_booking_id FOREIGN KEY (booking_id)
                    REFERENCES bookings(id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Ensure bookings table has final invoice tracking columns
        $ensureColumn = function (string $table, string $column, string $alterSql) use ($columnExistsStmt, $pdo): void {
            $columnExistsStmt->execute([$table, $column]);
            $exists = (int)$columnExistsStmt->fetchColumn() > 0;
            if (!$exists) {
                $pdo->exec($alterSql);
            }
        };

        $ensureColumn('bookings', 'final_invoice_generated', "ALTER TABLE bookings ADD COLUMN final_invoice_generated TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether final invoice has been generated at checkout'");
        $ensureColumn('bookings', 'final_invoice_path', "ALTER TABLE bookings ADD COLUMN final_invoice_path VARCHAR(255) NULL COMMENT 'Path to final invoice file'");
        $ensureColumn('bookings', 'final_invoice_number', "ALTER TABLE bookings ADD COLUMN final_invoice_number VARCHAR(50) NULL COMMENT 'Final invoice number'");
        $ensureColumn('bookings', 'final_invoice_sent_at', "ALTER TABLE bookings ADD COLUMN final_invoice_sent_at DATETIME NULL COMMENT 'When final invoice email was sent'");
        $ensureColumn('bookings', 'checkout_completed_at', "ALTER TABLE bookings ADD COLUMN checkout_completed_at DATETIME NULL COMMENT 'When checkout was completed'");
        $ensureColumn('bookings', 'folio_charges_total', "ALTER TABLE bookings ADD COLUMN folio_charges_total DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Total of all folio charge lines (including VAT)'");
        $ensureColumn('bookings', 'primary_booking_id', "ALTER TABLE bookings ADD COLUMN primary_booking_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'For group bookings: ID of the primary (lead) booking. NULL on the primary itself.'");
    } catch (Throwable $e) {
        error_log('ensureBookingChargesTable warning: ' . $e->getMessage());
    }
}

// Initialize booking charges table on connection
ensureBookingChargesTable($pdo);

/**
 * Add a charge to a booking folio
 *
 * @param int $bookingId Booking ID
 * @param string $chargeType Type of charge (room, food, drink, service, minibar, custom, etc.)
 * @param string $description Charge description
 * @param float $quantity Quantity
 * @param float $unitPrice Unit price (snapshot at time of creation)
 * @param int|null $sourceItemId Source menu item ID if applicable
 * @param int|null $addedBy Admin user ID who added the charge
 * @return array Result with success status and charge ID
 */
function addBookingCharge(int $bookingId, string $chargeType, string $description, float $quantity, float $unitPrice, ?int $sourceItemId = null, ?int $addedBy = null): array
{
    global $pdo;

    $ownTransaction = !$pdo->inTransaction();

    try {
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        // Get VAT settings
        $vatEnabled = getSetting('vat_enabled') === '1';
        $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;

        // unitPrice is the VAT-inclusive (gross) price — F&B/menu convention shared
        // with the POS (pos_calculateRestaurantVatParts): the displayed menu price is
        // what the guest pays, so net/VAT are extracted from gross regardless of
        // vat_pricing_mode. (Room/conference/gym accounts instead use vat_components(),
        // which honours the exclusive/inclusive mode.)
        $lineTotal    = round($quantity * $unitPrice, 2);
        if ($vatRate > 0) {
            $lineSubtotal = round($lineTotal / (1 + ($vatRate / 100)), 2);
            $vatAmount    = round($lineTotal - $lineSubtotal, 2);
        } else {
            $lineSubtotal = $lineTotal;
            $vatAmount    = 0.0;
        }

        // Insert charge
        $stmt = $pdo->prepare("
            INSERT INTO booking_charges (
                booking_id, charge_type, source_item_id, description,
                quantity, unit_price, line_subtotal, vat_rate, vat_amount, line_total,
                posted_at, added_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");

        $stmt->execute([
            $bookingId,
            $chargeType,
            $sourceItemId,
            $description,
            $quantity,
            $unitPrice,
            $lineSubtotal,
            $vatRate,
            $vatAmount,
            $lineTotal,
            $addedBy
        ]);

        $chargeId = $pdo->lastInsertId();

        // Recalculate booking financials
        recalculateBookingFinancials($bookingId);

        if ($ownTransaction) {
            $pdo->commit();
        }

        return [
            'success' => true,
            'charge_id' => $chargeId,
            'line_total' => $lineTotal
        ];
    } catch (PDOException $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("addBookingCharge error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Failed to add charge: ' . $e->getMessage()
        ];
    }
}

/**
 * Add a charge from a menu item (food or drink)
 * Snapshots the current item name and price
 *
 * @param int $bookingId Booking ID
 * @param string $menuType 'food' or 'drink'
 * @param int $menuItemId Menu item ID
 * @param float $quantity Quantity
 * @param int|null $addedBy Admin user ID
 * @return array Result with success status
 */
function addBookingChargeFromMenu(int $bookingId, string $menuType, int $menuItemId, float $quantity, ?int $addedBy = null): array
{
    global $pdo;

    try {
        // Fetch menu item from unified menu_items table
        $stmt = $pdo->prepare("SELECT item_name, price, category FROM menu_items WHERE id = ? AND is_available = 1");
        $stmt->execute([$menuItemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            return [
                'success' => false,
                'message' => 'Menu item not found or unavailable'
            ];
        }

        $description = $item['item_name'];
        $unitPrice = (float)$item['price'];
        $chargeType = $menuType === 'food' ? 'food' : 'drink';

        $result = addBookingCharge($bookingId, $chargeType, $description, $quantity, $unitPrice, $menuItemId, $addedBy);

        // Stock integration: deduct ingredients if a recipe exists for this item.
        // Charge is NOT rolled back if stock deduction fails — kitchen gets the order,
        // reconciliation flag (booking_charges.stock_tracked=0) surfaces it on dashboard.
        if (!empty($result['success']) && !empty($result['charge_id'])) {
            try {
                // Only set stock_tracked=1 when the item actually has a recipe with ingredients.
                // deductStockForMenuItem returns true even for no-recipe items (silent pass-through),
                // which would cause false-positive stock_tracked flags and corrupt reconciliation counts.
                $rChk = $pdo->prepare(
                    "SELECT COUNT(sri.id) FROM stock_recipes sr
                     JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
                     WHERE sr.menu_item_id = ? AND sr.menu_type = ?"
                );
                $rChk->execute([$menuItemId, $menuType]);
                $hasRecipe = (int)$rChk->fetchColumn() > 0;

                $stockOk = deductStockForMenuItem($menuItemId, $menuType, $quantity, 'room_service', (int)$result['charge_id'], $addedBy);
                if ($stockOk && $hasRecipe) {
                    $upd = $pdo->prepare("UPDATE booking_charges SET stock_tracked = 1 WHERE id = ?");
                    $upd->execute([(int)$result['charge_id']]);
                }
            } catch (Throwable $stockEx) {
                error_log("addBookingChargeFromMenu stock deduction failed for charge {$result['charge_id']}: " . $stockEx->getMessage());
            }
        }

        return $result;
    } catch (PDOException $e) {
        error_log("addBookingChargeFromMenu error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}

/**
 * Void a booking charge (audit-safe reversal)
 *
 * @param int $chargeId Charge ID
 * @param string $voidReason Reason for voiding
 * @param int|null $voidedBy Admin user ID who voided the charge
 * @return array Result with success status
 */
function voidBookingCharge(int $chargeId, string $voidReason, ?int $voidedBy = null): array
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Get charge details (need extra fields for stock restoration)
        $stmt = $pdo->prepare("SELECT booking_id, voided, charge_type, source_item_id, quantity, stock_tracked FROM booking_charges WHERE id = ?");
        $stmt->execute([$chargeId]);
        $charge = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$charge) {
            $pdo->rollBack();
            return [
                'success' => false,
                'message' => 'Charge not found'
            ];
        }

        if ($charge['voided']) {
            $pdo->rollBack();
            return [
                'success' => false,
                'message' => 'Charge already voided'
            ];
        }

        // Mark as voided
        $updateStmt = $pdo->prepare("
            UPDATE booking_charges
            SET voided = 1, voided_at = NOW(), void_reason = ?, voided_by = ?
            WHERE id = ?
        ");
        $updateStmt->execute([$voidReason, $voidedBy, $chargeId]);

        // Stock integration: restore ingredients if this charge actually deducted stock.
        // Skips pre-migration charges (stock_tracked=0) so we never add phantom stock.
        // Failures are logged but do not block the void.
        if (
            !empty($charge['stock_tracked'])
            && in_array($charge['charge_type'], ['food', 'drink'], true)
            && !empty($charge['source_item_id'])
        ) {
            try {
                restoreStockForMenuItem(
                    (int)$charge['source_item_id'],
                    $charge['charge_type'],
                    (float)$charge['quantity'],
                    'Charge voided: ' . $voidReason,
                    $voidedBy,
                    $chargeId
                );
            } catch (Throwable $stockEx) {
                error_log("voidBookingCharge stock restore failed for charge {$chargeId}: " . $stockEx->getMessage());
            }
        }

        // Recalculate booking financials
        recalculateBookingFinancials($charge['booking_id']);

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Charge voided successfully'
        ];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("voidBookingCharge error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Failed to void charge: ' . $e->getMessage()
        ];
    }
}

/**
 * Get all charges for a booking (non-voided only by default)
 *
 * @param int $bookingId Booking ID
 * @param bool $includeVoided Include voided charges
 * @return array List of charges
 */
function getBookingCharges(int $bookingId, bool $includeVoided = false): array
{
    global $pdo;

    try {
        $voidedFilter = $includeVoided ? '' : 'AND bc.voided = 0';

        $stmt = $pdo->prepare("
            SELECT
                bc.*,
                mi.item_name AS source_item_name,
                mi.category  AS source_item_category
            FROM booking_charges bc
            LEFT JOIN menu_items mi ON mi.id = bc.source_item_id
            WHERE bc.booking_id = ? {$voidedFilter}
            ORDER BY bc.posted_at ASC, bc.id ASC
        ");

        $stmt->execute([$bookingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getBookingCharges error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get booking folio summary with totals breakdown
 *
 * @param int $bookingId Booking ID
 * @return array Folio summary
 */
function getBookingFolioSummary(int $bookingId): array
{
    global $pdo;

    try {
        // Get booking base amount
        $bookingStmt = $pdo->prepare("SELECT total_amount, total_with_vat, vat_amount, amount_paid, amount_due FROM bookings WHERE id = ?");
        $bookingStmt->execute([$bookingId]);
        $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            return ['error' => 'Booking not found'];
        }

        // Get charges summary
        $chargesStmt = $pdo->prepare("
            SELECT
                charge_type,
                COUNT(*) as item_count,
                SUM(line_subtotal) as total_subtotal,
                SUM(vat_amount) as total_vat,
                SUM(line_total) as total_amount
            FROM booking_charges
            WHERE booking_id = ? AND voided = 0
            GROUP BY charge_type
            ORDER BY charge_type
        ");
        $chargesStmt->execute([$bookingId]);
        $chargesByType = $chargesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get totals
        $totalsStmt = $pdo->prepare("
            SELECT
                SUM(line_subtotal) as folio_subtotal,
                SUM(vat_amount) as folio_vat,
                SUM(line_total) as folio_total,
                COUNT(*) as total_items
            FROM booking_charges
            WHERE booking_id = ? AND voided = 0
        ");
        $totalsStmt->execute([$bookingId]);
        $folioTotals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

        // Get payments — include positive completed payments and subtract completed/processing refunds
        $paymentsStmt = $pdo->prepare("
            SELECT
                SUM(CASE
                    WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount
                    WHEN payment_type = 'refund' AND payment_status IN ('completed', 'paid') THEN -total_amount
                    ELSE 0
                END) as total_paid
            FROM payments
            WHERE booking_type = 'room' AND booking_id = ? AND deleted_at IS NULL
        ");
        $paymentsStmt->execute([$bookingId]);
        $payments = $paymentsStmt->fetch(PDO::FETCH_ASSOC);

        // Calculate final totals
        $baseGross = (float)($booking['total_with_vat'] ?? 0);
        if ($baseGross <= 0) {
            $baseGross = (float)$booking['total_amount'] + (float)($booking['vat_amount'] ?? 0);
        }
        $baseNet = (float)($booking['total_amount'] ?? 0);
        $baseVat = (float)($booking['vat_amount'] ?? 0);
        $extrasSubtotal = (float)($folioTotals['folio_subtotal'] ?? 0);
        $extrasVat = (float)($folioTotals['folio_vat'] ?? 0);
        $extrasTotal = (float)($folioTotals['folio_total'] ?? 0);

        $totalSubtotal = $baseNet + $extrasSubtotal;   // net + net
        $totalVat      = $baseVat + $extrasVat;        // base VAT + extras VAT
        $grandTotal    = $baseGross + $extrasTotal;    // gross base + gross extras

        $amountPaid = max(0.0, (float)($payments['total_paid'] ?? 0));
        $balanceDue = max(0, $grandTotal - $amountPaid);

        return [
            'booking_base_amount' => $baseGross,
            'extras_subtotal' => $extrasSubtotal,
            'extras_vat' => $extrasVat,
            'extras_total' => $extrasTotal,
            'total_subtotal' => $totalSubtotal,
            'total_vat' => $totalVat,
            'grand_total' => $grandTotal,
            'amount_paid' => $amountPaid,
            'balance_due' => $balanceDue,
            'charges_by_type' => $chargesByType,
            'total_items' => (int)($folioTotals['total_items'] ?? 0)
        ];
    } catch (PDOException $e) {
        error_log("getBookingFolioSummary error: " . $e->getMessage());
        return ['error' => 'Database error'];
    }
}

/**
 * Recalculate booking financials based on room base + active charges
 * This is called automatically when charges are added/voided
 *
 * @param int $bookingId Booking ID
 * @return bool Success status
 */
function recalculateBookingFinancials(int $bookingId): bool
{
    global $pdo;

    try {
        // Get current booking
        $stmt = $pdo->prepare("SELECT total_amount, total_with_vat, vat_rate, vat_amount FROM bookings WHERE id = ?");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            return false;
        }

        // Calculate folio charges total
        $chargesStmt = $pdo->prepare("
            SELECT
                SUM(line_subtotal) as charges_subtotal,
                SUM(vat_amount) as charges_vat,
                SUM(line_total) as charges_total
            FROM booking_charges
            WHERE booking_id = ? AND voided = 0
        ");
        $chargesStmt->execute([$bookingId]);
        $charges = $chargesStmt->fetch(PDO::FETCH_ASSOC);

        $baseAmount = (float)$booking['total_amount'];
        $chargesSubtotal = (float)($charges['charges_subtotal'] ?? 0);
        $chargesVat = (float)($charges['charges_vat'] ?? 0);
        $chargesTotal = (float)($charges['charges_total'] ?? 0);

        // Get payments — include positive completed payments and subtract completed/processing refunds
        $paymentsStmt = $pdo->prepare("
            SELECT
                SUM(CASE
                    WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN total_amount
                    WHEN payment_type = 'refund' AND refund_status IN ('completed', 'processing') THEN -total_amount
                    ELSE 0
                END) as total_paid,
                MAX(CASE WHEN payment_status IN ('completed', 'paid') AND COALESCE(payment_type, '') != 'refund' THEN payment_date ELSE NULL END) as last_payment_date
            FROM payments
            WHERE booking_type = 'room' AND booking_id = ? AND deleted_at IS NULL
        ");
        $paymentsStmt->execute([$bookingId]);
        $payments = $paymentsStmt->fetch(PDO::FETCH_ASSOC);

        $amountPaid = max(0.0, (float)($payments['total_paid'] ?? 0));

        // Calculate totals
        // Note: Base amount already includes VAT from bookings table
        // Charges have their own VAT calculated
        $baseTotalWithVat = (float)($booking['total_with_vat'] ?? 0);
        if ($baseTotalWithVat <= 0) {
            $baseTotalWithVat = $baseAmount + (float)($booking['vat_amount'] ?? 0);
        }
        $totalAmount = $baseAmount + $chargesSubtotal;
        $totalVat = (float)$booking['vat_amount'] + $chargesVat;
        $totalWithVat = $baseTotalWithVat + $chargesTotal; // charges_total already includes VAT
        $amountDue = max(0, $totalWithVat - $amountPaid);
        $paymentStatus = $amountDue <= BALANCE_TOLERANCE ? 'paid' : ($amountPaid > BALANCE_TOLERANCE ? 'partial' : 'unpaid');

        // Update booking
        $updateStmt = $pdo->prepare("
            UPDATE bookings
            SET amount_paid = ?,
                amount_due = ?,
                folio_charges_total = ?,
                last_payment_date = ?,
                payment_status = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        $updateStmt->execute([
            $amountPaid,
            $amountDue,
            $chargesTotal,
            $payments['last_payment_date'] ?? null,
            $paymentStatus,
            $bookingId
        ]);

        return true;
    } catch (PDOException $e) {
        error_log("recalculateBookingFinancials error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get menu items for quick-add to booking folio
 *
 * @param string $menuType 'food' or 'drink'
 * @return array Menu items grouped by category
 */
function getMenuItemsForFolio(string $menuType = 'food'): array
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT mi.id, mi.item_name, mi.description, mi.price, mi.category, mi.is_featured
            FROM menu_items mi
            JOIN menu_categories mc ON mc.id = mi.category_id
            WHERE mi.is_available = 1 AND mc.slug = ?
            ORDER BY mi.category ASC, mi.display_order ASC, mi.item_name ASC
        ");
        $stmt->execute([$menuType]);

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by sub-category
        $grouped = [];
        foreach ($items as $item) {
            $category = $item['category'] ?? 'Other';
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $item;
        }

        return $grouped;
    } catch (PDOException $e) {
        error_log("getMenuItemsForFolio error: " . $e->getMessage());
        return [];
    }
}

/**
 * Ensure booking date adjustments support tables exist
 */
function ensureBookingDateAdjustmentsSupport(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $tableExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $columnExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");

        $tableExists = function (string $table) use ($tableExistsStmt): bool {
            $tableExistsStmt->execute([$table]);
            return (int)$tableExistsStmt->fetchColumn() > 0;
        };

        $ensureColumn = function (string $table, string $column, string $alterSql) use ($columnExistsStmt, $pdo): void {
            $columnExistsStmt->execute([$table, $column]);
            $exists = (int)$columnExistsStmt->fetchColumn() > 0;
            if (!$exists) {
                $pdo->exec($alterSql);
            }
        };

        // Create booking_date_adjustments table if not exists
        if (!$tableExists('booking_date_adjustments')) {
            $pdo->exec("CREATE TABLE booking_date_adjustments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_id INT UNSIGNED NOT NULL,
                booking_reference VARCHAR(50) NOT NULL,
                old_check_in_date DATE NOT NULL COMMENT 'Previous check-in date',
                new_check_in_date DATE NOT NULL COMMENT 'New check-in date',
                old_check_out_date DATE NOT NULL COMMENT 'Previous check-out date',
                new_check_out_date DATE NOT NULL COMMENT 'New check-out date',
                old_number_of_nights INT NOT NULL COMMENT 'Previous number of nights',
                new_number_of_nights INT NOT NULL COMMENT 'New number of nights',
                old_total_amount DECIMAL(10,2) NOT NULL COMMENT 'Previous booking total amount',
                new_total_amount DECIMAL(10,2) NOT NULL COMMENT 'New booking total amount',
                amount_delta DECIMAL(10,2) NOT NULL COMMENT 'Difference in amount (positive = additional charge, negative = refund)',
                adjustment_reason TEXT NOT NULL COMMENT 'Reason for the adjustment',
                adjusted_by INT UNSIGNED NOT NULL COMMENT 'Admin user ID who made the adjustment',
                adjusted_by_name VARCHAR(255) NOT NULL COMMENT 'Admin user name who made the adjustment',
                adjustment_timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the adjustment was made',
                ip_address VARCHAR(45) NULL COMMENT 'IP address of the admin making the adjustment',
                metadata JSON NULL COMMENT 'Additional metadata',
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_booking_date_adjustments_booking_id (booking_id),
                KEY idx_booking_date_adjustments_reference (booking_reference),
                KEY idx_booking_date_adjustments_timestamp (adjustment_timestamp),
                KEY idx_booking_date_adjustments_adjusted_by (adjusted_by),
                CONSTRAINT fk_booking_date_adjustments_booking_id FOREIGN KEY (booking_id)
                    REFERENCES bookings(id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_booking_date_adjustments_adjusted_by FOREIGN KEY (adjusted_by)
                    REFERENCES admin_users(id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    } catch (Throwable $e) {
        error_log('ensureBookingDateAdjustmentsSupport warning: ' . $e->getMessage());
    }
}

// Initialize booking date adjustments support on connection
ensureBookingDateAdjustmentsSupport($pdo);

/**
 * Validate if a booking is eligible for date adjustment
 *
 * @param array $booking Booking data
 * @return array Validation result with 'allowed' and 'reason' keys
 */
function validateDateAdjustment(array $booking, ?string $newCheckIn = null, ?string $newCheckOut = null): array
{
    // Check if booking exists
    if (empty($booking['id'])) {
        return [
            'allowed' => false,
            'reason' => 'booking_not_found',
            'message' => 'Booking not found.'
        ];
    }

    // Cannot adjust dates for certain statuses
    $ineligibleStatuses = ['cancelled', 'checked-out', 'no-show'];

    if (in_array($booking['status'] ?? '', $ineligibleStatuses)) {
        return [
            'allowed' => false,
            'reason' => 'status_ineligible',
            'message' => 'Cannot adjust dates for bookings that are cancelled, checked-out, or no-show.'
        ];
    }

    // Validate dates if provided
    if ($newCheckIn !== null && $newCheckOut !== null) {
        $checkIn = DateTime::createFromFormat('Y-m-d', $newCheckIn);
        $checkOut = DateTime::createFromFormat('Y-m-d', $newCheckOut);

        if (!$checkIn || !$checkOut) {
            return [
                'allowed' => false,
                'reason' => 'invalid_date_format',
                'message' => 'Invalid date format. Use Y-m-d format.'
            ];
        }

        if ($checkIn >= $checkOut) {
            return [
                'allowed' => false,
                'reason' => 'invalid_date_range',
                'message' => 'Check-out date must be after check-in date.'
            ];
        }

        // Calculate new number of nights
        $newNights = $checkIn->diff($checkOut)->days;

        if ($newNights <= 0) {
            return [
                'allowed' => false,
                'reason' => 'invalid_nights',
                'message' => 'Booking must be for at least one night.'
            ];
        }

        // Prevent adjusting to past dates (allow today if check-in hasn't happened yet)
        $today = new DateTime('today');
        $currentCheckIn = DateTime::createFromFormat('Y-m-d', $booking['check_in_date'] ?? '');

        // If original check-in is in the past, allow adjustments but warn
        // If original check-in is today or future, don't allow past dates
        if ($currentCheckIn && $currentCheckIn >= $today && $checkIn < $today) {
            return [
                'allowed' => false,
                'reason' => 'past_date_not_allowed',
                'message' => 'Cannot adjust dates to the past. The new check-in date must be today or in the future.'
            ];
        }

        // Validate maximum stay duration (e.g., 30 nights)
        $maxStayNights = 30;
        if ($newNights > $maxStayNights) {
            return [
                'allowed' => false,
                'reason' => 'max_stay_exceeded',
                'message' => "Booking cannot exceed {$maxStayNights} nights. Please contact management for extended stays."
            ];
        }
    }

    return ['allowed' => true];
}

/**
 * Calculate new booking amount based on date changes
 *
 * @param array $booking Current booking data
 * @param string $newCheckIn New check-in date (Y-m-d)
 * @param string $newCheckOut New check-out date (Y-m-d)
 * @return array Calculation result with new amount, nights, and error if any
 */
function calculateDateAdjustmentAmount(array $booking, string $newCheckIn, string $newCheckOut): array
{
    global $pdo;

    try {
        // Validate dates
        $checkIn = DateTime::createFromFormat('Y-m-d', $newCheckIn);
        $checkOut = DateTime::createFromFormat('Y-m-d', $newCheckOut);

        if (!$checkIn || !$checkOut) {
            return ['error' => 'Invalid date format. Use Y-m-d format.'];
        }

        if ($checkIn >= $checkOut) {
            return ['error' => 'Check-out date must be after check-in date.'];
        }

        // Calculate new number of nights
        $newNights = $checkIn->diff($checkOut)->days;

        if ($newNights <= 0) {
            return ['error' => 'Booking must be for at least one night.'];
        }

        // Get room rate
        $stmt = $pdo->prepare("SELECT price_per_night FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            return ['error' => 'Room not found.'];
        }

        $pricePerNight = (float)$room['price_per_night'];

        // Get old values
        $oldNights = (int)($booking['number_of_nights'] ?? 0);
        $oldTotalAmount = (float)($booking['total_amount'] ?? 0);
        $oldChildSupplement = (float)($booking['child_supplement_total'] ?? 0);

        // Calculate new base room amount
        $newBaseAmount = $pricePerNight * $newNights;

        // Calculate child supplement adjustment (proportional to nights change)
        // Preserve the child supplement by adjusting it proportionally based on night ratio
        $newChildSupplement = 0.0;
        if ($oldNights > 0 && $oldChildSupplement > 0) {
            $nightRatio = $newNights / $oldNights;
            $newChildSupplement = $oldChildSupplement * $nightRatio;
        }

        // VAT on the base room amount per installation mode: exclusive adds on
        // top, inclusive is already in the price (no add), off is zero.
        $vatParts = vat_components($newBaseAmount);
        $vatRate = $vatParts['rate'];
        $vatAmount = $vatParts['vat'];
        $newTotalAmount = $vatParts['total'] + $newChildSupplement;

        // Calculate delta (includes child supplement changes)
        $amountDelta = $newTotalAmount - $oldTotalAmount;

        return [
            'success' => true,
            'old_nights' => $oldNights,
            'new_nights' => $newNights,
            'nights_delta' => $newNights - $oldNights,
            'old_total_amount' => $oldTotalAmount,
            'new_total_amount' => $newTotalAmount,
            'old_child_supplement' => $oldChildSupplement,
            'new_child_supplement' => $newChildSupplement,
            'child_supplement_delta' => $newChildSupplement - $oldChildSupplement,
            'amount_delta' => $amountDelta,
            'price_per_night' => $pricePerNight,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount
        ];
    } catch (Exception $e) {
        error_log("calculateDateAdjustmentAmount error: " . $e->getMessage());
        return ['error' => 'Failed to calculate adjustment amount.'];
    }
}

/**
 * Process booking date adjustment with full audit trail and financial impact
 *
 * @param int $bookingId Booking ID
 * @param string $newCheckIn New check-in date (Y-m-d)
 * @param string $newCheckOut New check-out date (Y-m-d)
 * @param string $reason Reason for adjustment
 * @param int $adjustedBy Admin user ID
 * @param string $adjustedByName Admin user name
 * @return array Result with success status and details
 */
function processBookingDateAdjustment(int $bookingId, string $newCheckIn, string $newCheckOut, string $reason, int $adjustedBy, string $adjustedByName): array
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Get current booking with room and individual room info
        $stmt = $pdo->prepare("
            SELECT b.*, r.price_per_night, r.name as room_name, ir.room_number as individual_room_number
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id
            WHERE b.id = ?
            FOR UPDATE
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Booking not found.'];
        }

        // Validate adjustment eligibility (including date validations)
        $validation = validateDateAdjustment($booking, $newCheckIn, $newCheckOut);
        if (!$validation['allowed']) {
            $pdo->rollBack();
            return ['success' => false, 'message' => $validation['message']];
        }

        // Calculate new amount
        $calculation = calculateDateAdjustmentAmount($booking, $newCheckIn, $newCheckOut);
        if (isset($calculation['error'])) {
            $pdo->rollBack();
            return ['success' => false, 'message' => $calculation['error']];
        }

        // Store old values
        $oldCheckIn = $booking['check_in_date'];
        $oldCheckOut = $booking['check_out_date'];
        $oldNights = (int)$booking['number_of_nights'];
        $oldTotalAmount = (float)$booking['total_amount'];
        $oldChildSupplement = (float)($booking['child_supplement_total'] ?? 0);
        $oldAmountPaid = (float)($booking['amount_paid'] ?? 0);

        // Check room availability for new dates (excluding current booking)
        $availabilityCheck = isRoomAvailable($booking['room_id'], $newCheckIn, $newCheckOut, $bookingId);
        if (!$availabilityCheck) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Room is not available for the selected dates.'];
        }

        // If individual room is assigned, check its specific availability
        if (!empty($booking['individual_room_id'])) {
            $individualRoomAvailable = isIndividualRoomAvailable($booking['individual_room_id'], $newCheckIn, $newCheckOut, $bookingId);
            if (!$individualRoomAvailable) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'The assigned individual room is not available for the selected dates. Please select different dates or reassign the room.'];
            }
        }

        // Get folio charges total (additional charges beyond room rate)
        $folioStmt = $pdo->prepare("
            SELECT COALESCE(SUM(line_total), 0) as folio_total
            FROM booking_charges
            WHERE booking_id = ? AND status != 'voided'
        ");
        $folioStmt->execute([$bookingId]);
        $folioData = $folioStmt->fetch(PDO::FETCH_ASSOC);
        $folioTotal = (float)($folioData['folio_total'] ?? 0);

        // Calculate new amount due including folio charges
        // New total = new room total (with VAT) + child supplement + folio charges
        $newRoomTotal = $calculation['new_total_amount'];
        $newTotalWithFolio = $newRoomTotal + $folioTotal;
        $newAmountDue = $newTotalWithFolio - $oldAmountPaid;

        // Determine payment status and credit balance
        $newPaymentStatus = $booking['payment_status'];
        $creditBalance = 0.0;

        if ($newAmountDue <= BALANCE_TOLERANCE) {
            // Fully paid or overpaid (credit)
            $newPaymentStatus = 'paid';
            $creditBalance = abs($newAmountDue); // Track credit balance separately
        } elseif ($oldAmountPaid > 0) {
            // Partial payment
            $newPaymentStatus = 'partial';
        } else {
            // No payment yet
            $newPaymentStatus = 'unpaid';
        }

        // Update booking with all values
        $updateStmt = $pdo->prepare("
            UPDATE bookings
            SET check_in_date = ?,
                check_out_date = ?,
                number_of_nights = ?,
                total_amount = ?,
                vat_amount = ?,
                child_supplement_total = ?,
                amount_due = ?,
                payment_status = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        $updateStmt->execute([
            $newCheckIn,
            $newCheckOut,
            $calculation['new_nights'],
            $newRoomTotal, // Store room total only (folio charges tracked separately)
            $calculation['vat_amount'],
            $calculation['new_child_supplement'],
            max(0, $newAmountDue), // Don't allow negative amount_due (credit tracked in metadata)
            $newPaymentStatus,
            $bookingId
        ]);

        // Record adjustment in audit table
        $adjustmentStmt = $pdo->prepare("
            INSERT INTO booking_date_adjustments (
                booking_id, booking_reference,
                old_check_in_date, new_check_in_date,
                old_check_out_date, new_check_out_date,
                old_number_of_nights, new_number_of_nights,
                old_total_amount, new_total_amount,
                amount_delta, adjustment_reason,
                adjusted_by, adjusted_by_name,
                ip_address, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $adjustmentStmt->execute([
            $bookingId,
            $booking['booking_reference'],
            $oldCheckIn,
            $newCheckIn,
            $oldCheckOut,
            $newCheckOut,
            $oldNights,
            $calculation['new_nights'],
            $oldTotalAmount,
            $newRoomTotal,
            $calculation['amount_delta'],
            $reason,
            $adjustedBy,
            $adjustedByName,
            $_SERVER['REMOTE_ADDR'] ?? null,
            json_encode([
                'room_id' => $booking['room_id'],
                'room_name' => $booking['room_name'],
                'individual_room_number' => $booking['individual_room_number'] ?? null,
                'price_per_night' => $calculation['price_per_night'],
                'vat_rate' => $calculation['vat_rate'],
                'old_child_supplement' => $oldChildSupplement,
                'new_child_supplement' => $calculation['new_child_supplement'],
                'child_supplement_delta' => $calculation['child_supplement_delta'],
                'folio_charges_total' => $folioTotal,
                'credit_balance' => $creditBalance > 0 ? $creditBalance : null
            ])
        ]);

        $adjustmentId = $pdo->lastInsertId();

        // Log to timeline
        require_once __DIR__ . '/../includes/booking-timeline.php';

        $deltaText = $calculation['amount_delta'] >= 0
            ? '+$' . number_format(abs($calculation['amount_delta']), 2) . ' additional charge'
            : '-$' . number_format(abs($calculation['amount_delta']), 2) . ' refund/credit';

        // Add credit note if applicable
        $creditNote = '';
        if ($creditBalance > BALANCE_TOLERANCE) {
            $creditNote = ' (Credit balance: $' . number_format($creditBalance, 2) . ')';
        }

        $description = sprintf(
            "Stay dates adjusted: %s to %s → %s to %s (%d → %d nights, %s)%s",
            $oldCheckIn,
            $oldCheckOut,
            $newCheckIn,
            $newCheckOut,
            $oldNights,
            $calculation['new_nights'],
            $deltaText,
            $creditNote
        );

        logBookingEvent(
            $bookingId,
            $booking['booking_reference'],
            'Stay dates adjusted',
            'date_adjustment',
            $description,
            json_encode(['old' => ['check_in' => $oldCheckIn, 'check_out' => $oldCheckOut, 'nights' => $oldNights, 'total' => $oldTotalAmount]]),
            json_encode(['new' => ['check_in' => $newCheckIn, 'check_out' => $newCheckOut, 'nights' => $calculation['new_nights'], 'total' => $newRoomTotal]]),
            'admin',
            $adjustedBy,
            $adjustedByName,
            [
                'adjustment_id' => $adjustmentId,
                'amount_delta' => $calculation['amount_delta'],
                'child_supplement_delta' => $calculation['child_supplement_delta'],
                'credit_balance' => $creditBalance > 0 ? $creditBalance : null,
                'reason' => $reason
            ]
        );

        $pdo->commit();

        return [
            'success' => true,
            'adjustment_id' => $adjustmentId,
            'calculation' => $calculation,
            'credit_balance' => $creditBalance > 0 ? $creditBalance : null,
            'message' => 'Booking dates adjusted successfully.' . ($creditBalance > 0 ? " Guest has a credit balance of $" . number_format($creditBalance, 2) . "." : '')
        ];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("processBookingDateAdjustment error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to process date adjustment: ' . $e->getMessage()];
    }
}

/**
 * Get date adjustment history for a booking
 *
 * @param int $bookingId Booking ID
 * @return array List of adjustments
 */
function getBookingDateAdjustments(int $bookingId): array
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM booking_date_adjustments
            WHERE booking_id = ?
            ORDER BY adjustment_timestamp DESC
        ");
        $stmt->execute([$bookingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getBookingDateAdjustments error: " . $e->getMessage());
        return [];
    }
}

/**
 * Ensure audit log tables exist for housekeeping and maintenance.
 * This function creates the audit tables if they don't exist.
 */
function ensureAuditLogTables(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $tableExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");

        $tableExists = function (string $table) use ($tableExistsStmt): bool {
            $tableExistsStmt->execute([$table]);
            return (int)$tableExistsStmt->fetchColumn() > 0;
        };

        // Create housekeeping_audit_log table if not exists
        if (!$tableExists('housekeeping_audit_log')) {
            $pdo->exec("CREATE TABLE housekeeping_audit_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                assignment_id INT UNSIGNED NOT NULL COMMENT 'FK to housekeeping_assignments.id',
                action ENUM('created', 'updated', 'deleted', 'verified', 'status_changed', 'assigned', 'unassigned', 'priority_changed', 'notes_updated', 'recurring_created') NOT NULL COMMENT 'Type of action performed',
                old_values JSON NULL COMMENT 'Snapshot of data before change',
                new_values JSON NULL COMMENT 'Snapshot of data after change',
                changed_fields JSON NULL COMMENT 'Array of field names that changed',
                performed_by INT UNSIGNED DEFAULT NULL COMMENT 'Admin user ID who performed the action',
                performed_by_name VARCHAR(255) DEFAULT NULL COMMENT 'Username for historical accuracy',
                performed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the action was performed',
                ip_address VARCHAR(45) DEFAULT NULL COMMENT 'IP address of the user (optional, for security)',
                user_agent VARCHAR(500) DEFAULT NULL COMMENT 'Browser user agent (optional, for context)',
                PRIMARY KEY (id),
                KEY idx_housekeeping_audit_assignment (assignment_id),
                KEY idx_housekeeping_audit_action (action),
                KEY idx_housekeeping_audit_performed_by (performed_by),
                KEY idx_housekeeping_audit_performed_at (performed_at),
                CONSTRAINT fk_housekeeping_audit_assignment FOREIGN KEY (assignment_id) REFERENCES housekeeping_assignments (id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_housekeeping_audit_performed_by FOREIGN KEY (performed_by) REFERENCES admin_users (id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit log for housekeeping assignments'");
        }

        // Create maintenance_audit_log table if not exists
        if (!$tableExists('maintenance_audit_log')) {
            $pdo->exec("CREATE TABLE maintenance_audit_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                maintenance_id INT UNSIGNED NOT NULL COMMENT 'FK to room_maintenance_schedules.id',
                action ENUM('created', 'updated', 'deleted', 'verified', 'status_changed', 'assigned', 'unassigned', 'priority_changed', 'notes_updated', 'recurring_created', 'type_changed') NOT NULL COMMENT 'Type of action performed',
                old_values JSON NULL COMMENT 'Snapshot of data before change',
                new_values JSON NULL COMMENT 'Snapshot of data after change',
                changed_fields JSON NULL COMMENT 'Array of field names that changed',
                performed_by INT UNSIGNED DEFAULT NULL COMMENT 'Admin user ID who performed the action',
                performed_by_name VARCHAR(255) DEFAULT NULL COMMENT 'Username for historical accuracy',
                performed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the action was performed',
                ip_address VARCHAR(45) DEFAULT NULL COMMENT 'IP address of the user (optional, for security)',
                user_agent VARCHAR(500) DEFAULT NULL COMMENT 'Browser user agent (optional, for context)',
                PRIMARY KEY (id),
                KEY idx_maintenance_audit_maintenance (maintenance_id),
                KEY idx_maintenance_audit_action (action),
                KEY idx_maintenance_audit_performed_by (performed_by),
                KEY idx_maintenance_audit_performed_at (performed_at),
                CONSTRAINT fk_maintenance_audit_maintenance FOREIGN KEY (maintenance_id) REFERENCES room_maintenance_schedules (id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_maintenance_audit_performed_by FOREIGN KEY (performed_by) REFERENCES admin_users (id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit log for maintenance schedules'");
        }
    } catch (Throwable $e) {
        error_log('ensureAuditLogTables warning: ' . $e->getMessage());
    }
}

/**
 * Calculate changed fields between two arrays.
 *
 * @param array $oldData Old data
 * @param array $newData New data
 * @return array List of field names that changed
 */
function calculateChangedFields(array $oldData, array $newData): array
{
    $changedFields = [];
    $allKeys = array_unique(array_merge(array_keys($oldData), array_keys($newData)));

    foreach ($allKeys as $key) {
        $oldValue = $oldData[$key] ?? null;
        $newValue = $newData[$key] ?? null;

        // Compare values (handle JSON encoding for arrays)
        if (is_array($oldValue)) {
            $oldValue = json_encode($oldValue);
        }
        if (is_array($newValue)) {
            $newValue = json_encode($newValue);
        }

        if ((string)$oldValue !== (string)$newValue) {
            $changedFields[] = $key;
        }
    }

    return $changedFields;
}

/**
 * Log an action for housekeeping assignment.
 *
 * @param int $assignmentId Assignment ID
 * @param string $action Action performed (created, updated, deleted, verified, etc.)
 * @param array|null $oldData Old data (before change)
 * @param array|null $newData New data (after change)
 * @param int|null $performedBy User ID who performed the action
 * @param string|null $performedByName Username for historical accuracy
 * @return bool Success status
 */
if (!function_exists('logHousekeepingAction')) {
    function logHousekeepingAction(int $assignmentId, string $action, ?array $oldData, ?array $newData, ?int $performedBy, ?string $performedByName = null): bool
    {
        global $pdo;

        try {
            // Ensure audit tables exist
            ensureAuditLogTables($pdo);

            // Calculate changed fields
            $changedFields = null;
            if ($oldData !== null && $newData !== null) {
                $changedFields = calculateChangedFields($oldData, $newData);
            }

            $stmt = $pdo->prepare("
            INSERT INTO housekeeping_audit_log (
                assignment_id, action, old_values, new_values, changed_fields,
                performed_by, performed_by_name, ip_address, user_agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

            $stmt->execute([
                $assignmentId,
                $action,
                $oldData !== null ? json_encode($oldData) : null,
                $newData !== null ? json_encode($newData) : null,
                $changedFields !== null ? json_encode($changedFields) : null,
                $performedBy,
                $performedByName,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            return true;
        } catch (Throwable $e) {
            error_log('logHousekeepingAction error: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Log an action for maintenance schedule.
 *
 * @param int $maintenanceId Maintenance ID
 * @param string $action Action performed (created, updated, deleted, verified, etc.)
 * @param array|null $oldData Old data (before change)
 * @param array|null $newData New data (after change)
 * @param int|null $performedBy User ID who performed the action
 * @param string|null $performedByName Username for historical accuracy
 * @return bool Success status
 */
if (!function_exists('logMaintenanceAction')) {
    function logMaintenanceAction(int $maintenanceId, string $action, ?array $oldData, ?array $newData, ?int $performedBy, ?string $performedByName = null): bool
    {
        global $pdo;

        try {
            // Ensure audit tables exist
            ensureAuditLogTables($pdo);

            // Calculate changed fields
            $changedFields = null;
            if ($oldData !== null && $newData !== null) {
                $changedFields = calculateChangedFields($oldData, $newData);
            }

            $stmt = $pdo->prepare("
            INSERT INTO maintenance_audit_log (
                maintenance_id, action, old_values, new_values, changed_fields,
                performed_by, performed_by_name, ip_address, user_agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

            $stmt->execute([
                $maintenanceId,
                $action,
                $oldData !== null ? json_encode($oldData) : null,
                $newData !== null ? json_encode($newData) : null,
                $changedFields !== null ? json_encode($changedFields) : null,
                $performedBy,
                $performedByName,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            return true;
        } catch (Throwable $e) {
            error_log('logMaintenanceAction error: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get audit log history for a housekeeping assignment.
 *
 * @param int $assignmentId Assignment ID
 * @return array List of audit log entries
 */
if (!function_exists('getHousekeepingAuditLog')) {
    function getHousekeepingAuditLog(int $assignmentId): array
    {
        global $pdo;

        try {
            ensureAuditLogTables($pdo);

            $stmt = $pdo->prepare("
            SELECT * FROM housekeeping_audit_log
            WHERE assignment_id = ?
            ORDER BY performed_at DESC
        ");
            $stmt->execute([$assignmentId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode JSON fields
            foreach ($results as &$row) {
                if ($row['old_values'] !== null) {
                    $row['old_values'] = json_decode($row['old_values'], true);
                }
                if ($row['new_values'] !== null) {
                    $row['new_values'] = json_decode($row['new_values'], true);
                }
                if ($row['changed_fields'] !== null) {
                    $row['changed_fields'] = json_decode($row['changed_fields'], true);
                }
            }

            return $results;
        } catch (Throwable $e) {
            error_log('getHousekeepingAuditLog error: ' . $e->getMessage());
            return [];
        }
    }
}

/**
 * Get audit log history for a maintenance schedule.
 *
 * @param int $maintenanceId Maintenance ID
 * @return array List of audit log entries
 */
if (!function_exists('getMaintenanceAuditLog')) {
    function getMaintenanceAuditLog(int $maintenanceId): array
    {
        global $pdo;

        try {
            ensureAuditLogTables($pdo);

            $stmt = $pdo->prepare("
            SELECT * FROM maintenance_audit_log
            WHERE maintenance_id = ?
            ORDER BY performed_at DESC
        ");
            $stmt->execute([$maintenanceId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode JSON fields
            foreach ($results as &$row) {
                if ($row['old_values'] !== null) {
                    $row['old_values'] = json_decode($row['old_values'], true);
                }
                if ($row['new_values'] !== null) {
                    $row['new_values'] = json_decode($row['new_values'], true);
                }
                if ($row['changed_fields'] !== null) {
                    $row['changed_fields'] = json_decode($row['changed_fields'], true);
                }
            }

            return $results;
        } catch (Throwable $e) {
            error_log('getMaintenanceAuditLog error: ' . $e->getMessage());
            return [];
        }
    }
}

/**
 * Get all audit log entries for housekeeping (admin view).
 *
 * @param int|null $limit Optional limit
 * @param int|null $offset Optional offset
 * @return array List of audit log entries with related data
 */
function getAllHousekeepingAuditLogs(?int $limit = null, ?int $offset = null): array
{
    global $pdo;

    try {
        ensureAuditLogTables($pdo);

        $sql = "
            SELECT hal.*,
                   ha.individual_room_id,
                   ir.room_number,
                   ir.room_name,
                   ha.status as current_status
            FROM housekeeping_audit_log hal
            LEFT JOIN housekeeping_assignments ha ON hal.assignment_id = ha.id
            LEFT JOIN individual_rooms ir ON ha.individual_room_id = ir.id
            ORDER BY hal.performed_at DESC
        ";

        if ($limit !== null) {
            $sql .= " LIMIT " . (int)$limit;
            if ($offset !== null) {
                $sql .= " OFFSET " . (int)$offset;
            }
        }

        $stmt = $pdo->query($sql);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode JSON fields
        foreach ($results as &$row) {
            if ($row['old_values'] !== null) {
                $row['old_values'] = json_decode($row['old_values'], true);
            }
            if ($row['new_values'] !== null) {
                $row['new_values'] = json_decode($row['new_values'], true);
            }
            if ($row['changed_fields'] !== null) {
                $row['changed_fields'] = json_decode($row['changed_fields'], true);
            }
        }

        return $results;
    } catch (Throwable $e) {
        error_log('getAllHousekeepingAuditLogs error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get all audit log entries for maintenance (admin view).
 *
 * @param int|null $limit Optional limit
 * @param int|null $offset Optional offset
 * @return array List of audit log entries with related data
 */
function getAllMaintenanceAuditLogs(?int $limit = null, ?int $offset = null): array
{
    global $pdo;

    try {
        ensureAuditLogTables($pdo);

        $sql = "
            SELECT mal.*,
                   rms.individual_room_id,
                   ir.room_number,
                   ir.room_name,
                   rms.status as current_status,
                   rms.title
            FROM maintenance_audit_log mal
            LEFT JOIN room_maintenance_schedules rms ON mal.maintenance_id = rms.id
            LEFT JOIN individual_rooms ir ON rms.individual_room_id = ir.id
            ORDER BY mal.performed_at DESC
        ";

        if ($limit !== null) {
            $sql .= " LIMIT " . (int)$limit;
            if ($offset !== null) {
                $sql .= " OFFSET " . (int)$offset;
            }
        }

        $stmt = $pdo->query($sql);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode JSON fields
        foreach ($results as &$row) {
            if ($row['old_values'] !== null) {
                $row['old_values'] = json_decode($row['old_values'], true);
            }
            if ($row['new_values'] !== null) {
                $row['new_values'] = json_decode($row['new_values'], true);
            }
            if ($row['changed_fields'] !== null) {
                $row['changed_fields'] = json_decode($row['changed_fields'], true);
            }
        }

        return $results;
    } catch (Throwable $e) {
        error_log('getAllMaintenanceAuditLogs error: ' . $e->getMessage());
        return [];
    }
}

// ============================================================================
// STOCK MANAGEMENT INTEGRATION
// (Migration 015 — see admin/migrations/015_stock_management.php)
//
// All functions below are defensive: they no-op silently if stock tables
// have not yet been created. This ensures a fresh deployment that hasn't
// run the migration won't break booking charge flows.
// ============================================================================

/**
 * Returns true if the core stock tables exist.
 * Result is cached per request via static var.
 */
function ensureStockTablesExist(): bool
{
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute(['stock_ingredients']);
        $checked = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        error_log('ensureStockTablesExist warning: ' . $e->getMessage());
        $checked = false;
    }
    return $checked;
}

/**
 * Map a charge type to a stock menu_type value (slug).
 * With dynamic categories this is now a pass-through for non-null slugs.
 */
function chargeTypeToMenuType(string $chargeType): ?string
{
    // Any non-empty string is a valid category slug now
    return ($chargeType !== '') ? $chargeType : null;
}

/**
 * Deduct stock for a menu item using its recipe.
 *
 * Walks the recipe ingredients and deducts each from stock using FIFO batch
 * consumption. Yield % is applied per recipe-line: an item that "uses 100g
 * cooked chicken at 60% yield" actually deducts 166.7g of raw chicken.
 *
 * Source attribution is recorded in stock_adjustments via $sourceType + $sourceId
 * so the deduction can later be traced back to the order/charge that caused it.
 *
 * @return bool true on success, false if no recipe / tables missing / failure.
 */
function deductStockForMenuItem(int $menuItemId, string $menuType, float $portions, string $sourceType, ?int $sourceId, ?int $doneBy): bool
{
    global $pdo;
    if ($portions <= 0) return true;
    if (!ensureStockTablesExist()) return false;
    if ($menuType === '') return false;
    if (!in_array($sourceType, ['pos_order', 'room_service', 'manual', 'stock_in', 'void_restore', 'wastage', 'expiry', 'recall'], true)) {
        $sourceType = 'manual';
    }

    $ownTx = !$pdo->inTransaction();

    try {
        if ($ownTx) $pdo->beginTransaction();

        // Fetch recipe + ingredients
        $stmt = $pdo->prepare("
            SELECT sri.ingredient_id, sri.quantity_per_portion, sri.yield_percent
            FROM stock_recipes sr
            INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
            WHERE sr.menu_item_id = ? AND sr.menu_type = ?
        ");
        $stmt->execute([$menuItemId, $menuType]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            // No recipe — order is allowed, just no stock impact
            if ($ownTx) $pdo->commit();
            return true;
        }

        $roomServiceDupCheck = null;
        if ($sourceType === 'room_service' && $sourceId !== null) {
            $roomServiceDupCheck = $pdo->prepare("
                SELECT id
                FROM stock_adjustments
                WHERE source_type = 'room_service'
                  AND source_id = ?
                  AND ingredient_id = ?
                  AND quantity_change < 0
                LIMIT 1
            ");
        }

        // Idempotency guard for POS orders: prevents double-deduction on retried requests
        $posOrderDupCheck = null;
        if ($sourceType === 'pos_order' && $sourceId !== null) {
            $posOrderDupCheck = $pdo->prepare("
                SELECT id
                FROM stock_adjustments
                WHERE source_type = 'pos_order'
                  AND source_id = ?
                  AND ingredient_id = ?
                  AND quantity_change < 0
                LIMIT 1
            ");
        }

        foreach ($rows as $row) {
            $yield = (float)$row['yield_percent'];
            if ($yield <= 0) $yield = 100.0; // safety
            $rawNeeded = ((float)$row['quantity_per_portion'] * $portions) / ($yield / 100.0);
            if ($rawNeeded <= 0) continue;
            if ($roomServiceDupCheck) {
                $roomServiceDupCheck->execute([$sourceId, (int)$row['ingredient_id']]);
                if ($roomServiceDupCheck->fetchColumn()) {
                    continue;
                }
            }
            if ($posOrderDupCheck) {
                $posOrderDupCheck->execute([$sourceId, (int)$row['ingredient_id']]);
                if ($posOrderDupCheck->fetchColumn()) {
                    continue;
                }
            }
            deductStockBatchFIFO((int)$row['ingredient_id'], $rawNeeded, $sourceType, $sourceId, $doneBy);
        }

        if ($ownTx) $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log("deductStockForMenuItem error (item {$menuItemId}/{$menuType}): " . $e->getMessage());
        return false;
    }
}

/**
 * Ensure the batch ledger has enough active quantity to cover a deduction.
 *
 * Legacy/manual flows can leave ingredient totals ahead of active batches.
 * This helper mints a reconciliation batch for the uncovered gap WITHOUT
 * changing ingredient.current_quantity, so FIFO deductions stay batch-consistent.
 *
 * @return float Quantity added to reconciliation batches.
 */
function ensureStockBatchCoverageForDeduction(int $ingredientId, float $requiredQty, float $costPerUnit, ?int $doneBy, string $note = 'Auto stock batch reconciliation'): float
{
    global $pdo;
    if (!ensureStockTablesExist() || $requiredQty <= 0) {
        return 0.0;
    }

    $ownTx = !$pdo->inTransaction();

    try {
        if ($ownTx) {
            $pdo->beginTransaction();
        }

        $batchSel = $pdo->prepare("\n            SELECT quantity_remaining\n            FROM stock_batches\n            WHERE ingredient_id = ?\n              AND status = 'active'\n              AND quantity_remaining > 0.0001\n              AND (expiry_date IS NULL OR expiry_date >= CURDATE())\n            FOR UPDATE\n        ");
        $batchSel->execute([$ingredientId]);

        $available = 0.0;
        foreach ($batchSel->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $available += (float)$row['quantity_remaining'];
        }

        $gap = round($requiredQty - $available, 4);
        if ($gap > 0.0001) {
            $tmpBatchNumber = 'TMP-R-' . date('YmdHis') . '-' . strtoupper(substr(str_replace('.', '', uniqid('', true)), -8));
            $ins = $pdo->prepare("\n                INSERT INTO stock_batches (\n                    ingredient_id, batch_number, quantity_received, quantity_remaining, cost_per_unit,\n                    supplier_name, supplier_contact, received_date, expiry_date, expiry_alert_days, status, notes, created_by\n                ) VALUES (?, ?, ?, ?, ?, NULL, NULL, CURDATE(), NULL, 7, 'active', ?, ?)\n            ");
            $ins->execute([
                $ingredientId,
                $tmpBatchNumber,
                $gap,
                $gap,
                max(0, $costPerUnit),
                mb_substr($note, 0, 500),
                $doneBy,
            ]);

            $batchId = (int)$pdo->lastInsertId();
            $batchNumber = 'R' . date('Ymd') . '-' . str_pad((string)$batchId, 6, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE stock_batches SET batch_number = ? WHERE id = ?')->execute([$batchNumber, $batchId]);
        } else {
            $gap = 0.0;
        }

        if ($ownTx) {
            $pdo->commit();
        }
        return $gap;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("ensureStockBatchCoverageForDeduction error (ingredient {$ingredientId}, qty {$requiredQty}): " . $e->getMessage());
        return 0.0;
    }
}

/**
 * Deduct a quantity from a single ingredient's batches using FIFO.
 *
 * - Locks active, non-expired batches with SELECT ... FOR UPDATE to prevent
 *   races between concurrent orders.
 * - Walks oldest expiry first (NULL expiry = treated as last).
 * - If all batches exhausted, allows current_quantity to go negative (kitchen
 *   reality) but flags via stock_adjustments. UI surfaces this as a warning.
 * - Records each batch-level deduction in stock_batch_deductions so a later
 *   void/restore can put the stock back into the same batches.
 *
 * Honours an outer transaction if one already exists.
 *
 * @return int|null Returns the stock_adjustments.id created, or null on failure.
 */
function deductStockBatchFIFO(int $ingredientId, float $qty, string $sourceType, ?int $sourceId, ?int $doneBy): ?int
{
    global $pdo;
    if (!ensureStockTablesExist()) return null;
    if ($qty <= 0) return null;

    $ownTx = !$pdo->inTransaction();

    try {
        if ($ownTx) $pdo->beginTransaction();

        // Snapshot current cost for adjustment row
        $costStmt = $pdo->prepare("SELECT cost_per_unit FROM stock_ingredients WHERE id = ? FOR UPDATE");
        $costStmt->execute([$ingredientId]);
        $costRow = $costStmt->fetch(PDO::FETCH_ASSOC);
        if (!$costRow) {
            if ($ownTx) $pdo->rollBack();
            error_log("deductStockBatchFIFO: ingredient {$ingredientId} not found");
            return null;
        }
        $costAtTime = (float)$costRow['cost_per_unit'];

        // Lock active batches with stock remaining, oldest expiry first
        $batchStmt = $pdo->prepare("
            SELECT id, quantity_remaining, cost_per_unit
            FROM stock_batches
            WHERE ingredient_id = ?
              AND status = 'active'
              AND quantity_remaining > 0.0001
              AND (expiry_date IS NULL OR expiry_date >= CURDATE())
            ORDER BY (expiry_date IS NULL) ASC, expiry_date ASC, id ASC
            FOR UPDATE
        ");
        $batchStmt->execute([$ingredientId]);
        $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

        // Plan deductions
        $remaining = $qty;
        $plan = [];
        foreach ($batches as $b) {
            if ($remaining <= 0.0001) break;
            $take = min((float)$b['quantity_remaining'], $remaining);
            if ($take <= 0) continue;
            $plan[] = ['batch_id' => (int)$b['id'], 'qty' => $take, 'batch_remaining_after' => (float)$b['quantity_remaining'] - $take, 'cost' => (float)$b['cost_per_unit']];
            $remaining -= $take;
        }
        // Unallocated portion (negative stock allowed; no batch attribution)
        $unallocated = $remaining > 0.0001 ? $remaining : 0.0;

        // Compute weighted average cost from the actual batches consumed (not ingredient average)
        $planTotal = 0.0;
        $planCost = 0.0;
        foreach ($plan as $p) {
            $planTotal += $p['qty'];
            $planCost  += $p['qty'] * $p['cost'];
        }
        if ($unallocated > 0.0001) {
            // Unallocated portion falls back to ingredient average cost
            $planTotal += $unallocated;
            $planCost  += $unallocated * $costAtTime;
        }
        $weightedCostAtTime = ($planTotal > 0) ? round($planCost / $planTotal, 4) : $costAtTime;

        // Insert the adjustment row first
        $adjStmt = $pdo->prepare("
            INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $reason = match ($sourceType) {
            'pos_order'    => 'POS order deduction',
            'room_service' => 'Room service charge deduction',
            'wastage'      => 'Wastage deduction',
            'variance'     => 'Stock count variance',
            'expiry'       => 'Expired batch removed',
            'recall'       => 'Batch recalled',
            'void_restore' => 'Restore from voided charge',
            'stock_in'     => 'Stock received',
            default        => 'Manual adjustment',
        };
        $adjStmt->execute([$ingredientId, -$qty, $reason, $sourceType, $sourceId, $weightedCostAtTime, $doneBy]);
        $adjustmentId = (int)$pdo->lastInsertId();

        // Apply batch deductions
        $bdStmt = $pdo->prepare("INSERT INTO stock_batch_deductions (batch_id, adjustment_id, quantity_deducted, created_at) VALUES (?, ?, ?, NOW())");
        $bUpd = $pdo->prepare("UPDATE stock_batches SET quantity_remaining = ?, status = ?, updated_at = NOW() WHERE id = ?");
        foreach ($plan as $p) {
            $newRemaining = $p['batch_remaining_after'];
            $newStatus = ($newRemaining < 0.0001) ? 'depleted' : 'active';
            $bUpd->execute([max(0, $newRemaining), $newStatus, $p['batch_id']]);
            $bdStmt->execute([$p['batch_id'], $adjustmentId, $p['qty']]);
        }

        // Update aggregate quantity on ingredient (subtracts the FULL qty including unallocated)
        $ingUpd = $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity - ?, updated_at = NOW() WHERE id = ?");
        $ingUpd->execute([$qty, $ingredientId]);

        if ($unallocated > 0.0001) {
            error_log(sprintf(
                "deductStockBatchFIFO: ingredient %d went negative by %.4f units (source=%s/%s)",
                $ingredientId,
                $unallocated,
                $sourceType,
                $sourceId ?? '-'
            ));
            rh_log_event('stock', 'warning', sprintf(
                'Ingredient #%d went negative by %.4f units — stock needs reconciliation.',
                $ingredientId,
                $unallocated
            ), ['ingredient_id' => $ingredientId, 'shortfall' => $unallocated, 'source_type' => $sourceType, 'source_id' => $sourceId]);
        }

        if ($ownTx) $pdo->commit();
        return $adjustmentId;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log("deductStockBatchFIFO error (ingredient {$ingredientId}, qty {$qty}): " . $e->getMessage());
        return null;
    }
}

/**
 * Restore stock for a previously-deducted menu item charge/order.
 *
 * Strategy:
 *   1. Find the original stock_adjustments rows for this charge (matched by
 *      source_type + source_id).
 *   2. Walk stock_batch_deductions linked to those adjustments and restore
 *      quantity_remaining to those exact batches (reactivating from depleted).
 *   3. Insert a void_restore stock_adjustments row.
 *   4. Update stock_ingredients.current_quantity.
 *
 * If we cannot trace the original (e.g. charge was placed before migration
 * but stock_tracked got set somehow), fall back to deducting by recipe and
 * adding back to general stock without batch attribution.
 */
function restoreStockForMenuItem(int $menuItemId, string $menuType, float $portions, string $reason, ?int $doneBy, ?int $originalChargeId, string $originalSourceType = 'room_service'): bool
{
    global $pdo;
    if ($portions <= 0) return true;
    if (!ensureStockTablesExist()) return false;
    if ($menuType === '') return false;

    $ownTx = !$pdo->inTransaction();

    try {
        if ($ownTx) $pdo->beginTransaction();

        // Use the caller-supplied source type to find the original deduction adjustments.
        // Room-service charges use 'room_service'; POS orders use 'pos_order'.
        $sourceType = in_array($originalSourceType, ['pos_order', 'room_service', 'manual', 'variance', 'wastage'], true)
            ? $originalSourceType
            : 'room_service';

        // Idempotency guard — mirrors the dup-check on the deduction side. A void
        // restore for a given charge/line is written as a 'void_restore' adjustment
        // keyed on that charge id. If one already exists, this charge was already
        // put back; a retried or double-clicked void must NOT add the stock again.
        if ($originalChargeId !== null) {
            $restoreDupCheck = $pdo->prepare("
                SELECT id FROM stock_adjustments
                WHERE source_type = 'void_restore' AND source_id = ?
                LIMIT 1
            ");
            $restoreDupCheck->execute([$originalChargeId]);
            if ($restoreDupCheck->fetchColumn()) {
                if ($ownTx && $pdo->inTransaction()) $pdo->commit();
                return true;
            }
        }

        $byBatch = []; // batch_id => qty to add back
        $byIngredient = []; // ingredient_id => qty to add back (fallback / aggregate)

        if ($originalChargeId !== null) {
            $sel = $pdo->prepare("
                SELECT sa.id AS adjustment_id, sa.ingredient_id, sa.quantity_change, sbd.batch_id, sbd.quantity_deducted
                FROM stock_adjustments sa
                LEFT JOIN stock_batch_deductions sbd ON sbd.adjustment_id = sa.id
                WHERE sa.source_type = ? AND sa.source_id = ?
            ");
            $sel->execute([$sourceType, $originalChargeId]);
            $hits = $sel->fetchAll(PDO::FETCH_ASSOC);

            $seenAdj = [];
            foreach ($hits as $h) {
                $adjId = (int)$h['adjustment_id'];
                $ingId = (int)$h['ingredient_id'];
                // The LEFT JOIN can multiply rows when one adjustment spans several batches.
                // Add quantity_change once per adjustment to avoid over-restoring the ingredient total.
                if (!isset($seenAdj[$adjId])) {
                    $seenAdj[$adjId] = true;
                    $byIngredient[$ingId] = ($byIngredient[$ingId] ?? 0) + abs((float)$h['quantity_change']);
                }
                if (!empty($h['batch_id'])) {
                    $bid = (int)$h['batch_id'];
                    $byBatch[$bid] = ($byBatch[$bid] ?? 0) + (float)$h['quantity_deducted'];
                }
            }
        }

        // Fallback: nothing found, recompute from recipe
        $usedRecipeFallback = false;
        if (empty($byIngredient)) {
            $usedRecipeFallback = true;
            $rec = $pdo->prepare("
                SELECT sri.ingredient_id, sri.quantity_per_portion, sri.yield_percent
                FROM stock_recipes sr
                INNER JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
                WHERE sr.menu_item_id = ? AND sr.menu_type = ?
            ");
            $rec->execute([$menuItemId, $menuType]);
            foreach ($rec->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $yield = (float)$row['yield_percent'];
                if ($yield <= 0) $yield = 100.0;
                $rawNeeded = ((float)$row['quantity_per_portion'] * $portions) / ($yield / 100.0);
                if ($rawNeeded > 0) {
                    $byIngredient[(int)$row['ingredient_id']] = ($byIngredient[(int)$row['ingredient_id']] ?? 0) + $rawNeeded;
                }
            }
        }

        if ($usedRecipeFallback && !empty($byIngredient) && empty($byBatch)) {
            $batchRestoreSel = $pdo->prepare("
                SELECT id, quantity_received, quantity_remaining
                FROM stock_batches
                WHERE ingredient_id = ?
                  AND status IN ('active', 'depleted')
                ORDER BY (expiry_date IS NULL) ASC, expiry_date ASC, id ASC
                FOR UPDATE
            ");
            foreach ($byIngredient as $ingId => $q) {
                $remainingRestore = $q;
                $batchRestoreSel->execute([$ingId]);
                foreach ($batchRestoreSel->fetchAll(PDO::FETCH_ASSOC) as $batchRow) {
                    if ($remainingRestore <= 0.0001) {
                        break;
                    }
                    $batchId = (int)$batchRow['id'];
                    $capacity = max(0.0, (float)$batchRow['quantity_received'] - (float)$batchRow['quantity_remaining']);
                    if ($capacity <= 0.0001) {
                        continue;
                    }
                    $restoreQty = min($capacity, $remainingRestore);
                    $byBatch[$batchId] = ($byBatch[$batchId] ?? 0) + $restoreQty;
                    $remainingRestore -= $restoreQty;
                }
            }
        }

        // Apply per-batch restorations (reactivate depleted batches if needed)
        if (!empty($byBatch)) {
            $bUpd = $pdo->prepare("
                UPDATE stock_batches
                SET quantity_remaining = quantity_remaining + ?,
                    status = CASE
                        WHEN status = 'depleted' THEN 'active'
                        ELSE status
                    END,
                    updated_at = NOW()
                WHERE id = ?
            ");
            foreach ($byBatch as $batchId => $q) {
                $bUpd->execute([$q, $batchId]);
            }
        }

        // For each ingredient: insert restore adjustment + bump aggregate qty
        $adjStmt = $pdo->prepare("
            INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by, created_at)
            VALUES (?, ?, ?, 'void_restore', ?, ?, ?, NOW())
        ");
        $costSel = $pdo->prepare("SELECT cost_per_unit FROM stock_ingredients WHERE id = ?");
        $ingUpd = $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity + ?, updated_at = NOW() WHERE id = ?");

        foreach ($byIngredient as $ingId => $q) {
            $costSel->execute([$ingId]);
            $costAtTime = (float)($costSel->fetchColumn() ?: 0);
            $adjStmt->execute([$ingId, $q, mb_substr($reason, 0, 250), $originalChargeId, $costAtTime, $doneBy]);
            $ingUpd->execute([$q, $ingId]);
        }

        if ($ownTx) $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log("restoreStockForMenuItem error (item {$menuItemId}/{$menuType}): " . $e->getMessage());
        return false;
    }
}

/**
 * Run lightweight expiry maintenance: mark expired active batches as 'expired'.
 * Called from stock dashboard / batch pages on each load (cheap UPDATE).
 *
 * Returns number of batches transitioned.
 */
function runStockExpiryCheck(): int
{
    global $pdo;
    if (!ensureStockTablesExist()) return 0;
    try {
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();

        $sel = $pdo->prepare("
            SELECT id, ingredient_id, quantity_remaining, cost_per_unit
            FROM stock_batches
            WHERE status = 'active'
              AND expiry_date IS NOT NULL
              AND expiry_date < CURDATE()
            FOR UPDATE
        ");
        $sel->execute();
        $batches = $sel->fetchAll(PDO::FETCH_ASSOC);

        $ingUpd = $pdo->prepare("UPDATE stock_ingredients SET current_quantity = current_quantity - ?, updated_at = NOW() WHERE id = ?");
        $adjIns = $pdo->prepare("
            INSERT INTO stock_adjustments (ingredient_id, quantity_change, reason, source_type, source_id, cost_at_time, adjusted_by, created_at)
            VALUES (?, ?, 'Expired batch removed', 'expiry', ?, ?, NULL, NOW())
        ");
        $batchUpd = $pdo->prepare("UPDATE stock_batches SET quantity_remaining = 0, status = 'expired', updated_at = NOW() WHERE id = ?");

        foreach ($batches as $batch) {
            $quantity = max(0.0, (float)$batch['quantity_remaining']);
            if ($quantity > 0.0001) {
                $ingUpd->execute([$quantity, (int)$batch['ingredient_id']]);
                $adjIns->execute([(int)$batch['ingredient_id'], -$quantity, (int)$batch['id'], (float)$batch['cost_per_unit']]);
            }
            $batchUpd->execute([(int)$batch['id']]);
        }

        if ($ownTx) $pdo->commit();
        return count($batches);
    } catch (Throwable $e) {
        if (isset($ownTx) && $ownTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('runStockExpiryCheck error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Recalculate weighted-average cost when receiving new stock.
 * Returns new average. Does NOT persist — caller should UPDATE.
 *
 * Formula: new_avg = (max(0, current_qty) * old_avg + incoming_qty * incoming_cost)
 *                    / (max(0, current_qty) + incoming_qty)
 *
 * Negative current_quantity is clamped to 0 to prevent the average being
 * distorted by past over-consumption.
 */
function calculateWeightedAvgCost(float $currentQty, float $oldAvg, float $incomingQty, float $incomingCost): float
{
    $base = max(0.0, $currentQty);
    $denom = $base + $incomingQty;
    if ($denom <= 0.0001) return $incomingCost;
    return (($base * $oldAvg) + ($incomingQty * $incomingCost)) / $denom;
}

/**
 * Generate the next POS order reference: ORD-YYYYMMDD-NNN
 */
function generateStockOrderReference(): string
{
    global $pdo;
    $prefix = 'ORD-' . date('Ymd') . '-';
    // Retry up to 5 times to handle concurrent inserts that grab the same candidate number
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $stmt = $pdo->prepare("SELECT reference FROM stock_orders WHERE reference LIKE ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$prefix . '%']);
            $last = $stmt->fetchColumn();
            if ($last) {
                $n = (int)substr($last, strlen($prefix));
                $candidate = $prefix . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT);
            } else {
                $candidate = $prefix . '001';
            }
            // Confirm candidate is still unused before returning it
            $chk = $pdo->prepare("SELECT 1 FROM stock_orders WHERE reference = ? LIMIT 1");
            $chk->execute([$candidate]);
            if (!$chk->fetchColumn()) {
                return $candidate;
            }
        } catch (Throwable $e) {
            error_log('generateStockOrderReference error: ' . $e->getMessage());
            break;
        }
    }
    // Last-resort: append microseconds to ensure uniqueness
    return $prefix . 'X' . substr((string)round(microtime(true) * 1000), -6);
}
