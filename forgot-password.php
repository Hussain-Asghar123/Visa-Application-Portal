<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$errors = [];
if (is_post()) {
    $v = new Validator($_POST);
    $v->required('email', 'Email')->email('email', 'Email');
    if ($botError = bot_trap_check('forgot_password')) {
        $v->add('email', $botError);
    }
    $errors = $v->errors();
    if (!$errors) {
        $email = strtolower(post('email'));
        $ip = client_ip();
        $throttled = rl_exceeded('reset_email', $email, 3, 3600) || rl_exceeded('reset_ip', $ip, 10, 3600);
        if ($throttled) {
            security_event('password_reset.throttled', null, null, ['email' => $email]);
        } else {
            rl_hit('reset_email', $email);
            rl_hit('reset_ip', $ip);
            $user = db_one("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email]);
            if ($user) {
                send_password_reset_email($user);
                audit_log('password_reset.requested', 'user', (int) $user['id'], [], 'security', (int) $user['id']);
            }
        }
        // Same response whether or not the account exists (prevents account enumeration).
        flash('success', 'If an account exists for that email, a password reset link has been sent. The link is valid for 60 minutes.');
        redirect('login.php');
    }
}

render_header('Forgot password');
?>
<div class="row justify-content-center">
  <div class="col-md-7 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h1 class="h3">Forgot your password?</h1>
        <p class="text-muted">Enter your account email and we'll send you a link to choose a new password.</p>
        <form method="post" novalidate>
          <?= csrf_field() ?>
          <?= bot_trap_fields() ?>
          <?= form_input('email', 'Email address', '', $errors, 'email', ['required' => true, 'autocomplete' => 'email']) ?>
          <button type="submit" class="btn btn-brand w-100">Send reset link</button>
        </form>
        <p class="small text-muted mt-3 mb-0">No email arriving? Contact the office: staff can issue a one-time reset link after confirming your identity.</p>
      </div>
    </div>
  </div>
</div>
<?php render_footer();
