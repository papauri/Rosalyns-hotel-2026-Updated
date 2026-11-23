<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Shared fixtures for the booking-clash suites (room_night_clash_test.php, block_clash_test.php,
 * maintenance_clash_test.php). Not a test on its own.
 *
 * clash_test_pdo(): a connection that can NEVER commit: the outer beginTransaction() is real, an inner
 * beginTransaction()/commit()/rollBack() (the code under test) becomes SAVEPOINT / RELEASE / ROLLBACK TO, and
 * finalRollback() throws everything away. Replace the global $pdo with it before calling the code under test.
 */

if (!class_exists('ClashTxPdo')) {
    class ClashTxPdo extends PDO
    {
        private $sp = 0;
        public function beginTransaction(): bool
        {
            if (!$this->inTransaction()) {
                return parent::beginTransaction();
            }
            $this->sp++;
            parent::exec('SAVEPOINT clash_sp' . $this->sp);
            return true;
        }
        public function commit(): bool
        {
            if ($this->sp > 0) {
                parent::exec('RELEASE SAVEPOINT clash_sp' . $this->sp);
                $this->sp--;
            }
            return true; // the outer transaction is never committed
        }
        #[\ReturnTypeWillChange]
        public function rollBack()
        {
            if ($this->sp > 0) {
                parent::exec('ROLLBACK TO SAVEPOINT clash_sp' . $this->sp);
                parent::exec('RELEASE SAVEPOINT clash_sp' . $this->sp);
                $this->sp--;
            }
            return true;
        }
        public function finalRollback(): void
        {
            if ($this->inTransaction()) {
                parent::rollBack();
            }
        }
    }
}

function clash_test_pdo(): ClashTxPdo
{
    $p = new ClashTxPdo('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $p->exec("SET time_zone = '" . (new DateTime('now'))->format('P') . "'");
    return $p;
}

/**
 * Fixture (inside the caller's transaction): type T1 with rooms r1 r2 r3, type T2 with room r4, joined type T3 whose
 * only pair is p1 + p2 (both rooms of T3: a joined-only pair, ONE room to staff).
 */
function clash_test_fixtures(PDO $pdo, string $tag): array
{
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $tag));
    $type = static function (string $n) use ($pdo, $tag, $slug): int {
        $pdo->prepare("INSERT INTO rooms (name, slug, description, price_per_night, max_guests, total_rooms, rooms_available, display_order, is_active) VALUES (?, ?, 'ZZ clash test', 100000, 2, 3, 3, 0, 1)")
            ->execute([$tag . ' ' . $n, $slug . '-' . strtolower($n)]);
        return (int)$pdo->lastInsertId();
    };
    $room = static function (int $t, string $num) use ($pdo): int {
        $pdo->prepare("INSERT INTO individual_rooms (room_type_id, room_number, room_name, status, is_active) VALUES (?, ?, 'ZZ clash test', 'available', 1)")->execute([$t, $num]);
        return (int)$pdo->lastInsertId();
    };
    $sfx = strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
    $fx = ['T1' => $type('A'), 'T2' => $type('B'), 'T3' => $type('Pair')];
    $fx['r1'] = $room($fx['T1'], 'ZC1' . $sfx);
    $fx['r2'] = $room($fx['T1'], 'ZC2' . $sfx);
    $fx['r3'] = $room($fx['T1'], 'ZC3' . $sfx);
    $fx['r4'] = $room($fx['T2'], 'ZC4' . $sfx);
    $fx['p1'] = $room($fx['T3'], 'ZC5' . $sfx);
    $fx['p2'] = $room($fx['T3'], 'ZC6' . $sfx);
    $pdo->prepare("INSERT INTO room_combinations (combined_name, combined_room_type_id, room_a_id, room_b_id, is_active) VALUES (?, ?, ?, ?, 1)")
        ->execute([$tag . ' pair', $fx['T3'], $fx['p1'], $fx['p2']]);
    return $fx;
}

/** A booking row (made-up guest). $room null = no room yet. */
function clash_test_booking(PDO $pdo, string $ref, int $type, ?int $room, string $ci, string $co, string $status = 'confirmed', ?string $tentativeExpires = null): int
{
    $n = max(1, (int)round((strtotime($co) - strtotime($ci)) / 86400));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, individual_room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights, number_of_guests, adult_guests, child_guests, total_amount, total_with_vat, amount_paid, amount_due, status, tentative_expires_at)
        VALUES (?, ?, ?, ?, 'zz.clash@example.invalid', '000', ?, ?, ?, 1, 1, 0, 1000, 1000, 0, 1000, ?, ?)")
        ->execute([$ref, $type, $room, 'ZZ Guest ' . $ref, $ci, $co, $n, $status, $tentativeExpires]);
    return (int)$pdo->lastInsertId();
}

/** Rows a suite could have left behind (from a SECOND connection): must be 0. */
function clash_test_left_behind(): int
{
    $p = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    return (int)$p->query("SELECT (SELECT COUNT(*) FROM rooms WHERE description = 'ZZ clash test') + (SELECT COUNT(*) FROM individual_rooms WHERE room_name = 'ZZ clash test')
        + (SELECT COUNT(*) FROM bookings WHERE guest_email = 'zz.clash@example.invalid')")->fetchColumn();
}
