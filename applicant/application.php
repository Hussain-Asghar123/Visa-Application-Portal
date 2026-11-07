<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_applicant();
$app = applicant_application(query_int('id') ?: post_int('id'), $user);
$appId = (int) $app['id'];
$tabs = ['overview' => 'Overview', 'documents' => 'Documents', 'payments' => 'Payments', 'biometrics' => 'Biometrics', 'offer' => 'Offer letter', 'travel' => 'Travel', 'cancel' => 'Cancel'];
$tab = array_key_exists(query('tab'), $tabs) ? query('tab') : 'overview';
$errors = [];

// ---------------------------------------------------------------------
// Actions (all re-check ownership via applicant_application above)
// ---------------------------------------------------------------------
if (is_post()) {
    $action = post('action');

    if ($action === 'upload_document') {
        $return = post('return') === 'apply' && $app['status'] === 'draft' ? 'apply' : 'application';
        $back = static fn () => $return === 'apply'
            ? redirect('applicant/apply.php', ['id' => $appId, 'step' => 4])
            : redirect('applicant/application.php', ['id' => $appId, 'tab' => 'documents']);
        $docType = post('doc_type');
        $req = null;
        foreach (requirements_for_category((int) $app['visa_category_id']) as $r) {
            if ($r['doc_type'] === $docType) {
                $req = $r;
            }
        }
        if (!app_accepts_documents($app)) {
            flash('error', 'Documents can no longer be changed for this application.');
            $back();
        }
        if (!$req) {
            flash('error', 'Unknown document type.');
            $back();
        }
        if (rl_exceeded('upload_user', (string) $user['id'], 40, 3600)) {
            flash('error', 'You have uploaded many files recently. Please wait a while and try again.');
            $back();
        }
        $current = db_one("SELECT * FROM documents WHERE application_id = ? AND doc_type = ? AND status <> 'superseded' ORDER BY id DESC LIMIT 1", [$appId, $docType]);
        if ($current && $current['status'] === 'accepted') {
            flash('error', 'This document has already been accepted. Contact support if it needs to be changed.');
            $back();
        }
        $res = store_upload($_FILES['file'] ?? [], 'documents');
        rl_hit('upload_user', (string) $user['id']);
        if (!$res['ok']) {
            flash('error', $req['name'] . ': ' . $res['error']);
            $back();
        }
        // If the database write fails, remove the stored file so no orphan remains.
        register_shutdown_function(static function () use ($res, &$docId) {
            if (empty($docId)) {
                delete_stored_file('documents', $res['stored_name']);
            }
        });
        $docId = db_transaction(static function () use ($appId, $docType, $req, $res, $user, $current) {
            db_run("UPDATE documents SET status = 'superseded' WHERE application_id = ? AND doc_type = ? AND status <> 'superseded'", [$appId, $docType]);
            return db_insert('documents', [
                'application_id' => $appId,
                'requirement_id' => $req['id'],
                'doc_type' => $docType,
                'original_name' => $res['original_name'],
                'stored_name' => $res['stored_name'],
                'mime_type' => $res['mime_type'],
                'size_bytes' => $res['size_bytes'],
                'sha256' => $res['sha256'],
                'status' => 'pending',
                'supersedes_id' => $current['id'] ?? null,
                'uploaded_by' => $user['id'],
                'created_at' => now(),
            ]);
        });
        audit_log($current ? 'document.resubmitted' : 'document.uploaded', 'document', $docId, ['application_id' => $appId, 'doc_type' => $docType]);
        if ($app['status'] !== 'draft') {
            notify_application_staff($app, 'Document ' . ($current ? 'resubmitted' : 'uploaded') . ': ' . $app['reference_no'], $req['name'] . ' was ' . ($current ? 'resubmitted' : 'uploaded') . ' and needs review.', 'documents.review');
        }
        flash('success', $req['name'] . ' uploaded successfully.' . ($app['status'] !== 'draft' ? ' It will be reviewed by our team.' : ''));
        $back();
    }

    if ($action === 'set_region') {
        $regionId = post_int('region_id');
        if (is_terminal($app)) {
            flash('error', 'This application is closed.');
        } elseif (!array_key_exists($regionId, region_options())) {
            flash('error', 'Please choose a valid region.');
        } else {
            db_update('applications', ['region_id' => $regionId], 'id = ?', [$appId]);
            audit_log('application.region_set', 'application', $appId, ['region_id' => $regionId, 'from' => $app['region_id']]);
            flash('success', 'Region saved. The payment accounts for ' . region_name($regionId) . ' are shown below.');
        }
        redirect('applicant/application.php', ['id' => $appId, 'tab' => 'payments']);
    }

    if ($action === 'submit_payment') {
        $fees = db_all('SELECT * FROM fee_types WHERE is_active = 1 AND (visa_category_id IS NULL OR visa_category_id = ?)', [$app['visa_category_id']]);
        $feeIds = array_map('strval', array_column($fees, 'id'));
        $v = new Validator($_POST);
        $v->required('fee_type_id', 'Fee type')->in('fee_type_id', $feeIds, 'Fee type')
          ->required('amount', 'Amount paid')->money('amount', 'Amount paid')
          ->required('payer_name', 'Payer name')->max('payer_name', 150, 'Payer name')
          ->required('transaction_reference', 'Transaction reference')->max('transaction_reference', 100, 'Transaction reference')
          ->required('paid_on', 'Payment date')->date('paid_on', 'Payment date', date('Y-m-d', strtotime('-1 year')), date('Y-m-d'));
        if ($v->value('amount') !== '' && (float) $v->value('amount') <= 0) {
            $v->add('amount', 'Amount paid must be greater than zero.');
        }
        if (!app_accepts_payments($app)) {
            $v->add('fee_type_id', 'Payments cannot be submitted for this application in its current status.');
        }
        if (region_options() && empty($app['region_id'])) {
            $v->add('fee_type_id', 'Please choose your region first, so you pay into the correct account.');
        }
        if (rl_exceeded('payment_user', (string) $user['id'], 10, 3600)) {
            $v->add('fee_type_id', 'Too many payment submissions. Please try again later.');
        }
        $errors = $v->errors();
        if (!$errors) {
            $res = store_upload($_FILES['receipt'] ?? [], 'receipts');
            if (!$res['ok']) {
                $errors['receipt'] = 'Receipt: ' . $res['error'];
            } else {
                rl_hit('payment_user', (string) $user['id']);
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
                $payId = db_insert('payments', [
                    'application_id' => $appId,
                    'fee_type_id' => $fee['id'],
                    'region_id' => $app['region_id'] ?: null,
                    'amount' => $v->value('amount'),
                    'currency' => $fee['currency'],
                    'payer_name' => $v->value('payer_name'),
                    'transaction_reference' => $v->value('transaction_reference'),
                    'paid_on' => $v->value('paid_on'),
                    'receipt_original_name' => $res['original_name'],
                    'receipt_stored_name' => $res['stored_name'],
                    'receipt_mime' => $res['mime_type'],
                    'receipt_size' => $res['size_bytes'],
                    'receipt_sha256' => $res['sha256'],
                    'status' => 'submitted',
                    'uploaded_by' => $user['id'],
                    'created_at' => now(),
                ]);
                audit_log('payment.receipt_uploaded', 'payment', $payId, ['application_id' => $appId, 'amount' => $v->value('amount'), 'currency' => $fee['currency']]);
                notify_application_staff($app, 'Payment receipt to verify: ' . $app['reference_no'], 'A receipt of ' . $fee['currency'] . ' ' . $v->value('amount') . ' (' . $fee['name'] . ') was uploaded and awaits verification.', 'payments.review');
                flash('success', 'Your receipt was uploaded. Payment is NOT confirmed yet: our finance team will verify it and notify you.');
                redirect('applicant/application.php', ['id' => $appId, 'tab' => 'payments']);
            }
        }
        $tab = 'payments';
    }

    if ($action === 'request_cancellation') {
        $pending = db_value("SELECT 1 FROM cancellation_requests WHERE application_id = ? AND status = 'pending'", [$appId]);
        $v = new Validator($_POST);
        $v->required('reason', 'Reason')->min('reason', 10, 'Reason')->max('reason', 1000, 'Reason');
        if ($app['status'] === 'draft' || is_terminal($app) || $pending) {
            $v->add('reason', 'A cancellation request cannot be made for this application right now.');
        }
        $errors = $v->errors();
        if (!$errors) {
            $cid = db_insert('cancellation_requests', [
                'application_id' => $appId,
                'requested_by' => $user['id'],
                'reason' => $v->value('reason'),
                'previous_status' => $app['status'],
                'status' => 'pending',
                'created_at' => now(),
            ]);
            add_history_note($appId, $app['status'], 'Applicant requested cancellation', (int) $user['id']);
            audit_log('cancellation.requested', 'cancellation_request', $cid, ['application_id' => $appId]);
            notify_application_staff($app, 'Cancellation requested: ' . $app['reference_no'], 'The applicant asked to cancel this application.', 'cancellations.manage');
            flash('success', 'Your cancellation request was sent. Processing continues until it is approved.');
            redirect('applicant/application.php', ['id' => $appId, 'tab' => 'cancel']);
        }
        $tab = 'cancel';
    }

    if ($action === 'withdraw_cancellation') {
        $n = db_run("UPDATE cancellation_requests SET status = 'withdrawn', reviewed_at = ? WHERE application_id = ? AND status = 'pending'", [now(), $appId])->rowCount();
        if ($n) {
            add_history_note($appId, $app['status'], 'Applicant withdrew the cancellation request', (int) $user['id']);
            audit_log('cancellation.withdrawn', 'application', $appId);
            flash('success', 'Your cancellation request was withdrawn.');
        }
        redirect('applicant/application.php', ['id' => $appId, 'tab' => 'cancel']);
    }

    if ($action === 'upload_biometric') {
        [$ok, $msg] = biometric_store_photo($app, post('slot'), $_FILES['photo'] ?? [], (int) $user['id']);
        flash($ok ? 'success' : 'error', $msg);
        redirect('applicant/application.php', ['id' => $appId, 'tab' => 'biometrics']);
    }

    if ($action === 'submit_biometrics') {
        [$ok, $msg] = biometric_submit_set($app);
        flash($ok ? 'success' : 'error', $msg);
        redirect('applicant/application.php', ['id' => $appId, 'tab' => 'biometrics']);
    }

    if ($action === 'discard_draft') {
        if ($app['status'] === 'draft') {
            change_application_status($app, 'cancelled', 'Draft discarded by applicant', (int) $user['id']);
            flash('success', 'The draft application was discarded.');
        }
        redirect('applicant/applications.php');
    }
}

