<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_permission('catalog.manage');
$edit = query_int('edit') ?: post_int('category_id');
$current = $edit ? db_one('SELECT * FROM visa_categories WHERE id = ?', [$edit]) : null;
$errors = [];

if (is_post()) {
    if (post('action') === 'toggle') {
        $c = db_one('SELECT * FROM visa_categories WHERE id = ?', [post_int('category_id')]);
        if ($c) {
            db_update('visa_categories', ['is_active' => (int) $c['is_active'] ? 0 : 1, 'updated_by' => $user['id']], 'id = ?', [$c['id']]);
            audit_log('category.' . ((int) $c['is_active'] ? 'deactivated' : 'activated'), 'visa_category', (int) $c['id']);
        }
        redirect('admin/categories.php');
    }
    $_POST['code'] = strtoupper(post('code'));
    $v = new Validator($_POST);
    $v->required('code', 'Code')->regex('code', '/^[A-Z0-9_]{2,30}$/', 'Code must be 2–30 capital letters, digits or underscores.')
      ->required('name', 'Name')->max('name', 120, 'Name')->max('description', 2000, 'Description')
      ->int('processing_days_min', 'Minimum days', 0, 3650)->int('processing_days_max', 'Maximum days', 0, 3650)
      ->max('processing_notes', 500, 'Processing notes')->int('sort_order', 'Sort order', -999, 999);
    if ($v->value('processing_days_min') !== '' && $v->value('processing_days_max') !== '' && (int) $v->value('processing_days_min') > (int) $v->value('processing_days_max')) {
        $v->add('processing_days_max', 'Maximum days must be greater than or equal to minimum days.');
    }
    $dupe = db_value('SELECT id FROM visa_categories WHERE code = ? AND id <> ?', [strtoupper(post('code')), $current['id'] ?? 0]);
    if ($dupe) {
        $v->add('code', 'This code is already used.');
    }
    $errors = $v->errors();
    if (!$errors) {
        $data = [
            'code' => $v->value('code'),
            'name' => $v->value('name'),
            'description' => nullable($v->value('description')),
            'processing_days_min' => nullable($v->value('processing_days_min')),
            'processing_days_max' => nullable($v->value('processing_days_max')),
            'processing_notes' => nullable($v->value('processing_notes')),
            'sort_order' => (int) $v->value('sort_order'),
            'is_active' => post('is_active') === '1' ? 1 : 0,
            'updated_by' => $user['id'],
        ];
        if ($current) {
            db_update('visa_categories', $data, 'id = ?', [$current['id']]);
            audit_log('category.updated', 'visa_category', (int) $current['id'], ['before' => array_intersect_key($current, $data), 'after' => $data]);
        } else {
            $id = db_insert('visa_categories', $data + ['created_at' => now()]);
            audit_log('category.created', 'visa_category', $id, $data);
        }
        flash('success', 'Visa category saved.');
        redirect('admin/categories.php');
    }
}

$rows = db_all('SELECT c.*, (SELECT COUNT(*) FROM applications a WHERE a.visa_category_id = c.id) AS apps FROM visa_categories c ORDER BY c.sort_order, c.name');
$c = $current ?? ['code' => '', 'name' => '', 'description' => '', 'processing_days_min' => '', 'processing_days_max' => '', 'processing_notes' => '', 'sort_order' => 0, 'is_active' => 1];

render_header('Visa categories', 'config');
?>
<h1 class="h3 mb-3">Visa categories &amp; processing times</h1>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="table-responsive">
    <table class="table bg-white shadow-sm align-middle">
      <thead><tr><th>Category</th><th>Processing</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['name']) ?></strong> <span class="small text-muted"><?= e($r['code']) ?></span><div class="small text-muted"><?= (int) $r['apps'] ?> application(s)</div></td>
          <td class="small text-nowrap"><?= $r['processing_days_max'] !== null ? (int) $r['processing_days_min'] . '–' . (int) $r['processing_days_max'] . ' days' : '—' ?></td>
          <td><?= (int) $r['is_active'] ? '<span class="badge bg-success">Open</span>' : '<span class="badge bg-secondary">Closed</span>' ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/categories.php', ['edit' => $r['id']])) ?>">Edit</a>
            <?= post_button(url('admin/categories.php'), ['action' => 'toggle', 'category_id' => $r['id']], (int) $r['is_active'] ? 'Close' : 'Open', 'btn btn-sm btn-outline-secondary') ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card shadow-sm"><div class="card-body">
      <h2 class="h5"><?= $current ? 'Edit ' . e($current['name']) : 'New category' ?></h2>
      <?= errors_summary($errors) ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($current): ?><input type="hidden" name="category_id" value="<?= (int) $current['id'] ?>"><?php endif; ?>
        <div class="row">
          <div class="col-5"><?= form_input('code', 'Code', $c['code'], $errors, 'text', ['required' => true, 'maxlength' => 30, 'autocapitalize' => 'characters']) ?></div>
          <div class="col-7"><?= form_input('name', 'Name', $c['name'], $errors, 'text', ['required' => true, 'maxlength' => 120]) ?></div>
        </div>
        <?= form_textarea('description', 'Description', $c['description'], $errors, ['rows' => 2, 'maxlength' => 2000]) ?>
        <div class="row">
          <div class="col-6"><?= form_input('processing_days_min', 'Min. days', $c['processing_days_min'], $errors, 'number', ['min' => 0, 'max' => 3650]) ?></div>
          <div class="col-6"><?= form_input('processing_days_max', 'Max. days', $c['processing_days_max'], $errors, 'number', ['min' => 0, 'max' => 3650]) ?></div>
        </div>
        <?= form_input('processing_notes', 'Processing notes (shown to applicants)', $c['processing_notes'], $errors, 'text', ['maxlength' => 500]) ?>
        <?= form_input('sort_order', 'Sort order', $c['sort_order'], $errors, 'number') ?>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"<?= (int) $c['is_active'] ? ' checked' : '' ?>><label class="form-check-label" for="is_active">Open for new applications</label></div>
        <div class="d-flex gap-2"><button class="btn btn-brand" type="submit">Save</button><?php if ($current): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/categories.php')) ?>">Cancel</a><?php endif; ?></div>
      </form>
    </div></div>
  </div>
</div>
<?php render_footer();
