<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
if (!empty($user['email_verified_at']) || is_staff($user) || !config('security.require_email_verification', true)) {
    redirect(home_url_for($user));
}

if (is_post()) {
    if (rl_exceeded('verify_resend', (string) $user['id'], 3, 3600)) {
        flash('warning', 'You have requested several emails recently. Please wait an hour before trying again, or contact support.');
    } else {
        rl_hit('verify_resend', (string) $user['id']);
        if (send_verification_email($user)) {
            flash('success', 'A new confirmation link has been sent to ' . $user['email'] . '.');
        } else {
            flash('error', 'We could not send the email right now. Please try again later or contact support so they can verify your account manually.');
        }
    }
    redirect('verify-notice.php');
}

render_header('Confirm your email');
?>
<div class="row justify-content-center">
  <div class="col-md-8 col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h1 class="h3">Confirm your email address</h1>
        <p>We sent a confirmation link to <strong><?= e($user['email']) ?></strong>. Open the link in that email to activate your account. It expires after 48 hours.</p>
        <p class="text-muted small">Didn't get it? Check your spam folder, or request a new link below. If email is not arriving, open a request with support or ask the office to verify your account.</p>
        <form method="post" class="d-inline">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-brand">Send a new link</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php render_footer();
