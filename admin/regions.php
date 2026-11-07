<?php
/**
 * Payment regions (e.g. Pakistan, UAE, UK). Each region can have its own bank accounts
 * (Configuration → Payment instructions). Applicants choose their region and only see
 * that region's accounts plus accounts marked "All regions".
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_permission('payments.instructions');
$edit = query_int('edit') ?: post_int('region_id');
$current = $edit ? db_one('SELECT * FROM regions WHERE id = ?', [$edit]) : null;
$errors = [];

if (is_post()) {
    $action = post('action');
    if ($action === 'toggle' || $action === 'delete') {
        $r = db_one('SELECT * FROM regions WHERE id = ?', [post_int('region_id')]);
        if (!$r) {
            abort(404);
        }
        if ($action === 'toggle') {
            db_update('regions', ['is_active' => (int) $r['is_active'] ? 0 : 1, 'updated_by' => $user['id']], 'id = ?', [$r['id']]);
            audit_log('region.' . ((int) $r['is_active'] ? 'deactivated' : 'activated'), 'region', (int) $r['id'], ['name' => $r['name']], 'security');
            flash('success', 'Region "' . $r['name'] . '" ' . ((int) $r['is_active'] ? 'hidden from applicants.' : 'is now available to applicants.'));
        } else {
            $used = (int) db_value('SELECT (SELECT COUNT(*) FROM payment_instructions WHERE region_id = ?) + (SELECT COUNT(*) FROM applications WHERE region_id = ?) + (SELECT COUNT(*) FROM payments WHERE region_id = ?)', [$r['id'], $r['id'], $r['id']]);
            if ($used > 0) {
                flash('error', 'This region is used by payment accounts, applications or payments. Deactivate it instead of deleting it.');
            } else {
                db_run('DELETE FROM regions WHERE id = ?', [$r['id']]);
                audit_log('region.deleted', 'region', (int) $r['id'], ['name' => $r['name']], 'security');
                flash('success', 'Region deleted.');
            }
        }
        redirect('admin/regions.php');
    }

    $_POST['code'] = strtoupper(post('code'));
    $v = new Validator($_POST);
    $v->required('name', 'Region name')->max('name', 120, 'Region name')
      ->required('code', 'Code')->regex('code', '/^[A-Z0-9_]{2,20}$/', 'Code must be 2–20 capital letters, digits or underscores (e.g. PK, UAE, UK).')
      ->max('description', 255, 'Description')->int('sort_order', 'Sort order', -999, 999);
    if (db_value('SELECT id FROM regions WHERE code = ? AND id <> ?', [post('code'), $current['id'] ?? 0])) {
        $v->add('code', 'This code is already used.');
    }
    if (db_value('SELECT id FROM regions WHERE name = ? AND id <> ?', [post('name'), $current['id'] ?? 0])) {
        $v->add('name', 'A region with this name already exists.');
    }
    $errors = $v->errors();
    if (!$errors) {
        $data = [
            'name' => $v->value('name'),
            'code' => $v->value('code'),
            'description' => nullable($v->value('description')),
            'sort_order' => (int) $v->value('sort_order'),
            'is_active' => post('is_active') === '1' ? 1 : 0,
            'updated_by' => $user['id'],
        ];
        if ($current) {
            db_update('regions', $data, 'id = ?', [$current['id']]);
            audit_log('region.updated', 'region', (int) $current['id'], ['before' => array_intersect_key($current, $data), 'after' => $data], 'security');
        } else {
            $id = db_insert('regions', $data + ['created_at' => now()]);
            audit_log('region.created', 'region', $id, $data, 'security');
        }
        flash('success', 'Region saved. Now add its bank account under Payment instructions.');
        redirect('admin/regions.php');
    }
}

$rows = db_all(
    'SELECT r.*,
            (SELECT COUNT(*) FROM payment_instructions p WHERE p.region_id = r.id) AS accounts,
            (SELECT COUNT(*) FROM payment_instructions p WHERE p.region_id = r.id AND p.is_published = 1) AS published,
            (SELECT COUNT(*) FROM applications a WHERE a.region_id = r.id) AS apps
     FROM regions r ORDER BY r.sort_order, r.name'
);
$globalAccounts = (int) db_value('SELECT COUNT(*) FROM payment_instructions WHERE region_id IS NULL AND is_published = 1');
$r0 = $current ?? ['name' => '', 'code' => '', 'description' => '', 'sort_order' => 0, 'is_active' => 1];

render_header('Payment regions', 'config');
?>
<div class="page-head">
  <div>
    <span class="eyebrow"><i class="bi bi-globe2"></i> Configuration</span>
    <h1 class="h2 mt-1 mb-1">Payment regions</h1>
    <p class="text-muted mb-0">Create a region for each country or area you collect payments in, then give it its own bank account under <a href="<?= e(url('admin/payment-instructions.php')) ?>">Payment instructions</a>.</p>
  </div>
  <a class="btn btn-outline-primary" href="<?= e(url('admin/payment-instructions.php')) ?>"><i class="bi bi-bank me-1"></i>Payment instructions</a>
</div>
<div class="row g-4">
  <div class="col-lg-7">
    <?php if ($globalAccounts): ?>
      <div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i><?= $globalAccounts ?> published account(s) are set to <strong>All regions</strong> and are shown to every applicant in addition to their region's accounts.</div>
    <?php endif; ?>
    <?php if (!$rows): ?><?= empty_state('No regions yet. Add your first region using the form.', 'globe2') ?><?php else: ?>
    <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>Region</th><th>Bank accounts</th><th>Applicants</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= (int) $r['is_active'] ? '' : 'text-muted' ?>">
          <td><strong><?= e($r['name']) ?></strong> <span class="small text-muted"><?= e($r['code']) ?></span>
            <div><?= (int) $r['is_active'] ? badge(['a' => ['Active', 'success']], 'a') : badge(['i' => ['Hidden', 'secondary']], 'i') ?></div>
            <?php if ($r['description']): ?><div class="small text-muted"><?= e($r['description']) ?></div><?php endif; ?></td>
          <td class="small"><?= (int) $r['published'] ?> published / <?= (int) $r['accounts'] ?> total
            <?php if (!(int) $r['published'] && (int) $r['is_active']): ?><div class="text-danger"><i class="bi bi-exclamation-triangle"></i> No published account</div><?php endif; ?></td>
          <td class="small"><?= (int) $r['apps'] ?></td>
          <td class="text-end">
            <div class="d-flex flex-wrap gap-1 justify-content-end">
              <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/regions.php', ['edit' => $r['id']])) ?>">Edit</a>
              <?= post_button(url('admin/regions.php'), ['action' => 'toggle', 'region_id' => $r['id']], (int) $r['is_active'] ? 'Hide' : 'Activate', 'btn btn-sm btn-outline-secondary') ?>
              <?php if (!(int) $r['accounts'] && !(int) $r['apps']): ?><?= post_button(url('admin/regions.php'), ['action' => 'delete', 'region_id' => $r['id']], 'Delete', 'btn btn-sm btn-outline-danger', 'Delete this region?') ?><?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <h2 class="h5"><?= $current ? 'Edit region' : 'Add a region' ?></h2>
      <?= errors_summary($errors) ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($current): ?><input type="hidden" name="region_id" value="<?= (int) $current['id'] ?>"><?php endif; ?>
        <div class="row">
          <div class="col-8"><?= form_input('name', 'Region name', $r0['name'], $errors, 'text', ['required' => true, 'maxlength' => 120, 'placeholder' => 'e.g. Pakistan']) ?></div>
          <div class="col-4"><?= form_input('code', 'Code', $r0['code'], $errors, 'text', ['required' => true, 'maxlength' => 20, 'placeholder' => 'PK']) ?></div>
        </div>
        <?= form_input('description', 'Description (optional, shown to applicants)', $r0['description'], $errors, 'text', ['maxlength' => 255, 'placeholder' => 'e.g. Applicants paying from Pakistan']) ?>
        <?= form_input('sort_order', 'Sort order', $r0['sort_order'], $errors, 'number') ?>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"<?= (int) $r0['is_active'] ? ' checked' : '' ?>><label class="form-check-label" for="is_active">Active (applicants can choose it)</label></div>
        <div class="d-flex gap-2"><button class="btn btn-brand" type="submit">Save region</button><?php if ($current): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/regions.php')) ?>">Cancel</a><?php endif; ?></div>
      </form>
    </div></div>
  </div>
</div>
<?php render_footer();