// ---------------------------------------------------------------------
// Data for the view
// ---------------------------------------------------------------------
$app = find_application($appId);
$checklist = document_checklist($app);
$history = db_all('SELECT * FROM application_status_history WHERE application_id = ? AND visible_to_applicant = 1 ORDER BY created_at DESC, id DESC', [$appId]);
$payments = db_all('SELECT p.*, f.name AS fee_name FROM payments p LEFT JOIN fee_types f ON f.id = p.fee_type_id WHERE p.application_id = ? ORDER BY p.created_at DESC', [$appId]);
$regions = region_options();
$instructions = payment_instructions_for($app['region_id'] ? (int) $app['region_id'] : null);
$fees = db_all('SELECT * FROM fee_types WHERE is_active = 1 AND (visa_category_id IS NULL OR visa_category_id = ?) ORDER BY name', [$app['visa_category_id']]);
$bios = db_all('SELECT * FROM biometric_appointments WHERE application_id = ? ORDER BY appointment_at DESC', [$appId]);
// Applicants only ever see PUBLISHED offer letters.
$offers = db_all('SELECT id, title, notes, original_name, size_bytes, published_at FROM offer_letters WHERE application_id = ? AND is_published = 1 ORDER BY published_at DESC', [$appId]);
$travel = db_one('SELECT * FROM travel_arrangements WHERE application_id = ? AND is_published = 1', [$appId]);
$cancellations = db_all('SELECT * FROM cancellation_requests WHERE application_id = ? ORDER BY created_at DESC', [$appId]);
$pendingCancel = array_filter($cancellations, static fn ($c) => $c['status'] === 'pending');
$missing = missing_mandatory_documents($app);

