<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_permission('cancellations.manage');
[$scope, $params] = staff_application_scope($user);
$rows = db_all(
    "SELECT c.*, a.reference_no, a.status AS app_status, u.full_name
     FROM cancellation_requests c JOIN applications a ON a.id = c.application_id JOIN users u ON u.id = a.user_id
     WHERE $scope AND c.status = 'pending' ORDER BY c.created_at ASC",
    $params
);

render_header('Cancellation requests', 'cancellations');
?>
<h1 class="h3 mb-3">Pending cancellation requests</h1>
<?php if (!$rows): ?><?= empty_state('No pending cancellation requests.') ?><?php endif; ?>
<div class="list-group shadow-sm">
<?php foreach ($rows as $c): ?>
  <a class="list-group-item list-group-item-action py-3" href="<?= e(url('admin/application.php', ['id' => $c['application_id'], 'tab' => 'cancel'])) ?>">
    <div class="d-flex justify-content-between flex-wrap gap-1"><strong><?= e($c['reference_no']) ?> · <?= e($c['full_name']) ?></strong><span class="small text-muted"><?= e(fmt_date($c['created_at'], true)) ?></span></div>
    <div class="small">Current status: <?= status_badge($c['app_status']) ?></div>
    <div class="small text-muted mt-1"><?= e(str_limit($c['reason'], 200)) ?></div>
  </a>
<?php endforeach; ?>
</div>
<?php render_footer();
