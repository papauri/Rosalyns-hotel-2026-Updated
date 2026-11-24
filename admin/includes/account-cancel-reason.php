<?php
/**
 * Why a conference enquiry, event booking or gym account / member was cancelled (owner 2026-11-24: "hard to keep track
 * of why something is cancelled; the timeline, history, logs don't show the whys" - same for conference, events, gym).
 *
 * The rule and the pick lists are shared with room bookings (includes/cancel-reason.php: rh_cancel_reason_check,
 * rh_cancel_reason_presets 'conference' / 'event' / 'gym'). The screens check the reason BEFORE anything is written
 * (their payment-snapshot sync writes too), so a refusal changes nothing. This file only records an accepted reason:
 *
 *   rh_account_cancel_actor(array $user): string             the staff name used in the note and the log
 *   rh_account_cancel_note(string $reason, string $actor)     "[Y-m-d H:i] Cancelled by <name>. Reason: <reason>"
 *   rh_account_cancel_append_note(PDO, $type, $id, $note)     adds that line to the record's own Notes (conference_inquiries /
 *                                                            event_inquiries / gym_inquiries), on a new line, never
 *                                                            replacing what is there (gym notes carry a [member:...]
 *                                                            marker). Same format the gym billing void already writes.
 *                                                            Call it inside the cancel transaction.
 *   rh_account_cancel_log($type, $ref, $id, $reason, $user)   one Admin activity row (System Logs: who, when, why).
 *                                                            Call it after the commit.
 *
 * Notes are staff-only: quotations are always built from their own quotation_notes and the cancellation emails do not
 * print notes, so the reason is not sent to the client.
 */

require_once __DIR__ . '/../../includes/cancel-reason.php';

if (!function_exists('rh_account_cancel_note')) {

    function rh_account_cancel_actor(array $user): string
    {
        $name = trim((string)($user['full_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($user['username'] ?? ''));
        }
        return $name !== '' ? $name : ('user #' . (int)($user['id'] ?? 0));
    }

    /** $reason must already have passed rh_cancel_reason_check(). */
    function rh_account_cancel_note(string $reason, string $actor): string
    {
        return '[' . date('Y-m-d H:i') . '] Cancelled by ' . $actor . '. Reason: ' . $reason;
    }

    /** The record tables whose Notes take the line (fixed list: the table name is never taken from input). */
    function rh_account_cancel_tables(): array
    {
        return ['conference' => 'conference_inquiries', 'event' => 'event_inquiries', 'gym' => 'gym_inquiries'];
    }

    function rh_account_cancel_append_note(PDO $pdo, string $type, int $id, string $note): void
    {
        $tables = rh_account_cancel_tables();
        if (!isset($tables[$type]) || $id <= 0 || trim($note) === '') {
            throw new InvalidArgumentException('Cancel note: unknown record.');
        }
        $pdo->prepare('UPDATE `' . $tables[$type] . "` SET notes = CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE '\n' END, ?) WHERE id = ?")
            ->execute([$note, $id]);
    }

    /** System Logs > Admin activity: "Cancelled conference enquiry CONF-1 (id 5). Reason: ...". Never breaks the cancel. */
    function rh_account_cancel_log(string $type, string $reference, int $id, string $reason, array $user): void
    {
        $labels = ['conference' => 'conference enquiry', 'event' => 'event booking', 'gym' => 'gym membership', 'gym_member' => 'gym member', 'gym_slot' => 'gym slot booking'];
        $label = $labels[$type] ?? $type;
        $details = 'Cancelled ' . $label . ' ' . ($reference !== '' ? $reference : '#' . $id) . ' (id ' . $id . ') by '
            . rh_account_cancel_actor($user) . '. Reason: ' . $reason;
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof PDO)) {
            return;
        }
        try {
            $pdo->prepare("INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())")
                ->execute([(int)($user['id'] ?? 0), substr((string)($user['username'] ?? ''), 0, 100), $type . '_cancelled',
                    mb_substr($details, 0, 1000), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        } catch (Throwable $e) {
            error_log('Cancel reason log failed: ' . $e->getMessage());
        }
    }
}
