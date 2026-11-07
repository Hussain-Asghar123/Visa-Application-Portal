<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_applicant();
$apps = db_all('SELECT a.*, c.name AS category_name FROM applications a JOIN visa_categories c ON c.id = a.visa_category_id WHERE a.user_id = ? AND a.status <> \'cancelled\' ORDER BY a.created_at DESC LIMIT 5', [$user['id']]);
$notes = db_all('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5', [$user['id']]);
$profile = db_one('SELECT * FROM applicant_profiles WHERE user_id = ?', [$user['id']]) ?? [];
$profileComplete = !empty($profile['date_of_birth']) && !empty($profile['nationality']) && !empty($profile['address_line']) && !empty($profile['country']);
$openTickets = (int) db_value("SELECT COUNT(*) FROM support_tickets WHERE user_id = ? AND status <> 'closed'", [$user['id']]);
$counts = db_one("SELECT COUNT(*) total, SUM(status = 'draft') drafts, SUM(status NOT IN ('draft','approved','rejected','cancelled')) active, SUM(status = 'approved') approved FROM applications WHERE user_id = ?", [$user['id']]);

render_header('Dashboard', 'dashboard');
?>
<div class="page-head">
  <div>
    <span class="eyebrow"><i class="bi bi-grid-1x2"></i> Dashboard</span>
    <h1 class="h2 mt-1">Welcome, <?= e(explode(' ', trim($user['full_name']))[0]) ?> <span aria-hidden="true">👋</span></h1>
    <p class="text-muted mb-0">Here is the latest on your visa applications.</p>
  </div>
  <a class="btn btn-brand" href="<?= e(url('applicant/applications.php')) ?>"><i class="bi bi-plus-lg me-1"></i>New application</a>
</div>

<?php if (!$profileComplete): ?>
  <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span><i class="bi bi-person-vcard me-2"></i>Complete your profile and contact information so we can reach you.</span>
    <a class="btn btn-sm btn-brand" href="<?= e(url('applicant/profile.php')) ?>">Complete profile</a>
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php foreach ([
      ['Active applications', (int) $counts['active'], 'activity', 'ic-blue', 'applicant/applications.php'],
      ['Drafts', (int) $counts['drafts'], 'pencil-square', 'ic-slate', 'applicant/applications.php'],
      ['Approved', (int) $counts['approved'], 'patch-check', 'ic-green', 'applicant/applications.php'],
      ['Open support requests', $openTickets, 'chat-dots', 'ic-amber', 'applicant/tickets.php'],
  ] as [$label, $n, $icon, $tone, $link]): ?>
  <div class="col-6 col-lg-3">
    <a class="card stat-card h-100 text-decoration-none" href="<?= e(url($link)) ?>"><div class="card-body d-flex align-items-center gap-3">
      <span class="icon-circle <?= $tone ?>"><i class="bi bi-<?= $icon ?>"></i></span>
      <div><div class="stat-value"><?= $n ?></div><div class="stat-label"><?= e($label) ?></div></div>
    </div></a>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h5 mb-0">Your applications</h2>
        <a class="small fw-semibold" href="<?= e(url('applicant/applications.php')) ?>">View all</a>
      </div>
      <?php if (!$apps): ?><?= empty_state((int) $counts['total'] ? 'You have no open applications. See all of them under My applications.' : 'No applications yet. Start one to begin.') ?><?php endif; ?>
      <div class="list-group list-group-flush">
      <?php foreach ($apps as $a): ?>
        <a class="list-group-item list-group-item-action px-0" href="<?= e(url('applicant/application.php', ['id' => $a['id']])) ?>">
          <div class="d-flex justify-content-between flex-wrap gap-1">
            <strong><?= e($a['reference_no']) ?></strong><?= status_badge($a['status']) ?>
          </div>
          <div class="small text-muted"><?= e($a['category_name']) ?> · updated <?= e(fmt_date($a['updated_at'] ?? $a['created_at'])) ?></div>
          <?php if ($a['status'] !== 'draft'): ?><?= tracking_badges(tracking_summary($a)) ?><?php endif; ?>
        </a>
      <?php endforeach; ?>
      </div>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card shadow-sm h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h5 mb-0">Recent notifications</h2>
        <a class="small" href="<?= e(url('account/notifications.php')) ?>">View all</a>
      </div>
      <?php if (!$notes): ?><?= empty_state('No notifications yet.') ?><?php endif; ?>
      <ul class="list-unstyled mb-0">
      <?php foreach ($notes as $n): ?>
        <li class="border-bottom py-2<?= $n['is_read'] ? '' : ' fw-semibold' ?>">
          <div class="small"><?= e($n['title']) ?></div>
          <div class="small text-muted"><?= e(fmt_date($n['created_at'], true)) ?></div>
        </li>
      <?php endforeach; ?>
      </ul>
    </div></div>
  </div>
</div>
<?php render_footer();
