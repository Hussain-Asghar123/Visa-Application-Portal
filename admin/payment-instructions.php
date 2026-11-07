<?php
/**
 * Payment instructions (bank accounts), optionally per region. Restricted to Super Admin /
 * finance roles with payments.instructions. Every create / edit / publish is audited with
 * before & after values. The portal never ships with bank details – they must be entered
 * here by an authorised person.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_permission('payments.instructions');
$edit = query_int('edit') ?: post_int('instruction_id');
$current = $edit ? db_one('SELECT * FROM payment_instructions WHERE id = ?', [$edit]) : null;
$fields = ['title', 'bank_name', 'account_name', 'account_number', 'iban', 'swift_code', 'branch', 'reference_format', 'instructions_text'];
$allRegions = db_all('SELECT * FROM regions ORDER BY sort_order, name');
$regionOpts = ['0' => 'All regions (shown to every applicant)'];
foreach ($allRegions as $r) {
    $regionOpts[$r['id']] = $r['name'] . ((int) $r['is_active'] ? '' : ' (hidden)');
}
$errors = [];

if (is_post()) {
    $action = post('action');
    if ($action === 'publish' || $action === 'unpublish') {
        $pi = db_one('SELECT * FROM payment_instructions WHERE id = ?', [post_int('instruction_id')]);
        if ($pi) {
            db_update('payment_instructions', ['is_published' => $action === 'publish' ? 1 : 0, 'updated_by' => $user['id']], 'id = ?', [$pi['id']]);
            audit_log('payment_instructions.' . $action . 'ed', 'payment_instruction', (int) $pi['id'], ['title' => $pi['title'], 'region' => region_name($pi['region_id'] ? (int) $pi['region_id'] : null) ?? 'All regions'], 'security');
            flash('success', 'Payment instructions ' . ($action === 'publish' ? 'published to applicants.' : 'hidden from applicants.'));
        }
        redirect('admin/payment-instructions.php', array_filter(['region' => query('region')]));
    }
    $v = new Validator($_POST);
    $v->required('title', 'Title')->max('title', 150, 'Title')
      ->in('region_id', array_map('strval', array_keys($regionOpts)), 'Region')
      ->max('bank_name', 150, 'Bank name')->max('account_name', 150, 'Account name')
      ->max('account_number', 80, 'Account number')->regex('account_number', '/^[A-Za-z0-9 \-]{4,80}$/', 'Account number may contain letters, digits, spaces and dashes.')
      ->max('iban', 80, 'IBAN')->regex('iban', '/^[A-Za-z]{2}[0-9]{2}[A-Za-z0-9 ]{8,40}$/', 'IBAN format looks invalid.')
      ->max('swift_code', 30, 'SWIFT/BIC')->regex('swift_code', '/^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/', 'SWIFT/BIC must be 8 or 11 characters.')
      ->max('branch', 150, 'Branch')->max('reference_format', 255, 'Reference format')->max('instructions_text', 5000, 'Instructions');
    if (post('bank_name') === '' && post('instructions_text') === '') {
        $v->add('bank_name', 'Enter bank details or written instructions.');
    }
    if (post('confirm_verified') !== '1') {
        $v->add('confirm_verified', 'Confirm that these details were verified with the official account holder.');
    }
    $errors = $v->errors();
    if (!$errors) {
        $data = [];
        foreach ($fields as $f) {
            $data[$f] = nullable($v->value($f));
        }
        $data['region_id'] = post_int('region_id') ?: null;
        $data['updated_by'] = $user['id'];
        if ($current) {
            db_update('payment_instructions', $data, 'id = ?', [$current['id']]);
            $changed = [];
            foreach (array_merge($fields, ['region_id']) as $f) {
                if ((string) $current[$f] !== (string) $data[$f]) {
                    $changed[$f] = ['from' => $current[$f], 'to' => $data[$f]];
                }
            }
            audit_log('payment_instructions.updated', 'payment_instruction', (int) $current['id'], ['changes' => $changed, 'was_published' => (int) $current['is_published']], 'security');
            if ((int) $current['is_published'] === 1 && $changed) {
                flash('warning', 'These instructions are live: applicants see the changes immediately.');
            }
        } else {
            $id = db_insert('payment_instructions', $data + ['is_published' => 0, 'created_by' => $user['id'], 'created_at' => now()]);
            audit_log('payment_instructions.created', 'payment_instruction', $id, ['title' => $data['title'], 'region' => region_name($data['region_id']) ?? 'All regions'], 'security');
        }
        flash('success', 'Payment instructions saved' . ($current ? '.' : ' as unpublished. Publish them when ready.'));
        redirect('admin/payment-instructions.php');
    }
}

$filter = query('region');
$where = '1=1';
$params = [];
if ($filter === 'all') {
    $where = 'p.region_id IS NULL';
} elseif (ctype_digit($filter) && $filter !== '0') {
    $where = 'p.region_id = ?';
    $params[] = (int) $filter;
}
$rows = db_all("SELECT p.*, r.name AS region_name, r.is_active AS region_active, c.full_name AS created_name, u.full_name AS updated_name
                FROM payment_instructions p LEFT JOIN regions r ON r.id = p.region_id LEFT JOIN users c ON c.id = p.created_by LEFT JOIN users u ON u.id = p.updated_by
                WHERE $where ORDER BY p.region_id IS NOT NULL, r.sort_order, r.name, p.id", $params);
$history = db_all("SELECT l.*, u.full_name FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.entity_type = 'payment_instruction' ORDER BY l.created_at DESC LIMIT 15");
$p0 = $current ?? array_fill_keys($fields, '') + ['region_id' => null];
$preRegion = $current ? (string) ($current['region_id'] ?? '0') : (ctype_digit($filter) ? $filter : '0');

render_header('Payment instructions', 'config');
?>
<div class="page-head">
  <div>
    <span class="eyebrow"><i class="bi bi-bank"></i> Configuration</span>
    <h1 class="h2 mt-1 mb-1">Payment instructions</h1>
    <p class="text-muted mb-0">Bank accounts applicants deposit into. Give each region its own account, or use "All regions".</p>
  </div>
  <a class="btn btn-outline-primary" href="<?= e(url('admin/regions.php')) ?>"><i class="bi bi-globe2 me-1"></i>Manage regions</a>
</div>
<div class="alert alert-warning small">Enter only bank details confirmed with the official account holder. Applicants rely on these details to send money. Every change is logged with your name and the time.</div>
<?php if ($allRegions): ?>
<ul class="nav nav-pills mb-3 flex-nowrap overflow-auto">
  <li class="nav-item"><a class="nav-link text-nowrap<?= $filter === '' ? ' active' : '' ?>" href="<?= e(url('admin/payment-instructions.php')) ?>">Everything</a></li>
  <li class="nav-item"><a class="nav-link text-nowrap<?= $filter === 'all' ? ' active' : '' ?>" href="<?= e(url('admin/payment-instructions.php', ['region' => 'all'])) ?>">All regions</a></li>
  <?php foreach ($allRegions as $r): ?>
  <li class="nav-item"><a class="nav-link text-nowrap<?= $filter === (string) $r['id'] ? ' active' : '' ?>" href="<?= e(url('admin/payment-instructions.php', ['region' => $r['id']])) ?>"><?= e($r['name']) ?></a></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
<div class="row g-4">
  <div class="col-lg-7">
    <?php if (!$rows): ?><?= empty_state('No payment instructions here yet. Applicants are told not to pay until instructions are published.', 'bank') ?><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between flex-wrap gap-2 align-items-start">
          <div>
            <h2 class="h6 mb-1"><?= e($r['title']) ?></h2>
            <?= $r['region_id'] ? '<span class="badge soft bg-primary-subtle text-primary-emphasis border-primary-subtle"><i class="bi bi-geo-alt me-1"></i>' . e($r['region_name']) . ((int) $r['region_active'] ? '' : ' (hidden)') . '</span>' : '<span class="badge soft bg-info-subtle text-info-emphasis border-info-subtle"><i class="bi bi-globe2 me-1"></i>All regions</span>' ?>
          </div>
          <?= (int) $r['is_published'] ? badge(['p' => ['Published', 'success']], 'p') : badge(['u' => ['Unpublished', 'secondary']], 'u') ?>
        </div>
        <div class="small mt-2">
          <?php foreach (['Bank' => 'bank_name', 'Account name' => 'account_name', 'Account number' => 'account_number', 'IBAN' => 'iban', 'SWIFT' => 'swift_code', 'Branch' => 'branch', 'Reference' => 'reference_format'] as $l => $f): ?>
            <?php if ($r[$f]): ?><div><span class="text-muted"><?= e($l) ?>:</span> <?= e($r[$f]) ?></div><?php endif; ?>
          <?php endforeach; ?>
          <?php if ($r['instructions_text']): ?><div class="pre-wrap mt-1"><?= e($r['instructions_text']) ?></div><?php endif; ?>
        </div>
        <div class="small text-muted mt-2">Created by <?= e($r['created_name'] ?? '—') ?> <?= e(fmt_date($r['created_at'], true)) ?> · last changed by <?= e($r['updated_name'] ?? '—') ?> <?= e(fmt_date($r['updated_at'] ?? $r['created_at'], true)) ?></div>
        <div class="d-flex gap-2 mt-2">
          <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/payment-instructions.php', ['edit' => $r['id']])) ?>">Edit</a>
          <?= (int) $r['is_published']
              ? post_button(url('admin/payment-instructions.php'), ['action' => 'unpublish', 'instruction_id' => $r['id']], 'Unpublish', 'btn btn-sm btn-outline-warning', 'Hide these instructions from applicants?')
              : post_button(url('admin/payment-instructions.php'), ['action' => 'publish', 'instruction_id' => $r['id']], 'Publish', 'btn btn-sm btn-success', 'Publish these bank details to applicants of ' . ($r['region_name'] ?? 'all regions') . '? Make sure they are correct.') ?>
        </div>
      </div></div>
    <?php endforeach; ?>
    <?php if ($history): ?>
      <h2 class="h6 mt-4">Change history</h2>
      <ul class="small list-unstyled">
      <?php foreach ($history as $h): ?><li class="border-bottom py-1"><?= e(fmt_date($h['created_at'], true)) ?> · <?= e($h['full_name'] ?? 'System') ?> · <?= e($h['action']) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <h2 class="h5"><?= $current ? 'Edit account' : 'Add a payment account' ?></h2>
      <?= errors_summary($errors) ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($current): ?><input type="hidden" name="instruction_id" value="<?= (int) $current['id'] ?>"><?php endif; ?>
        <?= form_select('region_id', 'Region', $regionOpts, $preRegion, $errors, ['required' => true], null) ?>
        <?php if (!$allRegions): ?><p class="form-text mt-n2 mb-3">No regions yet – <a href="<?= e(url('admin/regions.php')) ?>">add regions</a> to show different accounts per country.</p><?php endif; ?>
        <?= form_input('title', 'Title', $p0['title'], $errors, 'text', ['required' => true, 'maxlength' => 150, 'placeholder' => 'e.g. Bank transfer – Pakistan']) ?>
        <?= form_input('bank_name', 'Bank name', $p0['bank_name'], $errors, 'text', ['maxlength' => 150]) ?>
        <?= form_input('account_name', 'Account holder name', $p0['account_name'], $errors, 'text', ['maxlength' => 150]) ?>
        <?= form_input('account_number', 'Account number', $p0['account_number'], $errors, 'text', ['maxlength' => 80]) ?>
        <?= form_input('iban', 'IBAN', $p0['iban'], $errors, 'text', ['maxlength' => 80]) ?>
        <div class="row">
          <div class="col-6"><?= form_input('swift_code', 'SWIFT / BIC', $p0['swift_code'], $errors, 'text', ['maxlength' => 30]) ?></div>
          <div class="col-6"><?= form_input('branch', 'Branch', $p0['branch'], $errors, 'text', ['maxlength' => 150]) ?></div>
        </div>
        <?= form_input('reference_format', 'Payment reference to use', $p0['reference_format'], $errors, 'text', ['maxlength' => 255, 'placeholder' => 'Your application reference, e.g. VA26-XXXXXX']) ?>
        <?= form_textarea('instructions_text', 'Written instructions (e.g. mobile wallet / Easypaisa / JazzCash details)', $p0['instructions_text'], $errors, ['rows' => 4, 'maxlength' => 5000]) ?>
        <div class="form-check mb-3">
          <input class="form-check-input<?= invalid($errors, 'confirm_verified') ?>" type="checkbox" name="confirm_verified" value="1" id="confirm_verified">
          <label class="form-check-label small" for="confirm_verified">I confirm these details were verified with the official account holder.</label>
          <?= field_error($errors, 'confirm_verified') ?>
        </div>
        <div class="d-flex gap-2"><button class="btn btn-brand" type="submit">Save</button><?php if ($current): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/payment-instructions.php')) ?>">Cancel</a><?php endif; ?></div>
      </form>
    </div></div>
  </div>
</div>
<?php render_footer();
