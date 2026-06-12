<?php

/**
 * Finance schema helpers for mixed/legacy database structures.
 */

if (!function_exists('finance_table_columns')) {
    function finance_table_columns(PDO $pdo, string $table): array
    {
        static $cache = [];
        $table = finance_assert_identifier($table);

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $cols = [];
        foreach ($rows as $row) {
            $field = (string)($row['Field'] ?? '');
            if (finance_is_identifier($field)) {
                $cols[$field] = true;
            }
        }

        $cache[$table] = $cols;
        return $cols;
    }
}

if (!function_exists('finance_first_existing_column')) {
    function finance_first_existing_column(PDO $pdo, string $table, array $candidates, string $fallback): string
    {
        $cols = finance_table_columns($pdo, $table);
        foreach ($candidates as $candidate) {
            $candidate = finance_assert_identifier((string)$candidate);
            if (isset($cols[$candidate])) {
                return $candidate;
            }
        }

        return finance_assert_identifier($fallback);
    }
}

if (!function_exists('finance_is_identifier')) {
    function finance_is_identifier(string $identifier): bool
    {
        return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier);
    }
}

if (!function_exists('finance_assert_identifier')) {
    function finance_assert_identifier(string $identifier): string
    {
        if (!finance_is_identifier($identifier)) {
            throw new InvalidArgumentException('Unsafe finance schema identifier.');
        }

        return $identifier;
    }
}

if (!function_exists('finance_conference_fields')) {
    function finance_conference_fields(PDO $pdo): array
    {
        return [
            'reference' => finance_first_existing_column($pdo, 'conference_inquiries', ['enquiry_reference', 'inquiry_reference'], 'inquiry_reference'),
            'company' => finance_first_existing_column($pdo, 'conference_inquiries', ['organization_name', 'company_name'], 'company_name'),
            'contact_name' => finance_first_existing_column($pdo, 'conference_inquiries', ['contact_name', 'contact_person'], 'contact_person'),
            'email' => finance_first_existing_column($pdo, 'conference_inquiries', ['contact_email', 'email'], 'email'),
            'phone' => finance_first_existing_column($pdo, 'conference_inquiries', ['contact_phone', 'phone'], 'phone'),
            'start_date' => finance_first_existing_column($pdo, 'conference_inquiries', ['start_date', 'event_date'], 'event_date'),
            'end_date' => finance_first_existing_column($pdo, 'conference_inquiries', ['end_date', 'event_date'], 'event_date'),
            'expected_attendees' => finance_first_existing_column($pdo, 'conference_inquiries', ['expected_attendees', 'number_of_attendees'], 'number_of_attendees'),
        ];
    }
}

if (!function_exists('finance_payment_transaction_column')) {
    function finance_payment_transaction_column(PDO $pdo): string
    {
        return finance_first_existing_column(
            $pdo,
            'payments',
            ['transaction_reference', 'payment_reference_number', 'transaction_id'],
            'payment_reference_number'
        );
    }
}

