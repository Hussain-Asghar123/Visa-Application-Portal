<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_applicant();
$categories = active_categories();
$errors = [];

if (is_post() && post('action') === 'create') {
    $catId = post_int('visa_category_id');
    $cat = db_one('SELECT * FROM visa_categories WHERE id = ? AND is_active = 1', [$catId]);
    $openDrafts = (int) db_value("SELECT COUNT(*) FROM applications WHERE user_id = ? AND status = 'draft'", [$user['id']]);
    if (!$cat) {
        $errors['visa_category_id'] = 'Please choose a visa category.';
    } elseif ($openDrafts >= 3) {
        $errors['visa_category_id'] = 'You already have 3 draft applications. Please complete or discard one first.';
    } else {
        $profile = db_one('SELECT * FROM applicant_profiles WHERE user_id = ?', [$user['id']]) ?? [];
        $ref = generate_reference();
        $id = db_insert('applications', [
            'reference_no' => $ref,
            'user_id' => $user['id'],
            'visa_category_id' => $cat['id'],
            'status' => 'draft',
            'current_step' => 1,
            'full_name' => $user['full_name'],
            'date_of_birth' => $profile['date_of_birth'] ?? null,
            'gender' => $profile['gender'] ?? null,
            'nationality' => $profile['nationality'] ?? null,
            'created_at' => now(),
        ]);
        db_insert('application_status_history', [
            'application_id' => $id, 'old_status' => null, 'new_status' => 'draft',
            'note' => 'Application started', 'visible_to_applicant' => 1, 'changed_by' => $user['id'], 'created_at' => now(),
        ]);
        audit_log('application.created', 'application', $id, ['reference' => $ref]);
        flash('success', 'Application ' . $ref . ' created. Your progress is saved as a draft at every step.');
        redirect('applicant/apply.php', ['id' => $id, 'step' => 1]);
    }
}

$apps = db_all(
    'SELECT a.*, c.name AS category_name,
            (SELECT COUNT(*) FROM cancellation_requests cr WHERE cr.application_id = a.id AND cr.status = \'pending\') AS pending_cancel
     FROM applications a JOIN visa_categories c ON c.id = a.visa_category_id
     WHERE a.user_id = ? ORDER BY a.created_at DESC',
    [$user['id']]
);

render_header('My applications', 'applications');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h1 class="h3 mb-0">My applications</h1>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <h2 class="h5">Start a new application</h2>
    <?= errors_summary($errors) ?>
    <?php if ($categories): ?>
    <form method="post" class="row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="col-md-8">
        <?php $opts = []; foreach ($categories as $c) { $opts[$c['id']] = $c['name'] . ($c['processing_days_max'] ? ' (approx. ' . $c['processing_days_min'] . '–' . $c['processing_days_max'] . ' days)' : ''); } ?>
        <?= form_select('visa_category_id', 'Visa category', $opts, '', [], ['required' => true]) ?>
      </div>
      <div class="col-md-4 mb-3"><button class="btn btn-brand w-100" type="submit">Start application</button></div>
    </form>
    <?php else: ?>
      <?= empty_state('No visa categories are open for applications at the moment.') ?>
    <?php endif; ?>
  </div>
</div>

<?php if (!$apps): ?>
  <?= empty_state('You have not started any applications yet.') ?>
<?php else: ?>
<div class="table-responsive">
<table class="table bg-white shadow-sm align-middle">
  <thead><tr><th>Reference</th><th>Category</th><th>Status</th><th class="d-none d-md-table-cell">Updated</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($apps as $a): ?>
    <tr>
      <td class="fw-semibold text-nowrap"><?= e($a['reference_no']) ?></td>
      <td><?= e($a['category_name']) ?></td>
      <td><?= status_badge($a['status']) ?><?= $a['pending_cancel'] ? ' <span class="badge bg-light text-dark border">Cancellation requested</span>' : '' ?></td>
      <td class="d-none d-md-table-cell small"><?= e(fmt_date($a['updated_at'] ?? $a['created_at'], true)) ?></td>
      <td class="text-end text-nowrap">
        <?php if ($a['status'] === 'draft'): ?>
          <a class="btn btn-sm btn-brand" href="<?= e(url('applicant/apply.php', ['id' => $a['id'], 'step' => $a['current_step']])) ?>">Continue</a>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('applicant/application.php', ['id' => $a['id']])) ?>">View</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php render_footer();
