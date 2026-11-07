<?php
/**
 * Super Admin only: create / disable staff accounts and assign roles.
 * New staff never get a default password: they receive a one-time link to set their own.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_super_admin();
$roles = db_all('SELECT * FROM roles ORDER BY name');
$roleOpts = [];
foreach ($roles as $r) {
    $roleOpts[$r['id']] = $r['name'];
}
$errors = [];
$generatedLink = null;

function active_super_admins(): int
{
    return (int) db_value("SELECT COUNT(*) FROM users WHERE user_type = 'staff' AND is_super_admin = 1 AND status = 'active'");
}

if (is_post()) {
    $action = post('action');
    if ($action === 'create') {
        $v = new Validator($_POST);
        $v->required('full_name', 'Full name')->max('full_name', 150, 'Full name')
          ->required('email', 'Email')->email('email', 'Email')
          ->phone('phone', 'Phone')
          ->in('role_id', array_map('strval', array_keys($roleOpts)), 'Role');
        if (post('role_id') === '' && post('is_super_admin') !== '1') {
            $v->add('role_id', 'Choose a role (or make the account a Super Admin).');
        }
        $email = strtolower(post('email'));
        if (!$v->fails() && db_value('SELECT 1 FROM users WHERE email = ?', [$email])) {
            $v->add('email', 'An account with this email already exists.');
        }
        $errors = $v->errors();
        if (!$errors) {
            $isSuper = post('is_super_admin') === '1' ? 1 : 0;
            $id = db_insert('users', [
                'user_type' => 'staff',
                'email' => $email,
                // Random unusable password until the staff member sets their own via the link.
                'password_hash' => hash_password(bin2hex(random_bytes(32))),
                'full_name' => post('full_name'),
                'phone' => nullable(post('phone')),
                'status' => 'active',
                'email_verified_at' => now(),
                'role_id' => post_int('role_id') ?: null,
                'is_super_admin' => $isSuper,
                'created_by' => $user['id'],
                'created_at' => now(),
            ]);
            $token = create_user_token($id, 'reset_password', 72 * 60, (int) $user['id']);
            $generatedLink = absolute_url('reset-password.php', ['token' => $token]);
            send_mail($email, 'Your staff account', "Hello " . post('full_name') . ",\n\nA staff account was created for you on " . app_name() . ". Set your password using this one-time link (valid 72 hours):\n\n{$generatedLink}\n\nIf you were not expecting this, ignore this email.");
            audit_log('staff.created', 'user', $id, ['email' => $email, 'role_id' => post_int('role_id') ?: null, 'super_admin' => $isSuper], 'security');
            flash('success', 'Staff account created. A set-password link was emailed (if email is configured) and is shown below.');
        }
    } else {
        $target = db_one("SELECT * FROM users WHERE id = ? AND user_type = 'staff'", [post_int('user_id')]);
        if (!$target) {
            abort(404);
        }
        $tid = (int) $target['id'];
        $isSelf = $tid === (int) $user['id'];
        switch ($action) {
            case 'set_role':
                $roleId = post_int('role_id');
                if ($roleId && !isset($roleOpts[$roleId])) {
                    flash('error', 'Unknown role.');
                    break;
                }
                db_update('users', ['role_id' => $roleId ?: null], 'id = ?', [$tid]);
                audit_log('staff.role_changed', 'user', $tid, ['from' => $target['role_id'], 'to' => $roleId ?: null], 'security');
                flash('success', 'Role updated for ' . $target['full_name'] . '.');
                break;
            case 'toggle_super':
                $make = (int) $target['is_super_admin'] === 1 ? 0 : 1;
                if ($make === 0 && ($isSelf || active_super_admins() <= 1)) {
                    flash('error', 'You cannot remove Super Admin rights from yourself or from the last Super Admin.');
                    break;
                }
                db_run('UPDATE users SET is_super_admin = ?, session_version = session_version + 1 WHERE id = ?', [$make, $tid]);
                audit_log($make ? 'staff.super_admin_granted' : 'staff.super_admin_revoked', 'user', $tid, [], 'security');
                flash('success', 'Super Admin rights ' . ($make ? 'granted to ' : 'removed from ') . $target['full_name'] . '.');
                break;
            case 'disable':
                if ($isSelf || ((int) $target['is_super_admin'] === 1 && active_super_admins() <= 1)) {
                    flash('error', 'You cannot disable your own account or the last Super Admin.');
                    break;
                }
                db_run("UPDATE users SET status = 'disabled', session_version = session_version + 1 WHERE id = ?", [$tid]);
                audit_log('staff.disabled', 'user', $tid, [], 'security');
                flash('success', $target['full_name'] . ' disabled and signed out.');
                break;
            case 'enable':
                db_run("UPDATE users SET status = 'active' WHERE id = ?", [$tid]);
                audit_log('staff.enabled', 'user', $tid, [], 'security');
                flash('success', $target['full_name'] . ' enabled.');
                break;
            case 'reset_2fa':
                db_run('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL, session_version = session_version + 1 WHERE id = ?', [$tid]);
                db_run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$tid]);
                audit_log('2fa.reset_by_admin', 'user', $tid, [], 'security');
                notify_user($tid, '2-step login was reset', 'An administrator reset 2-step login for your account after verifying your identity. Set it up again under Account > Security.', 'account/security.php', true);
                flash('success', '2-step login reset for ' . $target['email'] . '. They were signed out and can set it up again.');
                break;
            case 'password_link':
                $token = create_user_token($tid, 'reset_password', 24 * 60, (int) $user['id']);
                $generatedLink = absolute_url('reset-password.php', ['token' => $token]);
                audit_log('staff.reset_link_issued', 'user', $tid, [], 'security');
                break;
        }
    }
    if ($generatedLink === null && !$errors) {
        redirect('admin/staff.php');
    }
}

$staff = db_all("SELECT u.*, r.name AS role_name, (SELECT COUNT(*) FROM application_assignments aa WHERE aa.staff_user_id = u.id) AS assigned FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.user_type = 'staff' ORDER BY u.status, u.full_name");

render_header('Staff accounts', 'config');
?>
<h1 class="h3 mb-3">Staff accounts</h1>
<?php if ($generatedLink): ?>
  <div class="alert alert-warning">
    <strong>One-time set-password link.</strong> Send it privately to the staff member. It is shown only once.
    <div class="mt-2"><code class="user-select-all text-break"><?= e($generatedLink) ?></code></div>
  </div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="table-responsive">
    <table class="table bg-white shadow-sm align-middle">
      <thead><tr><th>Staff member</th><th>Role</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($staff as $s): ?>
        <tr>
          <td><?= e($s['full_name']) ?><?= (int) $s['id'] === (int) $user['id'] ? ' <span class="badge bg-light text-dark border">You</span>' : '' ?><?= !empty($s['totp_enabled_at']) ? ' <span class="badge soft bg-success-subtle text-success-emphasis border-success-subtle" title="2-step login on"><i class="bi bi-shield-check"></i> 2-step</span>' : '' ?><div class="small text-muted"><?= e($s['email']) ?> · <?= (int) $s['assigned'] ?> assigned · last login <?= e(fmt_date($s['last_login_at'], true)) ?></div></td>
          <td>
            <?php if ((int) $s['is_super_admin']): ?><span class="badge bg-warning text-dark">Super Admin</span><?php endif; ?>
            <form method="post" class="d-flex gap-1 mt-1">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="set_role"><input type="hidden" name="user_id" value="<?= (int) $s['id'] ?>">
              <select class="form-select form-select-sm" name="role_id" aria-label="Role">
                <option value="">No role</option>
                <?php foreach ($roleOpts as $rid => $rname): ?><option value="<?= (int) $rid ?>"<?= (int) $s['role_id'] === (int) $rid ? ' selected' : '' ?>><?= e($rname) ?></option><?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
            </form>
          </td>
          <td><?= $s['status'] === 'active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Disabled</span>' ?></td>
          <td class="text-end">
            <div class="d-flex flex-wrap gap-1 justify-content-end">
              <?= post_button(url('admin/staff.php'), ['action' => 'password_link', 'user_id' => $s['id']], 'Password link', 'btn btn-sm btn-outline-secondary', 'Generate a one-time set-password link?') ?>
              <?php if (!empty($s['totp_enabled_at'])): ?><?= post_button(url('admin/staff.php'), ['action' => 'reset_2fa', 'user_id' => $s['id']], 'Reset 2-step', 'btn btn-sm btn-outline-warning', 'Reset 2-step login for this staff member?') ?><?php endif; ?>
              <?= post_button(url('admin/staff.php'), ['action' => 'toggle_super', 'user_id' => $s['id']], (int) $s['is_super_admin'] ? 'Revoke super' : 'Make super', 'btn btn-sm btn-outline-warning', 'Change Super Admin rights for this account?') ?>
              <?php if ($s['status'] === 'active'): ?>
                <?= post_button(url('admin/staff.php'), ['action' => 'disable', 'user_id' => $s['id']], 'Disable', 'btn btn-sm btn-outline-danger', 'Disable this staff account?') ?>
              <?php else: ?>
                <?= post_button(url('admin/staff.php'), ['action' => 'enable', 'user_id' => $s['id']], 'Enable', 'btn btn-sm btn-outline-success') ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card shadow-sm"><div class="card-body">
      <h2 class="h5">Create staff account</h2>
      <?= errors_summary($errors) ?>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <?= form_input('full_name', 'Full name', '', $errors, 'text', ['required' => true, 'maxlength' => 150]) ?>
        <?= form_input('email', 'Email', '', $errors, 'email', ['required' => true, 'maxlength' => 190]) ?>
        <?= form_input('phone', 'Phone (optional)', '', $errors, 'tel', ['maxlength' => 25]) ?>
        <?= form_select('role_id', 'Role', $roleOpts, '', $errors, [], 'Select role…') ?>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_super_admin" value="1" id="is_super_admin"><label class="form-check-label" for="is_super_admin">Super Admin (full access – use sparingly)</label></div>
        <button class="btn btn-brand w-100" type="submit">Create &amp; generate set-password link</button>
      </form>
      <p class="small text-muted mt-2 mb-0">No default password is ever set. The new staff member chooses their own password with the one-time link.</p>
    </div></div>
  </div>
</div>
<?php render_footer();
