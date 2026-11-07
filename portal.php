<?php
/**
 * Shared links (portal.php?p=<purpose>&k=<key>): the SAME link is sent to many applicants.
 * Each one types their own passport number, then – for their own application only – can:
 *   passport   upload the passport bio-data page
 *   biometrics scan 10 fingerprints and a face photo
 *   offer      see only their offer letter: issued or not, and the download
 *   lmia       see the LMIA status and download the LMIA letter
 *   payment    see the bank details and upload the payment screenshot
 *   medical    see / download the medical report (staff upload) or upload their own
 *   visa       see and download the visa staff uploaded (nothing to upload)
 * No account sign-in is needed. Staff copy the links from admin/shared-links.php.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

const LINK_UPLOADS_PER_HOUR = 40;

$purpose = shared_link_purpose() ?? render_invalid_shared_link();
$self = ['p' => $purpose, 'k' => query('k')];
[$title, $icon] = SHARED_LINKS[$purpose];

$gateError = handle_shared_unlock($purpose);
if (is_post() && post('action') === 'forget_shared') {
    shared_link_forget();
    redirect('portal.php', $self);
}
$app = shared_link_application($purpose);
// Live refresh of the offer and visa pages: app.js fetches only the panel (403 = passport needed again).
if (query('partial') === '1') {
    if (!$app || !in_array($purpose, ['offer', 'visa'], true)) {
        http_response_code(403);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    if ($purpose === 'offer') {
        echo offer_panel_html($app, static fn (int $id): string => url('portal.php', $self + ['offer' => $id]));
    } else {
        [, , $vLabel, $vColor, $vDetail, $vDoc] = visa_state($app);
        echo received_document_panel_html($vLabel, $vColor, $vDetail, $vDoc, static fn (int $id): string => url('portal.php', $self + ['file' => $id]));
    }
    exit;
}
if (!$app || $gateError !== null) {
    render_shared_gate($purpose, $gateError);
    exit;
}
$appId = (int) $app['id'];
$applicantId = (int) $app['user_id'];
$errors = [];

// ---- Files of this application only ----
if ($purpose === 'biometrics' && query_int('img')) {
    $c = db_one("SELECT * FROM biometric_captures WHERE id = ? AND application_id = ? AND status <> 'superseded'", [query_int('img'), $appId]);
    if (!$c) {
        abort(404);
    }
    stream_stored_file('biometrics', $c['stored_name'], $c['mime_type'], $c['original_name'], true);
}
if ($purpose === 'offer' && query_int('offer')) {
    $offer = db_one('SELECT * FROM offer_letters WHERE id = ? AND application_id = ? AND is_published = 1', [query_int('offer'), $appId]);
    if (!$offer) {
        abort(404);
    }
    audit_log('offer.downloaded', 'offer_letter', (int) $offer['id'], ['application_id' => $appId, 'via' => 'shared link'], 'audit', $applicantId);
    stream_stored_file('offers', $offer['stored_name'], $offer['mime_type'], $offer['original_name']);
}
// Passport / medical / visa file of this application – only the current one of this link's type.
if (in_array($purpose, ['passport', 'medical', 'visa'], true) && query_int('file')) {
    $doc = db_one("SELECT * FROM documents WHERE id = ? AND application_id = ? AND doc_type = ? AND status <> 'superseded'", [query_int('file'), $appId, $purpose]);
    if (!$doc) {
        abort(404);
    }
    audit_log('file.download', 'document', (int) $doc['id'], ['application_id' => $appId, 'doc_type' => $purpose, 'via' => 'shared link'], 'audit', $applicantId);
    stream_stored_file('documents', $doc['stored_name'], $doc['mime_type'], $doc['original_name']);
}
if ($purpose === 'lmia' && query('download') === '1') {
    if (empty($app['lmia_doc_stored_name'])) {
        abort(404);
    }
    audit_log('lmia.letter_downloaded', 'application', $appId, ['via' => 'shared link'], 'audit', $applicantId);
    stream_stored_file('lmia', $app['lmia_doc_stored_name'], $app['lmia_doc_mime'], $app['lmia_doc_original_name']);
}

$fees = db_all('SELECT * FROM fee_types WHERE is_active = 1 AND (visa_category_id IS NULL OR visa_category_id = ?) ORDER BY name', [$app['visa_category_id']]);

// ---- Actions ----
if (is_post()) {
    $action = post('action');
    $tooMany = rl_exceeded('upload_link', 'a:' . $appId, LINK_UPLOADS_PER_HOUR, 3600);
    if ($action === 'upload_document' && in_array($purpose, ['passport', 'medical'], true)) {
        [$ok, $msg] = $tooMany ? [false, 'Too many uploads. Please wait an hour and try again.'] : document_store($app, $purpose, $_FILES['file'] ?? [], $applicantId);
        rl_hit('upload_link', 'a:' . $appId);
        flash($ok ? 'success' : 'error', $ok ? $msg . ' The visa office will check it.' : $msg);
        redirect('portal.php', $self);
    } elseif ($action === 'upload_biometric' && $purpose === 'biometrics') {
        [$ok, $msg] = $tooMany ? [false, 'Too many uploads. Please wait an hour and try again.'] : biometric_store_photo($app, post('slot'), $_FILES['photo'] ?? [], $applicantId);
        rl_hit('upload_link', 'a:' . $appId);
        flash($ok ? 'success' : 'error', $msg);
        redirect('portal.php', $self);
    } elseif ($action === 'submit_biometrics' && $purpose === 'biometrics') {
        [$ok, $msg] = biometric_submit_set($app);
        flash($ok ? 'success' : 'error', $msg);
        redirect('portal.php', $self);
    } elseif ($action === 'set_region' && $purpose === 'payment') {
        $regionId = post_int('region_id');
        if (!is_terminal($app) && array_key_exists($regionId, region_options())) {
            db_update('applications', ['region_id' => $regionId], 'id = ?', [$appId]);
            audit_log('application.region_set', 'application', $appId, ['region_id' => $regionId, 'from' => $app['region_id'], 'via' => 'shared link'], 'audit', $applicantId);
        }
        redirect('portal.php', $self);
    } elseif ($action === 'submit_payment' && $purpose === 'payment') {
        $feeIds = array_map('strval', array_column($fees, 'id'));
        $v = new Validator($_POST);
        $v->required('amount', 'Amount paid')->money('amount', 'Amount paid')
          ->max('transaction_reference', 100, 'Transaction number')
          ->required('paid_on', 'Payment date')->date('paid_on', 'Payment date', date('Y-m-d', strtotime('-1 year')), date('Y-m-d'));
        if ($fees) {
            $v->required('fee_type_id', 'Fee paid')->in('fee_type_id', $feeIds, 'Fee paid');
        } elseif (!preg_match('/^[A-Z]{3}$/', strtoupper(post('currency')))) {
            $v->add('currency', 'Enter the currency, e.g. USD.');
        }
        if ($v->value('amount') !== '' && (float) $v->value('amount') <= 0) {
            $v->add('amount', 'Amount paid must be greater than zero.');
        }
        if (!app_accepts_payments($app)) {
            $v->add('amount', 'Payments cannot be submitted for this application in its current status.');
        }
        if (rl_exceeded('payment_user', (string) $applicantId, 10, 3600) || $tooMany) {
            $v->add('amount', 'Too many payment submissions. Please try again later.');
        }
        $errors = $v->errors();
        if (!$errors) {
            $res = store_upload($_FILES['receipt'] ?? [], 'receipts');
            if (!$res['ok']) {
                $errors['receipt'] = 'Screenshot: ' . $res['error'];
            } else {
                rl_hit('payment_user', (string) $applicantId);
                $fee = null;
                foreach ($fees as $f) {
                    if ((string) $f['id'] === $v->value('fee_type_id')) {
                        $fee = $f;
                    }
                }
                register_shutdown_function(static function () use ($res, &$payId) {
                    if (empty($payId)) {
                        delete_stored_file('receipts', $res['stored_name']);
                    }
                });
                $currency = $fee['currency'] ?? strtoupper(post('currency'));
                $payId = db_insert('payments', [
                    'application_id' => $appId,
                    'fee_type_id' => $fee['id'] ?? null,
                    'region_id' => $app['region_id'] ?: null,
                    'amount' => $v->value('amount'),
                    'currency' => $currency,
                    'payer_name' => mb_substr((string) ($app['full_name'] ?: $app['applicant_name']), 0, 150),
                    'transaction_reference' => $v->value('transaction_reference') !== '' ? $v->value('transaction_reference') : 'Screenshot',
                    'paid_on' => $v->value('paid_on'),
                    'receipt_original_name' => $res['original_name'],
                    'receipt_stored_name' => $res['stored_name'],
                    'receipt_mime' => $res['mime_type'],
                    'receipt_size' => $res['size_bytes'],
                    'receipt_sha256' => $res['sha256'],
                    'status' => 'submitted',
                    'uploaded_by' => $applicantId,
                    'created_at' => now(),
                ]);
                audit_log('payment.receipt_uploaded', 'payment', $payId, ['application_id' => $appId, 'amount' => $v->value('amount'), 'currency' => $currency, 'via' => 'shared link'], 'audit', $applicantId);
                notify_application_staff($app, 'Payment screenshot to verify: ' . $app['reference_no'], 'A payment of ' . $currency . ' ' . $v->value('amount') . ' was uploaded through the payment link and awaits verification.', 'payments.review');
                flash('success', 'Your payment screenshot was uploaded. Payment is NOT confirmed yet: the visa office will check it against the bank record.');
                redirect('portal.php', $self);
            }
        }
    } else {
        abort(400);
    }
}

render_header($title . ' ' . $app['reference_no']);
?>
<div class="row justify-content-center">
<div class="<?= in_array($purpose, ['biometrics', 'payment'], true) ? 'col-xl-10' : 'col-lg-9 col-xl-8' ?>">
  <div class="page-head">
    <div>
      <span class="eyebrow"><i class="bi bi-<?= e($icon) ?>"></i> <?= e($title) ?></span>
      <h1 class="h2 mt-1 mb-0"><?= e(match ($purpose) {
          'passport' => 'Upload your passport',
          'biometrics' => 'Scan your fingerprints',
          'offer' => 'Your offer letter',
          'visa' => 'Your visa',
          'medical' => 'Your medical report',
          'lmia' => 'Labour Market Impact Assessment',
          'payment' => 'Your payment',
          default => 'Upload your medical report',
      }) ?></h1>
      <div class="text-muted small mt-1">Application <?= e($app['reference_no']) ?> · <?= e(mask_name((string) ($app['full_name'] ?: $app['applicant_name']))) ?> · <?= e($app['category_name']) ?></div>
    </div>
    <?= post_button(url('portal.php', $self), ['action' => 'forget_shared'], 'Not you? Use another passport', 'btn btn-sm btn-outline-secondary') ?>
  </div>

<?php if ($purpose === 'passport' || $purpose === 'medical'): ?>
  <?php $doc = current_document($appId, $purpose); [$docLabel, $docColor, $docDetail] = document_state($doc); $canUpload = app_accepts_documents($app) && (!$doc || $doc['status'] !== 'accepted'); ?>
  <div class="card shadow-sm"><div class="card-body p-4">
    <div class="track-tile tone-<?= e($docColor) ?> mb-3">
      <div class="small text-muted"><?= $purpose === 'passport' ? 'Passport bio-data page' : 'Medical examination report' ?></div>
      <div class="track-status fs-4 text-<?= e($docColor) ?>-emphasis"><?= e($docLabel) ?></div>
      <div class="small text-muted mt-1"><?= e($docDetail) ?></div>
      <?php if ($doc): ?><div class="track-time">Uploaded <?= e(fmt_date($doc['created_at'], true)) ?></div><?php endif; ?>
    </div>
    <?php if ($doc): ?>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border rounded-3 p-3 mb-3">
        <div><div class="fw-semibold"><?= e($doc['original_name']) ?></div><div class="small text-muted"><?= e(fmt_bytes((int) $doc['size_bytes'])) ?></div></div>
        <a class="btn btn-outline-primary" href="<?= e(url('portal.php', $self + ['file' => $doc['id']])) ?>"><i class="bi bi-download me-1"></i>Download</a>
      </div>
    <?php endif; ?>
    <?php if ($canUpload): ?>
      <form method="post" action="<?= e(url('portal.php', $self)) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_document">
        <label class="form-label" for="doc_file"><?= $doc ? 'Upload a new file' : ($purpose === 'passport' ? 'Photo or scan of the passport page with your photo' : 'Your medical examination report') ?> (PDF, JPG or PNG, max <?= upload_max_mb() ?> MB)</label>
        <input class="form-control mb-3" type="file" id="doc_file" name="file" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" data-max-mb="<?= upload_max_mb() ?>">
        <button class="btn btn-brand w-100" type="submit"><i class="bi bi-upload me-1"></i>Upload</button>
      </form>
    <?php elseif ($doc && $doc['status'] === 'accepted'): ?>
      <div class="alert alert-success small mb-0"><i class="bi bi-check-circle me-1"></i>Accepted by the visa office – nothing more to do.</div>
    <?php else: ?>
      <div class="alert alert-secondary small mb-0">This application no longer accepts documents.</div>
    <?php endif; ?>
  </div></div>

<?php elseif ($purpose === 'biometrics'): ?>
  <?php if (!empty($app['biometrics_done_at'])): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Your biometrics are complete – nothing more to do.</div>
  <?php endif; ?>
  <?php
  $bioPostUrl = url('portal.php', $self);
  $bioHidden = [];
  $bioImgUrl = static fn (int $cid): string => url('portal.php', $self + ['img' => $cid]);
  include APP_ROOT . '/includes/partials/biometric-capture.php';
  ?>

<?php elseif ($purpose === 'visa'): ?>
  <?php [, , $vLabel, $vColor, $vDetail, $vDoc] = visa_state($app); ?>
  <div class="card shadow-sm"><div class="card-body p-3 p-md-4">
    <div data-track-panel data-track-src="<?= e(url('portal.php', $self + ['partial' => 1])) ?>">
      <?= received_document_panel_html($vLabel, $vColor, $vDetail, $vDoc, static fn (int $id): string => url('portal.php', $self + ['file' => $id])) ?>
    </div>
    <div class="track-live small text-muted mt-2"><span class="live-dot" aria-hidden="true"></span> Live – this page updates automatically. <span data-track-checked></span></div>
  </div></div>

<?php elseif ($purpose === 'offer'): ?>
  <div class="card shadow-sm"><div class="card-body p-3 p-md-4">
    <div data-track-panel data-track-src="<?= e(url('portal.php', $self + ['partial' => 1])) ?>">
      <?= offer_panel_html($app, static fn (int $id): string => url('portal.php', $self + ['offer' => $id])) ?>
    </div>
    <div class="track-live small text-muted mt-2"><span class="live-dot" aria-hidden="true"></span> Live – this page updates automatically. <span data-track-checked></span></div>
  </div></div>

<?php elseif ($purpose === 'lmia'): ?>
  <?php $lmiaDownloadUrl = url('portal.php', $self + ['download' => 1]); include APP_ROOT . '/includes/partials/lmia-panel.php'; ?>

<?php elseif ($purpose === 'payment'): ?>
  <?php
  $regions = region_options();
  $instructions = payment_instructions_for($app['region_id'] ? (int) $app['region_id'] : null);
  $payments = db_all('SELECT p.*, f.name AS fee_name FROM payments p LEFT JOIN fee_types f ON f.id = p.fee_type_id WHERE p.application_id = ? ORDER BY p.created_at DESC', [$appId]);
  ?>
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card shadow-sm h-100"><div class="card-body">
        <h2 class="h5">Where to pay</h2>
        <?php if ($regions && !is_terminal($app)): ?>
          <form method="post" action="<?= e(url('portal.php', $self)) ?>" class="region-picker mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_region">
            <label class="form-label" for="region_id"><i class="bi bi-geo-alt me-1"></i>Your region – where you will pay from</label>
            <div class="d-flex gap-2">
              <select class="form-select" id="region_id" name="region_id" required>
                <option value="">Choose your region…</option>
                <?php foreach ($regions as $rid => $rname): ?><option value="<?= (int) $rid ?>"<?= (int) $app['region_id'] === (int) $rid ? ' selected' : '' ?>><?= e($rname) ?></option><?php endforeach; ?>
              </select>
              <button class="btn btn-outline-primary text-nowrap" type="submit">Show accounts</button>
            </div>
          </form>
        <?php endif; ?>
        <?php include APP_ROOT . '/includes/partials/payment-instructions.php'; ?>
      </div></div>
    </div>
    <div class="col-lg-6">
      <div class="card shadow-sm mb-3"><div class="card-body">
        <h2 class="h5">Upload your payment screenshot</h2>
        <?php if (!app_accepts_payments($app)): ?>
          <p class="text-muted mb-0">This application no longer accepts payments.</p>
        <?php else: ?>
          <?= errors_summary($errors) ?>
          <form method="post" action="<?= e(url('portal.php', $self)) ?>" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="submit_payment">
            <?php if ($fees): ?>
              <?php $feeOpts = []; foreach ($fees as $f) { $feeOpts[$f['id']] = $f['name'] . ' (' . $f['currency'] . ' ' . number_format((float) $f['amount'], 2) . ')'; } ?>
              <?= form_select('fee_type_id', 'Fee paid', $feeOpts, post('fee_type_id') ?: (count($fees) === 1 ? (string) $fees[0]['id'] : ''), $errors, ['required' => true]) ?>
            <?php endif; ?>
            <div class="row">
              <div class="col-sm-6"><?= form_input('amount', 'Amount paid', post('amount'), $errors, 'text', ['required' => true, 'inputmode' => 'decimal', 'placeholder' => '0.00']) ?></div>
              <?php if (!$fees): ?>
                <div class="col-sm-6"><?= form_input('currency', 'Currency', post('currency') ?: 'USD', $errors, 'text', ['required' => true, 'maxlength' => 3, 'autocapitalize' => 'characters']) ?></div>
              <?php endif; ?>
              <div class="col-sm-6"><?= form_input('paid_on', 'Payment date', post('paid_on') ?: date('Y-m-d'), $errors, 'date', ['required' => true, 'max' => date('Y-m-d')]) ?></div>
              <div class="col-sm-6"><?= form_input('transaction_reference', 'Transaction number (optional)', post('transaction_reference'), $errors, 'text', ['maxlength' => 100]) ?></div>
            </div>
            <div class="mb-3">
              <label class="form-label" for="receipt">Payment screenshot or receipt (JPG, PNG or PDF, max <?= upload_max_mb() ?> MB) <span class="text-danger">*</span></label>
              <input class="form-control<?= invalid($errors, 'receipt') ?>" type="file" id="receipt" name="receipt" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" data-max-mb="<?= upload_max_mb() ?>">
              <?= field_error($errors, 'receipt') ?>
            </div>
            <p class="small text-muted">Uploading a screenshot does not confirm your payment. The visa office will check it against the bank record.</p>
            <button class="btn btn-brand w-100" type="submit"><i class="bi bi-upload me-1"></i>Upload screenshot</button>
          </form>
        <?php endif; ?>
      </div></div>
      <div class="card shadow-sm"><div class="card-body">
        <h2 class="h5">Your payments</h2>
        <?php if (!$payments): ?><?= empty_state('No payment screenshots uploaded yet.') ?><?php endif; ?>
        <?php foreach ($payments as $p): ?>
          <div class="border-bottom py-2">
            <div class="d-flex justify-content-between flex-wrap gap-1">
              <strong><?= fmt_money($p['amount'], $p['currency']) ?></strong>
              <?= badge(PAY_STATUS, $p['status']) ?>
            </div>
            <div class="small text-muted"><?= e($p['fee_name'] ?? 'Payment') ?> · paid <?= e(fmt_date($p['paid_on'])) ?> · uploaded <?= e(fmt_date($p['created_at'], true)) ?></div>
            <?php if ($p['status'] === 'rejected' && $p['review_note']): ?><div class="small text-danger"><?= e($p['review_note']) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div></div>
    </div>
  </div>
<?php endif; ?>

  <p class="small text-muted mt-3 mb-0"><i class="bi bi-shield-lock me-1"></i>You are seeing your own application only. Close this page when you are done, especially on a shared phone.</p>
</div>
</div>
<?php render_footer();
