<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) {
    redirect(home_url_for($u));
}

$errors = [];
if (is_post()) {
    $v = new Validator($_POST);
    $v->required('full_name', 'Full name')->max('full_name', 150, 'Full name')->min('full_name', 3, 'Full name')
      ->required('email', 'Email')->email('email', 'Email')
      ->required('phone', 'Phone number')->phone('phone', 'Phone number')
      ->required('password', 'Password');
    $email = strtolower(post('email'));
    $password = (string) ($_POST['password'] ?? '');
    foreach (password_problems($password, $email) as $p) {
        $v->add('password', $p);
    }
    if ($password !== (string) ($_POST['password_confirm'] ?? '')) {
        $v->add('password_confirm', 'The two passwords do not match.');
    }
    if (post('agree') !== '1') {
        $v->add('agree', 'You must accept the privacy notice to continue.');
    }
    if ($botError = bot_trap_check('register')) {
        $v->add('email', $botError);
    }
    if (rl_exceeded('register_ip', client_ip(), 10, 3600)) {
        $v->add('email', 'Too many registrations from your network. Please try again later.');
    }
    $errors = $v->errors();

    if (!$errors) {
        rl_hit('register_ip', client_ip());
        $verify = (bool) config('security.require_email_verification', true);
        $existing = db_one('SELECT id FROM users WHERE email = ?', [$email]);
        if ($existing && !$verify) {
            // Without email verification the user is signed in straight away, so a duplicate
            // email has to be reported (there is no neutral "check your inbox" step).
            security_event('register.duplicate_email', 'user', (int) $existing['id']);
            $errors['email'] = 'An account with this email already exists. Please sign in or use "Forgot password".';
        } elseif ($existing) {
            // Do not reveal whether an email is registered; behave as if it worked.
            security_event('register.duplicate_email', 'user', (int) $existing['id']);
            flash('success', 'Thank you. If this email address can be used, a confirmation link has been sent to it. Please check your inbox (and spam folder).');
            redirect('login.php');
        }
    }

    if (!$errors) {
        $userId = db_transaction(static function () use ($email, $password) {
            $id = db_insert('users', [
                'user_type' => 'applicant',
                'email' => $email,
                'password_hash' => hash_password($password),
                'full_name' => post('full_name'),
                'phone' => post('phone'),
                'status' => 'active',
                'password_changed_at' => now(),
                'created_at' => now(),
            ]);
            db_insert('applicant_profiles', ['user_id' => $id]);
            return $id;
        });
        audit_log('user.registered', 'user', $userId, [], 'audit', $userId);
        $user = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$verify) {
            // Email verification is switched off: no email is sent, sign the applicant in now.
            notify_user($userId, 'Welcome to ' . app_name(), 'Your account was created. You can start your visa application now.');
            login_user($user);
            flash('success', 'Welcome! Your account has been created and you are signed in.');
            redirect('applicant/index.php');
        }
        $sent = send_verification_email($user);
        notify_user($userId, 'Welcome to ' . app_name(), 'Your account was created. Confirm your email address to start your application.');
        if (!$sent) {
            app_log('warning', 'Verification email could not be sent', ['user' => $userId]);
        }
        // Same message as the duplicate-email branch, so registration cannot be used to discover accounts.
        flash('success', 'Thank you. If this email address can be used, a confirmation link has been sent to it. Please check your inbox (and spam folder), then sign in.');
        redirect('login.php');
    }
}

render_header('Create account', 'register');
?>
<div class="auth-wrap">
  <?= auth_side('Start your visa application today', [
      ['person-check', 'Create your account in under a minute'],
      ['floppy', 'Save your application as a draft and continue any time'],
      ['shield-lock', 'Upload documents privately from your phone'],
      ['bell', 'Get notified at every stage – review, biometrics, payment and decision'],
  ], 'We only use your details to process your visa application.') ?>
  <div class="auth-form">
    <span class="eyebrow mb-2"><i class="bi bi-person-plus"></i> Create account</span>
    <h1 class="mb-1">Create your applicant account</h1>
    <p class="text-muted mb-4">Already registered? <a class="fw-semibold" href="<?= e(url('login.php')) ?>">Sign in</a></p>
    <?= errors_summary($errors) ?>
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <?= bot_trap_fields() ?>
      <?= form_input('full_name', 'Full name (as in passport)', '', $errors, 'text', ['required' => true, 'maxlength' => 150, 'autocomplete' => 'name', 'placeholder' => 'e.g. Ayesha Khan']) ?>
      <div class="row g-0 gx-sm-3">
        <div class="col-sm-6"><?= form_input('email', 'Email address', '', $errors, 'email', ['required' => true, 'maxlength' => 190, 'autocomplete' => 'email', 'placeholder' => 'you@example.com']) ?></div>
        <div class="col-sm-6"><?= form_input('phone', 'Mobile number', '', $errors, 'tel', ['required' => true, 'maxlength' => 25, 'autocomplete' => 'tel', 'placeholder' => '+92 300 1234567']) ?></div>
        <div class="col-sm-6"><?= form_input('password', 'Password', '', $errors, 'password', ['required' => true, 'autocomplete' => 'new-password', 'minlength' => 10, 'maxlength' => 72]) ?></div>
        <div class="col-sm-6"><?= form_input('password_confirm', 'Confirm password', '', $errors, 'password', ['required' => true, 'autocomplete' => 'new-password']) ?></div>
      </div>
      <p class="form-text mt-n2 mb-3"><i class="bi bi-info-circle me-1"></i>At least 10 characters, with letters and numbers.</p>
      <div class="form-check mb-4">
        <input class="form-check-input<?= invalid($errors, 'agree') ?>" type="checkbox" value="1" id="agree" name="agree" <?= post('agree') === '1' ? 'checked' : '' ?>>
        <label class="form-check-label small" for="agree">I agree that my personal data and documents are processed to handle my visa application.</label>
        <?= field_error($errors, 'agree') ?>
      </div>
      <button type="submit" class="btn btn-brand btn-lg w-100">Create account</button>
    </form>
  </div>
</div>
<?php render_footer();
