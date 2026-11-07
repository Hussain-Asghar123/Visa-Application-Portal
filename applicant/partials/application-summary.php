<?php
/** Read-only summary of an application. Expects $app. Shared by applicant and admin views. */
defined('APP_ROOT') || exit;
$genders = ['female' => 'Female', 'male' => 'Male', 'other' => 'Other', 'undisclosed' => 'Prefer not to say'];
$rows = [
    'Personal details' => [
        'Full name' => $app['full_name'],
        'Date of birth' => fmt_date($app['date_of_birth']),
        'Place of birth' => $app['place_of_birth'],
        'Gender' => $genders[$app['gender']] ?? null,
        'Nationality' => $app['nationality'],
        'Marital status' => $app['marital_status'] ? ucfirst((string) $app['marital_status']) : null,
        'Occupation' => $app['occupation'],
        'Employer / institution' => $app['employer_or_institution'],
        'Payment region' => region_name($app['region_id'] ? (int) $app['region_id'] : null),
    ],
    'Passport' => [
        'Passport number' => $app['passport_number'],
        'Issuing country' => $app['passport_issuing_country'],
        'Issue date' => fmt_date($app['passport_issue_date']),
        'Expiry date' => fmt_date($app['passport_expiry_date']),
    ],
    'Travel' => [
        'Purpose' => $app['purpose_of_travel'],
        'Intended entry' => fmt_date($app['intended_entry_date']),
        'Length of stay' => $app['intended_duration_days'] ? $app['intended_duration_days'] . ' days' : null,
        'Address during stay' => $app['destination_address'],
        'Previous refusals' => $app['previous_refusals'] === null ? null : ((int) $app['previous_refusals'] ? 'Yes – ' . $app['previous_refusal_details'] : 'No'),
    ],
];
?>
<div class="row g-3">
<?php foreach ($rows as $section => $fields): ?>
  <div class="col-md-4">
    <h3 class="h6 text-uppercase text-muted"><?= e($section) ?></h3>
    <dl class="detail-list mb-0">
    <?php foreach ($fields as $label => $value): ?>
      <dt class="small"><?= e($label) ?></dt>
      <dd><?= ($value === null || $value === '' || $value === '—') ? '<span class="text-muted">Not provided</span>' : e($value) ?></dd>
    <?php endforeach; ?>
    </dl>
  </div>
<?php endforeach; ?>
</div>
