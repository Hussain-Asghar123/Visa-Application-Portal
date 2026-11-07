<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$token = query('token');
$row = find_valid_token($token, 'verify_email');

if (!$row || !consume_token((int) $row['id'])) {
    if ($token !== '') {
        security_event('verify_email.invalid_token');
    }
    render_header('Link invalid');
    echo '<div class="row justify-content-center"><div class="col-md-8 col-lg-6"><div class="card shadow-sm"><div class="card-body p-4">'
        . '<h1 class="h3">This link is invalid or has expired</h1><p>Sign in and request a new confirmation link.</p>'
        . '<a class="btn btn-brand" href="' . e(url('login.php')) . '">Sign in</a></div></div></div></div>';
    render_footer();
    exit;
}

db_run('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [now(), $row['user_id']]);
audit_log('user.email_verified', 'user', (int) $row['user_id'], [], 'audit', (int) $row['user_id']);
flash('success', 'Thank you, your email address is confirmed.' . (current_user() ? '' : ' You can now sign in.'));
redirect(current_user() ? 'applicant/index.php' : 'login.php');
