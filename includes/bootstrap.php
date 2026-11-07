<?php
/**
 * Application bootstrap. Every public PHP entry point starts with:
 *     require __DIR__ . '/includes/bootstrap.php';   (or ../includes/...)
 */
declare(strict_types=1);

define('APP_ROOT', str_replace('\\', '/', dirname(__DIR__)));
define('APP_VERSION', '1.0.0');

require APP_ROOT . '/includes/helpers.php';
require APP_ROOT . '/includes/config.php';
require APP_ROOT . '/includes/db.php';
require APP_ROOT . '/includes/security.php';
require APP_ROOT . '/includes/auth.php';
require APP_ROOT . '/includes/validation.php';
require APP_ROOT . '/includes/mailer.php';
require APP_ROOT . '/includes/uploads.php';
require APP_ROOT . '/includes/app.php';
require APP_ROOT . '/includes/links.php';
require APP_ROOT . '/includes/totp.php';
require APP_ROOT . '/includes/firewall.php';
require APP_ROOT . '/includes/migrations.php';
require APP_ROOT . '/includes/layout.php';

load_config();

date_default_timezone_set((string) config('app.timezone', 'UTC'));
mb_internal_encoding('UTF-8');

// ---- Error handling: log details privately, show visitors a generic message ----
$debug = (bool) config('app.debug', false);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    // Deprecations must never break a page after a host upgrades PHP: log them only.
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        app_log('notice', 'Deprecated: ' . $message, ['file' => $file . ':' . $line]);
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $ex): void {
    $ref = strtoupper(bin2hex(random_bytes(4)));
    app_log('error', 'Unhandled exception [' . $ref . ']: ' . get_class($ex) . ': ' . $ex->getMessage(), [
        'file' => $ex->getFile() . ':' . $ex->getLine(),
        'uri'  => $_SERVER['REQUEST_URI'] ?? 'cli',
        'trace' => substr($ex->getTraceAsString(), 0, 3000),
    ]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $ex . PHP_EOL);
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    render_error_page(500, 'Something went wrong', 'An unexpected error occurred. Please try again later. If the problem continues, contact support and quote reference ' . $ref . '.', config('app.debug') ? (string) $ex : null);
    exit;
});

// Upgrade older databases automatically after new files are uploaded.
if (!empty($GLOBALS['__vap_config']['db']['host'] ?? '')) {
    run_migrations();
}

if (PHP_SAPI !== 'cli') {
    enforce_https();
    send_security_headers();
    start_secure_session();
    enforce_session_timeouts();
    firewall_check();
    verify_csrf_on_post();
}
