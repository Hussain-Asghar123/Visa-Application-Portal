<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Configuration is loaded from the first file found:
 *   1. the path in the VAP_CONFIG environment variable
 *   2. ../vap-private/config.php  (one level ABOVE the web root – preferred when the host allows it)
 *   3. private/config.php          (inside the web root, protected by private/.htaccess)
 */
function config_candidates(): array
{
    $list = [];
    $env = getenv('VAP_CONFIG');
    if (is_string($env) && $env !== '') {
        $list[] = $env;
    }
    $list[] = dirname(APP_ROOT) . '/vap-private/config.php';
    $list[] = APP_ROOT . '/private/config.php';
    return $list;
}

function load_config(): void
{
    foreach (config_candidates() as $file) {
        if (@is_file($file) && @is_readable($file)) {
            $cfg = require $file;
            if (!is_array($cfg)) {
                break;
            }
            $GLOBALS['__vap_config'] = $cfg;
            $GLOBALS['__vap_config_file'] = $file;
            return;
        }
    }
    $GLOBALS['__vap_config'] = [];
    $GLOBALS['__vap_config_file'] = null;
    if (defined('VAP_INSTALLER')) {
        return; // install.php runs before a config file exists
    }
    if (PHP_SAPI !== 'cli') {
        http_response_code(503);
        if (is_file(APP_ROOT . '/install.php')) {
            header('Location: ' . url('install.php'), true, 302);
            exit;
        }
        render_error_page(503, 'Setup required', 'The portal is not configured yet. Upload install.php again and open it, or copy private/config.sample.php to private/config.php (see docs/INSTALL.md).');
        exit;
    }
}

/** Read a configuration value using dot notation, e.g. config('db.host'). */
function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['__vap_config'] ?? [];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function storage_dir(string $kind = ''): string
{
    $root = (string) config('paths.storage', '');
    $root = $root !== '' ? rtrim(str_replace('\\', '/', $root), '/') : APP_ROOT . '/private/storage';
    return $kind === '' ? $root : $root . '/' . $kind;
}

/** Default configuration used by the installer and the Site Settings page. */
function config_defaults(): array
{
    return [
        'app' => ['name' => 'Visa Application Portal', 'url' => '', 'key' => '', 'timezone' => 'UTC', 'support_email' => '', 'debug' => false, 'force_https' => false, 'hsts' => false],
        'db' => ['host' => '', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => ''],
        'paths' => ['storage' => '', 'logs' => ''],
        'uploads' => ['max_mb' => 5, 'strip_metadata' => true],
        'mail' => ['driver' => 'log', 'from_email' => '', 'from_name' => 'Visa Application Portal', 'notify_by_email' => true,
            'smtp' => ['host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '', 'timeout' => 15]],
        'security' => ['require_email_verification' => false, 'session_idle_minutes' => 30, 'session_absolute_hours' => 8,
            'login_max_attempts' => 5, 'login_window_minutes' => 15, 'client_ip_header' => '', 'firewall' => true,
            'firewall_threshold' => 8, 'firewall_ban_minutes' => 60, 'form_min_seconds' => 2],
        'setup' => ['enabled' => false, 'secret' => ''],
    ];
}

/** Recursively merge $over into $base (keeps keys missing from $over). */
function config_merge(array $base, array $over): array
{
    foreach ($over as $k => $v) {
        $base[$k] = is_array($v) && isset($base[$k]) && is_array($base[$k]) ? config_merge($base[$k], $v) : $v;
    }
    return $base;
}

/** PHP source of a config file. */
function config_export(array $cfg): string
{
    return "<?php
/**
 * Visa Application Portal configuration.
 * Written by the installer / Admin > Site settings on " . date('Y-m-d H:i') . ".
"
        . " * Keep this file private. It is protected by private/.htaccess.
 */
return " . var_export($cfg, true) . ";
";
}

/** Atomically write the config file. Returns false if the folder is not writable. */
function write_config_file(string $path, array $cfg): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }
    $tmp = $dir . '/.config-' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, config_export($cfg), LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) {
        @unlink($path);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }
    return true;
}

/** Where the config file lives (or will be written). */
function config_file_path(): string
{
    return $GLOBALS['__vap_config_file'] ?? (getenv('VAP_CONFIG') ?: APP_ROOT . '/private/config.php');
}
