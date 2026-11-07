<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/** Staff permission codes. Super Admins implicitly hold all of them. */
const PERMISSIONS = [
    'applications.view_all' => 'View all applications (not only assigned ones)',
    'applications.review'   => 'Review applications and update their status',
    'applications.assign'   => 'Assign applications to staff members',
    'documents.review'      => 'View, accept or reject applicant documents',
    'offers.manage'         => 'Upload offer letters',
    'offers.publish'        => 'Publish or unpublish offer letters',
    'biometrics.manage'     => 'Schedule biometrics and approve or reject the stage',
    'payments.instructions' => 'Manage fee types and payment instructions',
    'payments.review'       => 'Confirm or reject payment receipts',
    'travel.manage'         => 'Manage and publish travel & accommodation',
    'support.manage'        => 'Respond to support tickets',
    'cancellations.manage'  => 'Approve or reject cancellation requests',
    'applicants.manage'     => 'Manage applicant accounts (verify, disable, reset links)',
    'catalog.manage'        => 'Manage visa categories, processing times and document requirements',
    'audit.view'            => 'View audit logs and security events',
];

/** The signed-in user, re-validated against the database on every request. */
function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if ($uid <= 0) {
        return null;
    }
    $row = db_one('SELECT u.*, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]);
    // A stolen session cookie used from a different browser is rejected.
    if (isset($_SESSION['ua']) && !hash_equals((string) $_SESSION['ua'], hash('sha256', user_agent()))) {
        security_event('session.browser_mismatch', 'user', $uid, [], $uid);
        logout_user();
        start_secure_session();
        return null;
    }
    if (!$row || $row['status'] !== 'active' || (int) $row['session_version'] !== (int) ($_SESSION['sv'] ?? -1)) {
        // Account disabled, deleted, or password changed elsewhere: end this session.
        logout_user();
        start_secure_session();
        return null;
    }
    return $user = $row;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = [
        'uid' => (int) $user['id'],
        'sv' => (int) $user['session_version'],
        'ua' => hash('sha256', user_agent()),
        'login_at' => time(),
        'rotated_at' => time(),
        'last_activity' => time(),
    ];
    $ip = client_ip();
    if (!empty($user['last_login_ip']) && $user['last_login_ip'] !== $ip) {
        notify_user((int) $user['id'], 'New sign-in to your account', 'Your account was signed in from a new network (IP ' . $ip . ') on ' . date('d M Y, H:i') . '. If this was not you, change your password and turn on 2-step login under Account > Security.', 'account/security.php');
    }
    db_run('UPDATE users SET last_login_at = ?, last_login_ip = ? WHERE id = ?', [now(), $ip, $user['id']]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_destroy();
    }
}

function is_staff(?array $user): bool
{
    return $user !== null && $user['user_type'] === 'staff';
}

function is_super_admin(?array $user): bool
{
    return is_staff($user) && (int) $user['is_super_admin'] === 1;
}

function home_url_for(array $user): string
{
    return $user['user_type'] === 'staff' ? 'admin/index.php' : 'applicant/index.php';
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        $_SESSION['intended'] = (string) ($_SERVER['REQUEST_URI'] ?? '');
        flash('info', 'Please sign in to continue.');
        redirect('login.php');
    }
    return $user;
}

