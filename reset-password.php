<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$token = is_post() ? post('token') : query('token');
$row = find_valid_token($token, 'reset_password');
$errors = [];

if ($row && is_post()) {
    $user = db_one('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
    $password = (string) ($_POST['password'] ?? '');
    $problems = password_problems($password, (string) $user['email']);
    if ($problems) {
        $errors['password'] = implode(' ', $problems);
    } elseif ($password !== (string) ($_POST['password_confirm'] ?? '')) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    } elseif (password_verify($password, $user['password_hash'])) {
        $errors['password'] = 'Please choose a password different from your current one.';
    }
    if (!$errors) {
        if (!consume_token((int) $row['id'])) {
            abort(400, 'Link already used', 'This reset link has already been used. Request a new one.');
        }
        set_user_password((int) $user['id'], $password);
        // Opening a valid reset link proves control of the mailbox.
        db_run('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [now(), $user['id']]);
        rl_clear('login_email', strtolower($user['email']));
        audit_log('password_reset.completed', 'user', (int) $user['id'], [], 'security', (int) $user['id']);
        notify_user((int) $user['id'], 'Your password was changed', 'Your password was reset. If this was not you, contact support immediately.', null, true);
        logout_user();
        start_secure_session();
        flash('success', 'Your password has been changed. Please sign in with your new password.');
        redirect('login.php');
    }
}

render_header('Reset password');
?>
<div class="row justify-content-center">
  <div class="col-md-7 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body p-4">
      <?php if (!$row): ?>
        <h1 class="h3">This link is invalid or has expired</h1>
        <p>Password reset links are valid for 60 minutes and can only be used once.</p>
        <a class="btn btn-brand" href="<?= e(url('forgot-password.php')) ?>">Request a new link</a>
      <?php else: ?>
        <h1 class="h3">Choose a new password</h1>
        <form method="post" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <?= form_input('password', 'New password', '', $errors, 'password', ['required' => true, 'autocomplete' => 'new-password', 'minlength' => 10, 'maxlength' => 72]) ?>
          <p class="form-text mt-n2 mb-3">At least 10 characters, with letters and numbers.</p>
          <?= form_input('password_confirm', 'Confirm new password', '', $errors, 'password', ['required' => true, 'autocomplete' => 'new-password']) ?>
          <button type="submit" class="btn btn-brand w-100">Save new password</button>
        </form>
      <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php render_footer();
