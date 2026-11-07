<?php
/**
 * Multi-step visa application form (drafts only).
 * Steps: 1 Personal · 2 Passport · 3 Travel · 4 Documents · 5 Review & submit
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_applicant();
$app = applicant_application(query_int('id') ?: post_int('id'), $user);

if ($app['status'] !== 'draft') {
    flash('info', 'This application has been submitted and can no longer be edited. You can still upload or replace documents.');
    redirect('applicant/application.php', ['id' => $app['id']]);
}

const STEPS = [1 => 'Personal', 2 => 'Passport', 3 => 'Travel', 4 => 'Documents', 5 => 'Review & submit'];
const MARITAL = ['single' => 'Single', 'married' => 'Married', 'divorced' => 'Divorced', 'widowed' => 'Widowed', 'other' => 'Other'];
const GENDERS = ['female' => 'Female', 'male' => 'Male', 'other' => 'Other', 'undisclosed' => 'Prefer not to say'];

$step = max(1, min(5, query_int('step') ?: post_int('step') ?: 1));
// Applicants cannot jump past the furthest step they reached.
$maxReached = max(1, (int) $app['current_step']);
if ($step > $maxReached) {
    $step = $maxReached;
}

/**
 * Validate the fields of a step. $strict = required fields must be present.
 * Returns [errors, values-to-save].
 */
function validate_step(int $step, array $data, bool $strict): array
{
    $v = new Validator($data);
    $today = date('Y-m-d');
    $fields = [];
    switch ($step) {
        case 1:
            if ($strict) {
                $v->required('full_name', 'Full name')->required('date_of_birth', 'Date of birth')->required('place_of_birth', 'Place of birth')
                  ->required('gender', 'Gender')->required('nationality', 'Nationality')->required('occupation', 'Occupation');
            }
            $v->max('full_name', 150, 'Full name')->min('full_name', 3, 'Full name')
              ->date('date_of_birth', 'Date of birth', date('Y-m-d', strtotime('-120 years')), date('Y-m-d', strtotime('-1 day')))
              ->max('place_of_birth', 120, 'Place of birth')
              ->in('gender', array_keys(GENDERS), 'Gender')
              ->in('nationality', countries(), 'Nationality')
              ->in('marital_status', array_keys(MARITAL), 'Marital status')
              ->max('occupation', 120, 'Occupation')->max('employer_or_institution', 150, 'Employer / institution')
              ->in('region_id', array_map('strval', array_column(db_all('SELECT id FROM regions'), 'id')), 'Region');
            $fields = ['full_name', 'date_of_birth', 'place_of_birth', 'gender', 'nationality', 'marital_status', 'occupation', 'employer_or_institution', 'region_id'];
            break;
        case 2:
            $data['passport_number'] = strtoupper(str_replace(' ', '', (string) ($data['passport_number'] ?? '')));
            $v = new Validator($data);
            if ($strict) {
                $v->required('passport_number', 'Passport number')->required('passport_issuing_country', 'Issuing country')
                  ->required('passport_issue_date', 'Issue date')->required('passport_expiry_date', 'Expiry date');
            }
            $v->regex('passport_number', '/^[A-Z0-9]{5,20}$/', 'Passport number must be 5–20 letters or digits (no spaces or symbols).')
              ->in('passport_issuing_country', countries(), 'Issuing country')
              ->date('passport_issue_date', 'Issue date', date('Y-m-d', strtotime('-20 years')), $today)
              ->date('passport_expiry_date', 'Expiry date');
            $issue = $v->value('passport_issue_date');
            $expiry = $v->value('passport_expiry_date');
            if ($issue !== '' && $expiry !== '' && $expiry <= $issue) {
                $v->add('passport_expiry_date', 'Expiry date must be after the issue date.');
            }
            if ($expiry !== '' && $expiry < date('Y-m-d', strtotime('+6 months'))) {
                $v->add('passport_expiry_date', 'Your passport must be valid for at least 6 more months. Please renew it before applying.');
            }
            $fields = ['passport_number', 'passport_issuing_country', 'passport_issue_date', 'passport_expiry_date'];
            break;
        case 3:
            if ($strict) {
                $v->required('purpose_of_travel', 'Purpose of travel')->required('intended_entry_date', 'Intended entry date')
                  ->required('intended_duration_days', 'Intended stay')->required('previous_refusals', 'Previous refusals');
                if (($data['previous_refusals'] ?? '') === '1') {
                    $v->required('previous_refusal_details', 'Refusal details');
                }
            }
            $v->max('purpose_of_travel', 500, 'Purpose of travel')
              ->date('intended_entry_date', 'Intended entry date', $today, date('Y-m-d', strtotime('+3 years')))
              ->int('intended_duration_days', 'Intended stay (days)', 1, 3650)
              ->max('destination_address', 255, 'Destination address')
              ->in('previous_refusals', ['0', '1'], 'Previous refusals')
              ->max('previous_refusal_details', 500, 'Refusal details');
            $fields = ['purpose_of_travel', 'intended_entry_date', 'intended_duration_days', 'destination_address', 'previous_refusals', 'previous_refusal_details'];
            break;
    }
    $values = [];
    foreach ($fields as $f) {
        $values[$f] = nullable($v->value($f));
    }
    if (isset($values['passport_number'])) {
        $values['passport_number'] = $data['passport_number'] !== '' ? $data['passport_number'] : null;
    }
    if (array_key_exists('previous_refusals', $values) && $values['previous_refusals'] !== '1') {
        $values['previous_refusal_details'] = null;
    }
    return [$v->errors(), $values];
}

