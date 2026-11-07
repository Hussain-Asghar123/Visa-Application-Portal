<?php
/**
 * Account security: 2-step login (authenticator app), recovery codes,
 * sign out other devices, and recent sign-in activity.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();
if (!is_staff($user) && config('security.require_email_verification', false) && empty($user['email_verified_at'])) {
    redirect('verify-notice.php');
}
$uid = (int) $user['id'];
$errors = [];
$showCodes = $_SESSION['_show_recovery_codes'] ?? null;
unset($_SESSION['_show_recovery_codes']);
$mustKeep = is_staff($user) && staff_2fa_required();

if (is_post()) {
    $action = post('action');
    $passwordOk = static fn (): bool => password_verify((string) ($_POST['current_password'] ?? ''), $user['password_hash']);

    if ($action === 'start_2fa' && !user_has_2fa($user)) {
        $_SESSION['2fa_setup_secret'] = totp_generate_secret();
        redirect('account/security.php', ['setup' => 1]);
    }

    if ($action === 'confirm_2fa' && !user_has_2fa($user)) {
        $secret = (string) ($_SESSION['2fa_setup_secret'] ?? '');
        $step = $secret !== '' ? totp_verify($secret, post('code')) : null;
        if ($step === null) {
            $errors['code'] = 'That code did not match. Make sure you added the key exactly and your phone time is automatic, then enter the newest code.';
        } else {
            db_update('users', ['totp_secret' => secret_encrypt($secret), 'totp_enabled_at' => now(), 'totp_last_step' => $step], 'id = ?', [$uid]);
            unset($_SESSION['2fa_setup_secret']);
            $_SESSION['_show_recovery_codes'] = generate_recovery_codes($uid);
            audit_log('2fa.enabled', 'user', $uid, [], 'security');
            notify_user($uid, '2-step login turned on', 'Your account now asks for a code from your authenticator app when you sign in.', 'account/security.php', true);
            flash('success', '2-step login is on. Save your recovery codes below now – they are shown only once.');
            redirect('account/security.php');
        }
    }

    if ($action === 'disable_2fa' && user_has_2fa($user)) {
        $secret = secret_decrypt($user['totp_secret']);
        if ($mustKeep) {
            $errors['current_password'] = 'The Super Admin requires 2-step login for staff accounts, so it cannot be turned off.';
        } elseif (!$passwordOk()) {
            $errors['current_password'] = 'Your password is incorrect.';
            security_event('2fa.disable_wrong_password', 'user', $uid);
        } elseif ($secret === null || totp_verify($secret, post('code')) === null) {
            $errors['code'] = 'The authenticator code is not correct.';
        } else {
            db_update('users', ['totp_secret' => null, 'totp_enabled_at' => null, 'totp_last_step' => null], 'id = ?', [$uid]);
            db_run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$uid]);
            audit_log('2fa.disabled', 'user', $uid, [], 'security');
            notify_user($uid, '2-step login turned off', '2-step login was turned off for your account. If this was not you, contact support immediately.', 'account/security.php', true);
            flash('warning', '2-step login is now off.');
            redirect('account/security.php');
        }
    }

    if ($action === 'new_codes' && user_has_2fa($user)) {
        if (!$passwordOk()) {
            $errors['codes_password'] = 'Your password is incorrect.';
        } else {
            $_SESSION['_show_recovery_codes'] = generate_recovery_codes($uid);
            audit_log('2fa.recovery_codes_regenerated', 'user', $uid, [], 'security');
            flash('success', 'New recovery codes created. Your old codes no longer work.');
            redirect('account/security.php');
        }
    }

    if ($action === 'logout_others') {
        db_run('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$uid]);
        $_SESSION['sv'] = (int) db_value('SELECT session_version FROM users WHERE id = ?', [$uid]);
        session_regenerate_id(true);
        audit_log('sessions.logout_others', 'user', $uid, [], 'security');
        flash('success', 'All other devices have been signed out.');
        redirect('account/security.php');
    }
}

$user = db_one('SELECT * FROM users WHERE id = ?', [$uid]);
$setupSecret = query('setup') === '1' && !user_has_2fa($user) ? (string) ($_SESSION['2fa_setup_secret'] ?? '') : '';
$codesLeft = (int) db_value('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$uid]);
$activity = db_all("SELECT action, ip_address, user_agent, created_at FROM audit_logs WHERE user_id = ? AND action IN ('login.success','login.failed','login.2fa_failed','password.changed','password_reset.completed','2fa.enabled','2fa.disabled','sessions.logout_others','session.browser_mismatch') ORDER BY id DESC LIMIT 12", [$uid]);
$labels = ['login.success' => ['Signed in', 'success'], 'login.failed' => ['Wrong password', 'danger'], 'login.2fa_failed' => ['Wrong 2-step code', 'danger'], 'password.changed' => ['Password changed', 'info'], 'password_reset.completed' => ['Password reset', 'info'], '2fa.enabled' => ['2-step login on', 'success'], '2fa.disabled' => ['2-step login off', 'warning'], 'sessions.logout_others' => ['Other devices signed out', 'info'], 'session.browser_mismatch' => ['Blocked stolen session', 'danger']];

render_header('Account security');
?>
<div class="page-head">
  <div>
    <span class="eyebrow"><i class="bi bi-shield-lock"></i> Account</span>
    <h1 class="h2 mt-1 mb-1">Security</h1>
    <p class="text-muted mb-0">Protect your account with 2-step login and review recent activity.</p>
  </div>
  <a class="btn btn-outline-primary" href="<?= e(url('account/password.php')) ?>"><i class="bi bi-key me-1"></i>Change password</a>
</div>

<?php if ($showCodes): ?>
  <div class="card border-warning mb-4"><div class="card-body">
    <h2 class="h5"><i class="bi bi-life-preserver me-1"></i>Your recovery codes</h2>
    <p class="small">Write these down or take a screenshot and keep them somewhere safe. Each code works <strong>once</strong> if you lose your phone. They will not be shown again.</p>
    <div class="row g-2 mb-2">
      <?php foreach ($showCodes as $c): ?><div class="col-6 col-md-4 col-lg-2"><code class="d-block text-center p-2 border rounded bg-white fs-6 user-select-all"><?= e($c) ?></code></div><?php endforeach; ?>
    </div>
  </div></div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card mb-4"><div class="card-body">
      <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
        <div class="d-flex gap-3 align-items-center">
          <span class="icon-circle <?= user_has_2fa($user) ? 'ic-green' : 'ic-amber' ?>"><i class="bi bi-phone"></i></span>
          <div><h2 class="h5 mb-0">2-step login</h2><div class="small text-muted">A 6-digit code from your phone is needed after your password.</div></div>
        </div>
        <?= user_has_2fa($user) ? badge(['on' => ['On', 'success']], 'on') : badge(['off' => ['Off', 'warning']], 'off') ?>
      </div>
      <?= errors_summary($errors) ?>

      <?php if (user_has_2fa($user)): ?>
        <p class="small mb-3">Turned on <?= e(fmt_date($user['totp_enabled_at'], true)) ?> · <?= $codesLeft ?> recovery code(s) left.</p>
        <div class="row g-3">
          <div class="col-md-6">
            <form method="post" class="border rounded p-3 h-100">
              <?= csrf_field() ?><input type="hidden" name="action" value="new_codes">
              <h3 class="h6">New recovery codes</h3>
              <input class="form-control form-control-sm mb-2<?= invalid($errors, 'codes_password') ?>" type="password" name="current_password" placeholder="Your password" autocomplete="current-password" required aria-label="Your password">
              <button class="btn btn-sm btn-outline-primary" type="submit">Create new codes</button>
            </form>
          </div>
          <div class="col-md-6">
            <?php if ($mustKeep): ?>
              <div class="border rounded p-3 h-100 small text-muted"><i class="bi bi-lock me-1"></i>2-step login is required for staff by the Super Admin and cannot be turned off.</div>
            <?php else: ?>
            <form method="post" class="border rounded p-3 h-100" data-confirm="Turn off 2-step login? Your account will be less secure.">
              <?= csrf_field() ?><input type="hidden" name="action" value="disable_2fa">
              <h3 class="h6">Turn off</h3>
              <input class="form-control form-control-sm mb-2" type="password" name="current_password" placeholder="Your password" autocomplete="current-password" required aria-label="Your password">
              <input class="form-control form-control-sm mb-2" name="code" inputmode="numeric" placeholder="6-digit code" autocomplete="one-time-code" required aria-label="Authenticator code">
              <button class="btn btn-sm btn-outline-danger" type="submit">Turn off 2-step login</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      <?php elseif ($setupSecret !== ''): ?>
        <ol class="small ps-3">
          <li class="mb-2">Install <strong>Google Authenticator</strong> or <strong>Microsoft Authenticator</strong> from the Play Store.</li>
          <li class="mb-2">On this phone, tap the button below – or in the app choose <em>Add → Enter a setup key</em> and type this key (time-based):
            <div class="my-2"><code class="d-inline-block p-2 border rounded bg-white fs-6 user-select-all text-break"><?= e(trim(chunk_split($setupSecret, 4, ' '))) ?></code></div>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(totp_uri($setupSecret, (string) $user['email'])) ?>"><i class="bi bi-box-arrow-up-right me-1"></i>Open in authenticator app</a>
          </li>
          <li>Enter the 6-digit code the app shows to finish:</li>
        </ol>
        <form method="post" class="d-flex flex-wrap gap-2" novalidate>
          <?= csrf_field() ?><input type="hidden" name="action" value="confirm_2fa">
          <input class="form-control form-control-lg text-center fw-bold w-auto<?= invalid($errors, 'code') ?>" name="code" inputmode="numeric" maxlength="7" placeholder="123456" autocomplete="one-time-code" required aria-label="6-digit code">
          <button class="btn btn-brand btn-lg" type="submit">Turn on</button>
        </form>
      <?php else: ?>
        <p class="small">Even if someone steals your password, they cannot sign in without your phone.<?= is_staff($user) ? ' <strong>Strongly recommended for all staff.</strong>' : '' ?></p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="start_2fa"><button class="btn btn-brand" type="submit"><i class="bi bi-shield-check me-1"></i>Set up 2-step login</button></form>
      <?php endif; ?>
    </div></div>

    <div class="card"><div class="card-body">
      <div class="d-flex gap-3 align-items-center mb-2">
        <span class="icon-circle ic-blue"><i class="bi bi-laptop"></i></span>
        <div><h2 class="h5 mb-0">Signed-in devices</h2><div class="small text-muted">Lost a phone or used a shared computer? Sign out everywhere else.</div></div>
      </div>
      <?= post_button(url('account/security.php'), ['action' => 'logout_others'], 'Sign out all other devices', 'btn btn-outline-danger', 'Sign out of every other device and browser?') ?>
    </div></div>
  </div>

  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <h2 class="h5 mb-3">Recent activity</h2>
      <?php if (!$activity): ?><?= empty_state('No activity recorded yet.', 'clock-history') ?><?php endif; ?>
      <ul class="list-unstyled mb-0">
      <?php foreach ($activity as $a): [$l, $c] = $labels[$a['action']] ?? [$a['action'], 'secondary']; ?>
        <li class="border-bottom py-2">
          <div class="d-flex justify-content-between gap-2"><?= badge([$a['action'] => [$l, $c]], $a['action']) ?><span class="small text-muted"><?= e(fmt_date($a['created_at'], true)) ?></span></div>
          <div class="small text-muted mt-1">IP <?= e($a['ip_address']) ?> · <?= e(str_limit($a['user_agent'], 60)) ?></div>
        </li>
      <?php endforeach; ?>
      </ul>
      <p class="small text-muted mt-3 mb-0">Don't recognise something? Change your password and sign out all other devices.</p>
    </div></div>
  </div>
</div>
<?php render_footer();
