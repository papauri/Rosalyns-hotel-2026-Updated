<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Joined-room live quote (includes/joined-quote.php, used by check-availability.php). NO DATABASE: pure helpers fed
 * getAvailableRoomCombinations()-shaped rows. Story (F7): a party needs two joined pairs; pair one costs K500,000 a
 * night, pair two K650,000. The saved booking charges 500,000 + 650,000; the quote used to show 500,000 twice.
 * Usage (repo root): php scripts/tests/joined_quote.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/includes/joined-quote.php';
require_once $root . '/includes/guest-allocation.php';
check(!function_exists('getSetting') && !isset($GLOBALS['pdo']), 'the pure helpers load without the database or settings');

$pair = static function (int $id, int $a, int $b, $override): array {
    return ['id' => $id, 'room_a_id' => $a, 'room_b_id' => $b, 'price_override' => $override, 'max_guests_combined' => 4];
};
$ids = static function (array $queue): array { return array_map(static function ($c) { return (int)$c['id']; }, $queue); };

// The queue booking.php builds (:385-:396), copied here so the two can never drift apart unnoticed.
$bookingPhpQueue = static function (array $available, int $roomsNeeded): array {
    $combinationQueue = [];
    $claimedPhysicalRooms = [];
    foreach ($available as $candidateCombination) {
        if (count($combinationQueue) >= $roomsNeeded) { break; }
        $candidateRoomIds = [(int)$candidateCombination['room_a_id'], (int)$candidateCombination['room_b_id']];
        if (array_intersect($candidateRoomIds, $claimedPhysicalRooms)) { continue; }
        $claimedPhysicalRooms = array_merge($claimedPhysicalRooms, $candidateRoomIds);
        $combinationQueue[] = $candidateCombination;
    }
    return $combinationQueue;
};

// 1. Two separate pairs, different prices: each room is priced off its own pair.
$available = [$pair(1, 101, 102, '500000'), $pair(2, 103, 104, '650000')];
$queue = rh_joined_quote_queue($available);
check($ids($queue) === [1, 2], 'two separate pairs are both queued, in list order');
check(rh_joined_quote_rate($queue[0], 400000.0) === 500000.0, 'room 1 uses pair 1 price');
check(rh_joined_quote_rate($queue[1], 400000.0) === 650000.0, 'room 2 uses pair 2 price (was pair 1 price before F7)');

// 2. Overlapping pairs: 101+102 and 102+103 share room 102, so the second is skipped.
$available = [$pair(1, 101, 102, '500000'), $pair(2, 102, 103, '900000'), $pair(3, 104, 105, '650000')];
$queue = rh_joined_quote_queue($available);
check($ids($queue) === [1, 3], 'a pair sharing a physical room with one already taken is skipped', json_encode($ids($queue)));
check(rh_joined_quote_rate($queue[1], 400000.0) === 650000.0, 'room 2 is priced off the pair it would really get, not the skipped one');

// 3. Same answer as booking.php for 1, 2 and 3 rooms needed.
foreach ([1, 2, 3] as $n) {
    $mine = array_slice(rh_joined_quote_queue($available), 0, $n);
    check($ids($mine) === $ids($bookingPhpQueue($available, $n)), "queue matches booking.php when the party needs {$n} room(s)");
    check($ids(rh_joined_quote_queue($available, $n)) === $ids($bookingPhpQueue($available, $n)), "limit {$n} gives the same pairs");
}

// 4. A pair with no price of its own falls back to the room type's price.
check(rh_joined_quote_rate($pair(9, 1, 2, null), 400000.0) === 400000.0, 'no override (null) = room type price');
check(rh_joined_quote_rate($pair(9, 1, 2, ''), 400000.0) === 400000.0, 'no override (empty) = room type price');
check(rh_joined_quote_rate($pair(9, 1, 2, '0'), 400000.0) === 0.0, "override '0' is kept as booking.php keeps it");

// 5. The room row handed to pricing: every occupancy tier at the pair's rate, nothing else touched.
$type = ['id' => 7, 'name' => 'Family Suite', 'price_per_night' => 500000.0, 'price_single_occupancy' => 500000.0,
    'price_double_occupancy' => 500000.0, 'price_triple_occupancy' => 500000.0, 'max_guests' => 4];
$r2 = rh_joined_quote_room($type, $pair(2, 103, 104, '650000'), 400000.0);
foreach (['single', 'double', 'triple'] as $tier) {
    check(ga_price_for_occupancy($r2, $tier) === 650000.0, "pair price applies at {$tier} occupancy");
}
check($r2['id'] === 7 && $r2['name'] === 'Family Suite' && $r2['max_guests'] === 4, 'other room fields are untouched');
check($type['price_per_night'] === 500000.0, 'the headline room row is not changed');
$r3 = rh_joined_quote_room($type, $pair(3, 105, 106, null), 400000.0);
check(ga_price_for_occupancy($r3, 'double') === 400000.0, 'a pair without its own price uses the type price, not the headline pair price');

// 6. The story: 3 nights, two pairs -> (500,000 + 650,000) x 3.
$queue = rh_joined_quote_queue([$pair(1, 101, 102, '500000'), $pair(2, 103, 104, '650000')]);
$total = 0.0;
foreach ($queue as $c) { $total += ga_price_for_occupancy(rh_joined_quote_room($type, $c, 400000.0), 'double') * 3; }
check(abs($total - 3450000.0) < 0.001, 'quote = 500,000 x 3 + 650,000 x 3 = 3,450,000', (string)$total);

// 7. Edge cases.
check(rh_joined_quote_queue([]) === [], 'no pairs free = empty queue');
check(count(rh_joined_quote_queue([$pair(1, 101, 102, null), $pair(2, 101, 102, null)])) === 1, 'the same two rooms listed twice count once');

// 8. check-availability.php is wired to the helpers.
$src = (string)file_get_contents($root . '/check-availability.php');
check(strpos($src, "includes/joined-quote.php") !== false, 'check-availability.php loads the helper');
check(strpos($src, 'rh_joined_quote_queue($combinations)') !== false, 'check-availability.php builds the queue');
check(strpos($src, 'rh_joined_quote_room($room, $joinedQueue[$index], $joinedTypeRate)') !== false, 'each room is priced off its own pair');
check(strpos($src, '$childGuests, $joinedQueue, $joinedTypeRate)') !== false, 'the queue is passed into the split pricing');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
