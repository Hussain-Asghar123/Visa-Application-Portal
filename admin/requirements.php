<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_permission('catalog.manage');
$categories = db_all('SELECT id, name FROM visa_categories ORDER BY sort_order, name');
$catOpts = ['0' => 'All categories'];
foreach ($categories as $c) {
    $catOpts[$c['id']] = $c['name'];
}
$edit = query_int('edit') ?: post_int('requirement_id');
$current = $edit ? db_one('SELECT * FROM document_requirements WHERE id = ?', [$edit]) : null;
$errors = [];

if (is_post()) {
    if (post('action') === 'toggle') {
        $r = db_one('SELECT * FROM document_requirements WHERE id = ?', [post_int('requirement_id')]);
        if ($r) {
            db_update('document_requirements', ['is_active' => (int) $r['is_active'] ? 0 : 1], 'id = ?', [$r['id']]);
            audit_log('requirement.' . ((int) $r['is_active'] ? 'deactivated' : 'activated'), 'document_requirement', (int) $r['id']);
        }
        redirect('admin/requirements.php');
    }
    $v = new Validator($_POST);
    $v->required('doc_type', 'Document code')->regex('doc_type', '/^[a-z0-9_]{2,40}$/', 'Document code must be 2–40 lowercase letters, digits or underscores (e.g. bank_statement).')
      ->required('name', 'Name')->max('name', 150, 'Name')->max('description', 500, 'Description')
      ->in('visa_category_id', array_map('strval', array_keys($catOpts)), 'Category')->int('sort_order', 'Sort order', -999, 999);
    $catId = post_int('visa_category_id') ?: null;
    $dupe = db_value('SELECT id FROM document_requirements WHERE doc_type = ? AND ' . ($catId ? 'visa_category_id = ?' : 'visa_category_id IS NULL') . ' AND id <> ?', $catId ? [post('doc_type'), $catId, $current['id'] ?? 0] : [post('doc_type'), $current['id'] ?? 0]);
    if ($dupe) {
        $v->add('doc_type', 'This document code already exists for that category.');
    }
    $errors = $v->errors();
    if (!$errors) {
        $data = [
            'visa_category_id' => $catId,
            'doc_type' => $v->value('doc_type'),
            'name' => $v->value('name'),
            'description' => nullable($v->value('description')),
            'is_mandatory' => post('is_mandatory') === '1' ? 1 : 0,
            'is_active' => post('is_active') === '1' ? 1 : 0,
            'sort_order' => (int) $v->value('sort_order'),
        ];
        if ($current) {
            db_update('document_requirements', $data, 'id = ?', [$current['id']]);
            audit_log('requirement.updated', 'document_requirement', (int) $current['id'], ['before' => array_intersect_key($current, $data), 'after' => $data]);
        } else {
            $id = db_insert('document_requirements', $data + ['created_at' => now()]);
            audit_log('requirement.created', 'document_requirement', $id, $data);
        }
        flash('success', 'Requirement saved.');
        redirect('admin/requirements.php');
    }
}

$rows = db_all('SELECT r.*, c.name AS category_name FROM document_requirements r LEFT JOIN visa_categories c ON c.id = r.visa_category_id ORDER BY r.visa_category_id IS NOT NULL, c.name, r.sort_order, r.name');
$r0 = $current ?? ['visa_category_id' => 0, 'doc_type' => '', 'name' => '', 'description' => '', 'is_mandatory' => 1, 'is_active' => 1, 'sort_order' => 0];

render_header('Document requirements', 'config');
?>
<h1 class="h3 mb-1">Document requirements</h1>
<p class="small text-muted">These items form each applicant's required-document checklist. Mandatory items must be uploaded before an application can be submitted.</p>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="table-responsive">
    <table class="table bg-white shadow-sm align-middle">
      <thead><tr><th>Document</th><th>Applies to</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= (int) $r['is_active'] ? '' : 'text-muted' ?>">
          <td><strong><?= e($r['name']) ?></strong> <span class="small text-muted"><?= e($r['doc_type']) ?></span>
            <div class="small"><?= (int) $r['is_mandatory'] ? '<span class="badge bg-danger">Mandatory</span>' : '<span class="badge bg-secondary">Optional</span>' ?> <?= (int) $r['is_active'] ? '' : '<span class="badge bg-light text-dark border">Inactive</span>' ?></div></td>
          <td class="small"><?= e($r['category_name'] ?? 'All categories') ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/requirements.php', ['edit' => $r['id']])) ?>">Edit</a>
            <?= post_button(url('admin/requirements.php'), ['action' => 'toggle', 'requirement_id' => $r['id']], (int) $r['is_active'] ? 'Deactivate' : 'Activate', 'btn btn-sm btn-outline-secondary') ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card shadow-sm"><div class="card-body">
      <h2 class="h5"><?= $current ? 'Edit requirement' : 'New requirement' ?></h2>
      <?= errors_summary($errors) ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($current): ?><input type="hidden" name="requirement_id" value="<?= (int) $current['id'] ?>"><?php endif; ?>
        <?= form_select('visa_category_id', 'Applies to', $catOpts, (string) ($r0['visa_category_id'] ?? 0), $errors, [], null) ?>
        <?= form_input('doc_type', 'Document code', $r0['doc_type'], $errors, 'text', ['required' => true, 'maxlength' => 40, 'placeholder' => 'e.g. bank_statement']) ?>
        <?= form_input('name', 'Name shown to applicants', $r0['name'], $errors, 'text', ['required' => true, 'maxlength' => 150]) ?>
        <?= form_textarea('description', 'Guidance', $r0['description'], $errors, ['rows' => 2, 'maxlength' => 500]) ?>
        <?= form_input('sort_order', 'Sort order', $r0['sort_order'], $errors, 'number') ?>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="is_mandatory" value="1" id="is_mandatory"<?= (int) $r0['is_mandatory'] ? ' checked' : '' ?>><label class="form-check-label" for="is_mandatory">Mandatory</label></div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"<?= (int) $r0['is_active'] ? ' checked' : '' ?>><label class="form-check-label" for="is_active">Active</label></div>
        <div class="d-flex gap-2"><button class="btn btn-brand" type="submit">Save</button><?php if ($current): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/requirements.php')) ?>">Cancel</a><?php endif; ?></div>
      </form>
    </div></div>
  </div>
</div>
<?php render_footer();
