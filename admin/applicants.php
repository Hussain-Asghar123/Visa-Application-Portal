<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_permission('applicants.manage');
$generatedLink = null;

if (is_post()) {
    $target = db_one("SELECT * FROM users WHERE id = ? AND user_type = 'applicant'", [post_int('user_id')]);
    if (!$target) {
        abort(404);
    }
    $tid = (int) $target['id'];
    switch (post('action')) {
        case 'verify':
            db_run('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [now(), $tid]);
            audit_log('applicant.email_verified_manually', 'user', $tid, [], 'security');
            flash('success', 'Email marked as verified for ' . $target['email'] . '.');
            break;
        case 'disable':
            db_run("UPDATE users SET status = 'disabled', session_version = session_version + 1 WHERE id = ?", [$tid]);
            audit_log('applicant.disabled', 'user', $tid, [], 'security');
            flash('success', 'Account disabled and signed out everywhere.');
            break;
        case 'enable':
            db_run("UPDATE users SET status = 'active' WHERE id = ?", [$tid]);
            audit_log('applicant.enabled', 'user', $tid, [], 'security');
            flash('success', 'Account enabled.');
            break;
        case 'reset_link':
            if ($target['status'] !== 'active') {
                flash('error', 'Enable the account first.');
                break;
            }
            $token = create_user_token($tid, 'reset_password', 24 * 60, (int) $user['id']);
            $generatedLink = absolute_url('reset-password.php', ['token' => $token]);
            audit_log('applicant.reset_link_issued', 'user', $tid, [], 'security');
            break;
        case 'reset_2fa':
            db_run('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL, session_version = session_version + 1 WHERE id = ?', [$tid]);
            db_run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$tid]);
            audit_log('2fa.reset_by_admin', 'user', $tid, [], 'security');
            notify_user($tid, '2-step login was reset', 'An administrator reset 2-step login for your account after verifying your identity. Set it up again under Account > Security.', 'account/security.php', true);
            flash('success', '2-step login reset for ' . $target['email'] . '. They were signed out and can set it up again.');
            break;
        case 'resend_verification':
            send_verification_email($target);
            audit_log('applicant.verification_resent', 'user', $tid);
            flash('success', 'Verification email sent (if email delivery is configured).');
            break;
    }
    if ($generatedLink === null) {
        redirect('admin/applicants.php', array_filter(['q' => query('q')]));
    }
}

$q = mb_substr(query('q'), 0, 100);
$params = [];
$where = "u.user_type = 'applicant'";
if ($q !== '') {
    $where .= ' AND (u.email LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ?)';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $params = [$like, $like, $like];
}
$total = (int) db_value("SELECT COUNT(*) FROM users u WHERE $where", $params);
[$offset, $limit, $page, $pages] = paginate($total, 25);
$rows = db_all("SELECT u.*, (SELECT COUNT(*) FROM applications a WHERE a.user_id = u.id) AS app_count FROM users u WHERE $where ORDER BY u.created_at DESC LIMIT $limit OFFSET $offset", $params);

render_header('Applicants', 'applicants');
?>
<h1 class="h3 mb-3">Applicant accounts <span class="text-muted fs-6">(<?= $total ?>)</span></h1>
<?php if ($generatedLink): ?>
  <div class="alert alert-warning">
    <strong>One-time password reset link (valid 24 hours).</strong> Give it to the applicant only after confirming their identity. It is shown once and is not stored in readable form.
    <div class="mt-2"><code class="user-select-all text-break"><?= e($generatedLink) ?></code></div>
  </div>
<?php endif; ?>
<form method="get" class="d-flex gap-2 mb-3"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search name, email or phone" aria-label="Search"><button class="btn btn-brand">Search</button></form>
<div class="table-responsive">
<table class="table bg-white shadow-sm align-middle">
  <thead><tr><th>Applicant</th><th class="d-none d-md-table-cell">Registered</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e($r['full_name']) ?><div class="small text-muted"><?= is_no_login_email($r['email']) ? 'no email (added by staff)' : e($r['email']) ?> · <?= e($r['phone']) ?> · <?= (int) $r['app_count'] ?> application(s)</div></td>
      <td class="d-none d-md-table-cell small"><?= e(fmt_date($r['created_at'])) ?><div class="text-muted">last login <?= e(fmt_date($r['last_login_at'], true)) ?></div></td>
      <td>
        <?= $r['status'] === 'active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Disabled</span>' ?>
        <?= $r['email_verified_at'] ? '<span class="badge bg-light text-dark border">Verified</span>' : '<span class="badge bg-warning text-dark">Unverified</span>' ?>
      </td>
      <td class="text-end">
        <div class="d-flex flex-wrap gap-1 justify-content-end">
        <?php if (!$r['email_verified_at']): ?>
          <?= post_button(url('admin/applicants.php', ['q' => $q]), ['action' => 'verify', 'user_id' => $r['id']], 'Mark verified', 'btn btn-sm btn-outline-success', 'Mark this email as verified? Only do this after confirming the applicant controls it.') ?>
          <?= post_button(url('admin/applicants.php', ['q' => $q]), ['action' => 'resend_verification', 'user_id' => $r['id']], 'Resend email', 'btn btn-sm btn-outline-secondary') ?>
        <?php endif; ?>
        <?php if (!empty($r['totp_enabled_at'])): ?>
          <?= post_button(url('admin/applicants.php', ['q' => $q]), ['action' => 'reset_2fa', 'user_id' => $r['id']], 'Reset 2-step', 'btn btn-sm btn-outline-warning', 'Reset 2-step login for this applicant? Only do this after confirming their identity.') ?>
        <?php endif; ?>
        <?php if ($r['status'] === 'active'): ?>
          <?= post_button(url('admin/applicants.php', ['q' => $q]), ['action' => 'reset_link', 'user_id' => $r['id']], 'Reset link', 'btn btn-sm btn-outline-primary', 'Generate a one-time password reset link for this applicant?') ?>
          <?= post_button(url('admin/applicants.php', ['q' => $q]), ['action' => 'disable', 'user_id' => $r['id']], 'Disable', 'btn btn-sm btn-outline-danger', 'Disable this account? The applicant will be signed out.') ?>
        <?php else: ?>
          <?= post_button(url('admin/applicants.php', ['q' => $q]), ['action' => 'enable', 'user_id' => $r['id']], 'Enable', 'btn btn-sm btn-outline-success') ?>
        <?php endif; ?>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= pagination_links($page, $pages, array_filter(['q' => $q])) ?>
<?php render_footer();
