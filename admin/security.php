<?php
/**
 * Security Center: health checks, live protection test, attack statistics,
 * IP firewall (block / unblock), staff 2-step login policy and recent security events.
 * Viewing requires audit.view; changing anything requires the Super Admin.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_permission('audit.view');
$super = is_super_admin($user);
$myIp = client_ip();
$errors = [];

if (is_post()) {
    if (!$super) {
        security_event('permission.denied', null, null, ['needed' => 'super_admin', 'uri' => 'admin/security.php']);
        abort(403);
    }
    $action = post('action');
    if ($action === 'block_ip') {
        $ip = post('ip');
        $durations = ['60' => 60, '1440' => 1440, '10080' => 10080, 'forever' => null];
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $errors['ip'] = 'Enter a valid IP address (e.g. 203.0.113.7).';
        } elseif ($ip === $myIp) {
            $errors['ip'] = 'You cannot block your own current IP address.';
        } elseif (!array_key_exists(post('duration'), $durations)) {
            $errors['ip'] = 'Choose how long to block it.';
        } else {
            firewall_ban($ip, 'Manual: ' . (post('reason') !== '' ? mb_substr(post('reason'), 0, 200) : 'blocked by admin'), $durations[post('duration')], 'manual', (int) $user['id']);
            audit_log('firewall.ip_blocked_manually', null, null, ['ip' => $ip, 'duration' => post('duration'), 'reason' => post('reason')], 'security');
            flash('success', 'IP ' . $ip . ' is now blocked.');
            redirect('admin/security.php');
        }
    } elseif ($action === 'unblock_ip') {
        $ip = post('ip');
        firewall_unban($ip);
        audit_log('firewall.ip_unblocked', null, null, ['ip' => $ip], 'security');
        flash('success', 'IP ' . $ip . ' unblocked.');
        redirect('admin/security.php');
    } elseif ($action === 'require_2fa') {
        $on = post('value') === '1';
        if ($on && !user_has_2fa($user)) {
            flash('error', 'Turn on 2-step login for your own account first (Account → Security), so you are not locked out.');
        } else {
            set_setting('require_2fa_staff', $on ? '1' : '0', (int) $user['id']);
            audit_log('security.require_2fa_staff_' . ($on ? 'on' : 'off'), null, null, [], 'security');
            flash('success', $on ? 'All staff must now use 2-step login. Staff without it will be asked to set it up at their next page view.' : '2-step login is now optional for staff.');
        }
        redirect('admin/security.php');
    }
}

$since = date('Y-m-d H:i:s', time() - 86400);
$stat = static fn (string $sql, array $p = []): int => (int) db_value($sql, $p);
$stats = [
    ['Attacks blocked (24 h)', $stat("SELECT COUNT(*) FROM audit_logs WHERE action IN ('firewall.blocked','bot.blocked') AND created_at >= ?", [$since]), 'shield-fill-x', 'ic-red'],
    ['Failed sign-ins (24 h)', $stat("SELECT COUNT(*) FROM audit_logs WHERE action IN ('login.failed','login.2fa_failed') AND created_at >= ?", [$since]), 'person-x', 'ic-amber'],
    ['Blocked IP addresses', $stat('SELECT COUNT(*) FROM ip_blocks WHERE expires_at IS NULL OR expires_at > ?', [now()]), 'slash-circle', 'ic-violet'],
    ['Staff with 2-step login', $stat("SELECT COUNT(*) FROM users WHERE user_type = 'staff' AND status = 'active' AND totp_enabled_at IS NOT NULL"), 'phone', 'ic-green'],
];
$staffTotal = $stat("SELECT COUNT(*) FROM users WHERE user_type = 'staff' AND status = 'active'");
$require2fa = staff_2fa_required();
$cfgPath = str_replace('\\', '/', config_file_path());

$checks = [
    ['Debug mode is off', !config('app.debug'), 'Visitors never see error details.', 'Set debug to false in Site settings / config.php.'],
    ['Site is served over HTTPS', is_https(), 'Connection is encrypted.', 'Install the free SSL certificate (guide step 9).'],
    ['HTTP automatically redirects to HTTPS', (bool) config('app.force_https'), 'Every visit uses HTTPS.', 'Turn on "Force HTTPS" in Site settings after SSL works.'],
    ['Installer files removed', !is_file(APP_ROOT . '/install.php') && !is_file(APP_ROOT . '/setup.php') && !is_file(APP_ROOT . '/unpack.php'), 'No installer can be re-run.', 'Delete install.php, setup.php and unpack.php from htdocs.'],
    ['Install key removed', !is_file(APP_ROOT . '/private/install.key'), 'One-time key deleted.', 'Delete private/install.key.'],
    ['Firewall enabled', firewall_enabled(), 'Attack requests are blocked and repeat attackers banned.', 'Enable the firewall in Site settings.'],
    ['Staff must use 2-step login', $require2fa, 'Stolen staff passwords alone are useless.', 'Turn on "Require 2-step login for staff" below.'],
    ['Your account uses 2-step login', user_has_2fa($user), 'Protected.', 'Account → Security → Set up 2-step login.'],
    ['Encryption key configured', (string) config('app.key', '') !== '', 'Secrets are encrypted with a key outside the database.', 'Re-save Site settings to create one.'],
    ['PHP is up to date (8.1+)', version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP ' . PHP_VERSION, 'Choose PHP 8.1+ in the hosting panel.'],
    ['Config file is protected', !str_starts_with($cfgPath, APP_ROOT) || is_file(APP_ROOT . '/private/.htaccess'), str_starts_with($cfgPath, APP_ROOT) ? 'Inside private/ (blocked by .htaccess).' : 'Outside the web root.', 'Restore private/.htaccess.'],
    ['Email delivery configured', config('mail.driver') === 'smtp', 'Using SMTP.', 'Optional: set up SMTP in Site settings so password-reset emails arrive.'],
];
$score = count(array_filter($checks, static fn ($c) => $c[1]));
$blocks = db_all('SELECT b.*, u.full_name FROM ip_blocks b LEFT JOIN users u ON u.id = b.created_by WHERE b.expires_at IS NULL OR b.expires_at > ? ORDER BY b.created_at DESC LIMIT 50', [now()]);
$events = db_all("SELECT l.*, u.full_name FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.category = 'security' ORDER BY l.id DESC LIMIT 25");
$topAttackers = db_all("SELECT ip_address, COUNT(*) n FROM audit_logs WHERE action IN ('firewall.blocked','bot.blocked','login.failed','idor.blocked') AND created_at >= ? GROUP BY ip_address ORDER BY n DESC LIMIT 5", [$since]);

render_header('Security Center', 'security');
?>
<div class="page-head">
  <div>
    <span class="eyebrow"><i class="bi bi-shield-check"></i> Staff area</span>
    <h1 class="h2 mt-1 mb-1">Security Center</h1>
    <p class="text-muted mb-0">Protection status, blocked attacks and the IP firewall.</p>
  </div>
  <div class="text-end">
    <div class="stat-value <?= $score === count($checks) ? 'text-success' : ($score >= count($checks) - 3 ? 'text-warning' : 'text-danger') ?>"><?= $score ?>/<?= count($checks) ?></div>
    <div class="small text-muted">security checks passed</div>
  </div>
</div>

<div class="row g-3 mb-4">
<?php foreach ($stats as [$label, $n, $icon, $tone]): ?>
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
      <span class="icon-circle <?= $tone ?>"><i class="bi bi-<?= $icon ?>"></i></span>
      <div><div class="stat-value"><?= $n ?><?= $label === 'Staff with 2-step login' ? '<span class="fs-6 text-muted">/' . $staffTotal . '</span>' : '' ?></div><div class="stat-label"><?= e($label) ?></div></div>
    </div></div>
  </div>
<?php endforeach; ?>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card mb-4"><div class="card-body">
      <h2 class="h5 mb-2">Security checks</h2>
      <?php foreach ($checks as [$label, $ok, $good, $fix]): ?>
        <div class="check-row">
          <i class="bi <?= $ok ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-warning' ?>" aria-hidden="true"></i>
          <div><div class="fw-semibold"><?= e($label) ?></div><div class="small text-muted"><?= e($ok ? $good : 'Fix: ' . $fix) ?></div></div>
        </div>
      <?php endforeach; ?>
    </div></div>

    <div class="card mb-4"><div class="card-body">
      <h2 class="h5 mb-1">Live protection test</h2>
      <p class="small text-muted">Your browser tries to open private files directly. Every line must turn green ("blocked").</p>
      <?php foreach ([
          'private/config.php' => 'Configuration file (database password)',
          'private/storage/' => 'Uploaded passports & receipts folder',
          'private/logs/' => 'Log files',
          'includes/bootstrap.php' => 'Program code',
          'database/install.sql' => 'Database file',
          '.htaccess' => 'Server rules file',
      ] as $path => $label): ?>
        <div class="check-row" data-probe="<?= e(url($path)) ?>">
          <i class="bi bi-hourglass-split text-muted probe-icon" aria-hidden="true"></i>
          <div><div class="fw-semibold"><?= e($label) ?></div><div class="small text-muted"><code>/<?= e($path) ?></code> – <span class="probe-result">testing…</span></div></div>
        </div>
      <?php endforeach; ?>
    </div></div>

    <div class="card"><div class="card-body">
      <h2 class="h5 mb-3">Recent security events</h2>
      <?php if (!$events): ?><?= empty_state('No security events yet.', 'shield-check') ?><?php else: ?>
      <div class="table-responsive border-0 shadow-none">
      <table class="table table-sm align-middle small">
        <thead><tr><th>When</th><th>Event</th><th>IP</th><th class="d-none d-md-table-cell">Who</th></tr></thead>
        <tbody>
        <?php foreach ($events as $ev): ?>
          <tr><td class="text-nowrap"><?= e(fmt_date($ev['created_at'], true)) ?></td><td><code><?= e($ev['action']) ?></code><?php $d = json_decode((string) $ev['details'], true); if (!empty($d['reason'])): ?><div class="text-muted"><?= e(str_limit((string) $d['reason'], 60)) ?></div><?php endif; ?></td><td><?= e($ev['ip_address']) ?></td><td class="d-none d-md-table-cell"><?= e($ev['full_name'] ?? 'Guest') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <a class="small" href="<?= e(url('admin/audit.php', ['category' => 'security'])) ?>">Full security log →</a>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="col-lg-5">
    <div class="card mb-4"><div class="card-body">
      <h2 class="h5">2-step login for staff</h2>
      <p class="small text-muted">When required, every staff member must set up an authenticator app before they can use the staff area.</p>
      <div class="d-flex justify-content-between align-items-center gap-2">
        <?= $require2fa ? badge(['r' => ['Required', 'success']], 'r') : badge(['o' => ['Optional', 'warning']], 'o') ?>
        <?php if ($super): ?>
          <?= post_button(url('admin/security.php'), ['action' => 'require_2fa', 'value' => $require2fa ? '0' : '1'], $require2fa ? 'Make optional' : 'Require for all staff', $require2fa ? 'btn btn-sm btn-outline-secondary' : 'btn btn-sm btn-brand', $require2fa ? 'Make 2-step login optional for staff?' : 'Require 2-step login for every staff account?') ?>
        <?php endif; ?>
      </div>
    </div></div>

    <div class="card mb-4"><div class="card-body">
      <h2 class="h5">IP firewall</h2>
      <p class="small text-muted mb-2">Attack requests are blocked automatically; an IP that sends <?= (int) config('security.firewall_threshold', 8) ?>+ attacks in 10 minutes is banned for <?= (int) config('security.firewall_ban_minutes', 60) ?> minutes. Signed-in users are never auto-banned. Your IP: <code><?= e($myIp) ?></code></p>
      <?php if ($super): ?>
      <?= errors_summary($errors) ?>
      <form method="post" class="border rounded p-3 mb-3" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="block_ip">
        <div class="row g-2">
          <div class="col-7"><input class="form-control form-control-sm<?= invalid($errors, 'ip') ?>" name="ip" placeholder="IP address" value="<?= e(old('ip')) ?>" aria-label="IP address" required></div>
          <div class="col-5"><select class="form-select form-select-sm" name="duration" aria-label="Duration"><option value="60">1 hour</option><option value="1440">24 hours</option><option value="10080">7 days</option><option value="forever">Permanently</option></select></div>
          <div class="col-12"><input class="form-control form-control-sm" name="reason" maxlength="200" placeholder="Reason (optional)" aria-label="Reason"></div>
          <div class="col-12"><button class="btn btn-sm btn-outline-danger w-100" type="submit"><i class="bi bi-slash-circle me-1"></i>Block IP</button></div>
        </div>
      </form>
      <?php endif; ?>
      <?php if (!$blocks): ?><p class="small text-muted mb-0">No IP addresses are blocked right now.</p><?php endif; ?>
      <ul class="list-unstyled mb-0">
      <?php foreach ($blocks as $b): ?>
        <li class="border-bottom py-2 d-flex justify-content-between align-items-start gap-2">
          <div class="small"><code class="fs-6"><?= e($b['ip_address']) ?></code> <?= badge([$b['source'] => [$b['source'] === 'auto' ? 'Automatic' : 'Manual', $b['source'] === 'auto' ? 'warning' : 'danger']], $b['source']) ?>
            <div class="text-muted"><?= e($b['reason']) ?></div>
            <div class="text-muted"><?= $b['expires_at'] ? 'Until ' . e(fmt_date($b['expires_at'], true)) : 'Permanent' ?></div></div>
          <?php if ($super): ?><?= post_button(url('admin/security.php'), ['action' => 'unblock_ip', 'ip' => $b['ip_address']], 'Unblock', 'btn btn-sm btn-outline-secondary') ?><?php endif; ?>
        </li>
      <?php endforeach; ?>
      </ul>
    </div></div>

    <div class="card"><div class="card-body">
      <h2 class="h5">Most active suspicious IPs (24 h)</h2>
      <?php if (!$topAttackers): ?><p class="small text-muted mb-0">Nothing suspicious in the last 24 hours.</p><?php endif; ?>
      <ul class="list-unstyled mb-0 small">
      <?php foreach ($topAttackers as $t): ?><li class="d-flex justify-content-between border-bottom py-1"><code><?= e($t['ip_address']) ?></code><span><?= (int) $t['n'] ?> events</span></li><?php endforeach; ?>
      </ul>
    </div></div>
  </div>
</div>
<?php render_footer();
