<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_applicant();
$id = query_int('id') ?: post_int('id');
$ticket = db_one('SELECT t.*, a.reference_no FROM support_tickets t LEFT JOIN applications a ON a.id = t.application_id WHERE t.id = ?', [$id]);
if (!$ticket || (int) $ticket['user_id'] !== (int) $user['id']) {
    security_event('idor.blocked', 'support_ticket', $id ?: null, ['role' => 'applicant']);
    abort(404);
}
$errors = [];

if (is_post()) {
    if (post('action') === 'close') {
        db_update('support_tickets', ['status' => 'closed'], 'id = ?', [$id]);
        audit_log('ticket.closed', 'support_ticket', $id);
        flash('success', 'The request was closed.');
        redirect('applicant/ticket.php', ['id' => $id]);
    }
    $v = new Validator($_POST);
    $v->required('message', 'Message')->max('message', 5000, 'Message');
    if (rl_exceeded('ticket_reply', (string) $user['id'], 30, 3600)) {
        $v->add('message', 'Too many messages. Please wait a while.');
    }
    $errors = $v->errors();
    if (!$errors) {
        rl_hit('ticket_reply', (string) $user['id']);
        db_insert('ticket_messages', ['ticket_id' => $id, 'user_id' => $user['id'], 'is_staff' => 0, 'message' => $v->value('message'), 'created_at' => now()]);
        db_update('support_tickets', ['status' => 'awaiting_staff'], 'id = ?', [$id]);
        if ($ticket['assigned_to']) {
            notify_user((int) $ticket['assigned_to'], 'Reply on ' . $ticket['ticket_no'], 'The applicant replied to "' . $ticket['subject'] . '".', 'admin/ticket.php?id=' . $id);
        } elseif ($ticket['application_id'] && ($app = find_application((int) $ticket['application_id']))) {
            notify_application_staff($app, 'Reply on ' . $ticket['ticket_no'], 'The applicant replied to "' . $ticket['subject'] . '".', 'support.manage');
        }
        redirect('applicant/ticket.php', ['id' => $id]);
    }
}

$messages = db_all('SELECT m.*, u.full_name FROM ticket_messages m JOIN users u ON u.id = m.user_id WHERE m.ticket_id = ? ORDER BY m.created_at, m.id', [$id]);

render_header('Support request ' . $ticket['ticket_no'], 'tickets');
?>
<a class="small" href="<?= e(url('applicant/tickets.php')) ?>">&larr; All requests</a>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 my-2">
  <div>
    <h1 class="h4 mb-0"><?= e($ticket['subject']) ?></h1>
    <div class="small text-muted"><?= e($ticket['ticket_no']) ?> · <?= e(TICKET_CATEGORIES[$ticket['category']] ?? $ticket['category']) ?><?= $ticket['reference_no'] ? ' · ' . e($ticket['reference_no']) : '' ?></div>
  </div>
  <?= badge(TICKET_STATUS, $ticket['status']) ?>
</div>
<?php foreach ($messages as $m): ?>
  <div class="card shadow-sm mb-2 ticket-msg<?= $m['is_staff'] ? ' staff' : '' ?>"><div class="card-body py-2">
    <div class="small text-muted mb-1"><?= $m['is_staff'] ? 'Support team' : e($m['full_name']) ?> · <?= e(fmt_date($m['created_at'], true)) ?></div>
    <div class="pre-wrap"><?= e($m['message']) ?></div>
  </div></div>
<?php endforeach; ?>

<?php if ($ticket['status'] !== 'closed'): ?>
<div class="card shadow-sm mt-3"><div class="card-body">
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <?= form_textarea('message', 'Your reply', '', $errors, ['required' => true, 'rows' => 4, 'maxlength' => 5000]) ?>
    <button class="btn btn-brand" type="submit">Send reply</button>
  </form>
  <div class="mt-2"><?= post_button(url('applicant/ticket.php'), ['id' => $id, 'action' => 'close'], 'Close request', 'btn btn-sm btn-outline-secondary', 'Close this support request?') ?></div>
</div></div>
<?php else: ?>
  <p class="text-muted mt-3">This request is closed. Open a new request if you need more help.</p>
<?php endif; ?>
<?php render_footer();
