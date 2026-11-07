<?php
/**
 * ONE-PAGE INSTALLER
 * ---------------------------------------------------------------------------
 * 1. Enter the install key (from START-HERE.html in the deploy kit), your database details
 *    (hosting panel → MySQL Databases) and your Super Admin account.
 * 2. The installer creates all tables, writes private/config.php with a fresh encryption key,
 *    creates the Super Admin, signs you in, then locks and deletes itself.
 * Protected by a one-time install key stored in private/install.key (blocked from the web).
 */
declare(strict_types=1);
define('VAP_INSTALLER', true);
require __DIR__ . '/includes/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
$keyFile = APP_ROOT . '/private/install.key';
$lockFile = APP_ROOT . '/private/install.lock';

/** Remove installer files (best effort). */
function installer_cleanup(string $keyFile): void
{
    @unlink($keyFile);
    foreach (['install.php', 'setup.php', 'unpack.php'] as $f) {
        @unlink(APP_ROOT . '/' . $f);
    }
}

function installer_failures(): int
{
    $data = @json_decode((string) @file_get_contents(log_dir() . '/install-attempts.json'), true);
    return count(array_filter((array) ($data[client_ip()] ?? []), static fn ($t) => $t > time() - 3600));
}

function installer_record_failure(): void
{
    $file = log_dir() . '/install-attempts.json';
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0750, true);
    }
    $data = @json_decode((string) @file_get_contents($file), true) ?: [];
    $ip = client_ip();
    $data[$ip] = array_values(array_filter((array) ($data[$ip] ?? []), static fn ($t) => $t > time() - 3600));
    $data[$ip][] = time();
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

/** Is the portal already fully installed (config + Super Admin)? */
function installer_already_installed(): bool
{
    if (empty($GLOBALS['__vap_config']['db']['host'])) {
        return false;
    }
    try {
        return (bool) db_value("SELECT 1 FROM users WHERE user_type = 'staff' AND is_super_admin = 1 LIMIT 1");
    } catch (Throwable) {
        return false;
    }
}

if (is_file($lockFile) || installer_already_installed()) {
    installer_cleanup($keyFile);
    render_header('Already installed');
    echo '<div class="auth-wrap narrow-form auth-single"><div class="auth-form"><h1 class="h3">The portal is installed</h1>'
        . '<p>The installer is locked and has been removed. Sign in with your Super Admin account.</p>'
        . '<a class="btn btn-brand" href="' . e(url('login.php')) . '">Go to sign in</a></div></div>';
    render_footer();
    exit;
}

