<?php
/**
 * Audit Functions for Housekeeping and Maintenance
 * 
 * This file provides functions for logging and retrieving audit trails
 * for housekeeping assignments and maintenance schedules.
 * 
 * Audit tables track:
 * - Who made the change (performed_by, performed_by_name)
 * - What action was performed (created, updated, deleted, verified, etc.)
 * - When it was performed (performed_at timestamp)
 * - What changed (old_values, new_values, changed_fields)
 * 
 * Requires: admin-init.php must be included first (provides $pdo global)
 */

/**
 * Check if a table exists in the database
 * 
 * @param PDO $pdo Database connection
 * @param string $table Table name to check
 * @return bool True if table exists, false otherwise
 */
function auditTableExists(PDO $pdo, string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

/**
 * Log an action for a housekeeping assignment
 *
 * @param int $assignmentId The ID of the housekeeping assignment
 * @param string $action The action performed (created, updated, deleted, verified, etc.)
 * @param array|null $oldValues The values before the change (null for create)
 * @param array|null $newValues The values after the change (null for delete)
 * @param int|null $performedBy The user ID who performed the action
 * @param string|null $performedByName The username for historical accuracy
 * @return bool True if logged successfully, false otherwise
 */
if (!function_exists('logHousekeepingAction')) {
    function logHousekeepingAction(int $assignmentId, string $action, ?array $oldValues, ?array $newValues, ?int $performedBy = null, ?string $performedByName = null): bool {
    global $pdo;
    
    // Check if audit table exists
    if (!auditTableExists($pdo, 'housekeeping_audit_log')) {
        error_log('housekeeping_audit_log table does not exist - cannot log action');
        return false;
    }
    
    // Validate action type
    $validActions = ['created', 'updated', 'deleted', 'verified', 'status_changed', 'assigned', 'unassigned', 'priority_changed', 'notes_updated', 'recurring_created'];
    if (!in_array($action, $validActions, true)) {
        error_log('Invalid housekeeping audit action: ' . $action);
        return false;
    }
    
    try {
        // Calculate changed fields
        $changedFields = [];
        if ($oldValues !== null && $newValues !== null) {
            foreach ($newValues as $key => $newValue) {
                $oldValue = $oldValues[$key] ?? null;
                if ($oldValue !== $newValue) {
                    $changedFields[] = $key;
                }
            }
        } elseif ($oldValues !== null) {
            $changedFields = array_keys($oldValues);
        } elseif ($newValues !== null) {
            $changedFields = array_keys($newValues);
        }
        
        // Prepare data for JSON storage (remove sensitive data)
        $safeOldValues = $oldValues !== null ? json_encode($oldValues) : null;
        $safeNewValues = $newValues !== null ? json_encode($newValues) : null;
        $safeChangedFields = !empty($changedFields) ? json_encode($changedFields) : null;
        
        // Get IP address and user agent
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        // Insert audit log entry
        $stmt = $pdo->prepare("
            INSERT INTO housekeeping_audit_log 
            (assignment_id, action, old_values, new_values, changed_fields, performed_by, performed_by_name, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $assignmentId,
            $action,
            $safeOldValues,
            $safeNewValues,
            $safeChangedFields,
            $performedBy,
            $performedByName,
            $ipAddress,
            mb_substr($userAgent, 0, 500)
        ]);
        
        return true;
    } catch (Throwable $e) {
        error_log('Failed to log housekeeping action: ' . $e->getMessage());
        return false;
    }
    }
}

/**
 * Get audit log for a specific housekeeping assignment
 *
 * @param int $assignmentId The ID of the housekeeping assignment
 * @return array Array of audit log entries with performed_by_name
 */
if (!function_exists('getHousekeepingAuditLog')) {
    function getHousekeepingAuditLog(int $assignmentId): array {
    global $pdo;
    
    // Check if audit table exists
    if (!auditTableExists($pdo, 'housekeeping_audit_log')) {
        return [];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                hal.id,
                hal.assignment_id,
                hal.action,
                hal.old_values,
                hal.new_values,
                hal.changed_fields,
                hal.performed_by,
                hal.performed_by_name,
                hal.performed_at,
                hal.ip_address,
                COALESCE(hal.performed_by_name, au.username) as performed_by_display
            FROM housekeeping_audit_log hal
            LEFT JOIN admin_users au ON hal.performed_by = au.id
            WHERE hal.assignment_id = ?
            ORDER BY hal.performed_at DESC, hal.id DESC
        ");
        
        $stmt->execute([$assignmentId]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode JSON fields
        foreach ($logs as &$log) {
            $log['old_values'] = $log['old_values'] !== null ? json_decode($log['old_values'], true) : null;
            $log['new_values'] = $log['new_values'] !== null ? json_decode($log['new_values'], true) : null;
            $log['changed_fields'] = $log['changed_fields'] !== null ? json_decode($log['changed_fields'], true) : [];
        }
        
        return $logs;
    } catch (Throwable $e) {
        error_log('Failed to get housekeeping audit log: ' . $e->getMessage());
        return [];
    }
    }
}

/**
 * Log an action for a maintenance schedule
 *
 * @param int $maintenanceId The ID of the maintenance schedule
 * @param string $action The action performed (created, updated, deleted, verified, etc.)
 * @param array|null $oldValues The values before the change (null for create)
 * @param array|null $newValues The values after the change (null for delete)
 * @param int|null $performedBy The user ID who performed the action
 * @param string|null $performedByName The username for historical accuracy
 * @return bool True if logged successfully, false otherwise
 */
if (!function_exists('logMaintenanceAction')) {
    function logMaintenanceAction(int $maintenanceId, string $action, ?array $oldValues, ?array $newValues, ?int $performedBy = null, ?string $performedByName = null): bool {
    global $pdo;
    
    // Check if audit table exists
    if (!auditTableExists($pdo, 'maintenance_audit_log')) {
        error_log('maintenance_audit_log table does not exist - cannot log action');
        return false;
    }
    
    // Validate action type
    $validActions = ['created', 'updated', 'deleted', 'verified', 'status_changed', 'assigned', 'unassigned', 'priority_changed', 'notes_updated', 'recurring_created', 'type_changed'];
    if (!in_array($action, $validActions, true)) {
        error_log('Invalid maintenance audit action: ' . $action);
        return false;
    }
    
    try {
        // Calculate changed fields
        $changedFields = [];
        if ($oldValues !== null && $newValues !== null) {
            foreach ($newValues as $key => $newValue) {
                $oldValue = $oldValues[$key] ?? null;
                if ($oldValue !== $newValue) {
                    $changedFields[] = $key;
                }
            }
        } elseif ($oldValues !== null) {
            $changedFields = array_keys($oldValues);
        } elseif ($newValues !== null) {
            $changedFields = array_keys($newValues);
        }
        
        // Prepare data for JSON storage (remove sensitive data)
        $safeOldValues = $oldValues !== null ? json_encode($oldValues) : null;
        $safeNewValues = $newValues !== null ? json_encode($newValues) : null;
        $safeChangedFields = !empty($changedFields) ? json_encode($changedFields) : null;
        
        // Get IP address and user agent
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        // Insert audit log entry
        $stmt = $pdo->prepare("
            INSERT INTO maintenance_audit_log 
            (maintenance_id, action, old_values, new_values, changed_fields, performed_by, performed_by_name, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $maintenanceId,
            $action,
            $safeOldValues,
            $safeNewValues,
            $safeChangedFields,
            $performedBy,
            $performedByName,
            $ipAddress,
            mb_substr($userAgent, 0, 500)
        ]);
        
        return true;
    } catch (Throwable $e) {
        error_log('Failed to log maintenance action: ' . $e->getMessage());
        return false;
    }
    }
}

/**
 * Get audit log for a specific maintenance schedule
 *
 * @param int $maintenanceId The ID of the maintenance schedule
 * @return array Array of audit log entries with performed_by_name
 */
if (!function_exists('getMaintenanceAuditLog')) {
    function getMaintenanceAuditLog(int $maintenanceId): array {
    global $pdo;
    
    // Check if audit table exists
    if (!auditTableExists($pdo, 'maintenance_audit_log')) {
        return [];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                mal.id,
                mal.maintenance_id,
                mal.action,
                mal.old_values,
                mal.new_values,
                mal.changed_fields,
                mal.performed_by,
                mal.performed_by_name,
                mal.performed_at,
                mal.ip_address,
                COALESCE(mal.performed_by_name, au.username) as performed_by_display
            FROM maintenance_audit_log mal
            LEFT JOIN admin_users au ON mal.performed_by = au.id
            WHERE mal.maintenance_id = ?
            ORDER BY mal.performed_at DESC, mal.id DESC
        ");
        
        $stmt->execute([$maintenanceId]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode JSON fields
        foreach ($logs as &$log) {
            $log['old_values'] = $log['old_values'] !== null ? json_decode($log['old_values'], true) : null;
            $log['new_values'] = $log['new_values'] !== null ? json_decode($log['new_values'], true) : null;
            $log['changed_fields'] = $log['changed_fields'] !== null ? json_decode($log['changed_fields'], true) : [];
        }
        
        return $logs;
    } catch (Throwable $e) {
        error_log('Failed to get maintenance audit log: ' . $e->getMessage());
        return [];
    }
    }
}

/**
 * Get recent audit activity across all housekeeping assignments
 * Useful for dashboard widgets and activity feeds
 * 
 * @param int $limit Maximum number of entries to return
 * @return array Array of recent audit log entries
 */
function getRecentHousekeepingActivity(int $limit = 20): array {
    global $pdo;
    
    if (!auditTableExists($pdo, 'housekeeping_audit_log')) {
        return [];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                hal.id,
                hal.assignment_id,
                hal.action,
                hal.performed_by_name,
                hal.performed_at,
                ir.room_number,
                ir.room_name
            FROM housekeeping_audit_log hal
            LEFT JOIN housekeeping_assignments ha ON hal.assignment_id = ha.id
            LEFT JOIN individual_rooms ir ON ha.individual_room_id = ir.id
            ORDER BY hal.performed_at DESC
            LIMIT ?
        ");
        
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Failed to get recent housekeeping activity: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get recent audit activity across all maintenance schedules
 * Useful for dashboard widgets and activity feeds
 * 
 * @param int $limit Maximum number of entries to return
 * @return array Array of recent audit log entries
 */
function getRecentMaintenanceActivity(int $limit = 20): array {
    global $pdo;
    
    if (!auditTableExists($pdo, 'maintenance_audit_log')) {
        return [];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                mal.id,
                mal.maintenance_id,
                mal.action,
                mal.performed_by_name,
                mal.performed_at,
                rms.title,
                ir.room_number,
                ir.room_name
            FROM maintenance_audit_log mal
            LEFT JOIN room_maintenance_schedules rms ON mal.maintenance_id = rms.id
            LEFT JOIN individual_rooms ir ON rms.individual_room_id = ir.id
            ORDER BY mal.performed_at DESC
            LIMIT ?
        ");
        
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Failed to get recent maintenance activity: ' . $e->getMessage());
        return [];
    }
}

/**
 * Ensure the booking_audit_log table exists. Auto-creates on first call.
 */
if (!function_exists('ensureBookingAuditTable')) {
    function ensureBookingAuditTable(PDO $pdo): bool {
        if (auditTableExists($pdo, 'booking_audit_log')) return true;
        try {
            $pdo->exec("CREATE TABLE booking_audit_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_id INT UNSIGNED NOT NULL,
                booking_reference VARCHAR(64) DEFAULT NULL,
                action VARCHAR(64) NOT NULL,
                old_values JSON NULL,
                new_values JSON NULL,
                changed_fields JSON NULL,
                note TEXT NULL,
                performed_by INT UNSIGNED DEFAULT NULL,
                performed_by_name VARCHAR(255) DEFAULT NULL,
                performed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(500) DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_bal_booking (booking_id),
                KEY idx_bal_action (action),
                KEY idx_bal_performed_at (performed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit log for booking actions'");
            return true;
        } catch (Throwable $e) {
            error_log('ensureBookingAuditTable failed: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Log a booking-related action. Safe no-op if table cannot be created.
 *
 * @param int $bookingId
 * @param string $action e.g. 'modified', 'status_changed', 'payment_recorded', 'refund_initiated', 'cancelled', 'checked_in', 'checked_out', 'no_show', 'tentative', 'confirmed', 'room_assigned', 'upgraded', 'extended', 'email_sent'
 * @param array|null $oldValues
 * @param array|null $newValues
 * @param string|null $note
 * @param string|null $bookingReference
 * @return bool
 */
if (!function_exists('logBookingAudit')) {
    function logBookingAudit(int $bookingId, string $action, ?array $oldValues = null, ?array $newValues = null, ?string $note = null, ?string $bookingReference = null): bool {
        global $pdo, $user;
        if ($bookingId <= 0) return false;
        if (!ensureBookingAuditTable($pdo)) return false;

        $changedFields = [];
        if (is_array($oldValues) && is_array($newValues)) {
            foreach ($newValues as $k => $v) {
                $ov = $oldValues[$k] ?? null;
                if ((string)$ov !== (string)$v) $changedFields[] = $k;
            }
        }

        $performedBy = isset($user['id']) ? (int)$user['id'] : null;
        $performedByName = $user['full_name'] ?? ($user['username'] ?? null);

        try {
            if (empty($bookingReference)) {
                $r = $pdo->prepare("SELECT booking_reference FROM bookings WHERE id = ?");
                $r->execute([$bookingId]);
                $bookingReference = (string)($r->fetchColumn() ?: '');
            }

            $stmt = $pdo->prepare("INSERT INTO booking_audit_log
                (booking_id, booking_reference, action, old_values, new_values, changed_fields, note, performed_by, performed_by_name, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $bookingId,
                $bookingReference ?: null,
                $action,
                $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                !empty($changedFields) ? json_encode($changedFields) : null,
                $note,
                $performedBy,
                $performedByName,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('logBookingAudit failed: ' . $e->getMessage());
            return false;
        }
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 * Gym member audit log — a membership record is a person, not a reusable slot.
 * Every identity/pricing/status change is recorded here so misuse (recycling a
 * card, silently repricing, churning a name) is fully traceable by admins.
 * ───────────────────────────────────────────────────────────────────────── */
if (!function_exists('ensureGymMemberAuditTable')) {
    function ensureGymMemberAuditTable(PDO $pdo): bool {
        if (auditTableExists($pdo, 'gym_member_audit_log')) return true;
        try {
            // IF NOT EXISTS keeps this idempotent even if auditTableExists()'s
            // per-request static cache is stale after a first-time create.
            $pdo->exec("CREATE TABLE IF NOT EXISTS gym_member_audit_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                member_id INT UNSIGNED NOT NULL,
                member_number VARCHAR(32) DEFAULT NULL,
                action VARCHAR(64) NOT NULL,
                old_values JSON NULL,
                new_values JSON NULL,
                changed_fields JSON NULL,
                note TEXT NULL,
                performed_by INT UNSIGNED DEFAULT NULL,
                performed_by_name VARCHAR(255) DEFAULT NULL,
                performed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(500) DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_gmal_member (member_id),
                KEY idx_gmal_action (action),
                KEY idx_gmal_performed_at (performed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit log for gym membership changes'");
            return true;
        } catch (Throwable $e) {
            error_log('ensureGymMemberAuditTable failed: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Log a gym-member action. Safe no-op if the table cannot be created.
 *
 * @param string $action e.g. 'enrolled', 'updated', 'name_changed', 'repriced',
 *                        'status_changed', 'package_changed', 'renewed', 'deleted',
 *                        'card_emailed', 'complimentary_granted'
 */
if (!function_exists('logGymMemberAudit')) {
    function logGymMemberAudit(int $memberId, string $action, ?array $oldValues = null, ?array $newValues = null, ?string $note = null, ?string $memberNumber = null): bool {
        global $pdo, $user;
        if ($memberId <= 0) return false;
        if (!ensureGymMemberAuditTable($pdo)) return false;

        $changedFields = [];
        if (is_array($oldValues) && is_array($newValues)) {
            foreach ($newValues as $k => $v) {
                $ov = $oldValues[$k] ?? null;
                if ((string)$ov !== (string)$v) $changedFields[] = $k;
            }
        }

        $performedBy = isset($user['id']) ? (int)$user['id'] : null;
        $performedByName = $user['full_name'] ?? ($user['username'] ?? null);

        try {
            if (empty($memberNumber)) {
                $r = $pdo->prepare("SELECT member_number FROM gym_members WHERE id = ?");
                $r->execute([$memberId]);
                $memberNumber = (string)($r->fetchColumn() ?: '');
            }
            $stmt = $pdo->prepare("INSERT INTO gym_member_audit_log
                (member_id, member_number, action, old_values, new_values, changed_fields, note, performed_by, performed_by_name, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $memberId,
                $memberNumber ?: null,
                $action,
                $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                !empty($changedFields) ? json_encode($changedFields) : null,
                $note,
                $performedBy,
                $performedByName,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('logGymMemberAudit failed: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Fetch recent audit entries for a gym member (most recent first).
 */
if (!function_exists('getGymMemberAuditLog')) {
    function getGymMemberAuditLog(int $memberId, int $limit = 100): array {
        global $pdo;
        if (!auditTableExists($pdo, 'gym_member_audit_log')) return [];
        try {
            $stmt = $pdo->prepare("SELECT * FROM gym_member_audit_log WHERE member_id = ? ORDER BY performed_at DESC, id DESC LIMIT ?");
            $stmt->bindValue(1, $memberId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['old_values'] = $r['old_values'] !== null ? json_decode($r['old_values'], true) : null;
                $r['new_values'] = $r['new_values'] !== null ? json_decode($r['new_values'], true) : null;
                $r['changed_fields'] = $r['changed_fields'] !== null ? json_decode($r['changed_fields'], true) : [];
            }
            return $rows;
        } catch (Throwable $e) {
            error_log('getGymMemberAuditLog failed: ' . $e->getMessage());
            return [];
        }
    }
}

/**
 * Fetch recent audit entries for a booking.
 */
if (!function_exists('getBookingAuditLog')) {
    function getBookingAuditLog(int $bookingId, int $limit = 50): array {
        global $pdo;
        if (!auditTableExists($pdo, 'booking_audit_log')) return [];
        try {
            $stmt = $pdo->prepare("SELECT * FROM booking_audit_log WHERE booking_id = ? ORDER BY performed_at DESC, id DESC LIMIT ?");
            $stmt->bindValue(1, $bookingId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['old_values'] = $r['old_values'] !== null ? json_decode($r['old_values'], true) : null;
                $r['new_values'] = $r['new_values'] !== null ? json_decode($r['new_values'], true) : null;
                $r['changed_fields'] = $r['changed_fields'] !== null ? json_decode($r['changed_fields'], true) : [];
            }
            return $rows;
        } catch (Throwable $e) {
            error_log('getBookingAuditLog failed: ' . $e->getMessage());
            return [];
        }
    }
}



