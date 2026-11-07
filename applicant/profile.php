<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_applicant();
$profile = db_one('SELECT * FROM applicant_profiles WHERE user_id = ?', [$user['id']]) ?? [];
$genders = ['female' => 'Female', 'male' => 'Male', 'other' => 'Other', 'undisclosed' => 'Prefer not to say'];
$errors = [];

if (is_post()) {
    $v = new Validator($_POST);
    $v->required('full_name', 'Full name')->min('full_name', 3, 'Full name')->max('full_name', 150, 'Full name')
      ->required('phone', 'Mobile number')->phone('phone', 'Mobile number')
      ->phone('alt_phone', 'Alternative phone')
      ->date('date_of_birth', 'Date of birth', date('Y-m-d', strtotime('-120 years')), date('Y-m-d', strtotime('-1 day')))
      ->in('gender', array_keys($genders), 'Gender')
      ->in('nationality', countries(), 'Nationality')
      ->max('address_line', 255, 'Address')->max('city', 100, 'City')
      ->in('country', countries(), 'Country of residence')
      ->max('postal_code', 20, 'Postal code')->regex('postal_code', '/^[A-Za-z0-9 \-]{2,20}$/', 'Postal code may contain letters, digits, spaces and dashes only.')
      ->max('emergency_contact_name', 150, 'Emergency contact name')
      ->phone('emergency_contact_phone', 'Emergency contact phone');
    $errors = $v->errors();
    if (!$errors) {
        db_transaction(static function () use ($v, $user) {
            db_update('users', ['full_name' => $v->value('full_name'), 'phone' => $v->value('phone')], 'id = ?', [$user['id']]);
            $data = [];
            foreach (['date_of_birth', 'gender', 'nationality', 'address_line', 'city', 'country', 'postal_code', 'alt_phone', 'emergency_contact_name', 'emergency_contact_phone'] as $f) {
                $data[$f] = nullable($v->value($f));
            }
            db_run('INSERT IGNORE INTO applicant_profiles (user_id) VALUES (?)', [$user['id']]);
            db_update('applicant_profiles', $data, 'user_id = ?', [$user['id']]);
        });
        audit_log('profile.updated', 'user', (int) $user['id']);
        flash('success', 'Your profile has been saved.');
        redirect('applicant/profile.php');
    }
}

render_header('My profile');
?>
<div class="row justify-content-center">
<div class="col-lg-9">
<h1 class="h3 mb-3">My profile &amp; contact information</h1>
<?= errors_summary($errors) ?>
<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="card shadow-sm mb-3"><div class="card-body">
    <h2 class="h5 mb-3">Account</h2>
    <div class="row">
      <div class="col-md-6"><?= form_input('full_name', 'Full name', $user['full_name'], $errors, 'text', ['required' => true, 'maxlength' => 150]) ?></div>
      <div class="col-md-6">
        <div class="mb-3"><label class="form-label" for="email_ro">Email</label><input class="form-control" id="email_ro" value="<?= e($user['email']) ?>" disabled><div class="form-text">To change your email address, contact support.</div></div>
      </div>
      <div class="col-md-6"><?= form_input('phone', 'Mobile number', $user['phone'], $errors, 'tel', ['required' => true, 'maxlength' => 25]) ?></div>
      <div class="col-md-6"><?= form_input('alt_phone', 'Alternative phone', $profile['alt_phone'] ?? '', $errors, 'tel', ['maxlength' => 25]) ?></div>
    </div>
  </div></div>
  <div class="card shadow-sm mb-3"><div class="card-body">
    <h2 class="h5 mb-3">Personal</h2>
    <div class="row">
      <div class="col-md-4"><?= form_input('date_of_birth', 'Date of birth', $profile['date_of_birth'] ?? '', $errors, 'date', ['max' => date('Y-m-d')]) ?></div>
      <div class="col-md-4"><?= form_select('gender', 'Gender', $genders, $profile['gender'] ?? '', $errors) ?></div>
      <div class="col-md-4"><?= form_select('nationality', 'Nationality', country_options(), $profile['nationality'] ?? '', $errors) ?></div>
    </div>
  </div></div>
  <div class="card shadow-sm mb-3"><div class="card-body">
    <h2 class="h5 mb-3">Address</h2>
    <?= form_input('address_line', 'Street address', $profile['address_line'] ?? '', $errors, 'text', ['maxlength' => 255, 'autocomplete' => 'street-address']) ?>
    <div class="row">
      <div class="col-md-4"><?= form_input('city', 'City', $profile['city'] ?? '', $errors, 'text', ['maxlength' => 100]) ?></div>
      <div class="col-md-4"><?= form_input('postal_code', 'Postal code', $profile['postal_code'] ?? '', $errors, 'text', ['maxlength' => 20]) ?></div>
      <div class="col-md-4"><?= form_select('country', 'Country of residence', country_options(), $profile['country'] ?? '', $errors) ?></div>
    </div>
  </div></div>
  <div class="card shadow-sm mb-3"><div class="card-body">
    <h2 class="h5 mb-3">Emergency contact</h2>
    <div class="row">
      <div class="col-md-6"><?= form_input('emergency_contact_name', 'Name', $profile['emergency_contact_name'] ?? '', $errors, 'text', ['maxlength' => 150]) ?></div>
      <div class="col-md-6"><?= form_input('emergency_contact_phone', 'Phone', $profile['emergency_contact_phone'] ?? '', $errors, 'tel', ['maxlength' => 25]) ?></div>
    </div>
  </div></div>
  <div class="d-flex flex-wrap gap-2">
    <button class="btn btn-brand" type="submit">Save profile</button>
    <a class="btn btn-outline-secondary" href="<?= e(url('account/password.php')) ?>">Change password</a>
  </div>
</form>
</div>
</div>
<?php render_footer();
