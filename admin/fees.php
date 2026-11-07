<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_permission('payments.instructions');
$categories = db_all('SELECT id, name FROM visa_categories ORDER BY sort_order, name');
$catOpts = ['0' => 'All categories'];
foreach ($categories as $c) {
    $catOpts[$c['id']] = $c['name'];
}
$edit = query_int('edit') ?: post_int('fee_id');
$current = $edit ? db_one('SELECT * FROM fee_types WHERE id = ?', [$edit]) : null;
$errors = [];

if (is_post()) {
    $_POST['code'] = strtoupper(post('code'));
    $_POST['currency'] = strtoupper(post('currency'));
    $v = new Validator($_POST);
    $v->required('code', 'Code')->regex('code', '/^[A-Z0-9_]{2,30}$/', 'Code must be 2–30 capital letters, digits or underscores.')
      ->required('name', 'Name')->max('name', 120, 'Name')->max('description', 500, 'Description')
      ->required('amount', 'Amount')->money('amount', 'Amount')
      ->required('currency', 'Currency')->regex('currency', '/^[A-Z]{3}$/', 'Currency must be a 3-letter ISO code such as USD, EUR or PKR.')
      ->in('visa_category_id', array_map('strval', array_keys($catOpts)), 'Category');
    if (post('is_active') === '1' && (float) post('amount') <= 0) {
        $v->add('amount', 'Set an amount greater than zero before activating a fee.');
    }
    if (db_value('SELECT id FROM fee_types WHERE code = ? AND id <> ?', [post('code'), $current['id'] ?? 0])) {
        $v->add('code', 'This code is already used.');
    }
    $errors = $v->errors();
    if (!$errors) {
        $data = [
            'code' => $v->value('code'),
            'name' => $v->value('name'),
            'description' => nullable($v->value('description')),
            'amount' => $v->value('amount'),
            'currency' => $v->value('currency'),
            'visa_category_id' => post_int('visa_category_id') ?: null,
            'is_active' => post('is_active') === '1' ? 1 : 0,
            'updated_by' => $user['id'],
        ];
        if ($current) {
            db_update('fee_types', $data, 'id = ?', [$current['id']]);
            audit_log('fee.updated', 'fee_type', (int) $current['id'], ['before' => array_intersect_key($current, $data), 'after' => $data]);
        } else {
            $id = db_insert('fee_types', $data + ['created_at' => now()]);
            audit_log('fee.created', 'fee_type', $id, $data);
        }
        flash('success', 'Fee saved.');
        redirect('admin/fees.php');
    }
}

$rows = db_all('SELECT f.*, c.name AS category_name, u.full_name AS updated_name FROM fee_types f LEFT JOIN visa_categories c ON c.id = f.visa_category_id LEFT JOIN users u ON u.id = f.updated_by ORDER BY f.name');
$f0 = $current ?? ['code' => '', 'name' => '', 'description' => '', 'amount' => '', 'currency' => 'USD', 'visa_category_id' => 0, 'is_active' => 0];

render_header('Fee types', 'config');
?>
<h1 class="h3 mb-1">Fee types</h1>
<p class="small text-muted">Only active fees are shown to applicants. Every change is recorded in the audit log with your name.</p>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="table-responsive">
    <table class="table bg-white shadow-sm align-middle">
      <thead><tr><th>Fee</th><th>Amount</th><th>Applies to</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['name']) ?></strong> <?= (int) $r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?><div class="small text-muted"><?= e($r['code']) ?> · updated <?= e(fmt_date($r['updated_at'] ?? $r['created_at'])) ?> <?= $r['updated_name'] ? 'by ' . e($r['updated_name']) : '' ?></div></td>
          <td class="text-nowrap"><?= fmt_money($r['amount'], $r['currency']) ?></td>
          <td class="small"><?= e($r['category_name'] ?? 'All') ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/fees.php', ['edit' => $r['id']])) ?>">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card shadow-sm"><div class="card-body">
      <h2 class="h5"><?= $current ? 'Edit fee' : 'New fee' ?></h2>
      <?= errors_summary($errors) ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <?php if ($current): ?><input type="hidden" name="fee_id" value="<?= (int) $current['id'] ?>"><?php endif; ?>
        <div class="row">
          <div class="col-5"><?= form_input('code', 'Code', $f0['code'], $errors, 'text', ['required' => true, 'maxlength' => 30]) ?></div>
          <div class="col-7"><?= form_input('name', 'Name', $f0['name'], $errors, 'text', ['required' => true, 'maxlength' => 120]) ?></div>
          <div class="col-7"><?= form_input('amount', 'Amount', $f0['amount'], $errors, 'text', ['required' => true, 'inputmode' => 'decimal']) ?></div>
          <div class="col-5"><?= form_input('currency', 'Currency', $f0['currency'], $errors, 'text', ['required' => true, 'maxlength' => 3]) ?></div>
        </div>
        <?= form_select('visa_category_id', 'Applies to', $catOpts, (string) ($f0['visa_category_id'] ?? 0), $errors, [], null) ?>
        <?= form_textarea('description', 'Description', $f0['description'], $errors, ['rows' => 2, 'maxlength' => 500]) ?>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"<?= (int) $f0['is_active'] ? ' checked' : '' ?>><label class="form-check-label" for="is_active">Active (visible to applicants)</label></div>
        <div class="d-flex gap-2"><button class="btn btn-brand" type="submit">Save</button><?php if ($current): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/fees.php')) ?>">Cancel</a><?php endif; ?></div>
      </form>
    </div></div>
  </div>
</div>
<?php render_footer();