render_header('Application ' . $app['reference_no'], 'applications');
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Application <?= e($app['reference_no']) ?></h1>
    <div class="text-muted small"><?= e($app['category_name']) ?> · started <?= e(fmt_date($app['created_at'])) ?><?= $app['submitted_at'] ? ' · submitted ' . e(fmt_date($app['submitted_at'])) : '' ?></div>
  </div>
  <div class="text-end">
    <div class="fs-5"><?= status_badge($app['status']) ?></div>
    <?php if ($pendingCancel): ?><div class="small text-muted mt-1">Cancellation requested</div><?php endif; ?>
  </div>
</div>

<?php if ($app['status'] === 'draft'): ?>
  <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span>This application is a draft and has not been submitted.</span>
    <a class="btn btn-sm btn-brand" href="<?= e(url('applicant/apply.php', ['id' => $appId, 'step' => $app['current_step']])) ?>">Continue application</a>
  </div>
<?php elseif ($app['status'] === 'documents_required'): ?>
  <div class="alert alert-warning">Some documents need correction. Open the <a href="<?= e(url('applicant/application.php', ['id' => $appId, 'tab' => 'documents'])) ?>">Documents</a> tab to see what is needed and resubmit.</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
<?php foreach ($tabs as $k => $label): ?>
  <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= e(url('applicant/application.php', ['id' => $appId, 'tab' => $k])) ?>"><?= e($label) ?></a></li>
