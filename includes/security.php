<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

// ---------------------------------------------------------------------
// Transport & headers
// ---------------------------------------------------------------------
function enforce_https(): void
{
    if (config('app.force_https', false) && !is_https()) {
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        // Prefer the configured address so a forged Host header cannot redirect visitors elsewhere.
        $parts = parse_url((string) config('app.url', ''));
        if (!empty($parts['host'])) {
            $host = $parts['host'] . (!empty($parts['port']) ? ':' . (int) $parts['port'] : '');
        }
        if ($host !== '') {
            header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            exit;
        }
    }
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
    if (is_https() && config('app.hsts', false)) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    header_remove('X-Powered-By');
}

// ---------------------------------------------------------------------
// Sessions
// ---------------------------------------------------------------------
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) (max(1, (int) config('security.session_absolute_hours', 8)) * 3600));
    session_name('VAPSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path() === '' ? '/' : base_path() . '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function enforce_session_timeouts(): void
{
    $now = time();
    if (!empty($_SESSION['uid'])) {
        $idle = max(5, (int) config('security.session_idle_minutes', 30)) * 60;
        $absolute = max(1, (int) config('security.session_absolute_hours', 8)) * 3600;
        $last = (int) ($_SESSION['last_activity'] ?? $now);
        $started = (int) ($_SESSION['login_at'] ?? $now);
        if ($now - $last > $idle || $now - $started > $absolute) {
            logout_user();
            start_secure_session();
            flash('warning', 'Your session expired. Please sign in again.');
            return;
        }
        // Rotate the session id every 15 minutes to limit fixation/hijack windows.
        if ($now - (int) ($_SESSION['rotated_at'] ?? $started) > 900) {
            session_regenerate_id(true);
            $_SESSION['rotated_at'] = $now;
        }
    }
    $_SESSION['last_activity'] = $now;
}

// ---------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Every POST request must carry a valid token. Called from bootstrap. */
function verify_csrf_on_post(): void
{
    if (!is_post()) {
        return;
    }
    // Uploads larger than post_max_size arrive with empty $_POST and $_FILES.
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        abort(413, 'Upload too large', 'The file you selected is larger than this server accepts. Please upload a smaller file (maximum ' . upload_max_mb() . ' MB).');
    }
    $sent = $_POST['_csrf'] ?? '';
    $expected = $_SESSION['_csrf'] ?? '';
    if (!is_string($sent) || !is_string($expected) || $expected === '' || !hash_equals($expected, $sent)) {
        security_event('csrf.rejected', null, null, ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
        abort(400, 'Form expired', 'Your form session expired or the request could not be verified. Please go back, refresh the page and try again.');
    }
}

// ---------------------------------------------------------------------
// Rate limiting (database backed – works on shared hosting without cron)
// ---------------------------------------------------------------------
function rl_identifier(string $value): string
{
    return hash('sha256', strtolower(trim($value)));
}

function rl_hit(string $bucket, string $value): void
{
    try {
        db_run('INSERT INTO rate_limit_hits (bucket, identifier, created_at) VALUES (?, ?, ?)', [$bucket, rl_identifier($value), now()]);
        // Opportunistic cleanup (~2% of hits) since free hosts often lack cron.
        if (random_int(1, 50) === 1) {
            db_run('DELETE FROM rate_limit_hits WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        }
    } catch (Throwable $e) {
        app_log('warning', 'Rate limit write failed: ' . $e->getMessage());
    }
}

function rl_count(string $bucket, string $value, int $windowSeconds): int
{
    return (int) db_value(
        'SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ? AND identifier = ? AND created_at >= ?',
        [$bucket, rl_identifier($value), date('Y-m-d H:i:s', time() - $windowSeconds)]
    );
}

function rl_clear(string $bucket, string $value): void
{
    db_run('DELETE FROM rate_limit_hits WHERE bucket = ? AND identifier = ?', [$bucket, rl_identifier($value)]);
}

/** Returns true when the caller has exceeded $max hits in the window. */
function rl_exceeded(string $bucket, string $value, int $max, int $windowSeconds): bool
{
    return rl_count($bucket, $value, $windowSeconds) >= $max;
}

// ---------------------------------------------------------------------
// Audit logging
// ---------------------------------------------------------------------
function audit_log(string $action, ?string $entityType = null, ?int $entityId = null, array $details = [], string $category = 'audit', ?int $userId = null): void
{
    try {
        $uid = $userId ?? (isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null);
        db_insert('audit_logs', [
            'category' => $category,
            'user_id' => $uid ?: null,
            'action' => mb_substr($action, 0, 80),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => PHP_SAPI === 'cli' ? 'cli' : client_ip(),
            'user_agent' => PHP_SAPI === 'cli' ? 'cli' : user_agent(),
            'created_at' => now(),
        ]);
    } catch (Throwable $e) {
        app_log('error', 'Audit log write failed: ' . $e->getMessage(), ['action' => $action]);
    }
}

function security_event(string $action, ?string $entityType = null, ?int $entityId = null, array $details = [], ?int $userId = null): void
{
    audit_log($action, $entityType, $entityId, $details, 'security', $userId);
}
