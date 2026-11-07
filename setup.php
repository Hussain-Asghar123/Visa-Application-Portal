<?php
/**
 * ONE-TIME SUPER ADMIN SETUP
 * ---------------------------------------------------------------------------
 * Works only when ALL of these are true:
 *   - config.php has  'setup' => ['enabled' => true, 'secret' => '<32+ random characters>']
 *   - no Super Admin exists yet
 *   - private/setup.lock does not exist
 * After the Super Admin is created the page locks itself permanently (setup.lock).
 * Then set 'enabled' => false, clear the secret and DELETE this file from the server.
 * There is no default password: you choose it here.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');

$lockFile = APP_ROOT . '/private/setup.lock';
$secret = (string) config('setup.secret', '');
$enabled = (bool) config('setup.enabled', false) && strlen($secret) >= 32;

// ---- file-based throttle (works even before the database tables exist) ----
function setup_attempts_file(): string
{
    return log_dir() . '/setup-attempts.json';
}
function setup_failures(): int
{
    $data = @json_decode((string) @file_get_contents(setup_attempts_file()), true);
    $ip = client_ip();
    $list = array_filter((array) ($data[$ip] ?? []), static fn ($t) => $t > time() - 3600);
    return count($list);
}
function setup_record_failure(): void
{
    $file = setup_attempts_file();
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0750, true);
    }
    $data = @json_decode((string) @file_get_contents($file), true) ?: [];
    $ip = client_ip();
    $data[$ip] = array_values(array_filter((array) ($data[$ip] ?? []), static fn ($t) => $t > time() - 3600));
    $data[$ip][] = time();
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function tables_installed(): bool
{
    try {
        return (int) db_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('users','roles','permissions','applications','audit_logs')") === 5;
    } catch (Throwable) {
        return false;
    }
}

/** Execute a .sql file shipped with the app (simple statement splitter; files contain no procedures). */
function run_sql_file(string $path): int
{
    $sql = (string) file_get_contents($path);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $count = 0;
    foreach (preg_split('/;\s*(\r?\n|$)/', (string) $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            db()->exec($stmt);
            $count++;
        }
    }
    return $count;
}