<?php endforeach; ?>
</ul>

<?php if ($tab === 'overview'): ?>
<?php if ($app['status'] !== 'draft'): ?>
<?php $link = tracking_url($app); ?>
<div class="card shadow-sm mb-3"><div class="card-body">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h2 class="h6 text-uppercase text-muted mb-0"><i class="bi bi-broadcast me-1"></i>Live status</h2>
    <div class="d-flex flex-wrap gap-2">
      <button type="button" class="btn btn-sm btn-outline-primary" data-copy="<?= e($link) ?>"><i class="bi bi-link-45deg me-1"></i>Copy my status link</button>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e($link) ?>" target="_blank" rel="noopener">Open</a>
    </div>
  </div>
  <?= tracking_panel_html($app) ?>
  <p class="small text-muted mt-3 mb-0">Your private status link works without signing in (it asks for your passport number) – keep it to yourself.</p>
</div></div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted">Progress</h2>
      <ul class="milestones mt-3">
      <?php foreach (application_milestones($app) as [$label, $done]): ?>
        <li class="<?= $done ? 'done' : '' ?>"><span class="dot"></span><?= e($label) ?></li>
      <?php endforeach; ?>
      </ul>
    </div></div>
    <div class="card shadow-sm"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted">Processing time</h2>
      <?php if ($app['processing_days_max']): ?>
        <p class="mb-1">Typically <strong><?= (int) $app['processing_days_min'] ?>–<?= (int) $app['processing_days_max'] ?> days</strong> for <?= e($app['category_name']) ?>.</p>
        <?php if ($est = processing_estimate($app)): ?><p class="mb-1 small">Estimated decision window: <strong><?= e($est) ?></strong></p><?php endif; ?>
        <?php if ($app['processing_notes']): ?><p class="small text-muted mb-0"><?= e($app['processing_notes']) ?></p><?php endif; ?>
      <?php else: ?>
        <p class="mb-0 text-muted">No estimate available.</p>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted mb-3">Updates</h2>
      <?php if (!$history): ?><?= empty_state('No updates yet.') ?><?php endif; ?>
      <ul class="list-unstyled mb-0">
      <?php foreach ($history as $h): ?>
        <li class="border-bottom py-2">
          <div class="d-flex justify-content-between flex-wrap gap-1"><span><?= status_badge($h['new_status']) ?></span><span class="small text-muted"><?= e(fmt_date($h['created_at'], true)) ?></span></div>
          <?php if ($h['note']): ?><div class="small mt-1 pre-wrap"><?= e($h['note']) ?></div><?php endif; ?>
        </li>
      <?php endforeach; ?>
      </ul>
    </div></div>
    <div class="card shadow-sm"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted mb-3">Application details</h2>
      <?php include __DIR__ . '/partials/application-summary.php'; ?>
    </div></div>
  </div>
</div>

