<?php
/**
 * Offer letter link (offer.php?t=<token>): after typing the passport number the applicant sees
 * only their offer letter – whether it is issued yet, and a download button once it is.
 * Nothing else about the application (payment, biometrics, LMIA) is shown here.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$app = link_application('offer');
if (!$app) {
    // A page left open on a link that was reset keeps polling – answer quietly.
    if (query('partial') === '1') {
        http_response_code(404);
        exit;
    }
    render_invalid_link('offer');
}
$error = handle_link_unlock($app, 'offer');
if (!link_unlocked($app, 'offer')) {
    if (query('partial') === '1') {
        http_response_code(403);
        exit;
    }
    render_link_gate($app, 'offer', $error);
    exit;
}
$self = ['t' => (string) $app['offer_token']];
$downloadUrl = static fn (int $id): string => url('offer.php', $self + ['offer' => $id]);

if (query_int('offer')) {
    $offer = db_one('SELECT * FROM offer_letters WHERE id = ? AND application_id = ? AND is_published = 1', [query_int('offer'), $app['id']]);
    if (!$offer) {
        abort(404);
    }
    audit_log('offer.downloaded', 'offer_letter', (int) $offer['id'], ['application_id' => (int) $app['id'], 'via' => 'offer letter link'], 'audit', (int) $app['user_id']);
    stream_stored_file('offers', $offer['stored_name'], $offer['mime_type'], $offer['original_name']);
}

// Live refresh: app.js fetches only the panel, so a newly published letter appears by itself.
if (query('partial') === '1') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo offer_panel_html($app, $downloadUrl);
    exit;
}

render_header('Offer letter ' . $app['reference_no']);
?>
<div class="row justify-content-center">
<div class="col-lg-9 col-xl-8">
  <div class="page-head">
    <div>
      <span class="eyebrow"><i class="bi bi-file-earmark-text"></i> Offer letter</span>
      <h1 class="h2 mt-1 mb-0">Your offer letter</h1>
      <div class="text-muted small mt-1">Application <?= e($app['reference_no']) ?> · <?= e(mask_name((string) ($app['full_name'] ?: $app['applicant_name']))) ?> · <?= e($app['category_name']) ?></div>
    </div>
  </div>
  <div class="card shadow-sm"><div class="card-body p-3 p-md-4">
    <div data-track-panel data-track-src="<?= e(url('offer.php', $self + ['partial' => 1])) ?>">
      <?= offer_panel_html($app, $downloadUrl) ?>
    </div>
    <div class="track-live small text-muted mt-2"><span class="live-dot" aria-hidden="true"></span> Live – this page updates automatically. <span data-track-checked></span></div>
  </div></div>
  <p class="small text-muted mt-3 mb-0"><i class="bi bi-shield-lock me-1"></i>This link was made for you only – please do not share it.</p>
</div>
</div>
<?php render_footer();
