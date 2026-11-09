<?php
declare(strict_types=1);

/* =====================================================================
 * V5.2 – account helpers
 *  - Usernames for new accounts: two letters + dash + national ID  (AB-123456789)
 *  - Passwords may be simple (4+ characters, no complexity rules) but are
 *    always stored with a strong, slow hash (Argon2id, or bcrypt cost 12 fallback).
 *  - Old hashes are upgraded automatically on the next successful login.
 * ===================================================================== */

const PASSWORD_MIN_LENGTH = 4;

/** Hash algorithm + cost used for every stored password. */
function pw_algo(): array
{
    if (defined('PASSWORD_ARGON2ID')) {
        return [PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]];
    }
    return [PASSWORD_BCRYPT, ['cost' => 12]];
}

function hash_password(string $plain): string
{
    [$algo, $opts] = pw_algo();
    return password_hash($plain, $algo, $opts);
}

function pw_needs_rehash(string $hash): bool
{
    [$algo, $opts] = pw_algo();
    return password_needs_rehash($hash, $algo, $opts);
}

/** Simple on purpose: only a minimum length. */
function check_password_rules(string $plain): void
{
    if (mb_strlen($plain) < PASSWORD_MIN_LENGTH) {
        throw new RuntimeException('كلمة المرور يجب ألا تقل عن ' . PASSWORD_MIN_LENGTH . ' خانات (يمكن أن تكون بسيطة).');
    }
    if (mb_strlen($plain) > 200) {
        throw new RuntimeException('كلمة المرور طويلة جداً.');
    }
}

/** AB-123456789 (two letters, dash, 5–12 digits). */
function username_format_ok(string $un): bool
{
    return (bool)preg_match('/^[A-Za-z]{2}-\d{5,12}$/', $un);
}

/** Accepts "ab123456789", "ab 123456789", Arabic digits, odd dashes … and returns "AB-123456789" when it fits. */
function normalize_username(string $raw): string
{
    $s = normalize_digits($raw);
    $s = str_replace(['–', '—', '‑', '−', '_'], '-', $s);
    $s = preg_replace('/\s+/u', '', $s) ?? '';
    if (preg_match('/^([A-Za-z]{2})-?(\d{5,12})$/', $s, $m)) {
        return strtoupper($m[1]) . '-' . $m[2];
    }
    return $s;   // legacy usernames (admin, yarmouk.owner …) are kept as typed
}