<?php elseif ($tab === 'documents'): ?>
  <div class="card shadow-sm"><div class="card-body">
    <h2 class="h5">Required-document checklist</h2>
    <?php if ($missing && $app['status'] !== 'draft'): ?><div class="alert alert-warning small">Outstanding: <?= e(implode(', ', $missing)) ?></div><?php endif; ?>
    <?php if (!app_accepts_documents($app)): ?><p class="text-muted small">This application is closed; documents can no longer be changed.</p><?php endif; ?>
    <?php $returnTo = 'application'; include __DIR__ . '/partials/document-checklist.php'; ?>
  </div></div>

<?php elseif ($tab === 'payments'): ?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card shadow-sm h-100"><div class="card-body">
      <h2 class="h5">Payment instructions</h2>
      <?php if ($regions): ?>
        <form method="post" class="region-picker mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="set_region">
          <input type="hidden" name="id" value="<?= $appId ?>">
          <label class="form-label" for="region_id"><i class="bi bi-geo-alt me-1"></i>Your region – where you will pay from</label>
          <div class="d-flex gap-2">
            <select class="form-select" id="region_id" name="region_id" required<?= is_terminal($app) ? ' disabled' : '' ?>>
              <option value="">Choose your region…</option>
              <?php foreach ($regions as $rid => $rname): ?><option value="<?= (int) $rid ?>"<?= (int) $app['region_id'] === (int) $rid ? ' selected' : '' ?>><?= e($rname) ?></option><?php endforeach; ?>
            </select>
            <?php if (!is_terminal($app)): ?><button class="btn btn-outline-primary text-nowrap" type="submit">Show accounts</button><?php endif; ?>
          </div>
        </form>
        <?php if (empty($app['region_id'])): ?>
          <div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>Choose your region to see the bank account you should pay into.</div>
        <?php endif; ?>
      <?php endif; ?>
      <?php include APP_ROOT . '/includes/partials/payment-instructions.php'; ?>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h2 class="h5">Upload payment receipt</h2>
      <?php if (!app_accepts_payments($app)): ?>
        <p class="text-muted mb-0"><?= $app['status'] === 'draft' ? 'Submit your application before uploading payment receipts.' : 'This application no longer accepts payments.' ?></p>
      <?php elseif (!$fees): ?>
        <p class="text-muted mb-0">No fees are currently payable online for this application.</p>
      <?php else: ?>
        <?= errors_summary($errors) ?>
        <form method="post" enctype="multipart/form-data" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="submit_payment">
          <input type="hidden" name="id" value="<?= $appId ?>">
          <?php $feeOpts = []; foreach ($fees as $f) { $feeOpts[$f['id']] = $f['name'] . ' (' . $f['currency'] . ' ' . number_format((float) $f['amount'], 2) . ')'; } ?>
          <?= form_select('fee_type_id', 'Fee paid', $feeOpts, '', $errors, ['required' => true]) ?>
          <div class="row">
            <div class="col-sm-6"><?= form_input('amount', 'Amount paid', '', $errors, 'text', ['required' => true, 'inputmode' => 'decimal', 'placeholder' => '0.00']) ?></div>
            <div class="col-sm-6"><?= form_input('paid_on', 'Payment date', '', $errors, 'date', ['required' => true, 'max' => date('Y-m-d')]) ?></div>
          </div>
          <?= form_input('payer_name', 'Name of payer', $user['full_name'], $errors, 'text', ['required' => true, 'maxlength' => 150]) ?>
          <?= form_input('transaction_reference', 'Bank transaction / receipt number', '', $errors, 'text', ['required' => true, 'maxlength' => 100]) ?>
          <div class="mb-3">
            <label class="form-label" for="receipt">Receipt or screenshot of the deposit (PDF, JPG or PNG, max <?= upload_max_mb() ?> MB) <span class="text-danger">*</span></label>
            <input class="form-control<?= invalid($errors, 'receipt') ?>" type="file" id="receipt" name="receipt" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" data-max-mb="<?= upload_max_mb() ?>">
            <?= field_error($errors, 'receipt') ?>
          </div>
          <p class="small text-muted">Uploading a receipt does not confirm your payment. A finance officer will verify it against the bank record.</p>
          <button class="btn btn-brand" type="submit">Upload receipt</button>
        </form>
      <?php endif; ?>
    </div></div>
    <div class="card shadow-sm"><div class="card-body">
      <h2 class="h5">Your payments</h2>
      <?php if (!$payments): ?><?= empty_state('No payment receipts uploaded yet.') ?><?php endif; ?>
      <?php foreach ($payments as $p): ?>
        <div class="border-bottom py-2">
          <div class="d-flex justify-content-between flex-wrap gap-1">
            <strong><?= fmt_money($p['amount'], $p['currency']) ?></strong>
            <?= badge(PAY_STATUS, $p['status']) ?>
          </div>
          <div class="small text-muted"><?= e($p['fee_name'] ?? 'Fee') ?> · paid <?= e(fmt_date($p['paid_on'])) ?> · ref <?= e($p['transaction_reference']) ?></div>
          <div class="small"><a href="<?= e(url('download.php', ['type' => 'receipt', 'id' => $p['id']])) ?>"><?= e($p['receipt_original_name']) ?></a></div>
          <?php if ($p['review_note']): ?><div class="small mt-1"><strong>Note from finance:</strong> <?= e($p['review_note']) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div></div>
  </div>
