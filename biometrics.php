<?php
/**
 * Fingerprint link (biometrics.php?t=<token>): staff send it to one applicant, who types their
 * passport number and then scans their 10 fingerprints and face photo – no account sign-in needed.
 * This page shows nothing else about the application.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$app = link_application('biometrics') ?? render_invalid_link('biometrics');
$error = handle_link_unlock($app, 'biometrics');
if (!link_unlocked($app, 'biometrics')) {
    render_link_gate($app, 'biometrics', $error);
    exit;
}
$token = (string) $app['biometric_token'];
$self = ['t' => $token];

// Thumbnails of this application's own photos only.
if (query_int('img')) {
    $c = db_one("SELECT * FROM biometric_captures WHERE id = ? AND application_id = ? AND status <> 'superseded'", [query_int('img'), $app['id']]);
    if (!$c) {
        abort(404);
    }
    stream_stored_file('biometrics', $c['stored_name'], $c['mime_type'], $c['original_name'], true);
}

if (is_post()) {
    $action = post('action');
    if ($action === 'upload_biometric') {
        [$ok, $msg] = biometric_store_photo($app, post('slot'), $_FILES['photo'] ?? [], (int) $app['user_id']);
    } elseif ($action === 'submit_biometrics') {
        [$ok, $msg] = biometric_submit_set($app);
    } else {
        abort(400);
    }
    flash($ok ? 'success' : 'error', $msg);
    redirect('biometrics.php', $self);
}

render_header('Fingerprints ' . $app['reference_no']);
?>
<div class="row justify-content-center">
<div class="col-xl-10">
  <div class="page-head">
    <div>
      <span class="eyebrow"><i class="bi bi-fingerprint"></i> Biometrics</span>
      <h1 class="h2 mt-1 mb-0">Scan your fingerprints</h1>
      <div class="text-muted small mt-1">Application <?= e($app['reference_no']) ?> · <?= e(mask_name((string) ($app['full_name'] ?: $app['applicant_name']))) ?></div>
    </div>
  </div>
  <?php
  $bioPostUrl = url('biometrics.php', $self);
  $bioHidden = [];
  $bioImgUrl = static fn (int $cid): string => url('biometrics.php', $self + ['img' => $cid]);
  include APP_ROOT . '/includes/partials/biometric-capture.php';
  ?>
</div>
</div>
<?php render_footer();
