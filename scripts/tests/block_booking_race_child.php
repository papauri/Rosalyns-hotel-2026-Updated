<?php
/**
 * Child process for scripts/tests/block_booking_race.php: saves ONE block through the real block engine
 * (includes/block-save.php rh_block_save) with a normal (committing) connection, starting at the shared start time so
 * it races a booking made at the same moment. Refuses any room whose number does not start with "BR-" (the parent's
 * throwaway rooms). Usage: php block_booking_race_child.php <base64 json {room_id, night, start_at, reason}>
 * Prints one line: @@RESULT@@{ok, saved, message}.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
$root = dirname(__DIR__, 2);
chdir($root);
$cfg = json_decode(base64_decode((string)($argv[1] ?? '')), true);
require $root . '/config/database.php';
require_once $root . '/includes/block-save.php';
$rid = (int)($cfg['room_id'] ?? 0);
$st = $pdo->prepare('SELECT room_number, room_type_id FROM individual_rooms WHERE id = ?');
$st->execute([$rid]);
$room = $st->fetch(PDO::FETCH_ASSOC);
if (!$room || strpos((string)$room['room_number'], 'BR-') !== 0) {
    fwrite(STDERR, "refusing to run: not a BR- test room\n");
    exit(2);
}
$target = ['scope' => 'room', 'id' => $rid, 'room_ids' => [$rid], 'type_id' => (int)$room['room_type_id'], 'label' => 'Room ' . $room['room_number'], 'dates' => [(string)$cfg['night']]];
$startAt = (float)($cfg['start_at'] ?? 0);
while (microtime(true) < $startAt) { usleep(100); }
$res = rh_block_save($pdo, [$target], 'manual', (string)($cfg['reason'] ?? 'BLKRACE'), ['user_id' => 0, 'user_name' => 'race test']);
fwrite(STDOUT, "\n@@RESULT@@" . json_encode(['ok' => $res['ok'], 'saved' => $res['saved'], 'message' => rh_block_save_message($res)]) . "\n");
