<?php
/**
 * Second sign-in step: 6-digit code from the authenticator app, or a one-time recovery code.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$pending = $_SESSION['2fa_pending'] ?? null;
if (!is_array($pending) || empty($pending['uid']) || time() - (int) $pending['at'] > 300) {
    unset($_SESSION['2fa_pending']);
    flash('info', 'Please sign in again.');
    redirect('login.php');
}
$uid = (int) $pending['uid'];
$error = null;

if (is_post()) {
    $code = post('code');
    if (rl_exceeded('2fa', (string) $uid, 5, 600)) {
        security_event('login.2fa_throttled', 'user', $uid, [], $uid);
        unset($_SESSION['2fa_pending']);
        flash('error', 'Too many wrong codes. Please wait 10 minutes and sign in again.');
        redirect('login.php');
    }
    $user = db_one('SELECT * FROM users WHERE id = ?', [$uid]);
    if (!$user || $user['status'] !== 'active' || (int) $user['session_version'] !== (int) $pending['sv'] || !user_has_2fa($user)) {
        unset($_SESSION['2fa_pending']);
        redirect('login.php');
    }
    $ok = false;
    $method = 'app';
    $secret = secret_decrypt($user['totp_secret']);
    if ($secret !== null && preg_match('/^\s*\d{3}\s?\d{3}\s*$/', $code)) {
        $step = totp_verify($secret, $code, $user['totp_last_step'] !== null ? (int) $user['totp_last_step'] : null);
        if ($step !== null) {
            db_run('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $uid]);
            $ok = true;
        }
    } elseif ($code !== '' && use_recovery_code($uid, $code)) {
        $ok = true;
        $method = 'recovery code';
        $left = (int) db_value('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$uid]);
        notify_user($uid, 'A recovery code was used', 'One of your 2-step login recovery codes was used to sign in. ' . $left . ' code(s) left. Create new codes under Account > Security if you are running low.', 'account/security.php', true);
    }
    if ($ok) {
        unset($_SESSION['2fa_pending']);
        rl_clear('2fa', (string) $uid);
        login_user($user);
        audit_log('login.success', 'user', $uid, ['second_step' => $method], 'security', $uid);
        $intended = (string) ($_SESSION['intended'] ?? '');
        unset($_SESSION['intended']);
        $area = $user['user_type'] === 'staff' ? '/admin/' : '/applicant/';
        if ($intended !== '' && str_starts_with($intended, base_path() . $area) && !str_contains($intended, '//') && !preg_match('/[\r\n]/', $intended)) {
            header('Location: ' . $intended, true, 303);
            exit;
        }
        redirect(home_url_for($user));
    }
    rl_hit('2fa', (string) $uid);
    security_event('login.2fa_failed', 'user', $uid, [], $uid);
    $error = 'That code is not correct. Check the time on your phone is set automatically and try the newest code.';
}

render_header('2-step verification', 'login');
?>
<div class="auth-wrap narrow-form">
  <?= auth_side('One more step', [
      ['phone', 'Open your authenticator app (Google Authenticator, Microsoft Authenticator…)'],
      ['123', 'Enter the 6-digit code shown for this portal'],
      ['life-preserver', 'Lost your phone? Use one of your recovery codes'],
  ], '2-step login keeps your account safe even if someone learns your password.') ?>
  <div class="auth-form">
    <span class="eyebrow mb-2"><i class="bi bi-shield-lock"></i> 2-step verification</span>
    <h1 class="mb-1">Enter your security code</h1>
    <p class="text-muted mb-4">Your password was correct. Now enter the 6-digit code from your authenticator app.</p>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="code">6-digit code or recovery code</label>
        <input class="form-control form-control-lg text-center fw-bold" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus placeholder="123 456">
      </div>
      <button type="submit" class="btn btn-brand btn-lg w-100">Verify and sign in</button>
    </form>
    <p class="small text-muted mt-3 mb-0"><a href="<?= e(url('login.php')) ?>">Cancel and go back</a> · Lost access to your phone and codes? Contact the office to reset 2-step login.</p>
  </div>
</div>
<?php render_footer();
