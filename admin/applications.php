<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_staff();
[$scope, $params] = staff_application_scope($user);

$where = [$scope, "a.status <> 'draft'"];
$status = query('status');
if (isset(APP_STATUSES[$status])) {
    $where = [$scope, 'a.status = ?'];
    $params[] = $status;
}
$cat = query_int('category');
if ($cat) {
    $where[] = 'a.visa_category_id = ?';
    $params[] = $cat;
}
$q = mb_substr(query('q'), 0, 100);
if ($q !== '') {
    $where[] = '(a.reference_no LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR a.passport_number LIKE ?)';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    array_push($params, $like, $like, $like, $like);
}
$queue = query('queue');
if ($queue === 'mine') {
    $where[] = 'EXISTS (SELECT 1 FROM application_assignments m WHERE m.application_id = a.id AND m.staff_user_id = ?)';
    $params[] = $user['id'];
} elseif ($queue === 'unassigned' && can('applications.assign', $user)) {
    $where[] = "a.status NOT IN ('cancelled','rejected','approved') AND NOT EXISTS (SELECT 1 FROM application_assignments m WHERE m.application_id = a.id)";
} elseif ($queue === 'documents') {
    $where[] = "EXISTS (SELECT 1 FROM documents d WHERE d.application_id = a.id AND d.status = 'pending')";
} elseif ($queue === 'fingerprints') {
    $where[] = "EXISTS (SELECT 1 FROM biometric_captures b WHERE b.application_id = a.id AND b.status = 'submitted')";
} elseif ($queue === 'biometrics') {
    $where[] = "EXISTS (SELECT 1 FROM biometric_appointments b WHERE b.application_id = a.id AND b.status IN ('scheduled','attended'))";
}

$sqlWhere = implode(' AND ', $where);
$total = (int) db_value("SELECT COUNT(*) FROM applications a JOIN users u ON u.id = a.user_id WHERE $sqlWhere", $params);
[$offset, $limit, $page, $pages] = paginate($total, 25);
$rows = db_all(
    "SELECT a.id, a.reference_no, a.status, a.submitted_at, a.updated_at, a.created_at, u.full_name, u.email, c.name AS category_name,
            (SELECT GROUP_CONCAT(s.full_name SEPARATOR ', ') FROM application_assignments x JOIN users s ON s.id = x.staff_user_id WHERE x.application_id = a.id) AS assignees
     FROM applications a JOIN users u ON u.id = a.user_id JOIN visa_categories c ON c.id = a.visa_category_id
     WHERE $sqlWhere ORDER BY COALESCE(a.updated_at, a.created_at) DESC LIMIT $limit OFFSET $offset",
    $params
);
$catOpts = [];
foreach (db_all('SELECT id, name FROM visa_categories ORDER BY sort_order, name') as $c) {
    $catOpts[$c['id']] = $c['name'];
}
$statusOpts = [];
foreach (APP_STATUSES as $k => [$l]) {
    $statusOpts[$k] = $l;
}
$queueOpts = ['mine' => 'Assigned to me', 'documents' => 'Documents awaiting review', 'biometrics' => 'Biometrics open', 'fingerprints' => 'Fingerprints to review'];
if (can('applications.assign', $user)) {
    $queueOpts['unassigned'] = 'Unassigned';
}

render_header('Applications', 'applications');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0">Applications <span class="text-muted fs-6">(<?= $total ?>)</span></h1>
  <?php if (can('applications.review', $user)): ?><a class="btn btn-brand" href="<?= e(url('admin/client-new.php')) ?>"><i class="bi bi-person-plus me-1"></i>Add client by passport</a><?php endif; ?>
</div>
<form class="card shadow-sm mb-3" method="get">
  <div class="card-body row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Reference, name, email, passport"></div>
    <div class="col-6 col-md-2"><?= str_replace('mb-3', 'mb-0', form_select('status', 'Status', $statusOpts, $status, [], [], 'Submitted (all)')) ?></div>
    <div class="col-6 col-md-2"><?= str_replace('mb-3', 'mb-0', form_select('category', 'Category', $catOpts, (string) $cat, [], [], 'All')) ?></div>
    <div class="col-6 col-md-3"><?= str_replace('mb-3', 'mb-0', form_select('queue', 'Queue', $queueOpts, $queue, [], [], 'Any')) ?></div>
    <div class="col-6 col-md-2 d-flex gap-2"><button class="btn btn-brand w-100" type="submit">Filter</button><a class="btn btn-outline-secondary" href="<?= e(url('admin/applications.php')) ?>">Reset</a></div>
  </div>
</form>
<?php if (!$rows): ?><?= empty_state('No applications match these filters.') ?><?php else: ?>
<div class="table-responsive">
<table class="table table-hover bg-white shadow-sm align-middle">
  <thead><tr><th>Reference</th><th>Applicant</th><th class="d-none d-md-table-cell">Category</th><th>Status</th><th class="d-none d-lg-table-cell">Assigned</th><th class="d-none d-md-table-cell">Updated</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="text-nowrap"><a class="fw-semibold" href="<?= e(url('admin/application.php', ['id' => $r['id']])) ?>"><?= e($r['reference_no']) ?></a></td>
      <td><?= e($r['full_name']) ?><div class="small text-muted d-none d-sm-block"><?= is_no_login_email($r['email']) ? 'no email (added by staff)' : e($r['email']) ?></div></td>
      <td class="d-none d-md-table-cell"><?= e($r['category_name']) ?></td>
      <td><?= status_badge($r['status']) ?></td>
      <td class="d-none d-lg-table-cell small"><?= e($r['assignees'] ?? '—') ?></td>
      <td class="d-none d-md-table-cell small text-nowrap"><?= e(fmt_date($r['updated_at'] ?? $r['created_at'], true)) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= pagination_links($page, $pages, array_filter(['q' => $q, 'status' => $status, 'category' => $cat ?: '', 'queue' => $queue])) ?>
<?php endif; ?>
<?php render_footer();
