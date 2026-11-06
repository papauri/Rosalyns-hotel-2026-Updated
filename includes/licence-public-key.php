<?php

/**
 * The licence panel's PUBLIC key (PEM, ECDSA P-256), as a CODE constant - never a setting, never the database.
 *
 * Empty = licensing is OFF: nothing in the system checks, banners or restricts anything (this is how a hotel that is
 * not on a licence runs). To switch licensing on for a build, paste the panel's public key between the quotes below
 * AND set LICENCE_TENANT (and LICENCE_URL, LICENCE_KEY) in that host's .env. A public key is safe to commit.
 */
if (!defined('RH_LICENCE_PUBLIC_KEY')) {
    // MSP licence panel signing key, created 2026-11-07 on the panel's Signing key page.
    define('RH_LICENCE_PUBLIC_KEY', "-----BEGIN PUBLIC KEY-----\n"
        . "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE8shOfi8I/L1tL4xmP4ichOz1hDCx\n"
        . "GxFLdOmiWneOC9VVb2XSTcqDgnsxaV12Xvuj6zEG6Au+c7MQ0v+Z2XDj0A==\n"
        . "-----END PUBLIC KEY-----\n");
}
