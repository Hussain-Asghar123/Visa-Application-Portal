<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/** Escape for HTML output (text and attribute context). */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Base URL path of the application, e.g. "" or "/portal". */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = (string) config('app.url', '');
    if ($configured !== '') {
        $path = (string) (parse_url($configured, PHP_URL_PATH) ?? '');
        return $base = rtrim($path, '/');
    }
    // Detect from the executing script relative to APP_ROOT.
    $script = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $name = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== '' && str_starts_with($script, APP_ROOT)) {
        $rel = substr($script, strlen(APP_ROOT));
        if ($rel !== '' && str_ends_with($name, $rel)) {
            return $base = rtrim(substr($name, 0, -strlen($rel)), '/');
        }
    }
    return $base = '';
}

function url(string $path = '', array $query = []): string
{
    $u = base_path() . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

/** Absolute URL for e-mails. Uses app.url from config when set (recommended). */
function absolute_url(string $path = '', array $query = []): string
{
    $configured = rtrim((string) config('app.url', ''), '/');
    if ($configured !== '') {
        $origin = preg_replace('#^(https?://[^/]+).*$#i', '$1', $configured);
    } else {
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $origin = (is_https() ? 'https' : 'http') . '://' . $host;
    }
    return $origin . url($path, $query);
}

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : APP_VERSION;
    return url('assets/' . ltrim($path, '/'), ['v' => $v]);
}

function redirect(string $path, array $query = []): never
{
    $target = preg_match('#^https?://#i', $path) ? $path : url($path, $query);
    header('Location: ' . $target, true, 303);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Read a scalar POST value as a trimmed string (arrays are rejected). */
function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function post_int(string $key): int
{
    $v = post($key);
    return ctype_digit($v) ? (int) $v : 0;
}

function query(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function query_int(string $key): int
{
    $v = query($key);
    return ctype_digit($v) ? (int) $v : 0;
}

/** Re-populate a form value: POSTed value first, then the stored default. */
function old(string $key, mixed $default = ''): string
{
    if (is_post() && isset($_POST[$key]) && is_string($_POST[$key])) {
        return $_POST[$key];
    }
    return (string) ($default ?? '');
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function fmt_date(?string $value, bool $withTime = false): string
{
    if ($value === null || $value === '' || str_starts_with($value, '0000')) {
        return '—';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '—';
    }
    return date($withTime ? 'd M Y, H:i' : 'd M Y', $ts);
}

function fmt_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    return number_format(max(1, (int) round($bytes / 1024))) . ' KB';
}

function fmt_money(string|float $amount, string $currency): string
{
    return e($currency) . ' ' . number_format((float) $amount, 2);
}

/** Random string from an unambiguous alphabet (no 0/O/1/I). */
function random_code(int $length, string $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'): string
{
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $proto === 'https';
}

function client_ip(): string
{
    $header = (string) config('security.client_ip_header', '');
    if ($header !== '' && !empty($_SERVER[$header])) {
        $first = trim(explode(',', (string) $_SERVER[$header])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function log_dir(): string
{
    $dir = (string) config('paths.logs', '');
    return $dir !== '' ? rtrim(str_replace('\\', '/', $dir), '/') : APP_ROOT . '/private/logs';
}

/** Write to the private application log (never shown to visitors). */
function app_log(string $level, string $message, array $context = []): void
{
    $dir = log_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($level) . ': ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    @file_put_contents($dir . '/app-' . date('Y-m') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function abort(int $code, string $title = '', string $message = ''): never
{
    $defaults = [
        400 => ['Bad request', 'The request could not be processed.'],
        403 => ['Access denied', 'You do not have permission to access this page.'],
        404 => ['Not found', 'The page or record you requested does not exist or is not available to you.'],
        405 => ['Method not allowed', 'This action must be submitted from the website form.'],
        413 => ['Upload too large', 'The uploaded data exceeds the server limit.'],
        429 => ['Too many requests', 'Please wait a few minutes and try again.'],
        503 => ['Unavailable', 'This feature is currently unavailable.'],
    ];
    http_response_code($code);
    render_error_page($code, $title ?: ($defaults[$code][0] ?? 'Error'), $message ?: ($defaults[$code][1] ?? ''));
    exit;
}

function require_post(): void
{
    if (!is_post()) {
        abort(405);
    }
}

/** Simple pagination. Returns [offset, limit, page, pages]. */
function paginate(int $total, int $perPage = 20): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, query_int('page') ?: 1), $pages);
    return [($page - 1) * $perPage, $perPage, $page, $pages];
}

function pagination_links(int $page, int $pages, array $query = []): string
{
    if ($pages <= 1) {
        return '';
    }
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
    $html = '<nav aria-label="Pages"><ul class="pagination pagination-sm flex-wrap">';
    for ($p = 1; $p <= $pages; $p++) {
        if ($pages > 12 && $p > 2 && $p < $pages - 1 && abs($p - $page) > 2) {
            if ($p === 3 || $p === $pages - 2) {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            continue;
        }
        $q = http_build_query(array_merge($query, ['page' => $p]));
        $html .= '<li class="page-item' . ($p === $page ? ' active' : '') . '"><a class="page-link" href="' . e($path . '?' . $q) . '">' . $p . '</a></li>';
    }
    return $html . '</ul></nav>';
}

function str_limit(?string $s, int $len = 80): string
{
    $s = (string) $s;
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
}
