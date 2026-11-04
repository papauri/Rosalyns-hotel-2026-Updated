<?php

/**
 * Import - schema bootstrap, kill switch and the alias table (Phase 1b).
 *
 * Idempotent, additive-only DDL (same pattern as procurement-schema.php). Nothing here is in config/database.php.
 * Tables:
 *   import_jobs     one row per uploaded file: status, the analysis, the person's answers, the result.
 *   import_aliases  the vocabulary the engine reads (header words, unit words, section/total words ...), SEEDED once from
 *                   includes/import/default-aliases.php and afterwards read from here. Hotel-learned corrections
 *                   (a person said "Heinken" is the existing "Heineken") are rows with is_hotel_learned = 1.
 * (No import_rows table: row answers live in import_jobs.answers_json, keyed by a content key of the question. A row
 *  is re-derived from the stored file on every review, so there is nothing per row to keep in sync.)
 *
 * Settings (site_settings, group 'import'): data_import_enabled ('0' = OFF, ships OFF), data_import_max_mb ('10'),
 * data_import_retention_days ('7').
 */

require_once __DIR__ . '/procurement-schema.php';   // rh_table_exists / rh_column_exists helpers
require_once __DIR__ . '/../../includes/import/default-aliases.php';

if (!function_exists('rh_import_setting_defaults')) {

    function rh_import_setting_defaults(): array
    {
        return [
            'data_import_enabled'        => '0',   // the kill switch: OFF until the owner turns it on
            'data_import_max_mb'         => '10',  // largest upload accepted
            'data_import_retention_days' => '7',   // staged files are deleted after commit/cancel or this many days
        ];
    }

    /** One setting straight from site_settings (not the hour-long settings file cache). Default when missing/unreadable. */
    function rh_import_setting(string $key, ?PDO $db = null): string
    {
        $def = rh_import_setting_defaults()[$key] ?? '';
        $db = $db ?? ($GLOBALS['pdo'] ?? null);
        if (!$db instanceof PDO) {
            return $def;
        }
        try {
            $st = $db->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v === false ? $def : (string)$v;
        } catch (Throwable $e) {
            return $def;
        }
    }

    /**
     * The Import kill switch, FAIL-CLOSED: a missing setting, a missing table, an error or any value other than '1' is OFF.
     * (moduleEnabled() fails open, so it is never used for this.)
     */
    function rh_import_enabled(?PDO $db = null): bool
    {
        $db = $db ?? ($GLOBALS['pdo'] ?? null);
        if (!$db instanceof PDO) {
            return false;
        }
        try {
            $st = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'data_import_enabled' LIMIT 1");
            $st->execute();
            $v = $st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
        return $v !== false && trim((string)$v) === '1';
    }

    /**
     * May this person use Import for the stock target? Kill switch ON + data_import + stock_management (admin passes both
     * keys through hasPermission). Every entry point of the page / endpoints calls this on the server.
     */
    function rh_import_can_use(int $userId, ?PDO $db = null): bool
    {
        if ($userId <= 0 || !rh_import_enabled($db)) {
            return false;
        }
        if (!function_exists('hasPermission')) {
            return false;
        }
        return hasPermission($userId, 'data_import') && hasPermission($userId, 'stock_management');
    }

    /** Only the owner-level role may import a file again that was already imported (rule 7). Checked on the server. */
    function rh_import_can_reimport(int $userId, ?PDO $db = null): bool
    {
        $db = $db ?? ($GLOBALS['pdo'] ?? null);
        if ($userId <= 0 || !$db instanceof PDO) {
            return false;
        }
        try {
            $st = $db->prepare('SELECT role FROM admin_users WHERE id = ? AND is_active = 1');
            $st->execute([$userId]);
            return $st->fetchColumn() === 'admin';
        } catch (Throwable $e) {
            return false;
        }
    }

    // ---------------------------------------------------------------- aliases (seed once, then read from the DB)

    /** The seed vocabulary flattened to [kind, term, maps_to] rows (the shape stored in import_aliases). */
    function import_alias_seed_rows(): array
    {
        $d = import_default_aliases();
        $rows = [];
        foreach ($d['fields'] as $field => $phrases) {
            foreach ($phrases as $p) {
                $rows[] = ['field', (string)$p, (string)$field];
            }
        }
        foreach ($d['derived'] as $f) {
            $rows[] = ['derived', (string)$f, ''];
        }
        $kinds = ['units' => 'unit', 'section_words' => 'section_word', 'total_words' => 'total_word',
            'summary_phrases' => 'summary_phrase', 'skip_sheet_words' => 'skip_sheet_word', 'noise_tokens' => 'noise_token'];
        foreach ($kinds as $key => $kind) {
            foreach ($d[$key] as $w) {
                $rows[] = [$kind, (string)$w, ''];
            }
        }
        foreach ((array)($d['unit_synonyms'] ?? []) as $spelling => $unit) {
            $rows[] = ['unit_synonym', (string)$spelling, (string)$unit];
        }
        return $rows;
    }

    function import_alias_hash(string $kind, string $term, string $mapsTo): string
    {
        return sha1($kind . "\x1f" . $term . "\x1f" . $mapsTo);
    }

    /**
     * Seed import_aliases KIND BY KIND: a kind that has no seeded row yet gets its seed rows (so a new kind, like unit_synonym, reaches hotels that
     * were seeded before it existed, and a kind a hotel has edited is never refilled). Safe to call again. Returns the number of rows added.
     */
    function import_seed_aliases(PDO $pdo): int
    {
        $have = array_flip($pdo->query('SELECT DISTINCT kind FROM import_aliases WHERE is_hotel_learned = 0')->fetchAll(PDO::FETCH_COLUMN));
        $ins = $pdo->prepare('INSERT IGNORE INTO import_aliases (kind, term, maps_to, alias_hash, is_hotel_learned, created_by) VALUES (?, ?, ?, ?, 0, NULL)');
        $n = 0;
        foreach (import_alias_seed_rows() as [$kind, $term, $maps]) {
            if (isset($have[$kind])) {
                continue;
            }
            $ins->execute([$kind, $term, $maps, import_alias_hash($kind, $term, $maps)]);
            $n += $ins->rowCount();
        }
        return $n;
    }

    /**
     * The alias set the engine takes as its $aliases parameter, read from import_aliases (seed + hotel-learned header
     * words). Falls back to the seed if the table is missing or empty. `name_correction` rows are NOT part of it
     * (see import_load_name_corrections()).
     */
    function import_load_aliases(?PDO $pdo = null): array
    {
        $out = import_default_aliases();
        $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
        if (!$pdo instanceof PDO) {
            return $out;
        }
        try {
            $rows = $pdo->query("SELECT kind, term, maps_to FROM import_aliases WHERE kind <> 'name_correction' AND kind NOT LIKE 'learned\_%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $out;
        }
        if (!$rows) {
            return $out;
        }
        $listKey = ['unit' => 'units', 'section_word' => 'section_words', 'total_word' => 'total_words',
            'summary_phrase' => 'summary_phrases', 'skip_sheet_word' => 'skip_sheet_words', 'noise_token' => 'noise_tokens'];
        $res = ['version' => $out['version'], 'fields' => [], 'derived' => [], 'units' => [], 'section_words' => [],
            'total_words' => [], 'summary_phrases' => [], 'skip_sheet_words' => [], 'noise_tokens' => [], 'unit_synonyms' => []];
        foreach ($rows as $r) {
            $k = $r['kind'];
            if ($k === 'unit_synonym') {
                $res['unit_synonyms'][mb_strtolower((string)$r['term'])] = (string)$r['maps_to'];
            } elseif ($k === 'field') {
                $res['fields'][$r['maps_to']][] = $r['term'];
            } elseif ($k === 'derived') {
                $res['derived'][] = $r['term'];
            } elseif (isset($listKey[$k])) {
                $res[$listKey[$k]][] = $r['term'];
            }
        }
        if (!$res['unit_synonyms']) {
            $res['unit_synonyms'] = $out['unit_synonyms'];
        }
        return $res;
    }

    /** Learned name corrections: normalised typed name => the existing ingredient name a person chose for it. */
    function import_load_name_corrections(PDO $pdo): array
    {
        $out = [];
        try {
            foreach ($pdo->query("SELECT term, maps_to FROM import_aliases WHERE kind = 'name_correction' AND is_hotel_learned = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['term']] = $r['maps_to'];
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    /**
     * What this hotel decided in earlier imports (written only when an import was committed): kind learned_unit_item (term = normalised item name,
     * maps_to = the unit), learned_unit_cat (term = normalised category), learned_category (term = normalised item name, maps_to = category).
     * Later rows win. Each row keeps who (created_by) and when (created_at). Returns ['unit_item'=>[term=>['v','by','at']], 'unit_cat'=>[...], 'category'=>[...]].
     */
    function import_load_learned(PDO $pdo): array
    {
        $out = ['unit_item' => [], 'unit_cat' => [], 'category' => []];
        $map = ['learned_unit_item' => 'unit_item', 'learned_unit_cat' => 'unit_cat', 'learned_category' => 'category'];
        try {
            $st = $pdo->query("SELECT kind, term, maps_to, created_by, created_at FROM import_aliases WHERE is_hotel_learned = 1 AND kind IN ('learned_unit_item','learned_unit_cat','learned_category') ORDER BY id LIMIT 20000");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$map[$r['kind']]][$r['term']] = ['v' => $r['maps_to'], 'by' => $r['created_by'] !== null ? (int)$r['created_by'] : null, 'at' => $r['created_at']];
            }
        } catch (Throwable $e) {
            return $out;
        }
        return $out;
    }

    /** Save learned rows [[kind, term, maps_to], ...] (idempotent). Called inside the commit transaction only. Returns the number written. */
    function import_save_learned(PDO $pdo, array $rows, ?int $userId): int
    {
        $ins = $pdo->prepare('INSERT IGNORE INTO import_aliases (kind, term, maps_to, alias_hash, is_hotel_learned, created_by) VALUES (?, ?, ?, ?, 1, ?)');
        $n = 0;
        foreach ($rows as [$kind, $term, $maps]) {
            $term = mb_substr((string)$term, 0, 191);
            $maps = mb_substr((string)$maps, 0, 191);
            if ($term === '' || $maps === '' || !in_array($kind, ['learned_unit_item', 'learned_unit_cat', 'learned_category'], true)) {
                continue;
            }
            $ins->execute([$kind, $term, $maps, import_alias_hash($kind, $term, $maps), $userId]);
            $n += $ins->rowCount();
        }
        return $n;
    }

    /** Save (or refresh) one learned correction. $typedNorm is import_norm_text($typed). */
    function import_save_name_correction(PDO $pdo, string $typedNorm, string $existingName, ?int $userId): void
    {
        if ($typedNorm === '' || $existingName === '') {
            return;
        }
        $pdo->prepare('INSERT IGNORE INTO import_aliases (kind, term, maps_to, alias_hash, is_hotel_learned, created_by) VALUES (?, ?, ?, ?, 1, ?)')
            ->execute(['name_correction', mb_substr($typedNorm, 0, 191), mb_substr($existingName, 0, 191), import_alias_hash('name_correction', mb_substr($typedNorm, 0, 191), mb_substr($existingName, 0, 191)), $userId]);
    }
}

if (!function_exists('ensureImportSchema')) {
    /**
     * Create the Import tables and seed the settings and aliases. DDL commits implicitly, so call it BEFORE any
     * transaction (the page does, at the top). Returns false when something failed (Import then stays unavailable).
     */
    function ensureImportSchema(PDO $pdo): bool
    {
        static $done = null;
        if ($done !== null) {
            return $done;
        }
        try {
            if (!rh_table_exists($pdo, 'import_jobs')) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS import_jobs (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        target VARCHAR(20) NOT NULL DEFAULT 'stock',
                        original_name VARCHAR(255) NOT NULL,
                        stored_path VARCHAR(255) DEFAULT NULL,
                        sha256 CHAR(64) NOT NULL,
                        size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
                        status ENUM('uploaded','analysed','reviewing','confirmed','committed','cancelled','failed') NOT NULL DEFAULT 'uploaded',
                        analysis_json LONGTEXT DEFAULT NULL,
                        answers_json LONGTEXT DEFAULT NULL,
                        result_json LONGTEXT DEFAULT NULL,
                        error_text VARCHAR(500) DEFAULT NULL,
                        created_by INT UNSIGNED DEFAULT NULL,
                        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        committed_by INT UNSIGNED DEFAULT NULL,
                        committed_at TIMESTAMP NULL DEFAULT NULL,
                        expires_at DATETIME DEFAULT NULL,
                        KEY idx_import_sha (sha256, status),
                        KEY idx_import_status (status, expires_at),
                        KEY idx_import_user (created_by, created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            if (!rh_table_exists($pdo, 'import_aliases')) {
                // term / maps_to are binary-collated: "Mangina" and "Mangína", "L" and "l" are different rows. Uniqueness is on a
                // hash, so the key stays short on every MySQL/MariaDB version.
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS import_aliases (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        kind VARCHAR(30) NOT NULL,
                        term VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                        maps_to VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
                        alias_hash CHAR(40) NOT NULL,
                        is_hotel_learned TINYINT(1) NOT NULL DEFAULT 0,
                        created_by INT UNSIGNED DEFAULT NULL,
                        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_import_alias (alias_hash),
                        KEY idx_import_alias_kind (kind)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            // Settings: seeded once, never overwritten (an owner's choice stays).
            $ins = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'import') ON DUPLICATE KEY UPDATE setting_key = setting_key");
            foreach (rh_import_setting_defaults() as $k => $v) {
                $ins->execute([$k, $v]);
            }
            import_seed_aliases($pdo);
            return $done = true;
        } catch (Throwable $e) {
            error_log('ensureImportSchema failed: ' . $e->getMessage());
            return $done = false;
        }
    }
}
