<?php

/**
 * Shared salt for the secrets stored encrypted in site_settings (Facebook, WhatsApp, review-source keys).
 *
 * The current salt is APP_ENCRYPTION_SALT from .env. Rows saved before it was set on a host were encrypted
 * with the old built-in literal, so that literal stays in the list of salts to TRY WHEN READING: nothing a
 * hotel already saved is lost, and the next save re-encrypts it with the real salt.
 * When APP_ENCRYPTION_SALT is missing the old behaviour is kept (so a live site does not break) and a
 * warning is logged once per request.
 */

if (!defined('RH_LEGACY_SECRET_SALT')) {
    define('RH_LEGACY_SECRET_SALT', 'DEFAULT_SALT_CHANGE_IN_ENV');
}

if (!function_exists('rhConfiguredSecretSalt')) {

    /** The APP_ENCRYPTION_SALT from the environment, or '' when missing/empty/still the placeholder. */
    function rhConfiguredSecretSalt(): string
    {
        $salt = $_ENV['APP_ENCRYPTION_SALT'] ?? getenv('APP_ENCRYPTION_SALT');
        $salt = is_string($salt) ? $salt : '';
        return ($salt === RH_LEGACY_SECRET_SALT) ? '' : $salt;
    }

    /** The salt to ENCRYPT with: the real one, or (with a one-time warning) the legacy literal when none is set. */
    function rhSecretSalt(): string
    {
        $salt = rhConfiguredSecretSalt();
        if ($salt !== '') {
            return $salt;
        }
        static $warned = false;
        if (!$warned) {
            $warned = true;
            error_log('[security] APP_ENCRYPTION_SALT is not set in .env - stored integration secrets use the built-in default salt. Set it on this host.');
        }
        return RH_LEGACY_SECRET_SALT;
    }

    /** Salts to TRY when decrypting, best first: the real one, then the legacy literal. */
    function rhSecretSaltCandidates(): array
    {
        $out = [];
        $real = rhConfiguredSecretSalt();
        if ($real !== '') {
            $out[] = $real;
        }
        $out[] = RH_LEGACY_SECRET_SALT;
        return $out;
    }

    /**
     * AES-256-CBC decrypt $cipher (raw, iv excluded) with $iv, trying each salt candidate in turn.
     * $keyFor receives a salt and returns the 32-byte key for that salt. A wrong key sometimes passes
     * the padding check and yields junk, so only printable-ASCII results (every secret we store is) count.
     * @return string the plain value, or '' when no candidate works
     */
    function rhDecryptWithSalts(string $cipher, string $iv, callable $keyFor): string
    {
        foreach (rhSecretSaltCandidates() as $salt) {
            $plain = openssl_decrypt($cipher, 'AES-256-CBC', $keyFor($salt), OPENSSL_RAW_DATA, $iv);
            if (is_string($plain) && $plain !== '' && preg_match('/^[\x20-\x7E]+$/', $plain)) {
                return $plain;
            }
        }
        return '';
    }
}
