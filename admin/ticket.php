<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_permission('support.manage');
$id = query_int('id') ?: post_int('id');
$ticket = db_one('SELECT t.*, u.full_name, u.email, a.reference_no FROM support_tickets t JOIN users u ON u.id = t.user_id LEFT JOIN applications a ON a.id = t.application_id WHERE t.id = ?', [$id]);
if (!$ticket || !staff_can_access_ticket($user, $ticket)) {
    security_event('idor.blocked', 'support_ticket', $id ?: null, ['role' => 'staff']);
    abort(404);
}
$errors = [];

if (is_post()) {
    $action = post('action');
    if ($action === 'reply') {
        $v = new Validator($_POST);
        $v->required('message', 'Message')->max('message', 5000, 'Message');
        $errors = $v->errors();
        if (!$errors) {
            db_insert('ticket_messages', ['ticket_id' => $id, 'user_id' => $user['id'], 'is_staff' => 1, 'message' => $v->value('message'), 'created_at' => now()]);
            $newStatus = post('close') === '1' ? 'closed' : 'awaiting_applicant';
            db_update('support_tickets', ['status' => $newStatus, 'assigned_to' => $ticket['assigned_to'] ?: $user['id']], 'id = ?', [$id]);
            audit_log('ticket.replied', 'support_ticket', $id, ['status' => $newStatus]);
            notify_user((int) $ticket['user_id'], 'Reply to your request ' . $ticket['ticket_no'], 'Our team replied to "' . $ticket['subject'] . '".', 'applicant/ticket.php?id=' . $id, true);
            flash('success', 'Reply sent.');
            redirect('admin/ticket.php', ['id' => $id]);
        }
    } elseif ($action === 'status' && array_key_exists(post('status'), TICKET_STATUS)) {
        db_update('support_tickets', ['status' => post('status')], 'id = ?', [$id]);
        audit_log('ticket.status_changed', 'support_ticket', $id, ['status' => post('status')]);
        flash('success', 'Ticket status updated.');
        redirect('admin/ticket.php', ['id' => $id]);
    } elseif ($action === 'take') {
        db_update('support_tickets', ['assigned_to' => $user['id']], 'id = ?', [$id]);
        audit_log('ticket.assigned', 'support_ticket', $id, ['to' => (int) $user['id']]);
        flash('success', 'Ticket assigned to you.');
        redirect('admin/ticket.php', ['id' => $id]);
    }
}

$messages = db_all('SELECT m.*, u.full_name FROM ticket_messages m JOIN users u ON u.id = m.user_id WHERE m.ticket_id = ? ORDER BY m.created_at, m.id', [$id]);
$canSeeApp = $ticket['application_id'] && staff_can_access_application($user, (int) $ticket['application_id']);

render_header('Ticket ' . $ticket['ticket_no'], 'tickets');
?>
<a class="small" href="<?= e(url('admin/tickets.php')) ?>">&larr; Tickets</a>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 my-2">
  <div>
    <h1 class="h4 mb-0"><?= e($ticket['subject']) ?></h1>
    <div class="small text-muted"><?= e($ticket['ticket_no']) ?> · <?= e($ticket['full_name']) ?> (<?= e($ticket['email']) ?>) · <?= e(TICKET_CATEGORIES[$ticket['category']] ?? $ticket['category']) ?>
      <?php if ($ticket['reference_no']): ?> · <?= $canSeeApp ? '<a href="' . e(url('admin/application.php', ['id' => $ticket['application_id']])) . '">' . e($ticket['reference_no']) . '</a>' : e($ticket['reference_no']) ?><?php endif; ?></div>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <?= badge(TICKET_STATUS, $ticket['status']) ?>
    <?php if ((int) $ticket['assigned_to'] !== (int) $user['id']): ?><?= post_button(url('admin/ticket.php'), ['id' => $id, 'action' => 'take'], 'Assign to me', 'btn btn-sm btn-outline-primary') ?><?php endif; ?>
  </div>
</div>
<?php foreach ($messages as $m): ?>
  <div class="card shadow-sm mb-2 ticket-msg<?= $m['is_staff'] ? ' staff' : '' ?>"><div class="card-body py-2">
    <div class="small text-muted mb-1"><?= e($m['full_name']) ?><?= $m['is_staff'] ? ' (staff)' : '' ?> · <?= e(fmt_date($m['created_at'], true)) ?></div>
    <div class="pre-wrap"><?= e($m['message']) ?></div>
  </div></div>
<?php endforeach; ?>
<div class="row g-3 mt-1">
  <div class="col-lg-8">
    <div class="card shadow-sm"><div class="card-body">
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reply">
        <?= form_textarea('message', 'Reply to applicant', '', $errors, ['required' => true, 'rows' => 4, 'maxlength' => 5000]) ?>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="close" value="1" id="close"><label class="form-check-label" for="close">Close ticket after replying</label></div>
        <button class="btn btn-brand" type="submit">Send reply</button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card shadow-sm"><div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="status">
        <?php $opts = []; foreach (TICKET_STATUS as $k => [$l]) { $opts[$k] = $k === 'awaiting_applicant' ? 'Awaiting applicant' : $l; } ?>
        <?= form_select('status', 'Change status', $opts, $ticket['status'], [], [], null) ?>
        <button class="btn btn-outline-primary w-100" type="submit">Update</button>
      </form>
    </div></div>
  </div>
</div>
<?php render_footer();