$errors = [];

if (is_post()) {
    $nav = post('nav');
    if ($step <= 3) {
        $strict = $nav === 'next';
        [$errors, $values] = validate_step($step, $_POST, $strict);
        // Save only valid fields so drafts never contain malformed data.
        $saveable = array_diff_key($values, $errors);
        if ($saveable) {
            db_update('applications', $saveable, 'id = ? AND status = ?', [$app['id'], 'draft']);
        }
        if (!$errors) {
            if ($nav === 'next') {
                $next = $step + 1;
                db_run('UPDATE applications SET current_step = GREATEST(current_step, ?) WHERE id = ?', [$next, $app['id']]);
                redirect('applicant/apply.php', ['id' => $app['id'], 'step' => $next]);
            }
            if ($nav === 'prev') {
                redirect('applicant/apply.php', ['id' => $app['id'], 'step' => max(1, $step - 1)]);
            }
            flash('success', 'Draft saved.');
            redirect('applicant/apply.php', ['id' => $app['id'], 'step' => $step]);
        }
    } elseif ($step === 4) {
        if ($nav === 'next') {
            db_run('UPDATE applications SET current_step = GREATEST(current_step, 5) WHERE id = ?', [$app['id']]);
            redirect('applicant/apply.php', ['id' => $app['id'], 'step' => 5]);
        }
        redirect('applicant/apply.php', ['id' => $app['id'], 'step' => 3]);
    } elseif ($step === 5 && $nav === 'submit') {
        $fresh = find_application((int) $app['id']);
        $all = [];
        foreach ([1, 2, 3] as $s) {
            [$errs] = validate_step($s, array_map(static fn ($x) => $x === null ? '' : (string) $x, $fresh), true);
            foreach ($errs as $msg) {
                $all[] = 'Step ' . $s . ' (' . STEPS[$s] . '): ' . $msg;
            }
        }
        foreach (missing_mandatory_documents($fresh) as $name) {
            $all[] = 'Missing document: ' . $name;
        }
        if (post('declaration') !== '1') {
            $all[] = 'You must confirm the declaration.';
        }
        if ($all) {
            $errors = $all;
        } else {
            db_update('applications', ['declaration_accepted_at' => now(), 'current_step' => 5], 'id = ?', [$app['id']]);
            change_application_status($fresh, 'submitted', 'Application submitted by applicant', (int) $user['id']);
            notify_user((int) $user['id'], 'Your status link for ' . $fresh['reference_no'],
                'Check the live status of your payment, biometrics, offer letter and LMIA at any time with this private link (it asks for your passport number): ' . tracking_url($fresh),
                'applicant/application.php?id=' . $fresh['id'], true);
            notify_application_staff($fresh, 'New application submitted: ' . $fresh['reference_no'], $fresh['applicant_name'] . ' submitted a ' . $fresh['category_name'] . ' application.');
            flash('success', 'Your application ' . $fresh['reference_no'] . ' has been submitted. We will notify you of every update.');
            redirect('applicant/application.php', ['id' => $app['id']]);
        }
    }
    $app = find_application((int) $app['id']);
}

$checklist = $step >= 4 ? document_checklist($app) : [];

