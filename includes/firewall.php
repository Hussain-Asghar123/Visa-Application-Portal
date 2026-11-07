<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Lightweight application firewall (works on shared hosting, no server modules needed).
 *  - blocks requests whose URL contains common attack payloads (SQL injection, XSS,
 *    path traversal, PHP stream wrappers, scanner probes) and known hacking-tool user agents;
 *  - counts those hits per IP and temporarily bans IPs that keep attacking;
 *  - lets the Super Admin block / unblock IPs manually (Admin → Security Center).
 * Form fields (POST bodies) are NOT scanned: applicants may legitimately type any text,
 * which is always stored with prepared statements and escaped on output.
 * Signed-in users are never auto-banned (shared mobile IPs must not lock out real users).
 */
const FIREWALL_BAD_AGENTS = '/(sqlmap|nikto|nmap|masscan|acunetix|nessus|openvas|wpscan|dirbuster|gobuster|ffuf|feroxbuster|zgrab|nuclei|havij|w3af|commix|xsser|jorgee|netsparker|appscan|arachni)/i';

const FIREWALL_RULES = [
    'path traversal'     => '#\.\./|\.\.\\\\#',
    'system file probe'  => '#/etc/(passwd|shadow|hosts)|boot\.ini|win\.ini|/proc/self#i',
    'script injection'   => '#<\s*/?\s*(script|iframe|object|embed)\b|javascript\s*:|vbscript\s*:|\bon(error|load|mouseover|focus|click)\s*=#i',
    'SQL injection'      => '#\bunion\b(\s|/\*.*?\*/|\+)+(all(\s|\+)+)?select\b|\bselect\b.+\bfrom\b.+\binformation_schema\b|\bsleep\s*\(\s*\d|\bbenchmark\s*\(|\'\s*or\s*\'?1\'?\s*=\s*\'?1|\bor\s+1\s*=\s*1\b|;\s*(drop|truncate|alter)\s+table\b#i',
    'code injection'     => '#php://|phar://|expect://|zip://|data:text/html|<\?php|\bbase64_decode\s*\(|\beval\s*\(|\bshell_exec\b|\bpassthru\s*\(|\bproc_open\s*\(#i',
    'scanner probe'      => '#/(wp-admin|wp-login\.php|xmlrpc\.php|wp-content|wp-includes|phpmyadmin|pma|myadmin|\.env|\.git|\.svn|\.hg|vendor/phpunit|cgi-bin|actuator|server-status|\.aws|\.ssh)(/|$|\?)#i',
];

function firewall_enabled(): bool
{
    return (bool) config('security.firewall', true);
}

/** Returns the name of the matched attack rule for this request, or null. */
function firewall_attack_reason(): ?string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (preg_match(FIREWALL_BAD_AGENTS, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
        return 'hacking tool';
    }
    if (str_contains($uri, "\0") || stripos($uri, '%00') !== false) {
        return 'null byte';
    }
    // Decode twice to catch double-encoded payloads (%253C → %3C → <).
    $decoded = rawurldecode(rawurldecode(str_replace('+', ' ', $uri)));
    foreach (FIREWALL_RULES as $name => $pattern) {
        if (preg_match($pattern, $decoded)) {
            return $name;
        }
    }
    return null;
}

function firewall_is_blocked(string $ip): bool
{
    return (bool) db_value('SELECT 1 FROM ip_blocks WHERE ip_address = ? AND (expires_at IS NULL OR expires_at > ?)', [$ip, now()]);
}

function firewall_ban(string $ip, string $reason, ?int $minutes, string $source = 'auto', ?int $byUserId = null): void
{
    $expires = $minutes === null ? null : date('Y-m-d H:i:s', time() + $minutes * 60);
    db_run(
        'INSERT INTO ip_blocks (ip_address, reason, source, expires_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE reason = VALUES(reason), source = VALUES(source), expires_at = VALUES(expires_at), created_by = VALUES(created_by), created_at = VALUES(created_at)',
        [$ip, mb_substr($reason, 0, 255), $source, $expires, $byUserId, now()]
    );
}

function firewall_unban(string $ip): void
{
    db_run('DELETE FROM ip_blocks WHERE ip_address = ?', [$ip]);
}

function firewall_deny(string $title, string $message): never
{
    header('Cache-Control: no-store');
    abort(403, $title, $message);
}

/** Called from bootstrap on every web request (after the session starts). */
function firewall_check(): void
{
    if (!firewall_enabled() || PHP_SAPI === 'cli') {
        return;
    }
    $ip = client_ip();
    $signedIn = !empty($_SESSION['uid']);
    $reason = firewall_attack_reason();
    try {
        if ($reason !== null) {
            security_event('firewall.blocked', null, null, ['reason' => $reason, 'uri' => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 300), 'ua' => mb_substr(user_agent(), 0, 120)]);
            rl_hit('firewall', $ip);
            $threshold = max(3, (int) config('security.firewall_threshold', 8));
            $window = 600;
            if (!$signedIn && rl_count('firewall', $ip, $window) >= $threshold && !firewall_is_blocked($ip)) {
                $minutes = max(5, (int) config('security.firewall_ban_minutes', 60));
                firewall_ban($ip, 'Automatic: ' . $threshold . '+ attack requests in 10 minutes (last: ' . $reason . ')', $minutes);
                security_event('firewall.ip_banned', null, null, ['ip' => $ip, 'minutes' => $minutes, 'reason' => $reason]);
            }
        } elseif (!$signedIn && firewall_is_blocked($ip)) {
            firewall_deny('Access temporarily blocked', 'Your network address was blocked after suspicious activity. Please try again later. If you think this is a mistake, contact the office.');
        }
    } catch (Throwable $e) {
        // Tables not installed yet (installer) or DB unavailable: never break the page for this.
    }
    if ($reason !== null) {
        firewall_deny('Request blocked', 'This request looked unsafe and was blocked by the site firewall. If you were using the site normally, go back and try again.');
    }
}

// ---------------------------------------------------------------------
// Bot traps for public forms (no external CAPTCHA needed)
// ---------------------------------------------------------------------
function bot_trap_fields(): string
{
    $ts = (string) time();
    $sig = hash_hmac('sha256', $ts, app_secret_key());
    return '<div class="hp-field" aria-hidden="true"><label for="hp_website">Leave this field empty</label>'
        . '<input type="text" id="hp_website" name="website" value="" tabindex="-1" autocomplete="off"></div>'
        . '<input type="hidden" name="_ts" value="' . e($ts . '.' . $sig) . '">';
}

/** Returns why the submission looks automated, or null when it looks human. */
function bot_trap_reason(): ?string
{
    if (post('website') !== '') {
        return 'honeypot filled';
    }
    $parts = explode('.', post('_ts'), 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0]) || !hash_equals(hash_hmac('sha256', $parts[0], app_secret_key()), $parts[1])) {
        return 'missing form token';
    }
    $age = time() - (int) $parts[0];
    if ($age < (int) config('security.form_min_seconds', 2)) {
        return 'submitted too fast';
    }
    if ($age > 86400) {
        return 'form too old';
    }
    return null;
}

/** Reject automated submissions with a neutral message (and log them). */
function bot_trap_check(string $form): ?string
{
    $reason = bot_trap_reason();
    if ($reason === null) {
        return null;
    }
    security_event('bot.blocked', null, null, ['form' => $form, 'reason' => $reason]);
    rl_hit('firewall', client_ip());
    return 'Your form could not be verified. Please wait a moment and submit it again.';
}
