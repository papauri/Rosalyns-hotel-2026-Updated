<?php
/**
 * Stale-edit guard (.claude/CONCURRENCY_PLAN.md task 1): refuse a Save when the record changed since the form was opened.
 *
 * Render:  read the row with a PLAIN read of the base table (never a JOIN row), echo rh_stale_field(rh_stale_hash($row, $cols)).
 * Save:    $pdo->beginTransaction();
 *          $v = rh_stale_verify($pdo, 'food_menu', $id, $cols, rh_stale_posted());
 *          if (!$v['ok']) { $pdo->rollBack(); show $v['error']; }   // the CALLER rolls back: nothing may be written
 *          ... UPDATE ... ; $pdo->commit();
 * The same $cols list is used at render and at save. A form re-shown after an error carries the POSTED hash forward.
 * Table and column names are code constants, never user input; they are still checked here.
 */

if (!function_exists('rh_stale_tables')) {
    /** Tables the guard may read (allow-list). */
    function rh_stale_tables(): array
    {
        return ['bookings', 'food_menu', 'drink_menu', 'stock_ingredients', 'rooms', 'individual_rooms'];
    }
}

if (!function_exists('rh_stale_check_cols')) {
    /** Throws unless $cols is a non-empty list of distinct plain column names. */
    function rh_stale_check_cols(array $cols): void
    {
        if ($cols === [] || array_values($cols) !== $cols) {
            throw new InvalidArgumentException('Stale-edit: the column list must be a non-empty list.');
        }
        $seen = [];
        foreach ($cols as $c) {
            if (!is_string($c) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $c)) {
                throw new InvalidArgumentException('Stale-edit: invalid column name.');
            }
            if (isset($seen[$c])) {
                throw new InvalidArgumentException('Stale-edit: duplicate column ' . $c . '.');
            }
            $seen[$c] = true;
        }
    }
}

if (!function_exists('rh_stale_hash')) {
    /**
     * md5 of the given columns of $row, in $cols order. Each value is compared as a string (so int 5 and '5' match whichever
     * PDO read path produced them); NULL stays distinct from ''. A column missing from $row throws (a typo must never hash as NULL).
     */
    function rh_stale_hash(array $row, array $cols): string
    {
        rh_stale_check_cols($cols);
        $list = [];
        foreach ($cols as $c) {
            if (!array_key_exists($c, $row)) {
                throw new InvalidArgumentException('Stale-edit: column ' . $c . ' is not in the row.');
            }
            $list[] = $row[$c] === null ? null : (string)$row[$c];
        }
        return md5(serialize($list));
    }
}

if (!function_exists('rh_stale_field')) {
    /** The hidden form input carrying the hash. */
    function rh_stale_field(string $hash): string
    {
        return '<input type="hidden" name="_row_hash" value="' . htmlspecialchars($hash, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('rh_stale_posted')) {
    /** The posted hash, or '' when it is missing or not a 32-character md5 (which rh_stale_verify refuses). */
    function rh_stale_posted(): string
    {
        $v = $_POST['_row_hash'] ?? '';
        return is_string($v) && preg_match('/^[a-f0-9]{32}$/', $v) ? $v : '';
    }
}

if (!function_exists('rh_stale_verify')) {
    /**
     * Inside the caller's transaction: lock the row (FOR UPDATE), recompute the hash and compare with $postedHash.
     * Returns ['ok' => bool, 'row' => locked row|null, 'error' => string]. Never writes and never rolls back: on !ok the
     * caller rolls back. Throws when called outside a transaction or with a table/column/id that is not allowed.
     */
    function rh_stale_verify(PDO $pdo, string $table, int $id, array $cols, string $postedHash): array
    {
        if (!$pdo->inTransaction()) {
            throw new LogicException('Stale-edit: rh_stale_verify must run inside a transaction.');
        }
        if (!in_array($table, rh_stale_tables(), true)) {
            throw new InvalidArgumentException('Stale-edit: table not allowed.');
        }
        rh_stale_check_cols($cols);
        if ($id <= 0) {
            throw new InvalidArgumentException('Stale-edit: invalid id.');
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $postedHash)) {
            return ['ok' => false, 'row' => null, 'error' => 'This page was opened before a change to how saving works. Reload it and try again.'];
        }
        $sql = 'SELECT `' . implode('`, `', $cols) . '` FROM `' . $table . '` WHERE id = ? FOR UPDATE';
        $st = $pdo->prepare($sql);
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $st->closeCursor();
        if (!$row) {
            return ['ok' => false, 'row' => null, 'error' => 'This record no longer exists, so nothing was saved. Reload the page.'];
        }
        if (!hash_equals(rh_stale_hash($row, $cols), $postedHash)) {
            return ['ok' => false, 'row' => $row, 'error' => 'This record was changed by someone else since you opened it, so nothing was saved. Reload the page to see the new details, then make your change again.'];
        }
        return ['ok' => true, 'row' => $row, 'error' => ''];
    }
}