</div>

<?php elseif ($tab === 'biometrics'): ?>
  <?php
  $bioPostUrl = url('applicant/application.php');
  $bioHidden = ['id' => $appId];
  $bioImgUrl = static fn (int $cid): string => url('download.php', ['type' => 'biometric', 'id' => $cid, 'inline' => 1]);
  include APP_ROOT . '/includes/partials/biometric-capture.php';
  ?>

  <div class="card shadow-sm"><div class="card-body">
    <h2 class="h5">Biometric appointment</h2>
    <?php if (!$bios): ?>
      <?= empty_state('No biometric appointment has been scheduled yet. We will notify you when one is booked.') ?>
    <?php endif; ?>
    <?php foreach ($bios as $b): ?>
      <div class="border rounded p-3 mb-3">
        <div class="d-flex justify-content-between flex-wrap gap-2">
          <h3 class="h6 mb-1"><?= e(fmt_date($b['appointment_at'], true)) ?></h3>
          <?= badge(BIO_STATUS, $b['status']) ?>
        </div>
        <div><strong><?= e($b['center_name']) ?></strong></div>
        <?php if ($b['center_address']): ?><div class="small text-muted"><?= e($b['center_address']) ?></div><?php endif; ?>
        <?php if ($b['instructions']): ?><div class="small mt-2 pre-wrap"><?= e($b['instructions']) ?></div><?php endif; ?>
        <?php if ($b['decision_note'] && in_array($b['status'], ['approved', 'rejected', 'missed'], true)): ?><div class="small mt-2"><strong>Decision note:</strong> <?= e($b['decision_note']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <p class="small text-muted mb-0">Bring your original passport and appointment details. Contact support if you cannot attend.</p>
  </div></div>

<?php elseif ($tab === 'offer'): ?>
  <div class="card shadow-sm"><div class="card-body">
    <h2 class="h5">Offer letter</h2>
    <?php if (!$offers): ?>
      <div class="alert alert-secondary mb-0"><strong>Pending.</strong> Your offer letter has not been issued yet. You will receive a notification as soon as it is published for this application.</div>
    <?php endif; ?>
    <?php foreach ($offers as $o): ?>
      <div class="border rounded p-3 mb-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <div class="fw-semibold"><?= e($o['title']) ?></div>
          <div class="small text-muted">Issued <?= e(fmt_date($o['published_at'])) ?> · <?= e(fmt_bytes((int) $o['size_bytes'])) ?></div>
          <?php if ($o['notes']): ?><div class="small mt-1"><?= e($o['notes']) ?></div><?php endif; ?>
        </div>
        <a class="btn btn-success" href="<?= e(url('download.php', ['type' => 'offer', 'id' => $o['id']])) ?>">Download offer letter</a>
      </div>
    <?php endforeach; ?>
  </div></div>

<?php elseif ($tab === 'travel'): ?>
  <div class="card shadow-sm"><div class="card-body">
    <h2 class="h5">Travel &amp; accommodation</h2>
    <?php if (!$travel): ?>
      <?= empty_state('Travel and accommodation details have not been published yet.') ?>
    <?php else: ?>
      <div class="row g-3">
        <div class="col-md-6">
          <h3 class="h6 text-uppercase text-muted">Arrival</h3>
          <dl class="detail-list mb-0">
            <dt class="small">Arrival date</dt><dd><?= e(fmt_date($travel['arrival_date'])) ?></dd>
            <dt class="small">Airport</dt><dd><?= e($travel['arrival_airport'] ?: '—') ?></dd>
            <dt class="small">Flight</dt><dd><?= e($travel['flight_details'] ?: '—') ?></dd>
            <dt class="small">Airport pickup</dt><dd><?= (int) $travel['airport_pickup'] ? 'Yes' . ($travel['pickup_details'] ? ' – ' . e($travel['pickup_details']) : '') : 'No' ?></dd>
          </dl>
        </div>
        <div class="col-md-6">
          <h3 class="h6 text-uppercase text-muted">Accommodation</h3>
          <dl class="detail-list mb-0">
            <dt class="small">Name</dt><dd><?= e($travel['accommodation_name'] ?: '—') ?></dd>
            <dt class="small">Address</dt><dd><?= e($travel['accommodation_address'] ?: '—') ?></dd>
            <dt class="small">Contact</dt><dd><?= e($travel['accommodation_contact'] ?: '—') ?></dd>
            <dt class="small">Check-in / out</dt><dd><?= e(fmt_date($travel['checkin_date'])) ?> – <?= e(fmt_date($travel['checkout_date'])) ?></dd>
          </dl>
        </div>
      </div>
      <?php if ($travel['notes']): ?><div class="mt-3 small pre-wrap border-top pt-3"><?= e($travel['notes']) ?></div><?php endif; ?>
      <p class="small text-muted mt-3 mb-0">Published <?= e(fmt_date($travel['published_at'], true)) ?>.</p>
    <?php endif; ?>
  </div></div>

<?php elseif ($tab === 'cancel'): ?>
  <div class="card shadow-sm"><div class="card-body">
    <h2 class="h5">Cancel this application</h2>
    <?php if ($errors && ($app['status'] === 'draft' || is_terminal($app) || $pendingCancel)): ?><?= errors_summary($errors) ?><?php endif; ?>
    <?php if ($app['status'] === 'draft'): ?>
      <p>This application is still a draft. You can discard it; it will be marked as cancelled.</p>
      <?= post_button(url('applicant/application.php'), ['action' => 'discard_draft', 'id' => $appId], 'Discard draft', 'btn btn-outline-danger', 'Discard this draft application? This cannot be undone.') ?>
    <?php elseif (is_terminal($app)): ?>
      <p class="mb-0 text-muted">This application is <?= e(strtolower(status_label($app['status']))) ?> and cannot be cancelled.</p>
    <?php elseif ($pendingCancel): ?>
      <div class="alert alert-info">Your cancellation request is awaiting review. Processing continues until it is approved.</div>
      <?= post_button(url('applicant/application.php'), ['action' => 'withdraw_cancellation', 'id' => $appId], 'Withdraw cancellation request', 'btn btn-outline-secondary', 'Withdraw your cancellation request?') ?>
    <?php else: ?>
      <p class="small text-muted">Submitted applications are cancelled after review by our team. Fees already paid may not be refundable.</p>
      <form method="post" novalidate data-confirm="Send a cancellation request for this application?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="request_cancellation">
        <input type="hidden" name="id" value="<?= $appId ?>">
        <?= form_textarea('reason', 'Reason for cancellation', '', $errors, ['required' => true, 'maxlength' => 1000, 'rows' => 3]) ?>
        <button class="btn btn-danger" type="submit">Request cancellation</button>
      </form>
    <?php endif; ?>
    <?php if ($cancellations): ?>
      <h3 class="h6 mt-4">Previous requests</h3>
      <ul class="list-unstyled small mb-0">
      <?php foreach ($cancellations as $c): ?>
        <li class="border-bottom py-2"><strong><?= e(ucfirst($c['status'])) ?></strong> · <?= e(fmt_date($c['created_at'], true)) ?> – <?= e($c['reason']) ?><?= $c['review_note'] ? '<br><span class="text-muted">Response: ' . e($c['review_note']) . '</span>' : '' ?></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div></div>
<?php endif; ?>

<p class="mt-3 small"><a href="<?= e(url('applicant/tickets.php', ['application_id' => $appId])) ?>">Need help with this application? Contact support</a></p>
<?php render_footer();
