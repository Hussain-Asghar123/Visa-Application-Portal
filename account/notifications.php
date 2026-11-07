<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();
if (!is_staff($user)) {
    $user = require_applicant();
}

if (is_post()) {
    if (post('action') === 'read_all') {
        db_run('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', [$user['id']]);
        flash('success', 'All notifications marked as read.');
    } elseif (post('action') === 'open') {
        $n = db_one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [post_int('notification_id'), $user['id']]);
        if ($n) {
            db_run('UPDATE notifications SET is_read = 1 WHERE id = ?', [$n['id']]);
            // Links are stored by the app as relative paths; refuse anything else.
            if ($n['link'] && preg_match('#^(applicant|admin|account)/[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_=&%\-]*)?$#', $n['link'])) {
                header('Location: ' . url($n['link']), true, 303);
                exit;
            }
        }
    }
    redirect('account/notifications.php');
}

$total = (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$user['id']]);
[$offset, $limit, $page, $pages] = paginate($total, 20);
$items = db_all('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, [$user['id']]);

render_header('Notifications', 'notifications');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0">Notifications</h1>
  <?php if ($total): ?><?= post_button(url('account/notifications.php'), ['action' => 'read_all'], 'Mark all as read', 'btn btn-sm btn-outline-secondary') ?><?php endif; ?>
</div>
<?php if (!$items): ?><?= empty_state('You have no notifications.') ?><?php endif; ?>
<div class="list-group shadow-sm">
<?php foreach ($items as $n): ?>
  <div class="list-group-item py-3<?= $n['is_read'] ? '' : ' notif-unread' ?>">
    <div class="d-flex justify-content-between flex-wrap gap-1">
      <strong class="<?= $n['is_read'] ? 'fw-normal' : '' ?>"><?= e($n['title']) ?></strong>
      <span class="small text-muted"><?= e(fmt_date($n['created_at'], true)) ?></span>
    </div>
    <?php if ($n['body']): ?><div class="small mt-1"><?= e($n['body']) ?></div><?php endif; ?>
    <?php if ($n['link']): ?>
      <form method="post" class="mt-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="open">
        <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
        <button class="btn btn-sm btn-outline-primary" type="submit">Open</button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<div class="mt-3"><?= pagination_links($page, $pages) ?></div>
<?php render_footer();
