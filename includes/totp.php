<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

// ---------------------------------------------------------------------
// Secret key + encryption for sensitive values (2-step login secrets)
// ---------------------------------------------------------------------

/** 32-byte application key: config app.key (base64) or one generated once and kept in settings. */
function app_secret_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $cfg = (string) config('app.key', '');
    if ($cfg !== '' && ($raw = base64_decode($cfg, true)) !== false && strlen($raw) >= 32) {
        return $key = substr($raw, 0, 32);
    }
    try {
        $stored = db_value("SELECT setting_value FROM settings WHERE setting_key = 'app_key'");
        if (!$stored) {
            $stored = base64_encode(random_bytes(32));
            db_run("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('app_key', ?)", [$stored]);
            $stored = db_value("SELECT setting_value FROM settings WHERE setting_key = 'app_key'");
        }
        return $key = substr((string) base64_decode((string) $stored), 0, 32);
    } catch (Throwable) {
        // Before installation: a per-installation fallback (only used for short-lived form tokens).
        return $key = hash('sha256', 'vap|' . APP_ROOT . '|' . (string) config('db.name', ''), true);
    }
}

function secret_encrypt(string $plain): string
{
    if (!function_exists('openssl_encrypt')) {
        return 'p:' . base64_encode($plain);
    }
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', app_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

function secret_decrypt(?string $stored): ?string
{
    if ($stored === null || $stored === '') {
        return null;
    }
    if (str_starts_with($stored, 'p:')) {
        return base64_decode(substr($stored, 2)) ?: null;
    }
    if (str_starts_with($stored, 'v1:') && function_exists('openssl_decrypt')) {
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', app_secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }
    return null;
}

// ---------------------------------------------------------------------
// TOTP (RFC 6238) – works with Google Authenticator, Microsoft Authenticator, Authy…
// ---------------------------------------------------------------------
const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32_encode(string $bin): string
{
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode(string $b32): string
{
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $bits .= str_pad(decbin(strpos(BASE32_ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_generate_secret(): string
{
    return base32_encode(random_bytes(20)); // 32 characters
}

function totp_code(string $secretB32, int $step): string
{
    $key = base32_decode($secretB32);
    $hash = hash_hmac('sha1', pack('N2', 0, $step), $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $num = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string) ($num % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Verify a 6-digit code (±1 step = ±30 s for clock drift). Returns the matched time step,
 * or null. Pass $lastStep to reject re-use of the same or an older code (replay protection).
 */
function totp_verify(string $secretB32, string $code, ?int $lastStep = null): ?int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) {
        return null;
    }
    $now = intdiv(time(), 30);
    for ($i = -1; $i <= 1; $i++) {
        $step = $now + $i;
        if ($lastStep !== null && $step <= $lastStep) {
            continue;
        }
        if (hash_equals(totp_code($secretB32, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function totp_uri(string $secretB32, string $account): string
{
    $issuer = (string) config('app.name', 'Visa Portal');
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}

// ---------------------------------------------------------------------
// Recovery codes (one-time backup codes for a lost phone)
// ---------------------------------------------------------------------
function recovery_code_hash(string $code): string
{
    return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)));
}

/** Replace a user's recovery codes; returns the plain codes to show ONCE. */
function generate_recovery_codes(int $userId): array
{
    db_run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
    $codes = [];
    for ($i = 0; $i < 10; $i++) {
        $code = random_code(4) . '-' . random_code(4);
        $codes[] = $code;
        db_insert('user_recovery_codes', ['user_id' => $userId, 'code_hash' => recovery_code_hash($code), 'created_at' => now()]);
    }
    return $codes;
}

function use_recovery_code(int $userId, string $code): bool
{
    $row = db_one('SELECT id FROM user_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL', [$userId, recovery_code_hash($code)]);
    if (!$row) {
        return false;
    }
    return db_run('UPDATE user_recovery_codes SET used_at = ? WHERE id = ? AND used_at IS NULL', [now(), $row['id']])->rowCount() === 1;
}

function user_has_2fa(array $user): bool
{
    return !empty($user['totp_enabled_at']) && !empty($user['totp_secret']);
}

function staff_2fa_required(): bool
{
    try {
        return setting('require_2fa_staff', '0') === '1';
    } catch (Throwable) {
        return false;
    }
}
