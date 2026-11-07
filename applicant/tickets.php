<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_applicant();
$myApps = db_all("SELECT id, reference_no FROM applications WHERE user_id = ? AND status <> 'cancelled' ORDER BY created_at DESC", [$user['id']]);
$appOptions = [];
foreach ($myApps as $a) {
    $appOptions[$a['id']] = $a['reference_no'];
}
$errors = [];

if (is_post()) {
    $v = new Validator($_POST);
    $v->required('subject', 'Subject')->max('subject', 200, 'Subject')->min('subject', 4, 'Subject')
      ->required('category', 'Topic')->in('category', array_keys(TICKET_CATEGORIES), 'Topic')
      ->in('application_id', array_map('strval', array_keys($appOptions)), 'Application')
      ->required('message', 'Message')->min('message', 10, 'Message')->max('message', 5000, 'Message');
    if (rl_exceeded('ticket_user', (string) $user['id'], 5, 3600)) {
        $v->add('subject', 'You have opened several requests recently. Please wait before opening another.');
    }
    $errors = $v->errors();
    if (!$errors) {
        rl_hit('ticket_user', (string) $user['id']);
        $appId = post_int('application_id') ?: null;
        $ticketId = db_transaction(static function () use ($v, $user, $appId) {
            $tid = db_insert('support_tickets', [
                'ticket_no' => generate_ticket_no(),
                'user_id' => $user['id'],
                'application_id' => $appId,
                'subject' => $v->value('subject'),
                'category' => $v->value('category'),
                'status' => 'awaiting_staff',
                'created_at' => now(),
            ]);
            db_insert('ticket_messages', ['ticket_id' => $tid, 'user_id' => $user['id'], 'is_staff' => 0, 'message' => $v->value('message'), 'created_at' => now()]);
            return $tid;
        });
        audit_log('ticket.created', 'support_ticket', $ticketId);
        if ($appId && ($app = find_application($appId))) {
            notify_application_staff($app, 'New support request: ' . $v->value('subject'), 'Ticket opened for ' . $app['reference_no'] . '.', 'support.manage');
        } else {
            foreach (db_all("SELECT * FROM users WHERE user_type = 'staff' AND status = 'active'") as $s) {
                if (can('support.manage', $s)) {
                    notify_user((int) $s['id'], 'New support request: ' . $v->value('subject'), 'A general support ticket was opened.', 'admin/ticket.php?id=' . $ticketId);
                }
            }
        }
        flash('success', 'Your request was sent. We will reply here and notify you.');
        redirect('applicant/ticket.php', ['id' => $ticketId]);
    }
}

$tickets = db_all('SELECT t.*, a.reference_no FROM support_tickets t LEFT JOIN applications a ON a.id = t.application_id WHERE t.user_id = ? ORDER BY COALESCE(t.updated_at, t.created_at) DESC', [$user['id']]);
$preselect = query_int('application_id');

render_header('Support', 'tickets');
?>
<h1 class="h3 mb-3">Support</h1>
<div class="row g-3">
  <div class="col-lg-5 order-lg-2">
    <div class="card shadow-sm"><div class="card-body">
      <h2 class="h5">New request</h2>
      <?= errors_summary($errors) ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <?= form_input('subject', 'Subject', '', $errors, 'text', ['required' => true, 'maxlength' => 200]) ?>
        <?= form_select('category', 'Topic', TICKET_CATEGORIES, '', $errors, ['required' => true]) ?>
        <?= form_select('application_id', 'Related application (optional)', $appOptions, $preselect ?: '', $errors, [], 'None') ?>
        <?= form_textarea('message', 'Message', '', $errors, ['required' => true, 'rows' => 5, 'maxlength' => 5000]) ?>
        <button class="btn btn-brand" type="submit">Send request</button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-7 order-lg-1">
    <?php if (!$tickets): ?><?= empty_state('You have no support requests.') ?><?php endif; ?>
    <div class="list-group shadow-sm">
    <?php foreach ($tickets as $t): ?>
      <a class="list-group-item list-group-item-action" href="<?= e(url('applicant/ticket.php', ['id' => $t['id']])) ?>">
        <div class="d-flex justify-content-between flex-wrap gap-1"><strong><?= e($t['subject']) ?></strong><?= badge(TICKET_STATUS, $t['status']) ?></div>
        <div class="small text-muted"><?= e($t['ticket_no']) ?><?= $t['reference_no'] ? ' · ' . e($t['reference_no']) : '' ?> · <?= e(fmt_date($t['updated_at'] ?? $t['created_at'], true)) ?></div>
      </a>
    <?php endforeach; ?>
    </div>
  </div>
</div>
<?php render_footer();
