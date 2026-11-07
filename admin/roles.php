<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_super_admin();
$perms = db_all('SELECT * FROM permissions ORDER BY id');
$permIds = array_map('strval', array_column($perms, 'id'));
$errors = [];

if (is_post()) {
    $action = post('action');
    if ($action === 'create') {
        $v = new Validator($_POST);
        $v->required('name', 'Role name')->max('name', 100, 'Role name')->max('description', 255, 'Description');
        if (!$v->fails() && db_value('SELECT 1 FROM roles WHERE name = ?', [post('name')])) {
            $v->add('name', 'A role with this name already exists.');
        }
        $errors = $v->errors();
        if (!$errors) {
            $id = db_insert('roles', ['name' => post('name'), 'description' => nullable(post('description')), 'created_at' => now()]);
            audit_log('role.created', 'role', $id, ['name' => post('name')], 'security');
            flash('success', 'Role created. Now choose its permissions.');
            redirect('admin/roles.php', ['edit' => $id]);
        }
    } elseif ($action === 'save_permissions') {
        $role = db_one('SELECT * FROM roles WHERE id = ?', [post_int('role_id')]);
        if (!$role) {
            abort(404);
        }
        $selected = array_values(array_intersect($permIds, array_map('strval', (array) ($_POST['permissions'] ?? []))));
        $before = array_column(db_all('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', [$role['id']]), 'code');
        db_transaction(static function () use ($role, $selected) {
            db_run('DELETE FROM role_permissions WHERE role_id = ?', [$role['id']]);
            foreach ($selected as $pid) {
                db_run('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$role['id'], (int) $pid]);
            }
        });
        $after = array_column(db_all('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', [$role['id']]), 'code');
        audit_log('role.permissions_changed', 'role', (int) $role['id'], ['added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after))], 'security');
        flash('success', 'Permissions saved for ' . $role['name'] . '.');
        redirect('admin/roles.php');
    } elseif ($action === 'delete') {
        $role = db_one('SELECT * FROM roles WHERE id = ?', [post_int('role_id')]);
        if ($role && !db_value('SELECT 1 FROM users WHERE role_id = ?', [$role['id']])) {
            db_run('DELETE FROM roles WHERE id = ?', [$role['id']]);
            audit_log('role.deleted', 'role', (int) $role['id'], ['name' => $role['name']], 'security');
            flash('success', 'Role deleted.');
        } else {
            flash('error', 'Roles that are assigned to staff cannot be deleted.');
        }
        redirect('admin/roles.php');
    }
}

$roles = db_all('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS members FROM roles r ORDER BY r.name');
$rolePerms = [];
foreach (db_all('SELECT rp.role_id, p.id, p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id') as $rp) {
    $rolePerms[$rp['role_id']][] = (string) $rp['id'];
}
$edit = query_int('edit');

render_header('Roles & permissions', 'config');
?>
<h1 class="h3 mb-1">Roles &amp; permissions</h1>
<p class="text-muted small">Permissions are enforced on the server for every page and action. Staff without "View all applications" only see applications assigned to them. Super Admins have every permission.</p>
<div class="row g-3">
  <div class="col-lg-8">
  <?php foreach ($roles as $r): $has = $rolePerms[$r['id']] ?? []; ?>
    <div class="card shadow-sm mb-3"><div class="card-body">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div><h2 class="h6 mb-0"><?= e($r['name']) ?></h2><div class="small text-muted"><?= e($r['description']) ?> · <?= (int) $r['members'] ?> member(s)</div></div>
        <div class="d-flex gap-1">
          <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/roles.php', ['edit' => $r['id']])) ?>#role<?= (int) $r['id'] ?>">Edit permissions</a>
          <?php if (!(int) $r['members']): ?><?= post_button(url('admin/roles.php'), ['action' => 'delete', 'role_id' => $r['id']], 'Delete', 'btn btn-sm btn-outline-danger', 'Delete this role?') ?><?php endif; ?>
        </div>
      </div>
      <?php if ($edit === (int) $r['id']): ?>
        <form method="post" class="mt-3" id="role<?= (int) $r['id'] ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_permissions"><input type="hidden" name="role_id" value="<?= (int) $r['id'] ?>">
          <div class="row">
          <?php foreach ($perms as $p): ?>
            <div class="col-md-6"><div class="form-check">
              <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= (int) $p['id'] ?>" id="p<?= (int) $r['id'] ?>_<?= (int) $p['id'] ?>"<?= in_array((string) $p['id'], $has, true) ? ' checked' : '' ?>>
              <label class="form-check-label small" for="p<?= (int) $r['id'] ?>_<?= (int) $p['id'] ?>"><strong><?= e($p['label']) ?></strong><br><span class="text-muted"><?= e($p['description']) ?></span></label>
            </div></div>
          <?php endforeach; ?>
          </div>
          <button class="btn btn-brand mt-2" type="submit">Save permissions</button>
        </form>
      <?php else: ?>
        <div class="mt-2 d-flex flex-wrap gap-1">
          <?php foreach ($perms as $p): if (in_array((string) $p['id'], $has, true)): ?><span class="badge bg-light text-dark border"><?= e($p['label']) ?></span><?php endif; endforeach; ?>
          <?php if (!$has): ?><span class="small text-muted">No permissions</span><?php endif; ?>
        </div>
      <?php endif; ?>
    </div></div>
  <?php endforeach; ?>
  </div>
  <div class="col-lg-4">
    <div class="card shadow-sm"><div class="card-body">
      <h2 class="h5">New role</h2>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <?= form_input('name', 'Role name', '', $errors, 'text', ['required' => true, 'maxlength' => 100]) ?>
        <?= form_input('description', 'Description', '', $errors, 'text', ['maxlength' => 255]) ?>
        <button class="btn btn-brand w-100" type="submit">Create role</button>
      </form>
    </div></div>
  </div>
</div>
<?php render_footer();
