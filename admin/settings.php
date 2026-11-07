<?php
/**
 * Site settings (Super Admin only): site address, HTTPS, email, uploads and firewall.
 * Saves private/config.php for you – no file editing on the hosting panel needed.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_super_admin();
$cfg = config_merge(config_defaults(), $GLOBALS['__vap_config'] ?? []);
$path = config_file_path();
$errors = [];
$manualCode = null;
$zones = DateTimeZone::listIdentifiers();

if (is_post()) {
    $action = post('action');
    if (!password_verify((string) ($_POST['current_password'] ?? ''), $user['password_hash'])) {
        $errors['current_password'] = 'Enter your current password to confirm.';
        security_event('settings.wrong_password', 'user', (int) $user['id']);
    } elseif ($action === 'test_email') {
        $ok = send_mail((string) $user['email'], 'Test email', "This is a test email from your Visa Application Portal.\nIf you received it, email delivery works.");
        audit_log('settings.test_email', null, null, ['driver' => config('mail.driver'), 'ok' => $ok]);
        flash($ok ? 'success' : 'error', $ok
            ? (config('mail.driver') === 'log' ? 'Email written to private/logs (driver "log" – no real email is sent).' : 'Test email sent to ' . $user['email'] . '. Check your inbox and spam folder.')
            : 'The email could not be sent. Check the SMTP details; the reason is in private/logs/app-*.log.');
        redirect('admin/settings.php');
    } else {
        $v = new Validator($_POST);
        $v->required('name', 'Site name')->max('name', 100, 'Site name')
          ->required('url', 'Site address')->regex('url', '#^https?://[A-Za-z0-9.\-]+(:\d+)?(/[A-Za-z0-9._~\-/]*)?$#', 'Site address must look like https://www.your-domain.com (no trailing slash).')
          ->in('timezone', $zones, 'Timezone')->email('support_email', 'Support email')
          ->int('max_mb', 'Max upload size', 1, 9)
          ->in('driver', ['log', 'smtp', 'mail', 'none'], 'Email method')
          ->email('from_email', 'From email')->max('from_name', 100, 'From name')
          ->max('smtp_host', 150, 'SMTP host')->int('smtp_port', 'SMTP port', 1, 65535)->in('smtp_encryption', ['tls', 'ssl', 'none'], 'SMTP encryption')
          ->max('smtp_username', 150, 'SMTP username');
        $forceHttps = post('force_https') === '1';
        $hsts = post('hsts') === '1';
        if ($forceHttps && !is_https()) {
            $v->add('force_https', 'Open this page with https:// first. Force HTTPS can only be turned on once SSL works, so you cannot lock yourself out.');
        }
        if ($forceHttps && !str_starts_with(post('url'), 'https://')) {
            $v->add('url', 'With Force HTTPS on, the site address must start with https://');
        }
        if ($v->value('driver') === 'smtp' && $v->value('smtp_host') === '') {
            $v->add('smtp_host', 'Enter the SMTP host for the SMTP email method.');
        }
        $errors = $v->errors();
        if (!$errors) {
            $before = $cfg;
            $cfg['app']['name'] = $v->value('name');
            $cfg['app']['url'] = rtrim($v->value('url'), '/');
            $cfg['app']['timezone'] = $v->value('timezone');
            $cfg['app']['support_email'] = $v->value('support_email');
            $cfg['app']['force_https'] = $forceHttps;
            $cfg['app']['hsts'] = $forceHttps && $hsts;
            $cfg['app']['debug'] = false;
            if ((string) $cfg['app']['key'] === '') {
                // Keep using the key existing secrets were encrypted with.
                $cfg['app']['key'] = base64_encode(app_secret_key());
            }
            $cfg['security']['require_email_verification'] = post('require_email_verification') === '1';
            $cfg['security']['firewall'] = post('firewall') === '1';
            $cfg['uploads']['max_mb'] = (int) $v->value('max_mb');
            $cfg['mail']['driver'] = $v->value('driver');
            $cfg['mail']['from_email'] = $v->value('from_email');
            $cfg['mail']['from_name'] = $v->value('from_name');
            $cfg['mail']['smtp']['host'] = $v->value('smtp_host');
            $cfg['mail']['smtp']['port'] = (int) ($v->value('smtp_port') ?: 587);
            $cfg['mail']['smtp']['encryption'] = $v->value('smtp_encryption');
            $cfg['mail']['smtp']['username'] = $v->value('smtp_username');
            if ((string) ($_POST['smtp_password'] ?? '') !== '') {
                $cfg['mail']['smtp']['password'] = (string) $_POST['smtp_password'];
            }
            $cfg['setup'] = ['enabled' => false, 'secret' => ''];
            $changed = [];
            foreach (['app.name', 'app.url', 'app.timezone', 'app.support_email', 'app.force_https', 'app.hsts', 'security.require_email_verification', 'security.firewall', 'uploads.max_mb', 'mail.driver', 'mail.from_email', 'mail.smtp.host', 'mail.smtp.port', 'mail.smtp.encryption', 'mail.smtp.username'] as $key) {
                $a = $before; $b = $cfg;
                foreach (explode('.', $key) as $part) { $a = $a[$part] ?? null; $b = $b[$part] ?? null; }
                if ($a !== $b) { $changed[$key] = ['from' => $a, 'to' => $b]; }
            }
            if ((string) ($_POST['smtp_password'] ?? '') !== '') { $changed['mail.smtp.password'] = 'changed'; }
            if (write_config_file($path, $cfg)) {
                audit_log('settings.updated', null, null, ['changes' => $changed], 'security');
                flash('success', 'Settings saved.' . ($forceHttps ? ' All visits now use HTTPS.' : ''));
                redirect('admin/settings.php');
            }
            $manualCode = config_export($cfg);
            audit_log('settings.manual_save_needed', null, null, ['changes' => $changed], 'security');
        }
    }
}

$detected = (is_https() ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) . base_path();
render_header('Site settings', 'config');
?>
<div class="page-head">
  <div>
    <span class="eyebrow"><i class="bi bi-gear"></i> Configuration</span>
    <h1 class="h2 mt-1 mb-1">Site settings</h1>
    <p class="text-muted mb-0">Change your domain, HTTPS and email here – the portal updates its own config file.</p>
  </div>
  <a class="btn btn-outline-primary" href="<?= e(url('admin/security.php')) ?>"><i class="bi bi-shield-check me-1"></i>Security Center</a>
</div>
<?php if ($manualCode !== null): ?>
  <div class="alert alert-warning">
    <strong>The server did not allow saving the file automatically.</strong> In the hosting File Manager, open <code>private/config.php</code>, replace everything with the text below and save:
    <textarea class="form-control font-monospace small mt-2" rows="12" readonly><?= e($manualCode) ?></textarea>
  </div>
<?php endif; ?>
<?= errors_summary($errors) ?>
<form method="post" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <div class="row g-4">
    <div class="col-lg-6">
      <div class="card mb-4"><div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-globe me-1"></i>Website</h2>
        <?= form_input('name', 'Site name', $cfg['app']['name'], $errors, 'text', ['required' => true, 'maxlength' => 100]) ?>
        <?= form_input('url', 'Site address (your domain)', $cfg['app']['url'] ?: $detected, $errors, 'url', ['required' => true, 'placeholder' => 'https://www.your-domain.com']) ?>
        <p class="form-text mt-n2 mb-3">You are using: <code><?= e($detected) ?></code>. After connecting your domain, enter it here (used in email links).</p>
        <?= form_select('timezone', 'Timezone', array_combine($zones, $zones), $cfg['app']['timezone'], $errors, [], null) ?>
        <?= form_input('support_email', 'Support email (optional)', $cfg['app']['support_email'], $errors, 'email', ['maxlength' => 190]) ?>
        <?= form_input('max_mb', 'Max upload size per file (MB, 1–9)', $cfg['uploads']['max_mb'], $errors, 'number', ['min' => 1, 'max' => 9]) ?>
      </div></div>
      <div class="card"><div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-lock me-1"></i>HTTPS &amp; protection</h2>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input<?= invalid($errors, 'force_https') ?>" type="checkbox" role="switch" name="force_https" value="1" id="force_https"<?= $cfg['app']['force_https'] ? ' checked' : '' ?><?= is_https() ? '' : ' disabled' ?>>
          <label class="form-check-label" for="force_https">Force HTTPS (redirect every http:// visit)</label>
          <?= field_error($errors, 'force_https') ?>
          <?php if (!is_https()): ?><div class="form-text">Available once you open this page over https:// (after installing SSL).</div><?php endif; ?>
        </div>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch" name="hsts" value="1" id="hsts"<?= $cfg['app']['hsts'] ? ' checked' : '' ?><?= is_https() ? '' : ' disabled' ?>>
          <label class="form-check-label" for="hsts">HSTS (browsers always use HTTPS – turn on after a week of working HTTPS)</label>
        </div>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch" name="firewall" value="1" id="firewall"<?= $cfg['security']['firewall'] ? ' checked' : '' ?>>
          <label class="form-check-label" for="firewall">Firewall (block attacks and ban attacking IPs)</label>
        </div>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" name="require_email_verification" value="1" id="rev"<?= $cfg['security']['require_email_verification'] ? ' checked' : '' ?>>
          <label class="form-check-label" for="rev">Applicants must confirm their email (needs working SMTP)</label>
        </div>
      </div></div>
    </div>
    <div class="col-lg-6">
      <div class="card mb-4"><div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-envelope me-1"></i>Email</h2>
        <?= form_select('driver', 'Email method', ['log' => 'Save to log file (no real emails)', 'smtp' => 'SMTP (recommended – Gmail, Brevo, Zoho…)', 'mail' => 'PHP mail() (blocked on most free hosts)', 'none' => 'Off'], $cfg['mail']['driver'], $errors, [], null) ?>
        <div class="row">
          <div class="col-sm-6"><?= form_input('from_email', 'From email', $cfg['mail']['from_email'], $errors, 'email', ['maxlength' => 190]) ?></div>
          <div class="col-sm-6"><?= form_input('from_name', 'From name', $cfg['mail']['from_name'], $errors, 'text', ['maxlength' => 100]) ?></div>
          <div class="col-sm-8"><?= form_input('smtp_host', 'SMTP host', $cfg['mail']['smtp']['host'], $errors, 'text', ['placeholder' => 'smtp.gmail.com']) ?></div>
          <div class="col-sm-4"><?= form_input('smtp_port', 'Port', $cfg['mail']['smtp']['port'], $errors, 'number', ['min' => 1, 'max' => 65535]) ?></div>
          <div class="col-sm-6"><?= form_select('smtp_encryption', 'Encryption', ['tls' => 'TLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None'], $cfg['mail']['smtp']['encryption'], $errors, [], null) ?></div>
          <div class="col-sm-6"><?= form_input('smtp_username', 'SMTP username', $cfg['mail']['smtp']['username'], $errors, 'text', ['autocomplete' => 'off']) ?></div>
        </div>
        <?= form_input('smtp_password', 'SMTP password / app password' . ($cfg['mail']['smtp']['password'] !== '' ? ' (leave empty to keep the saved one)' : ''), '', $errors, 'password', ['autocomplete' => 'new-password']) ?>
      </div></div>
      <div class="card"><div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-person-lock me-1"></i>Confirm and save</h2>
        <?= form_input('current_password', 'Your current password', '', $errors, 'password', ['required' => true, 'autocomplete' => 'current-password']) ?>
        <div class="d-flex flex-wrap gap-2">
          <button class="btn btn-brand" type="submit"><i class="bi bi-save me-1"></i>Save settings</button>
          <button class="btn btn-outline-primary" type="submit" name="action" value="test_email" data-no-disable>Send me a test email</button>
        </div>
        <p class="small text-muted mt-2 mb-0">Saved to <code><?= e(str_replace(APP_ROOT, '', str_replace('\\', '/', $path))) ?></code>. Every change is recorded in the audit log.</p>
      </div></div>
    </div>
  </div>
</form>
<?php render_footer();