function require_applicant(): array
{
    $user = require_login();
    if ($user['user_type'] !== 'applicant') {
        security_event('access.denied', null, null, ['reason' => 'staff on applicant area', 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
        redirect('admin/index.php');
    }
    if (config('security.require_email_verification', true) && empty($user['email_verified_at'])) {
        redirect('verify-notice.php');
    }
    return $user;
}

function require_staff(): array
{
    $user = require_login();
    if (!is_staff($user)) {
        security_event('access.denied', null, null, ['reason' => 'applicant on admin area', 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
        abort(403);
    }
    if (staff_2fa_required() && !user_has_2fa($user)) {
        flash('warning', 'The Super Admin requires 2-step login for all staff. Turn it on below to continue.');
        redirect('account/security.php');
    }
    return $user;
}

function user_permissions(array $user): array
{
    static $cache = [];
    $id = (int) $user['id'];
    if (!isset($cache[$id])) {
        if (is_super_admin($user)) {
            $cache[$id] = array_keys(PERMISSIONS);
        } elseif (!is_staff($user) || empty($user['role_id'])) {
            $cache[$id] = [];
        } else {
            $cache[$id] = array_column(db_all(
                'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?',
                [$user['role_id']]
            ), 'code');
        }
    }
    return $cache[$id];
}

/** Does the user hold the permission? Server-side check used by every protected action. */
function can(string $permission, ?array $user = null): bool
{
    $user ??= current_user();
    if (!is_staff($user)) {
        return false;
    }
    return is_super_admin($user) || in_array($permission, user_permissions($user), true);
}

function can_any(array $permissions, ?array $user = null): bool
{
    foreach ($permissions as $p) {
        if (can($p, $user)) {
            return true;
        }
    }
    return false;
}

/** Require staff + at least one of the given permissions, otherwise 403 (and log it). */
function require_permission(string ...$permissions): array
{
    $user = require_staff();
    if (!can_any($permissions, $user)) {
        security_event('permission.denied', null, null, ['needed' => $permissions, 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
        abort(403);
    }
    return $user;
}

function require_super_admin(): array
{
    $user = require_staff();
    if (!is_super_admin($user)) {
        security_event('permission.denied', null, null, ['needed' => 'super_admin', 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
        abort(403);
    }
    return $user;
}

/** Password policy. Returns a list of problems (empty = OK). */
function password_problems(string $password, string $email = ''): array
{
    $problems = [];
    if (strlen($password) < 10) {
        $problems[] = 'Password must be at least 10 characters long.';
    }
    if (strlen($password) > 72) {
        $problems[] = 'Password must be at most 72 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $problems[] = 'Password must contain both letters and numbers.';
    }
    $local = strtolower((string) strstr($email, '@', true));
    if ($local !== '' && strlen($local) >= 4 && str_contains(strtolower($password), $local)) {
        $problems[] = 'Password must not contain your email name.';
    }
    $common = ['password1', 'password12', 'password123', 'qwerty1234', '1234567890a', 'letmein123', 'welcome123', 'admin12345'];
    if (in_array(strtolower($password), $common, true)) {
        $problems[] = 'This password is too common.';
    }
    return $problems;
}

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Attempt a login with rate limiting. Returns [user|null, errorMessage|null, needsSecondStep].
 */
function attempt_login(string $email, string $password): array
{
    $email = strtolower(trim($email));
    $ip = client_ip();
    $max = max(3, (int) config('security.login_max_attempts', 5));
    $window = max(5, (int) config('security.login_window_minutes', 15)) * 60;

    if (rl_exceeded('login_email', $email, $max, $window) || rl_exceeded('login_ip', $ip, $max * 4, $window)) {
        security_event('login.throttled', null, null, ['email' => $email]);
        return [null, 'Too many failed sign-in attempts. Please wait ' . (int) ($window / 60) . ' minutes and try again, or reset your password.', false];
    }

    $user = db_one('SELECT * FROM users WHERE email = ?', [$email]);
    // Verify against a dummy hash when the user does not exist to keep timing similar.
    $hash = $user['password_hash'] ?? '$2y$10$k9Yy0kXfJxWkR6m1vS7u3eQzv0Yl2qQmJb4Wc8n1G5tHjZr3xLwVu';
    $ok = password_verify($password, $hash);

    if (!$user || !$ok) {
        rl_hit('login_email', $email);
        rl_hit('login_ip', $ip);
        security_event('login.failed', 'user', $user ? (int) $user['id'] : null, ['email' => $email], $user ? (int) $user['id'] : null);
        return [null, 'Incorrect email or password.', false];
    }
    if ($user['status'] !== 'active') {
        security_event('login.disabled_account', 'user', (int) $user['id'], [], (int) $user['id']);
        return [null, 'This account has been disabled. Please contact support.', false];
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_run('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($password), $user['id']]);
    }
    rl_clear('login_email', $email);
    if (user_has_2fa($user)) {
        // Password is correct; the account is only signed in after the authenticator code.
        session_regenerate_id(true);
        $_SESSION['2fa_pending'] = ['uid' => (int) $user['id'], 'sv' => (int) $user['session_version'], 'at' => time()];
        audit_log('login.password_ok_2fa_pending', 'user', (int) $user['id'], [], 'security', (int) $user['id']);
        return [null, null, true];
    }
    login_user($user);
    audit_log('login.success', 'user', (int) $user['id'], [], 'security', (int) $user['id']);
    return [$user, null, false];
}

/** Change a password and invalidate all other sessions of that user. */
function set_user_password(int $userId, string $newPassword): void
{
    db_run(
        'UPDATE users SET password_hash = ?, password_changed_at = ?, session_version = session_version + 1 WHERE id = ?',
        [hash_password($newPassword), now(), $userId]
    );
    // Any outstanding reset tokens become useless.
    db_run("UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND purpose = 'reset_password' AND used_at IS NULL", [now(), $userId]);
}

// ---------------------------------------------------------------------
// One-time tokens (email verification / password reset)
// ---------------------------------------------------------------------
function create_user_token(int $userId, string $purpose, int $ttlMinutes, ?int $createdBy = null): string
{
    // Invalidate earlier unused tokens of the same purpose.
    db_run('UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [now(), $userId, $purpose]);
    $token = bin2hex(random_bytes(32));
    db_insert('user_tokens', [
        'user_id' => $userId,
        'purpose' => $purpose,
        'token_hash' => hash('sha256', $token),
        'expires_at' => date('Y-m-d H:i:s', time() + $ttlMinutes * 60),
        'requested_ip' => PHP_SAPI === 'cli' ? null : client_ip(),
        'created_by' => $createdBy,
        'created_at' => now(),
    ]);
    return $token;
}

/** Look up a valid (unused, unexpired) token. Does not consume it. */
function find_valid_token(string $token, string $purpose): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    return db_one(
        'SELECT * FROM user_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?',
        [hash('sha256', $token), $purpose, now()]
    );
}

function consume_token(int $tokenId): bool
{
    return db_run('UPDATE user_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [now(), $tokenId])->rowCount() === 1;
}

function send_verification_email(array $user): bool
{
    $token = create_user_token((int) $user['id'], 'verify_email', 48 * 60);
    $link = absolute_url('verify-email.php', ['token' => $token]);
    $body = "Hello {$user['full_name']},\n\nPlease confirm your email address for " . config('app.name', 'the Visa Application Portal') . " by opening this link:\n\n{$link}\n\nThe link expires in 48 hours. If you did not create this account, you can ignore this email.";
    return send_mail($user['email'], 'Confirm your email address', $body);
}

function send_password_reset_email(array $user): bool
{
    $token = create_user_token((int) $user['id'], 'reset_password', 60);
    $link = absolute_url('reset-password.php', ['token' => $token]);
    $body = "Hello {$user['full_name']},\n\nA password reset was requested for your account. To choose a new password, open this link:\n\n{$link}\n\nThe link expires in 60 minutes and can be used once. If you did not request this, ignore this email; your password will not change.";
    return send_mail($user['email'], 'Reset your password', $body);
}
