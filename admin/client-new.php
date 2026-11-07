<?php
/**
 * Add a client by passport number. The client does not need a portal account: staff type the
 * passport number and name, optionally add the offer letter, LMIA letter, visa, passport scan and medical
 * report straight away. Each shared link (portal.php) then shows the client only its own thing –
 * e.g. the offer letter link only the offer letter – after they type their passport number.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_staff();
if (!can('applications.review', $user)) {
    security_event('permission.denied', null, null, ['page' => 'client-new']);
    abort(403);
}
$uid = (int) $user['id'];
$errors = [];
$existing = null;

$categories = active_categories();
$catOpts = [];
foreach ($categories as $c) {
    $catOpts[$c['id']] = $c['name'];
}
$lmiaOpts = [];
foreach (LMIA_STATUS as $k => [$l]) {
    $lmiaOpts[$k] = $l;
}

/** True when a file was chosen for this input (an empty file input is not an error). */
function file_chosen(string $field): bool
{
    return isset($_FILES[$field]) && (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

if (is_post()) {
    $_POST['passport_number'] = normalize_passport(post('passport_number'));
    $_POST['email'] = strtolower(post('email'));
    $v = new Validator($_POST);
    $v->required('passport_number', 'Passport number')
      ->required('full_name', 'Full name')->max('full_name', 150, 'Full name')
      ->required('visa_category_id', 'Visa type')->in('visa_category_id', array_map('strval', array_keys($catOpts)), 'Visa type')
      ->max('phone', 40, 'Phone')->max('nationality', 80, 'Nationality')
      ->date('date_of_birth', 'Date of birth', '1900-01-01', date('Y-m-d'))
      ->in('lmia_status', array_keys(LMIA_STATUS), 'LMIA status')
      ->max('lmia_number', 40, 'LMIA number')->max('offer_title', 150, 'Offer letter title');
    if ($v->value('passport_number') !== '' && !preg_match('/^[A-Z0-9]{5,20}$/', $v->value('passport_number'))) {
        $v->add('passport_number', 'Passport number must be 5–20 letters or digits.');
    }
    if ($v->value('email') !== '') {
        $v->email('email', 'Email');
        if (db_value("SELECT 1 FROM users WHERE email = ? AND user_type = 'staff'", [$v->value('email')])) {
            $v->add('email', 'This email belongs to a staff account. Use the client\'s own email or leave it empty.');
        }
    }
    if (!$v->fails() && ($existing = open_application_by_passport($v->value('passport_number')))) {
        $v->add('passport_number', 'A client with this passport number already exists (' . $existing['reference_no'] . '). Open it below instead of adding it again.');
    }
    $errors = $v->errors();

    if (!$errors) {
        $appId = create_client_application([
            'passport_number' => $v->value('passport_number'),
            'full_name' => $v->value('full_name'),
            'visa_category_id' => (int) $v->value('visa_category_id'),
            'email' => $v->value('email'),
            'phone' => $v->value('phone'),
            'date_of_birth' => $v->value('date_of_birth'),
            'nationality' => $v->value('nationality'),
        ], $uid);
        // Staff who only see assigned cases must still be able to open the client they just added.
        if (!staff_can_access_application($user, $appId)) {
            db_run('INSERT IGNORE INTO application_assignments (application_id, staff_user_id, assigned_by, assigned_at) VALUES (?, ?, ?, ?)', [$appId, $uid, $uid, now()]);
        }
        audit_log('application.created_by_staff', 'application', $appId, ['passport' => $v->value('passport_number')]);
        $app = find_application($appId);
        $done = [];
        $failed = [];

        if (!in_array(post('lmia_status'), ['', 'not_started'], true) || post('lmia_number') !== '') {
            db_update('applications', ['lmia_status' => post('lmia_status') ?: 'not_started', 'lmia_number' => nullable(strtoupper(post('lmia_number'))), 'lmia_updated_at' => now()], 'id = ?', [$appId]);
            audit_log('lmia.updated', 'application', $appId, ['from' => 'not_started', 'to' => post('lmia_status')]);
            $done[] = 'LMIA status';
        }
        if (file_chosen('lmia_file')) {
            $res = store_upload($_FILES['lmia_file'], 'lmia', ['pdf', 'jpg', 'jpeg', 'png']);
            if ($res['ok']) {
                db_update('applications', ['lmia_doc_original_name' => $res['original_name'], 'lmia_doc_stored_name' => $res['stored_name'], 'lmia_doc_mime' => $res['mime_type'], 'lmia_doc_size' => $res['size_bytes'], 'lmia_doc_uploaded_at' => now()], 'id = ?', [$appId]);
                audit_log('lmia.letter_uploaded', 'application', $appId, ['replaced' => false]);
                $done[] = 'LMIA letter';
            } else {
                $failed[] = 'LMIA letter: ' . $res['error'];
            }
        }
        if (file_chosen('offer_file') && can('offers.manage', $user)) {
            $res = store_upload($_FILES['offer_file'], 'offers', ['pdf']);
            if ($res['ok']) {
                $publish = post('offer_publish') === '1' && can('offers.publish', $user);
                $oid = db_insert('offer_letters', [
                    'application_id' => $appId,
                    'title' => $v->value('offer_title') !== '' ? $v->value('offer_title') : 'Offer letter',
                    'original_name' => $res['original_name'],
                    'stored_name' => $res['stored_name'],
                    'mime_type' => $res['mime_type'],
                    'size_bytes' => $res['size_bytes'],
                    'sha256' => $res['sha256'],
                    'is_published' => $publish ? 1 : 0,
                    'published_at' => $publish ? now() : null,
                    'published_by' => $publish ? $uid : null,
                    'uploaded_by' => $uid,
                    'created_at' => now(),
                ]);
                audit_log('offer.uploaded', 'offer_letter', $oid, ['application_id' => $appId]);
                if ($publish) {
                    audit_log('offer.published', 'offer_letter', $oid, ['application_id' => $appId]);
                    change_application_status($app, 'offer_issued', 'Your offer letter is available to download.', $uid);
                }
                $done[] = $publish ? 'offer letter (published)' : 'offer letter (unpublished)';
            } else {
                $failed[] = 'Offer letter: ' . $res['error'];
            }
        }
        if (can('documents.review', $user)) {
            foreach (['visa_file' => 'visa', 'passport_file' => 'passport', 'medical_file' => 'medical'] as $field => $docType) {
                if (file_chosen($field)) {
                    [$ok, $msg] = document_store($app, $docType, $_FILES[$field], $uid, true);
                    if ($ok) {
                        $done[] = strtolower(LINK_DOC_TYPES[$docType]);
                    } else {
                        $failed[] = $msg;
                    }
                }
            }
        }

        flash('success', 'Client ' . $v->value('full_name') . ' added (' . $app['reference_no'] . ', passport ' . $v->value('passport_number') . ').'
            . ($done ? ' Added: ' . implode(', ', $done) . '.' : '')
            . ' Send them the link for what they need (offer letter, LMIA, payment …) – they type their passport number on it.');
        if ($failed) {
            flash('error', 'Not uploaded – add these again on this page: ' . implode(' ', $failed));
        }
        redirect(absolute_url('admin/application.php', ['id' => $appId, 'tab' => 'file']) . '#member-file');
    }
}

render_header('Add client', 'shared-links');
?>
<a class="small" href="<?= e(url('admin/shared-links.php')) ?>">&larr; Shared links</a>
<div class="my-2">
  <h1 class="h3 mb-1">Add client by passport number</h1>
  <p class="text-muted mb-0">The client does <strong>not</strong> need to register. They open a shared link, type this passport number and see only that link's thing – for example the offer letter link shows only their offer letter, or "not issued yet".</p>
</div>

<?php if ($existing): ?>
  <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span><i class="bi bi-person-check me-1"></i><?= e($existing['applicant_name']) ?> · <?= e($existing['reference_no']) ?> · passport <?= e($existing['passport_number']) ?> is already on the portal.</span>
    <a class="btn btn-sm btn-brand" href="<?= e(url('admin/application.php', ['id' => $existing['id'], 'tab' => 'file'])) ?>#member-file">Open this client</a>
  </div>
<?php endif; ?>
<?= errors_summary($errors) ?>

<form method="post" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card shadow-sm h-100"><div class="card-body">
        <h2 class="h6 text-uppercase text-muted mb-3">Client</h2>
        <?= form_input('passport_number', 'Passport number', '', $errors, 'text', ['required' => true, 'maxlength' => 20, 'autocapitalize' => 'characters', 'autocomplete' => 'off', 'placeholder' => 'e.g. AB1234567', 'autofocus' => true]) ?>
        <?= form_input('full_name', 'Full name (as in passport)', '', $errors, 'text', ['required' => true, 'maxlength' => 150]) ?>
        <?= form_select('visa_category_id', 'Visa type', $catOpts, count($catOpts) === 1 ? (string) array_key_first($catOpts) : '', $errors, ['required' => true]) ?>
        <div class="row">
          <div class="col-sm-6"><?= form_input('phone', 'Phone / WhatsApp (optional)', '', $errors, 'tel', ['maxlength' => 40]) ?></div>
          <div class="col-sm-6"><?= form_input('email', 'Email (optional)', '', $errors, 'email', ['maxlength' => 190]) ?></div>
          <div class="col-sm-6"><?= form_input('date_of_birth', 'Date of birth (optional)', '', $errors, 'date', ['max' => date('Y-m-d')]) ?></div>
          <div class="col-sm-6"><?= form_input('nationality', 'Nationality (optional)', '', $errors, 'text', ['maxlength' => 80]) ?></div>
        </div>
        <p class="small text-muted mb-0"><i class="bi bi-info-circle me-1"></i>Without an email the client gets no emails – send them the shared links on WhatsApp.</p>
      </div></div>
    </div>
    <div class="col-lg-6">
      <div class="card shadow-sm h-100"><div class="card-body">
        <h2 class="h6 text-uppercase text-muted mb-1">Documents (optional – you can add them later)</h2>
        <p class="small text-muted">PDF, JPG or PNG, max <?= upload_max_mb() ?> MB each.</p>
        <?php if (can('offers.manage', $user)): ?>
          <div class="border rounded-3 p-3 mb-3">
            <label class="form-label fw-semibold" for="offer_file"><i class="bi bi-file-earmark-text me-1"></i>Offer letter (PDF)</label>
            <input class="form-control mb-2" type="file" id="offer_file" name="offer_file" accept=".pdf,application/pdf" data-max-mb="<?= upload_max_mb() ?>">
            <input class="form-control form-control-sm mb-2" name="offer_title" maxlength="150" value="<?= e(old('offer_title', 'Offer letter')) ?>" aria-label="Offer letter title">
            <?php if (can('offers.publish', $user)): ?>
              <div class="form-check small"><input class="form-check-input" type="checkbox" name="offer_publish" value="1" id="offer_publish"<?= !is_post() || post('offer_publish') === '1' ? ' checked' : '' ?>><label class="form-check-label" for="offer_publish">Publish now – the client can download it straight away</label></div>
            <?php else: ?>
              <p class="small text-muted mb-0">It is saved unpublished until someone with the publish permission releases it.</p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="border rounded-3 p-3 mb-3">
          <div class="fw-semibold mb-2"><i class="bi bi-briefcase me-1"></i>LMIA</div>
          <div class="row g-2">
            <div class="col-sm-6"><?= form_select('lmia_status', 'Status', $lmiaOpts, 'not_started', $errors, [], null) ?></div>
            <div class="col-sm-6"><?= form_input('lmia_number', 'LMIA number', '', $errors, 'text', ['maxlength' => 40]) ?></div>
          </div>
          <label class="form-label small" for="lmia_file">LMIA letter</label>
          <input class="form-control" type="file" id="lmia_file" name="lmia_file" accept=".pdf,.jpg,.jpeg,.png" data-max-mb="<?= upload_max_mb() ?>">
        </div>
        <?php if (can('documents.review', $user)): ?>
          <div class="border rounded-3 p-3">
            <div class="fw-semibold mb-2"><i class="bi bi-folder2 me-1"></i>Other documents</div>
            <label class="form-label small" for="visa_file">Visa</label>
            <input class="form-control mb-2" type="file" id="visa_file" name="visa_file" accept=".pdf,.jpg,.jpeg,.png" data-max-mb="<?= upload_max_mb() ?>">
            <label class="form-label small" for="passport_file">Passport bio-data page</label>
            <input class="form-control mb-2" type="file" id="passport_file" name="passport_file" accept=".pdf,.jpg,.jpeg,.png" data-max-mb="<?= upload_max_mb() ?>">
            <label class="form-label small" for="medical_file">Medical examination report</label>
            <input class="form-control" type="file" id="medical_file" name="medical_file" accept=".pdf,.jpg,.jpeg,.png" data-max-mb="<?= upload_max_mb() ?>">
          </div>
        <?php endif; ?>
      </div></div>
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2 mt-3">
    <button class="btn btn-brand" type="submit"><i class="bi bi-person-plus me-1"></i>Add client</button>
    <a class="btn btn-outline-secondary" href="<?= e(url('admin/shared-links.php')) ?>">Cancel</a>
  </div>
</form>
<p class="small text-muted mt-3 mb-0">After adding: mark biometrics done, confirm payment screenshots and change the LMIA status on the client's page. The client sees each change on the matching shared link.</p>
<?php render_footer();
