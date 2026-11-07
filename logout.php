<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

// Logout only via POST (with CSRF token) so other sites cannot sign users out.
require_post();
$user = current_user();
if ($user) {
    audit_log('logout', 'user', (int) $user['id'], [], 'security');
}
logout_user();
start_secure_session();
flash('success', 'You have been signed out.');
redirect('login.php');