$installKey = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
$detectedUrl = (is_https() ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) . base_path();
$zones = DateTimeZone::listIdentifiers();
$popular = ['Asia/Karachi', 'Asia/Dubai', 'Asia/Riyadh', 'Europe/London', 'Asia/Kolkata', 'America/New_York', 'UTC'];
$zoneOpts = [];
foreach (array_merge($popular, $zones) as $z) {
    $zoneOpts[$z] = $z;
}
$checks = [
    ['PHP 8.1 or newer', version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP ' . PHP_VERSION],
    ['PDO MySQL', extension_loaded('pdo_mysql'), ''],
    ['mbstring', extension_loaded('mbstring'), ''],
    ['OpenSSL (encryption, SMTP)', extension_loaded('openssl'), ''],
    ['fileinfo (upload checks)', extension_loaded('fileinfo'), 'optional'],
    ['GD (removes photo GPS data)', extension_loaded('gd'), 'optional'],
    ['private/ folder writable', is_writable(APP_ROOT . '/private'), ''],
    ['Upload storage writable', is_writable(storage_dir()) || @mkdir(storage_dir(), 0750, true), ''],
    ['HTTPS', is_https(), is_https() ? '' : 'fine for now – add SSL after installing'],
];

$errors = [];
$notice = null;
$manualConfig = null;
$done = null;

if (is_post()) {
    if (installer_failures() >= 8) {
        abort(429, 'Too many attempts', 'Too many wrong install keys from your network. Wait one hour and try again.');
    }
    $v = new Validator($_POST);
    if ($installKey === '' || !hash_equals($installKey, post('install_key'))) {
        installer_record_failure();
        $v->add('install_key', $installKey === '' ? 'The install key file (private/install.key) is missing. Upload the files from the deploy kit again.' : 'The install key is not correct. Copy it from START-HERE.html.');
    }
    $v->required('db_host', 'Database host')->max('db_host', 190, 'Database host')
      ->int('db_port', 'Database port', 1, 65535)
      ->required('db_name', 'Database name')->max('db_name', 64, 'Database name')
      ->required('db_user', 'Database username')->max('db_user', 64, 'Database username');
    $dbCfg = ['host' => $v->value('db_host'), 'port' => (int) ($v->value('db_port') ?: 3306), 'name' => $v->value('db_name'), 'user' => $v->value('db_user'), 'pass' => (string) ($_POST['db_pass'] ?? '')];
    $pdo = null;
    if (!$v->fails()) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbCfg['host'], $dbCfg['port'], $dbCfg['name']),
                $dbCfg['user'], $dbCfg['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10]
            );
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            $hint = str_contains($msg, '1045') ? 'Wrong database username or password.' : (str_contains($msg, '1049') ? 'That database name does not exist – copy it exactly from the hosting panel.' : (str_contains($msg, '2002') || str_contains($msg, '2005') ? 'Cannot reach the database host – copy the "MySQL Host Name" exactly (on InfinityFree it looks like sqlXXX.infinityfree.com, not localhost).' : 'Check all database details.'));
            $v->add('db_host', 'Database connection failed: ' . $hint);
        }
    }

    if (post('action') === 'test_db') {
        $errors = $v->errors();
        if (!$errors) {
            $notice = 'Database connection works. Now fill in the rest and press Install.';
        }
    } else {
        $v->required('site_name', 'Site name')->max('site_name', 100, 'Site name')
          ->required('site_url', 'Site address')->regex('site_url', '#^https?://[A-Za-z0-9.\-]+(:\d+)?(/[A-Za-z0-9._~\-/]*)?$#', 'Site address must look like https://your-site.example (no trailing slash).')
          ->in('timezone', $zones, 'Timezone')->email('support_email', 'Support email')
          ->required('admin_name', 'Your name')->max('admin_name', 150, 'Your name')
          ->required('admin_email', 'Your email')->email('admin_email', 'Your email');
        $pw = (string) ($_POST['admin_password'] ?? '');
        if (strlen($pw) < 12) {
            $v->add('admin_password', 'The Super Admin password must be at least 12 characters.');
        }
        foreach (password_problems($pw, post('admin_email')) as $p) {
            $v->add('admin_password', $p);
        }
        if ($pw !== (string) ($_POST['admin_password_confirm'] ?? '')) {
            $v->add('admin_password_confirm', 'The two passwords do not match.');
        }
        if ($pdo && !$v->fails()) {
            try {
                $hasUsers = (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn();
                if ($hasUsers && $pdo->query("SELECT 1 FROM users WHERE user_type = 'staff' AND is_super_admin = 1 LIMIT 1")->fetchColumn()) {
                    $v->add('db_name', 'This database already contains an installed portal with a Super Admin. Use a new, empty database – or sign in to the existing site.');
                }
            } catch (Throwable) {
                // ignore – tables will be created
            }
        }
        $errors = $v->errors();
        if (!$errors) {
            // 1) tables + starter data (safe on an empty or partially installed database)
            db_run_sql_file(APP_ROOT . '/database/schema.sql', $pdo);
            db_run_sql_file(APP_ROOT . '/database/seed.sql', $pdo);
            // 2) configuration file with a fresh encryption key
            $cfg = config_merge(config_defaults(), $GLOBALS['__vap_config'] ?? []);
            $cfg['db'] = $dbCfg;
            $cfg['app']['name'] = $v->value('site_name');
            $cfg['app']['url'] = rtrim($v->value('site_url'), '/');
            $cfg['app']['timezone'] = $v->value('timezone');
            $cfg['app']['support_email'] = $v->value('support_email');
            $cfg['app']['key'] = (string) ($cfg['app']['key'] ?: base64_encode(random_bytes(32)));
            $cfg['app']['debug'] = false;
            $cfg['app']['force_https'] = is_https() && post('force_https') === '1';
            $cfg['mail']['from_name'] = $v->value('site_name');
            $cfg['setup'] = ['enabled' => false, 'secret' => ''];
            $path = config_file_path();
            if (!write_config_file($path, $cfg)) {
                $manualConfig = config_export($cfg);
            } else {
                // 3) Super Admin (inside the new configuration)
                $GLOBALS['__vap_config'] = $cfg;
                $GLOBALS['__vap_config_file'] = $path;
                date_default_timezone_set($cfg['app']['timezone']);
                $email = strtolower($v->value('admin_email'));
                $existing = db_value('SELECT id FROM users WHERE email = ?', [$email]);
                if ($existing) {
                    db_update('users', ['user_type' => 'staff', 'is_super_admin' => 1, 'status' => 'active', 'password_hash' => hash_password($pw), 'full_name' => $v->value('admin_name'), 'email_verified_at' => now(), 'password_changed_at' => now()], 'id = ?', [$existing]);
                    $adminId = (int) $existing;
                } else {
                    $adminId = db_insert('users', [
                        'user_type' => 'staff', 'email' => $email, 'password_hash' => hash_password($pw), 'full_name' => $v->value('admin_name'),
                        'status' => 'active', 'email_verified_at' => now(), 'is_super_admin' => 1, 'password_changed_at' => now(), 'created_at' => now(),
                    ]);
                }
                set_setting('setup_completed_at', now(), $adminId);
                audit_log('install.completed', 'user', $adminId, ['email' => $email, 'site' => $cfg['app']['url']], 'security', $adminId);
                @file_put_contents($lockFile, 'Installed ' . date('c') . PHP_EOL, LOCK_EX);
                @file_put_contents(APP_ROOT . '/private/setup.lock', 'Installed ' . date('c') . PHP_EOL, LOCK_EX);
                installer_cleanup($keyFile);
                // 4) sign the new Super Admin in
                $admin = db_one('SELECT * FROM users WHERE id = ?', [$adminId]);
                login_user($admin);
                $done = ['email' => $email, 'removed' => !is_file(__FILE__)];
            }
        }
    }
}

