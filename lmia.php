<?php
/**
 * LMIA link (lmia.php?t=<token>): after typing the passport number the applicant sees their
 * LMIA status, LMIA number, the note from the visa office and can download the LMIA letter.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$app = link_application('lmia') ?? render_invalid_link('lmia');
$error = handle_link_unlock($app, 'lmia');
if (!link_unlocked($app, 'lmia')) {
    render_link_gate($app, 'lmia', $error);
    exit;
}
$self = ['t' => (string) $app['lmia_token']];

if (query('download') === '1') {
    if (empty($app['lmia_doc_stored_name'])) {
        abort(404);
    }
    audit_log('lmia.letter_downloaded', 'application', (int) $app['id'], ['via' => 'lmia link'], 'audit', (int) $app['user_id']);
    stream_stored_file('lmia', $app['lmia_doc_stored_name'], $app['lmia_doc_mime'], $app['lmia_doc_original_name']);
}

render_header('LMIA ' . $app['reference_no']);
?>
<div class="row justify-content-center">
<div class="col-lg-9 col-xl-8">
  <div class="page-head">
    <div>
      <span class="eyebrow"><i class="bi bi-briefcase"></i> LMIA</span>
      <h1 class="h2 mt-1 mb-0">Labour Market Impact Assessment</h1>
      <div class="text-muted small mt-1">Application <?= e($app['reference_no']) ?> · <?= e(mask_name((string) ($app['full_name'] ?: $app['applicant_name']))) ?> · <?= e($app['category_name']) ?></div>
    </div>
  </div>
  <?php $lmiaDownloadUrl = url('lmia.php', $self + ['download' => 1]); include APP_ROOT . '/includes/partials/lmia-panel.php'; ?>
  <p class="small text-muted mt-3 mb-0"><i class="bi bi-shield-lock me-1"></i>This link was made for you only – please do not share it.</p>
</div>
</div>
<?php render_footer();
