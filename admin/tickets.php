<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_permission('support.manage');
[$scope, $params] = staff_ticket_scope($user);
$status = array_key_exists(query('status'), TICKET_STATUS) ? query('status') : '';
$where = $scope;
if ($status !== '') {
    $where .= ' AND t.status = ?';
    $params[] = $status;
} else {
    $where .= " AND t.status <> 'closed'";
}
$total = (int) db_value("SELECT COUNT(*) FROM support_tickets t WHERE $where", $params);
[$offset, $limit, $page, $pages] = paginate($total, 25);
$rows = db_all(
    "SELECT t.*, u.full_name, a.reference_no, s.full_name AS assignee
     FROM support_tickets t JOIN users u ON u.id = t.user_id LEFT JOIN applications a ON a.id = t.application_id LEFT JOIN users s ON s.id = t.assigned_to
     WHERE $where ORDER BY FIELD(t.status, 'awaiting_staff', 'open', 'awaiting_applicant', 'closed'), COALESCE(t.updated_at, t.created_at) DESC LIMIT $limit OFFSET $offset",
    $params
);

render_header('Support tickets', 'tickets');
?>
<h1 class="h3 mb-3">Support tickets</h1>
<ul class="nav nav-pills mb-3 flex-nowrap overflow-auto">
  <li class="nav-item"><a class="nav-link<?= $status === '' ? ' active' : '' ?>" href="<?= e(url('admin/tickets.php')) ?>">Open</a></li>
  <?php foreach (TICKET_STATUS as $k => [$l]): ?>
  <li class="nav-item"><a class="nav-link text-nowrap<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('admin/tickets.php', ['status' => $k])) ?>"><?= e($k === 'awaiting_applicant' ? 'Awaiting applicant' : $l) ?></a></li>
  <?php endforeach; ?>
</ul>
<?php if (!$rows): ?><?= empty_state('No tickets.') ?><?php endif; ?>
<div class="list-group shadow-sm">
<?php foreach ($rows as $t): ?>
  <a class="list-group-item list-group-item-action" href="<?= e(url('admin/ticket.php', ['id' => $t['id']])) ?>">
    <div class="d-flex justify-content-between flex-wrap gap-1"><strong><?= e($t['subject']) ?></strong><?= badge(TICKET_STATUS, $t['status']) ?></div>
    <div class="small text-muted"><?= e($t['ticket_no']) ?> · <?= e($t['full_name']) ?><?= $t['reference_no'] ? ' · ' . e($t['reference_no']) : '' ?> · <?= e(TICKET_CATEGORIES[$t['category']] ?? $t['category']) ?> · <?= e($t['assignee'] ? 'assigned to ' . $t['assignee'] : 'unassigned') ?> · <?= e(fmt_date($t['updated_at'] ?? $t['created_at'], true)) ?></div>
  </a>
<?php endforeach; ?>
</div>
<div class="mt-3"><?= pagination_links($page, $pages, array_filter(['status' => $status])) ?></div>
<?php render_footer();
