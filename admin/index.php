<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_staff();
[$scope, $params] = staff_application_scope($user);

$byStatus = [];
foreach (db_all("SELECT a.status, COUNT(*) n FROM applications a WHERE $scope GROUP BY a.status", $params) as $r) {
    $byStatus[$r['status']] = (int) $r['n'];
}
$queues = [];
if (can('documents.review', $user)) {
    $queues[] = ['file-earmark-check', 'ic-blue', 'Documents awaiting review', (int) db_value("SELECT COUNT(*) FROM documents d JOIN applications a ON a.id = d.application_id WHERE d.status = 'pending' AND a.status <> 'draft' AND $scope", $params), url('admin/applications.php', ['queue' => 'documents'])];
}
if (can('payments.review', $user)) {
    $queues[] = ['credit-card', 'ic-green', 'Payments to verify', (int) db_value("SELECT COUNT(*) FROM payments p JOIN applications a ON a.id = p.application_id WHERE p.status = 'submitted' AND $scope", $params), url('admin/payments.php')];
}
if (can('cancellations.manage', $user)) {
    $queues[] = ['x-octagon', 'ic-red', 'Cancellation requests', (int) db_value("SELECT COUNT(*) FROM cancellation_requests c JOIN applications a ON a.id = c.application_id WHERE c.status = 'pending' AND $scope", $params), url('admin/cancellations.php')];
}
if (can('biometrics.manage', $user)) {
    $queues[] = ['fingerprint', 'ic-blue', 'Fingerprints to review', (int) db_value("SELECT COUNT(DISTINCT b.application_id) FROM biometric_captures b JOIN applications a ON a.id = b.application_id WHERE b.status = 'submitted' AND $scope", $params), url('admin/applications.php', ['queue' => 'fingerprints'])];
    $queues[] = ['fingerprint', 'ic-amber', 'Biometrics awaiting decision', (int) db_value("SELECT COUNT(*) FROM biometric_appointments b JOIN applications a ON a.id = b.application_id WHERE b.status IN ('scheduled','attended') AND $scope", $params), url('admin/applications.php', ['queue' => 'biometrics'])];
}
if (can('support.manage', $user)) {
    [$tScope, $tParams] = staff_ticket_scope($user);
    $queues[] = ['chat-dots', 'ic-violet', 'Support tickets awaiting staff', (int) db_value("SELECT COUNT(*) FROM support_tickets t WHERE t.status IN ('open','awaiting_staff') AND $tScope", $tParams), url('admin/tickets.php')];
}
if (can('applications.assign', $user)) {
    $queues[] = ['person-plus', 'ic-teal', 'Unassigned submitted applications', (int) db_value("SELECT COUNT(*) FROM applications a WHERE a.status NOT IN ('draft','cancelled','rejected','approved') AND NOT EXISTS (SELECT 1 FROM application_assignments x WHERE x.application_id = a.id)"), url('admin/applications.php', ['queue' => 'unassigned'])];
}
// Status board: latest applications (or a passport-number search) with their four tracked stages.
$passport = strtoupper(preg_replace('/\s+/', '', query('passport')));
$boardSql = "SELECT a.*, c.name AS category_name, u.full_name AS applicant_name FROM applications a JOIN users u ON u.id = a.user_id JOIN visa_categories c ON c.id = a.visa_category_id WHERE a.status <> 'draft' AND $scope";
$boardParams = $params;
if ($passport !== '') {
    $boardSql .= ' AND a.passport_number LIKE ?';
    $boardParams[] = '%' . addcslashes($passport, '%_\\') . '%';
}
$board = db_all($boardSql . ' ORDER BY COALESCE(a.updated_at, a.created_at) DESC LIMIT 10', $boardParams);

$recent = db_all("SELECT a.id, a.reference_no, a.status, a.submitted_at, a.updated_at, u.full_name, c.name AS category_name FROM applications a JOIN users u ON u.id = a.user_id JOIN visa_categories c ON c.id = a.visa_category_id WHERE a.status <> 'draft' AND $scope ORDER BY COALESCE(a.updated_at, a.created_at) DESC LIMIT 8", $params);

render_header('Staff dashboard', 'dashboard');
?>
<div class="page-head">
  <div>
    <span class="eyebrow"><i class="bi bi-speedometer2"></i> Staff area</span>
    <h1 class="h2 mt-1 mb-0">Dashboard</h1>
    <div class="text-muted small mt-1">Signed in as <?= e($user['full_name']) ?> · <?= is_super_admin($user) ? 'Super Admin' : e($user['role_name'] ?? 'No role assigned') ?><?= (!is_super_admin($user) && !can('applications.view_all', $user)) ? ' · you see applications assigned to you' : '' ?></div>
  </div>
