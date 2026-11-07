<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_permission('audit.view');
$category = in_array(query('category'), ['audit', 'security'], true) ? query('category') : '';
$action = mb_substr(query('action'), 0, 80);
$from = query('from');
$to = query('to');

$where = ['1=1'];
$params = [];
if ($category !== '') {
    $where[] = 'l.category = ?';
    $params[] = $category;
}
if ($action !== '') {
    $where[] = 'l.action LIKE ?';
    $params[] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $action) . '%';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'l.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'l.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}
$sqlWhere = implode(' AND ', $where);
$total = (int) db_value("SELECT COUNT(*) FROM audit_logs l WHERE $sqlWhere", $params);
[$offset, $limit, $page, $pages] = paginate($total, 50);
$rows = db_all("SELECT l.*, u.full_name, u.email FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE $sqlWhere ORDER BY l.id DESC LIMIT $limit OFFSET $offset", $params);
$securityToday = (int) db_value("SELECT COUNT(*) FROM audit_logs WHERE category = 'security' AND action IN ('login.failed','login.throttled','idor.blocked','permission.denied','csrf.rejected','download.denied','setup.bad_secret') AND created_at >= ?", [date('Y-m-d H:i:s', time() - 86400)]);

render_header('Audit log', 'audit');
?>
<h1 class="h3 mb-1">Audit log &amp; security events</h1>
<p class="small text-muted mb-3">Suspicious security events in the last 24 hours: <strong class="<?= $securityToday ? 'text-danger' : 'text-success' ?>"><?= $securityToday ?></strong> (failed logins, blocked ID tampering, denied permissions, CSRF failures).</p>
<form class="card shadow-sm mb-3" method="get">
  <div class="card-body row g-2 align-items-end">
    <div class="col-6 col-md-2"><?= str_replace('mb-3', 'mb-0', form_select('category', 'Type', ['audit' => 'Audit', 'security' => 'Security'], $category, [], [], 'All')) ?></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="action">Action starts with</label><input class="form-control" id="action" name="action" value="<?= e($action) ?>" placeholder="e.g. payment."></div>
    <div class="col-6 col-md-2"><label class="form-label small" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <div class="col-md-3 d-flex gap-2"><button class="btn btn-brand w-100">Filter</button><a class="btn btn-outline-secondary" href="<?= e(url('admin/audit.php')) ?>">Reset</a></div>
  </div>
</form>
<div class="table-responsive">
<table class="table table-sm bg-white shadow-sm align-middle small">
  <thead><tr><th>When</th><th>Who</th><th>Action</th><th class="d-none d-md-table-cell">Record</th><th class="d-none d-lg-table-cell">Details</th><th class="d-none d-md-table-cell">IP</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['category'] === 'security' ? 'table-warning' : '' ?>">
      <td class="text-nowrap"><?= e(fmt_date($r['created_at'], true)) ?></td>
      <td><?= e($r['full_name'] ?? 'Guest') ?><div class="text-muted d-none d-md-block"><?= e($r['email'] ?? '') ?></div></td>
      <td><code><?= e($r['action']) ?></code></td>
      <td class="d-none d-md-table-cell"><?= e($r['entity_type'] ? $r['entity_type'] . ' #' . $r['entity_id'] : '—') ?></td>
      <td class="d-none d-lg-table-cell text-break audit-details"><?= e(str_limit($r['details'], 300)) ?></td>
      <td class="d-none d-md-table-cell"><?= e($r['ip_address']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= pagination_links($page, $pages, array_filter(['category' => $category, 'action' => $action, 'from' => $from, 'to' => $to])) ?>
<?php render_footer();