$checks = [
    ['PHP 8.1 or newer', version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP ' . PHP_VERSION],
    ['PDO MySQL extension', extension_loaded('pdo_mysql'), ''],
    ['mbstring extension', extension_loaded('mbstring'), ''],
    ['fileinfo extension (recommended)', extension_loaded('fileinfo'), extension_loaded('fileinfo') ? '' : 'optional – magic-byte checks still run'],
    ['openssl extension (for SMTP)', extension_loaded('openssl'), ''],
    ['Private storage writable', is_writable(storage_dir()) || @mkdir(storage_dir(), 0750, true), ''],
    ['Log folder writable', is_writable(log_dir()) || @mkdir(log_dir(), 0750, true), ''],
    ['private/.htaccess present', is_file(APP_ROOT . '/private/.htaccess'), ''],
    ['HTTPS', is_https(), is_https() ? '' : 'enable SSL before going live'],
];
$dbOk = false;
$dbError = '';
try {
    db_value('SELECT 1');
    $dbOk = true;
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}
$checks[] = ['Database connection', $dbOk, $dbOk ? '' : $dbError];
$installed = $dbOk && tables_installed();
$superExists = $installed && (bool) db_value("SELECT 1 FROM users WHERE user_type = 'staff' AND is_super_admin = 1 LIMIT 1");
$completed = is_file($lockFile) || $superExists;

$errors = [];
$done = false;

if (is_post() && $enabled && !$completed) {
    if (setup_failures() >= 5) {
        abort(429, 'Too many attempts', 'Too many incorrect setup secrets from your network. Wait one hour and try again.');
    }
    if (!hash_equals($secret, post('setup_secret'))) {
        setup_record_failure();
        if ($installed) {
            security_event('setup.bad_secret');
        }
        $errors['setup_secret'] = 'The setup secret is incorrect.';
    } elseif (post('action') === 'install_tables') {
        if (!$dbOk) {
            $errors['setup_secret'] = 'Fix the database connection first.';
        } else {
            run_sql_file(APP_ROOT . '/database/schema.sql');
            run_sql_file(APP_ROOT . '/database/seed.sql');
            flash('success', 'Database tables and starter data were installed.');
            redirect('setup.php');
        }
    } elseif (post('action') === 'create_admin' && $installed) {
        $v = new Validator($_POST);
        $v->required('full_name', 'Full name')->max('full_name', 150, 'Full name')
          ->required('email', 'Email')->email('email', 'Email');
        $password = (string) ($_POST['password'] ?? '');
        $problems = password_problems($password, post('email'));
        if (strlen($password) < 12) {
            $problems[] = 'Super Admin passwords must be at least 12 characters.';
        }
        foreach ($problems as $p) {
            $v->add('password', $p);
        }
        if ($password !== (string) ($_POST['password_confirm'] ?? '')) {
            $v->add('password_confirm', 'The two passwords do not match.');
        }
        $email = strtolower(post('email'));
        if (!$v->fails() && db_value('SELECT 1 FROM users WHERE email = ?', [$email])) {
            $v->add('email', 'This email is already used by another account.');
        }
        $errors = $v->errors();
        if (!$errors) {
            $id = db_insert('users', [
                'user_type' => 'staff',
                'email' => $email,
                'password_hash' => hash_password($password),
                'full_name' => post('full_name'),
                'status' => 'active',
                'email_verified_at' => now(),
                'is_super_admin' => 1,
                'password_changed_at' => now(),
                'created_at' => now(),
            ]);
            @file_put_contents($lockFile, 'Setup completed ' . date('c') . PHP_EOL, LOCK_EX);
            set_setting('setup_completed_at', now(), $id);
            audit_log('setup.super_admin_created', 'user', $id, ['email' => $email], 'security', $id);
            $done = true;
            $completed = true;
        }
    }
}

render_header('Installation');
?>
<div class="row justify-content-center">
<div class="col-lg-8">
<h1 class="h3 mb-3">Portal installation</h1>

<?php if ($done): ?>
  <div class="alert alert-success">
    <h2 class="h5">Super Admin created</h2>
    <p class="mb-2">Finish securing the installation now:</p>
    <ol class="mb-0">
      <li>Edit <code>private/config.php</code>: set <code>'enabled' =&gt; false</code> and <code>'secret' =&gt; ''</code> in the <code>setup</code> section.</li>
      <li>Delete <code>setup.php</code> from the server (File Manager).</li>
      <li><a href="<?= e(url('login.php')) ?>">Sign in</a> with your new Super Admin account.</li>
    </ol>
  </div>
<?php elseif ($completed): ?>
  <div class="alert alert-warning">
    <h2 class="h5">Setup is already complete</h2>
    <p class="mb-0">A Super Admin exists, so this page is locked. For security, delete <code>setup.php</code> from the server and set <code>setup.enabled</code> to <code>false</code> in <code>private/config.php</code>.</p>
  </div>
<?php elseif (!$enabled): ?>
  <div class="alert alert-info">
    <h2 class="h5">Setup is disabled</h2>
    <p>To create the first Super Admin, open <code>private/config.php</code> in your hosting File Manager and set:</p>
<pre class="bg-white border rounded p-2 small mb-2">'setup' =&gt; [
    'enabled' =&gt; true,
    'secret'  =&gt; 'PASTE-A-RANDOM-SECRET-HERE',
],</pre>
    <p class="mb-1">The secret must be at least 32 characters. You can copy this freshly generated random value (it is different every time this page loads and is not stored anywhere):</p>
    <p><code class="user-select-all fs-6"><?= e(bin2hex(random_bytes(24))) ?></code></p>
    <p class="mb-0 small">Then reload this page.</p>
  </div>
<?php else: ?>
  <?= errors_summary($errors) ?>
  <?php if (!$installed): ?>
    <div class="card shadow-sm mb-4"><div class="card-body">
      <h2 class="h5">Step 1 – Database tables</h2>
      <?php if (!$dbOk): ?>
        <p class="text-danger mb-0">The database connection failed. Check the <code>db</code> section of <code>private/config.php</code>.</p>
      <?php else: ?>
        <p>The database is connected but the tables are missing. Either import <code>database/schema.sql</code> and <code>database/seed.sql</code> in phpMyAdmin, or install them here:</p>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="install_tables">
          <?= form_input('setup_secret', 'Setup secret (from config.php)', '', $errors, 'password', ['required' => true, 'autocomplete' => 'off']) ?>
          <button class="btn btn-brand" type="submit">Install tables and starter data</button>
        </form>
      <?php endif; ?>
    </div></div>
  <?php else: ?>
    <div class="card shadow-sm mb-4"><div class="card-body">
      <h2 class="h5">Create the first Super Admin</h2>
      <p class="small text-muted">Use a personal email you control and a strong, unique password of at least 12 characters. There is no default password.</p>
      <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_admin">
        <?= form_input('setup_secret', 'Setup secret (from config.php)', '', $errors, 'password', ['required' => true, 'autocomplete' => 'off']) ?>
        <?= form_input('full_name', 'Full name', '', $errors, 'text', ['required' => true, 'maxlength' => 150]) ?>
        <?= form_input('email', 'Email', '', $errors, 'email', ['required' => true, 'maxlength' => 190]) ?>
        <?= form_input('password', 'Password (12+ characters)', '', $errors, 'password', ['required' => true, 'autocomplete' => 'new-password', 'minlength' => 12, 'maxlength' => 72]) ?>
        <?= form_input('password_confirm', 'Confirm password', '', $errors, 'password', ['required' => true, 'autocomplete' => 'new-password']) ?>
        <button class="btn btn-brand" type="submit">Create Super Admin</button>
      </form>
    </div></div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($enabled && !$completed): ?>
<div class="card shadow-sm"><div class="card-body">
  <h2 class="h6">Server checks</h2>
  <ul class="list-unstyled mb-0 small">
  <?php foreach ($checks as [$label, $ok, $note]): ?>
    <li><?= $ok ? '<span class="text-success">✔</span>' : '<span class="text-danger">✘</span>' ?> <?= e($label) ?><?= $note !== '' ? ' <span class="text-muted">(' . e($ok ? $note : (str_contains($label, 'Database') ? 'see private/logs for details' : $note)) . ')</span>' : '' ?></li>
  <?php endforeach; ?>
  </ul>
</div></div>
<?php endif; ?>
</div>
</div>
<?php render_footer();
