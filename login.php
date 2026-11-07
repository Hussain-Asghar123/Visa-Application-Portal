<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) {
    redirect(home_url_for($u));
}

$error = null;
if (is_post()) {
    $email = post('email');
    $password = (string) ($_POST['password'] ?? '');
    if ($botError = bot_trap_check('login')) {
        $error = $botError;
    } elseif ($email === '' || $password === '' || strlen($password) > 200) {
        $error = 'Please enter your email and password.';
    } else {
        [$user, $error, $needs2fa] = attempt_login($email, $password);
        if ($needs2fa) {
            redirect('two-factor.php');
        }
        if ($user) {
            $intended = (string) ($_SESSION['intended'] ?? '');
            unset($_SESSION['intended']);
            // Only follow same-site relative paths inside the app.
            $area = $user['user_type'] === 'staff' ? '/admin/' : '/applicant/';
            if ($intended !== '' && str_starts_with($intended, base_path() . $area) && !str_contains($intended, '//') && !preg_match('/[\r\n]/', $intended)) {
                header('Location: ' . $intended, true, 303);
                exit;
            }
            redirect(home_url_for($user));
        }
    }
}

render_header('Sign in', 'login');
?>
<div class="auth-wrap narrow-form">
  <?= auth_side('Welcome back', [
      ['activity', 'See the latest status of your application'],
      ['cloud-arrow-up', 'Upload or correct documents in minutes'],
      ['bell', 'Read updates from our team'],
  ], 'Your documents are stored privately and only shared with the staff handling your case.') ?>
  <div class="auth-form">
    <span class="eyebrow mb-2"><i class="bi bi-box-arrow-in-right"></i> Sign in</span>
    <h1 class="mb-1">Sign in to your account</h1>
    <p class="text-muted mb-4">New here? <a class="fw-semibold" href="<?= e(url('register.php')) ?>">Create an account</a></p>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <?= bot_trap_fields() ?>
      <?= form_input('email', 'Email address', '', [], 'email', ['required' => true, 'autocomplete' => 'username', 'maxlength' => 190, 'placeholder' => 'you@example.com']) ?>
      <?= form_input('password', 'Password', '', [], 'password', ['required' => true, 'autocomplete' => 'current-password', 'placeholder' => 'Your password']) ?>
      <div class="d-flex justify-content-end mb-3 mt-n2 small"><a href="<?= e(url('forgot-password.php')) ?>">Forgot password?</a></div>
      <button type="submit" class="btn btn-brand btn-lg w-100">Sign in</button>
    </form>
  </div>
</div>
<?php render_footer();
