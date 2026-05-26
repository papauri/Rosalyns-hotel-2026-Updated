<?php
/**
 * includes/idempotency.php — Generic request-level dedup for write endpoints.
 *
 * Pattern of use (at the top of any POST handler that creates a record):
 *
 *   require_once __DIR__ . '/../includes/idempotency.php';
 *
 *   // 1) Existing-row dedup (preferred — uses the entity's own client_uuid column)
 *   if ($existing = idem_find_existing_booking($pdo, $_POST['client_uuid'] ?? null)) {
 *       // Return success referencing the existing booking — do NOT insert again.
 *   }
 *
 *   // 2) Generic dedup (response cache for endpoints whose entity has no
 *   //    dedicated client_uuid column).
 *   $idem = idem_begin($pdo, '/api/foo.php', $_POST['client_uuid'] ?? null);
 *   if ($idem['cached']) { echo $idem['body']; http_response_code($idem['status']); exit; }
 *   ...do work...
 *   idem_finish($pdo, $idem, 200, $responseBody, ['entity_type'=>'foo','entity_id'=>$id]);
 *
 * Notes:
 * - All functions are no-ops when client_uuid is missing or invalid (falls through to normal flow).
 * - The unique indexes on bookings.client_uuid and payments.client_uuid are the
 *   ULTIMATE guarantee — even if this helper is bypassed, the DB rejects duplicates.
 */

if (!function_exists('idem_normalize_uuid')) {
    /** @param mixed $raw */
    function idem_normalize_uuid($raw): ?string {
        if (!is_string($raw)) return null;
        $s = trim($raw);
        if (strlen($s) < 8 || strlen($s) > 64) return null;
        // Permit UUIDs, ULIDs, and the offline-queue.js fallback "cli-..." form.
        if (!preg_match('/^[A-Za-z0-9_\-:]+$/', $s)) return null;
        return $s;
    }
}

if (!function_exists('idem_find_existing_booking')) {
    /**
     * If a booking with this client_uuid already exists, return it.
     * Otherwise null. Used by booking creation endpoints to short-circuit duplicates.
     * @param mixed $clientUuid
     */
    function idem_find_existing_booking(PDO $pdo, $clientUuid): ?array {
        $u = idem_normalize_uuid($clientUuid);
        if (!$u) return null;
        try {
            $st = $pdo->prepare("SELECT id, booking_reference, status, total_amount, room_id,
                                        check_in_date, check_out_date, guest_name, guest_email
                                 FROM bookings WHERE client_uuid = ? LIMIT 1");
            $st->execute([$u]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('[idem] booking lookup failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('idem_find_existing_payment')) {
    /** @param mixed $clientUuid */
    function idem_find_existing_payment(PDO $pdo, $clientUuid): ?array {
        $u = idem_normalize_uuid($clientUuid);
        if (!$u) return null;
        try {
            $st = $pdo->prepare("SELECT id, payment_reference, payment_amount, payment_status,
                                        booking_id, booking_reference, payment_method
                                 FROM payments WHERE client_uuid = ? LIMIT 1");
            $st->execute([$u]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('[idem] payment lookup failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('idem_begin')) {
    /**
     * Generic per-endpoint idempotency check. Returns:
     *   ['cached'=>true,  'status'=>int, 'body'=>string]   when a prior response exists
     *   ['cached'=>false, 'uuid'=>string|null, 'endpoint'=>string]  to be passed to idem_finish()
     * @param mixed $clientUuid
     */
    function idem_begin(PDO $pdo, string $endpoint, $clientUuid): array {
        $u = idem_normalize_uuid($clientUuid);
        if (!$u) return ['cached' => false, 'uuid' => null, 'endpoint' => $endpoint];
        try {
            $st = $pdo->prepare("SELECT response_status, response_body
                                 FROM idempotency_keys WHERE client_uuid = ? AND endpoint = ? LIMIT 1");
            $st->execute([$u, substr($endpoint, 0, 255)]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                return [
                    'cached' => true,
                    'status' => (int)$row['response_status'],
                    'body'   => (string)$row['response_body'],
                ];
            }
        } catch (Throwable $e) {
            error_log('[idem] begin failed: ' . $e->getMessage());
        }
        return ['cached' => false, 'uuid' => $u, 'endpoint' => substr($endpoint, 0, 255)];
    }
}

if (!function_exists('idem_finish')) {
    /**
     * Persist the response body so a future replay of the same client_uuid
     * to the same endpoint returns the same response without re-doing work.
     * Silently succeeds if there's no uuid (passthrough).
     */
    function idem_finish(PDO $pdo, array $idem, int $status, string $body, array $entity = []): void {
        if (empty($idem['uuid'])) return;
        try {
            $st = $pdo->prepare("INSERT INTO idempotency_keys
                (client_uuid, endpoint, response_status, response_body,
                 entity_type, entity_id, entity_reference, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
                ON DUPLICATE KEY UPDATE
                    response_status = VALUES(response_status),
                    response_body   = VALUES(response_body),
                    entity_type     = VALUES(entity_type),
                    entity_id       = VALUES(entity_id),
                    entity_reference = VALUES(entity_reference)");
            $st->execute([
                $idem['uuid'],
                $idem['endpoint'],
                $status,
                mb_substr($body, 0, 16777000), // medium-text safe cap
                isset($entity['entity_type']) ? mb_substr((string)$entity['entity_type'], 0, 48) : null,
                isset($entity['entity_id']) ? (int)$entity['entity_id'] : null,
                isset($entity['entity_reference']) ? mb_substr((string)$entity['entity_reference'], 0, 64) : null,
            ]);
        } catch (Throwable $e) {
            error_log('[idem] finish failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('idem_purge_expired')) {
    /**
     * Run from cron weekly. Deletes idempotency rows older than 14 days.
     */
    function idem_purge_expired(PDO $pdo): int {
        try {
            $st = $pdo->prepare("DELETE FROM idempotency_keys WHERE created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)");
            $st->execute();
            return $st->rowCount();
        } catch (Throwable $e) {
            error_log('[idem] purge failed: ' . $e->getMessage());
            return 0;
        }
    }
}