render_header('Install the portal');
?>
<div class="row justify-content-center">
<div class="col-xl-9">
<?php if ($done): ?>
  <div class="card border-success"><div class="card-body p-4">
    <span class="icon-circle ic-green mb-3"><i class="bi bi-check2-circle"></i></span>
    <h1 class="h3">Installation complete 🎉</h1>
    <p>Your portal is ready and you are signed in as Super Admin (<strong><?= e($done['email']) ?></strong>).</p>
    <ul class="small">
      <li>All database tables were created and the configuration file was written for you.</li>
      <li>The installer <?= $done['removed'] ? 'has deleted itself' : 'is locked – delete <code>install.php</code>, <code>setup.php</code> and <code>unpack.php</code> in the File Manager' ?>.</li>
    </ul>
    <h2 class="h6 mt-3">Next (5 minutes):</h2>
    <ol class="small">
      <li><strong>Security:</strong> Account menu → <em>Security &amp; 2-step login</em> → turn on 2-step login.</li>
      <li><strong>Payments:</strong> Configuration → Payment regions → Payment instructions → Fee types.</li>
      <li><strong>Domain &amp; HTTPS:</strong> after adding your domain and SSL, open Configuration → <em>Site settings</em>, enter the new address and switch on Force HTTPS.</li>
    </ol>
    <a class="btn btn-brand btn-lg" href="<?= e(url('admin/index.php')) ?>">Open the admin dashboard</a>
  </div></div>