</div>
<?php if (!is_super_admin($user) && !user_permissions($user)): ?>
  <div class="alert alert-warning">Your account has no permissions yet. Ask the Super Admin to assign you a role.</div>
<?php endif; ?>

<?php if ($queues): ?>
<div class="row g-3 mb-4">
<?php foreach ($queues as [$icon, $tone, $label, $n, $link]): ?>
  <div class="col-sm-6 col-xl-4">
    <a class="card stat-card h-100 text-decoration-none" href="<?= e($link) ?>"><div class="card-body d-flex align-items-center gap-3">
      <span class="icon-circle <?= $tone ?>"><i class="bi bi-<?= $icon ?>"></i></span>
      <div class="flex-grow-1"><div class="stat-value"><?= $n ?></div><div class="stat-label"><?= e($label) ?></div></div>
      <?= $n ? '<span class="badge soft bg-danger-subtle text-danger-emphasis border-danger-subtle">Action needed</span>' : '<i class="bi bi-check-circle-fill text-success" title="All clear"></i>' ?>
    </div></a>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card shadow-sm h-100"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted">Applications by status</h2>
      <ul class="list-unstyled mb-0">
      <?php foreach (APP_STATUSES as $code => [$label]): ?>
        <li class="d-flex justify-content-between border-bottom py-1">
          <a href="<?= e(url('admin/applications.php', ['status' => $code])) ?>"><?= e($label) ?></a><span class="fw-semibold"><?= $byStatus[$code] ?? 0 ?></span>
        </li>
      <?php endforeach; ?>
      </ul>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card shadow-sm h-100"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted">Recently updated</h2>
      <?php if (!$recent): ?><?= empty_state('No applications to show.') ?><?php endif; ?>
      <div class="list-group list-group-flush">
      <?php foreach ($recent as $a): ?>
        <a class="list-group-item list-group-item-action px-0" href="<?= e(url('admin/application.php', ['id' => $a['id']])) ?>">
          <div class="d-flex justify-content-between flex-wrap gap-1"><strong><?= e($a['reference_no']) ?> · <?= e($a['full_name']) ?></strong><?= status_badge($a['status']) ?></div>
          <div class="small text-muted"><?= e($a['category_name']) ?> · updated <?= e(fmt_date($a['updated_at'] ?? $a['submitted_at'], true)) ?></div>
        </a>
      <?php endforeach; ?>
      </div>
    </div></div>
  </div>
</div>

<div class="card shadow-sm mt-3" id="status-board"><div class="card-body">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h2 class="h6 text-uppercase text-muted mb-0"><i class="bi bi-broadcast me-1"></i>Live status board · payment, biometrics, offer letter, LMIA</h2>
    <form class="d-flex gap-2" method="get" action="<?= e(url('admin/index.php')) ?>#status-board">
      <label class="visually-hidden" for="passport">Passport number</label>
      <input class="form-control form-control-sm" id="passport" name="passport" value="<?= e($passport) ?>" placeholder="Search passport number" maxlength="20" autocomplete="off">
      <button class="btn btn-sm btn-brand" type="submit"><i class="bi bi-search"></i><span class="visually-hidden">Search</span></button>
      <?php if ($passport !== ''): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/index.php')) ?>#status-board">Clear</a><?php endif; ?>
    </form>
  </div>
  <?php if (!$board): ?><?= empty_state($passport !== '' ? 'No application with that passport number.' : 'No submitted applications yet.', 'search') ?><?php endif; ?>
  <div class="list-group list-group-flush">
  <?php foreach ($board as $b): ?>
    <div class="list-group-item px-0">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div>
          <a class="fw-semibold" href="<?= e(url('admin/application.php', ['id' => $b['id']])) ?>"><?= e($b['reference_no']) ?> · <?= e($b['applicant_name']) ?></a>
          <div class="small text-muted">Passport <?= e($b['passport_number'] ?: '—') ?> · <?= e($b['category_name']) ?> · <?= status_badge($b['status']) ?></div>
        </div>
        <div class="d-flex gap-2 align-items-start">
          <?php if (can('applications.review')): ?><button type="button" class="btn btn-sm btn-outline-primary" data-copy="<?= e(tracking_url($b)) ?>"><i class="bi bi-link-45deg me-1"></i>Copy status link</button><?php endif; ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/application.php', ['id' => $b['id']])) ?>">Open</a>
        </div>
      </div>
      <?= tracking_badges(tracking_summary($b)) ?>
    </div>
  <?php endforeach; ?>
  </div>
</div></div>
<?php render_footer();