render_header('Application ' . $app['reference_no'], 'applications');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h1 class="h4 mb-0">Application <?= e($app['reference_no']) ?></h1>
    <div class="text-muted small"><?= e($app['category_name']) ?> · <?= status_badge($app['status']) ?> · saved automatically when you press a button</div>
  </div>
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('applicant/application.php', ['id' => $app['id']])) ?>">Application overview</a>
</div>

<ol class="steps" aria-label="Form progress">
<?php foreach (STEPS as $n => $label): ?>
  <?php $cls = $n === $step ? 'current' : ($n < $maxReached || ($n < $step) ? 'done' : ''); ?>
  <li class="<?= $cls ?>"<?= $n === $step ? ' aria-current="step"' : '' ?>>
    <?php if ($n <= $maxReached && $n !== $step): ?><a href="<?= e(url('applicant/apply.php', ['id' => $app['id'], 'step' => $n])) ?>"><?= $n ?>. <?= e($label) ?></a><?php else: ?><?= $n ?>. <?= e($label) ?><?php endif; ?>
  </li>
<?php endforeach; ?>
</ol>

<div class="card shadow-sm">
<div class="card-body p-3 p-md-4">
<?php if ($step === 5 && $errors && array_is_list($errors)): ?>
  <div class="alert alert-danger"><strong>Your application is not complete yet:</strong><ul class="mb-0"><?php foreach ($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div>
<?php elseif ($errors): ?>
  <?= errors_summary($errors) ?>
<?php endif; ?>

<?php if ($step <= 3): ?>
<form method="post" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
  <input type="hidden" name="step" value="<?= $step ?>">
  <?php if ($step === 1): ?>
    <h2 class="h5 mb-3">Personal details</h2>
    <div class="row">
      <div class="col-md-6"><?= form_input('full_name', 'Full name (exactly as in passport)', $app['full_name'], $errors, 'text', ['required' => true, 'maxlength' => 150]) ?></div>
      <div class="col-md-6"><?= form_input('date_of_birth', 'Date of birth', $app['date_of_birth'], $errors, 'date', ['required' => true, 'max' => date('Y-m-d')]) ?></div>
      <div class="col-md-6"><?= form_input('place_of_birth', 'Place of birth (city, country)', $app['place_of_birth'], $errors, 'text', ['required' => true, 'maxlength' => 120]) ?></div>
      <div class="col-md-6"><?= form_select('gender', 'Gender', GENDERS, $app['gender'], $errors, ['required' => true]) ?></div>
      <div class="col-md-6"><?= form_select('nationality', 'Nationality', country_options(), $app['nationality'], $errors, ['required' => true]) ?></div>
      <div class="col-md-6"><?= form_select('marital_status', 'Marital status', MARITAL, $app['marital_status'], $errors) ?></div>
      <div class="col-md-6"><?= form_input('occupation', 'Occupation', $app['occupation'], $errors, 'text', ['required' => true, 'maxlength' => 120, 'placeholder' => 'e.g. Student, Engineer']) ?></div>
      <div class="col-md-6"><?= form_input('employer_or_institution', 'Employer or institution', $app['employer_or_institution'], $errors, 'text', ['maxlength' => 150]) ?></div>
      <?php if ($regionOpts = region_options()): ?>
      <div class="col-md-6"><?= form_select('region_id', 'Region you will pay from', $regionOpts, (string) ($app['region_id'] ?? ''), $errors, [], 'Choose region…') ?><p class="form-text mt-n2">Decides which bank account you see on the Payments tab. You can change it later.</p></div>
      <?php endif; ?>
    </div>
  <?php elseif ($step === 2): ?>
    <h2 class="h5 mb-3">Passport information</h2>
    <p class="text-muted small">Your passport must be valid for at least 6 months. You will upload a scan of the bio-data page in step 4.</p>
    <div class="row">
      <div class="col-md-6"><?= form_input('passport_number', 'Passport number', $app['passport_number'], $errors, 'text', ['required' => true, 'maxlength' => 20, 'autocapitalize' => 'characters', 'autocomplete' => 'off']) ?></div>
      <div class="col-md-6"><?= form_select('passport_issuing_country', 'Issuing country', country_options(), $app['passport_issuing_country'], $errors, ['required' => true]) ?></div>
      <div class="col-md-6"><?= form_input('passport_issue_date', 'Date of issue', $app['passport_issue_date'], $errors, 'date', ['required' => true, 'max' => date('Y-m-d')]) ?></div>
      <div class="col-md-6"><?= form_input('passport_expiry_date', 'Date of expiry', $app['passport_expiry_date'], $errors, 'date', ['required' => true, 'min' => date('Y-m-d')]) ?></div>
    </div>
  <?php else: ?>
    <h2 class="h5 mb-3">Travel plans &amp; background</h2>
    <?= form_textarea('purpose_of_travel', 'Purpose of travel', $app['purpose_of_travel'], $errors, ['required' => true, 'maxlength' => 500, 'rows' => 3]) ?>
    <div class="row">
      <div class="col-md-6"><?= form_input('intended_entry_date', 'Intended date of entry', $app['intended_entry_date'], $errors, 'date', ['required' => true, 'min' => date('Y-m-d')]) ?></div>
      <div class="col-md-6"><?= form_input('intended_duration_days', 'Intended length of stay (days)', $app['intended_duration_days'], $errors, 'number', ['required' => true, 'min' => 1, 'max' => 3650, 'inputmode' => 'numeric']) ?></div>
    </div>
    <?= form_input('destination_address', 'Address during your stay (if known)', $app['destination_address'], $errors, 'text', ['maxlength' => 255]) ?>
    <?= form_select('previous_refusals', 'Have you ever been refused a visa?', ['0' => 'No', '1' => 'Yes'], $app['previous_refusals'] === null ? '' : (string) $app['previous_refusals'], $errors, ['required' => true]) ?>
    <div data-show-if="f_previous_refusals">
      <?= form_textarea('previous_refusal_details', 'Refusal details (country, year, reason)', $app['previous_refusal_details'], $errors, ['maxlength' => 500, 'rows' => 2]) ?>
    </div>
  <?php endif; ?>
  <div class="d-flex flex-wrap gap-2 justify-content-between mt-2 btn-stack-sm">
    <div class="d-flex gap-2 flex-wrap">
      <?php if ($step > 1): ?><button class="btn btn-outline-secondary" name="nav" value="prev" type="submit" data-no-disable>&larr; Back</button><?php endif; ?>
      <button class="btn btn-outline-primary" name="nav" value="save" type="submit" data-no-disable>Save draft</button>
    </div>
    <button class="btn btn-brand" name="nav" value="next" type="submit" data-no-disable>Save &amp; continue &rarr;</button>
  </div>
</form>

<?php elseif ($step === 4): ?>
  <h2 class="h5 mb-1">Required documents</h2>
  <p class="text-muted small">Upload each document as PDF, JPG or PNG (max <?= upload_max_mb() ?> MB). Files are stored privately and only visible to you and authorised staff.</p>
  <?php include __DIR__ . '/partials/document-checklist.php'; ?>
  <form method="post" class="d-flex justify-content-between mt-3">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
    <input type="hidden" name="step" value="4">
    <button class="btn btn-outline-secondary" name="nav" value="prev" type="submit">&larr; Back</button>
    <button class="btn btn-brand" name="nav" value="next" type="submit">Continue to review &rarr;</button>
  </form>

<?php else: ?>
  <h2 class="h5 mb-3">Review &amp; submit</h2>
  <?php include __DIR__ . '/partials/application-summary.php'; ?>
  <h3 class="h6 mt-4">Documents</h3>
  <ul class="list-unstyled small">
  <?php foreach ($checklist as $item): $d = $item['document']; ?>
    <li class="mb-1"><?= $d ? badge(DOC_STATUS, $d['status']) : ((int) $item['requirement']['is_mandatory'] ? '<span class="badge bg-danger">Missing</span>' : '<span class="badge bg-secondary">Optional – not provided</span>') ?> <?= e($item['requirement']['name']) ?></li>
  <?php endforeach; ?>
  </ul>
  <form method="post" class="mt-3">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
    <input type="hidden" name="step" value="5">
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" name="declaration" value="1" id="declaration">
      <label class="form-check-label" for="declaration">I declare that the information I have given is true and complete, and that the documents I uploaded are genuine. I understand that false information may lead to refusal.</label>
    </div>
    <div class="d-flex flex-wrap justify-content-between gap-2">
      <a class="btn btn-outline-secondary" href="<?= e(url('applicant/apply.php', ['id' => $app['id'], 'step' => 4])) ?>">&larr; Back to documents</a>
      <button class="btn btn-success" name="nav" value="submit" type="submit">Submit application</button>
    </div>
  </form>
<?php endif; ?>
</div>
</div>
<?php render_footer();