<?php else: ?>
  <div class="page-head">
    <div>
      <span class="eyebrow"><i class="bi bi-magic"></i> One-page installer</span>
      <h1 class="h2 mt-1 mb-1">Install your visa portal</h1>
      <p class="text-muted mb-0">Fill this in once. Tables, settings and your admin account are created automatically.</p>
    </div>
  </div>
  <?php if ($installKey === ''): ?>
    <div class="alert alert-danger">The install key file <code>private/install.key</code> is missing, so this installer cannot run. Upload the site again from the deploy kit (it contains the key), or use the manual method in docs/INSTALL.md.</div>
  <?php endif; ?>
  <?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>
  <?php if ($manualConfig !== null): ?>
    <div class="alert alert-warning">
      <strong>One manual step:</strong> the server did not allow creating the settings file. In the File Manager create <code>private/config.php</code>, paste the text below, save, then come back and press <em>Install</em> again.
      <textarea class="form-control font-monospace small mt-2" rows="10" readonly><?= e($manualConfig) ?></textarea>
    </div>
  <?php endif; ?>
  <?= errors_summary($errors) ?>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="card mb-4"><div class="card-body">
      <h2 class="h5"><span class="badge rounded-pill bg-primary me-2">1</span>Install key</h2>
      <p class="small text-muted">Printed in <strong>START-HERE.html</strong> in your deploy kit. It proves you own this site.</p>
      <?= form_input('install_key', 'Install key', '', $errors, 'text', ['required' => true, 'autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => 'XXXX-XXXX-XXXX-XXXX']) ?>
    </div></div>
    <div class="card mb-4"><div class="card-body">
      <h2 class="h5"><span class="badge rounded-pill bg-primary me-2">2</span>Database</h2>
      <p class="small text-muted">Copy these from your hosting control panel → <strong>MySQL Databases</strong>.</p>
      <div class="row">
        <div class="col-md-8"><?= form_input('db_host', 'MySQL host name', '', $errors, 'text', ['required' => true, 'placeholder' => 'sql123.infinityfree.com']) ?></div>
        <div class="col-md-4"><?= form_input('db_port', 'Port', '3306', $errors, 'number') ?></div>
        <div class="col-md-6"><?= form_input('db_name', 'Database name', '', $errors, 'text', ['required' => true, 'placeholder' => 'if0_12345678_visa']) ?></div>
        <div class="col-md-6"><?= form_input('db_user', 'Database username', '', $errors, 'text', ['required' => true, 'placeholder' => 'if0_12345678']) ?></div>
        <div class="col-md-6"><?= form_input('db_pass', 'Database password', '', $errors, 'password', ['autocomplete' => 'off']) ?></div>
        <div class="col-md-6 d-flex align-items-end mb-3"><button class="btn btn-outline-primary w-100" type="submit" name="action" value="test_db" data-no-disable><i class="bi bi-plug me-1"></i>Test connection</button></div>
      </div>
    </div></div>
    <div class="card mb-4"><div class="card-body">
      <h2 class="h5"><span class="badge rounded-pill bg-primary me-2">3</span>Website</h2>
      <div class="row">
        <div class="col-md-6"><?= form_input('site_name', 'Site name', 'Visa Application Portal', $errors, 'text', ['required' => true, 'maxlength' => 100]) ?></div>
        <div class="col-md-6"><?= form_input('site_url', 'Site address', $detectedUrl, $errors, 'url', ['required' => true]) ?></div>
        <div class="col-md-6"><?= form_select('timezone', 'Timezone', $zoneOpts, 'Asia/Karachi', $errors, [], null) ?></div>
        <div class="col-md-6"><?= form_input('support_email', 'Support email (optional)', '', $errors, 'email') ?></div>
      </div>
      <?php if (is_https()): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="force_https" value="1" id="force_https" checked><label class="form-check-label" for="force_https">Always use HTTPS</label></div><?php endif; ?>
    </div></div>
    <div class="card mb-4"><div class="card-body">
      <h2 class="h5"><span class="badge rounded-pill bg-primary me-2">4</span>Your Super Admin account</h2>
      <p class="small text-muted">There is no default password – choose your own (12+ characters, letters and numbers).</p>
      <div class="row">
        <div class="col-md-6"><?= form_input('admin_name', 'Your full name', '', $errors, 'text', ['required' => true, 'maxlength' => 150]) ?></div>
        <div class="col-md-6"><?= form_input('admin_email', 'Your email (this is your login)', '', $errors, 'email', ['required' => true, 'maxlength' => 190]) ?></div>
        <div class="col-md-6"><?= form_input('admin_password', 'Password', '', $errors, 'password', ['required' => true, 'autocomplete' => 'new-password', 'minlength' => 12, 'maxlength' => 72]) ?></div>
        <div class="col-md-6"><?= form_input('admin_password_confirm', 'Confirm password', '', $errors, 'password', ['required' => true, 'autocomplete' => 'new-password']) ?></div>
      </div>
    </div></div>
    <button class="btn btn-brand btn-lg w-100 mb-4" type="submit" name="action" value="install"><i class="bi bi-rocket-takeoff me-1"></i>Install now</button>
  </form>
  <div class="card"><div class="card-body">
    <h2 class="h6">Server checks</h2>
    <?php foreach ($checks as [$label, $ok, $note]): ?>
      <div class="check-row"><i class="bi <?= $ok ? 'bi-check-circle-fill text-success' : ($note === 'optional' || str_starts_with($note, 'fine') ? 'bi-info-circle-fill text-warning' : 'bi-x-circle-fill text-danger') ?>"></i><div class="small"><?= e($label) ?><?= $note !== '' ? ' <span class="text-muted">(' . e($note) . ')</span>' : '' ?></div></div>
    <?php endforeach; ?>
  </div></div>
<?php endif; ?>
</div>
</div>
<?php render_footer();
