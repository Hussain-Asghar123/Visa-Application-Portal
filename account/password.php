<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();
$errors = [];

if (is_post()) {
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['password'] ?? '');
    if (rl_exceeded('pwchange', (string) $user['id'], 5, 900)) {
        $errors['current_password'] = 'Too many attempts. Please wait 15 minutes.';
    } elseif (!password_verify($current, $user['password_hash'])) {
        rl_hit('pwchange', (string) $user['id']);
        security_event('password_change.wrong_current', 'user', (int) $user['id']);
        $errors['current_password'] = 'Your current password is incorrect.';
    }
    if (!$errors) {
        $problems = password_problems($new, (string) $user['email']);
        if ($problems) {
            $errors['password'] = implode(' ', $problems);
        } elseif ($new !== (string) ($_POST['password_confirm'] ?? '')) {
            $errors['password_confirm'] = 'The two passwords do not match.';
        } elseif (hash_equals($current, $new)) {
            $errors['password'] = 'The new password must be different from the current one.';
        }
    }
    if (!$errors) {
        set_user_password((int) $user['id'], $new);
        // Keep this session valid with the new session version; other devices are signed out.
        $_SESSION['sv'] = (int) db_value('SELECT session_version FROM users WHERE id = ?', [$user['id']]);
        session_regenerate_id(true);
        audit_log('password.changed', 'user', (int) $user['id'], [], 'security');
        notify_user((int) $user['id'], 'Your password was changed', 'Your account password was changed. Other devices were signed out. If this was not you, contact support immediately.', null, true);
        flash('success', 'Your password has been changed. Other devices have been signed out.');
        redirect(home_url_for($user));
    }
}

render_header('Change password');
?>
<div class="row justify-content-center">
  <div class="col-md-7 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h1 class="h3 mb-3">Change password</h1>
        <form method="post" novalidate>
          <?= csrf_field() ?>
          <?= form_input('current_password', 'Current password', '', $errors, 'password', ['required' => true, 'autocomplete' => 'current-password']) ?>
          <?= form_input('password', 'New password', '', $errors, 'password', ['required' => true, 'autocomplete' => 'new-password', 'minlength' => 10, 'maxlength' => 72]) ?>
          <p class="form-text mt-n2 mb-3">At least 10 characters, with letters and numbers.</p>
          <?= form_input('password_confirm', 'Confirm new password', '', $errors, 'password', ['required' => true, 'autocomplete' => 'new-password']) ?>
          <button type="submit" class="btn btn-brand w-100">Change password</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php render_footer();
