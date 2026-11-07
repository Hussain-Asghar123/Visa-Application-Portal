<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_permission('payments.review');
[$scope, $params] = staff_application_scope($user);
$status = in_array(query('status'), ['submitted', 'confirmed', 'rejected'], true) ? query('status') : 'submitted';
$params[] = $status;
$regionFilter = query_int('region');
$regionSql = '';
if ($regionFilter) {
    $regionSql = ' AND p.region_id = ?';
    $params[] = $regionFilter;
}

$total = (int) db_value("SELECT COUNT(*) FROM payments p JOIN applications a ON a.id = p.application_id WHERE $scope AND p.status = ?$regionSql", $params);
[$offset, $limit, $page, $pages] = paginate($total, 25);
$rows = db_all(
    "SELECT p.*, a.reference_no, u.full_name, f.name AS fee_name, r.full_name AS reviewer, g.name AS region_name
     FROM payments p JOIN applications a ON a.id = p.application_id JOIN users u ON u.id = a.user_id
     LEFT JOIN fee_types f ON f.id = p.fee_type_id LEFT JOIN users r ON r.id = p.reviewed_by LEFT JOIN regions g ON g.id = p.region_id
     WHERE $scope AND p.status = ?$regionSql ORDER BY p.created_at " . ($status === 'submitted' ? 'ASC' : 'DESC') . " LIMIT $limit OFFSET $offset",
    $params
);

render_header('Payments', 'payments');
?>
<h1 class="h3 mb-3">Payment receipts</h1>
<ul class="nav nav-pills mb-3">
<?php foreach (PAY_STATUS as $k => [$l]): ?>
  <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('admin/payments.php', array_filter(['status' => $k, 'region' => $regionFilter ?: null]))) ?>"><?= e($l) ?></a></li>
<?php endforeach; ?>
</ul>
<?php if ($regionList = db_all('SELECT id, name FROM regions ORDER BY sort_order, name')): ?>
<form method="get" class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <label class="small fw-semibold" for="region"><i class="bi bi-geo-alt me-1"></i>Region</label>
  <select class="form-select form-select-sm w-auto" id="region" name="region">
    <option value="">All regions</option>
    <?php foreach ($regionList as $g): ?><option value="<?= (int) $g['id'] ?>"<?= $regionFilter === (int) $g['id'] ? ' selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-sm btn-outline-primary" type="submit">Filter</button>
</form>
<?php endif; ?>
<p class="small text-muted">Confirm a payment only after matching the bank reference and amount against your bank statement. Every decision is recorded with your name and the time.</p>
<?php if (!$rows): ?><?= empty_state('Nothing here.') ?><?php else: ?>
<div class="table-responsive">
<table class="table bg-white shadow-sm align-middle">
  <thead><tr><th>Application</th><th>Region</th><th>Amount</th><th class="d-none d-md-table-cell">Bank reference</th><th class="d-none d-md-table-cell">Paid on</th><th>Receipt</th><th><?= $status === 'submitted' ? 'Uploaded' : 'Reviewed' ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $p): ?>
    <tr>
      <td><a href="<?= e(url('admin/application.php', ['id' => $p['application_id'], 'tab' => 'payments'])) ?>"><?= e($p['reference_no']) ?></a><div class="small text-muted"><?= e($p['full_name']) ?></div></td>
      <td class="small"><?= e($p['region_name'] ?? '—') ?></td>
      <td class="text-nowrap"><?= fmt_money($p['amount'], $p['currency']) ?><div class="small text-muted"><?= e($p['fee_name'] ?? '') ?></div></td>
      <td class="d-none d-md-table-cell"><?= e($p['transaction_reference']) ?><div class="small text-muted"><?= e($p['payer_name']) ?></div></td>
      <td class="d-none d-md-table-cell"><?= e(fmt_date($p['paid_on'])) ?></td>
      <td><a href="<?= e(url('download.php', ['type' => 'receipt', 'id' => $p['id']])) ?>">View</a></td>
      <td class="small"><?= $status === 'submitted' ? e(fmt_date($p['created_at'], true)) : e(($p['reviewer'] ?? '—') . ', ' . fmt_date($p['reviewed_at'], true)) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= pagination_links($page, $pages, array_filter(['status' => $status, 'region' => $regionFilter ?: null])) ?>
<?php endif; ?>
<?php render_footer();
