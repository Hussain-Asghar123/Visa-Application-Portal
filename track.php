<?php
/**
 * Public status tracker. Two ways in:
 *   - track.php?t=<token>   the status & offer letter link staff share; asks for the passport number first
 *   - passport number + date of birth (both must match the application)
 * Shows only applicant-safe facts (see tracking_summary()) and the published offer letters; the name is masked.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

const TRACK_MAX_FAILURES = 8;     // failed look-ups per IP ...
const TRACK_WINDOW = 900;         // ... in 15 minutes

$ip = client_ip();
$app = null;
$token = query('t');
$errors = [];
$matches = [];

if ($token !== '') {
    $app = link_application('status');
    if (!$app) {
        // A page left open on a link that was reset keeps polling – that must not lock the applicant out.
        if (query('partial') === '1') {
            http_response_code(404);
            exit;
        }
        rl_hit('track', $ip);
        $errors['t'] = 'This status link is not valid any more. Ask the visa office for a new link, or look up your application below.';
    }
}

if ($app) {
    $gateError = handle_link_unlock($app, 'status');
    if (!link_unlocked($app, 'status')) {
        if (query('partial') === '1') {
            http_response_code(403);
            exit;
        }
        render_link_gate($app, 'status', $gateError);
        exit;
    }
    // Offer letter download – only letters published to this application.
    if (query_int('offer')) {
        $offer = db_one('SELECT * FROM offer_letters WHERE id = ? AND application_id = ? AND is_published = 1', [query_int('offer'), $app['id']]);
        if (!$offer) {
            abort(404);
        }
        audit_log('offer.downloaded', 'offer_letter', (int) $offer['id'], ['application_id' => (int) $app['id'], 'via' => 'status link'], 'audit', (int) $app['user_id']);
        stream_stored_file('offers', $offer['stored_name'], $offer['mime_type'], $offer['original_name']);
    }
}

// Live refresh: app.js fetches only the panel and swaps it in.
if ($app && query('partial') === '1') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo tracking_panel_html($app);
    exit;
}

if (is_post()) {
    $passport = strtoupper(preg_replace('/\s+/', '', post('passport_number')));
    $dob = post('date_of_birth');
    if (rl_exceeded('track', $ip, TRACK_MAX_FAILURES, TRACK_WINDOW)) {
        security_event('track.rate_limited', null, null, ['ip' => $ip]);
        $errors['passport_number'] = 'Too many unsuccessful attempts. Please wait 15 minutes and try again.';
    } elseif ($botError = bot_trap_check('track')) {
        $errors['passport_number'] = $botError;
    } elseif (!preg_match('/^[A-Z0-9]{5,20}$/', $passport)) {
        $errors['passport_number'] = 'Enter your passport number (5–20 letters or digits).';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        $errors['date_of_birth'] = 'Enter your date of birth.';
    } else {
        $matches = db_all(
            "SELECT a.id, a.reference_no, a.status, a.tracking_token, c.name AS category_name FROM applications a
             JOIN visa_categories c ON c.id = a.visa_category_id
             WHERE a.passport_number = ? AND a.date_of_birth = ? AND a.status <> 'draft'
             ORDER BY a.created_at DESC",
            [$passport, $dob]
        );
        if (!$matches) {
            rl_hit('track', $ip);
            $errors['passport_number'] = 'No submitted application matches this passport number and date of birth.';
        } else {
            foreach ($matches as &$m) {
                tracking_token($m);
                link_unlock($m, 'status');
            }
            unset($m);
            if (count($matches) === 1) {
                redirect('track.php', ['t' => $matches[0]['tracking_token']]);
            }
        }
    }
}

render_header($app ? 'Status ' . $app['reference_no'] : 'Track your application', 'track');
?>
<div class="row justify-content-center">
<div class="col-xl-10">

<?php if ($app): ?>
  <?php $link = tracking_url($app); ?>
  <div class="page-head">
    <div>
      <span class="eyebrow"><i class="bi bi-broadcast"></i> Live status</span>
      <h1 class="h2 mt-1 mb-0">Application status</h1>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <button type="button" class="btn btn-outline-primary btn-sm" data-copy="<?= e($link) ?>"><i class="bi bi-link-45deg me-1"></i>Copy link</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('track.php')) ?>"><i class="bi bi-search me-1"></i>Look up another</a>
    </div>
  </div>
  <div class="card shadow-sm"><div class="card-body p-3 p-md-4">
    <div data-track-panel data-track-src="<?= e(url('track.php', ['t' => $app['tracking_token'], 'partial' => 1])) ?>">
      <?= tracking_panel_html($app) ?>
    </div>
    <div class="track-live small text-muted mt-3"><span class="live-dot" aria-hidden="true"></span> Live – this page updates automatically. <span data-track-checked></span></div>
  </div></div>
  <?php $offers = db_all('SELECT id, title, notes, original_name, size_bytes, published_at FROM offer_letters WHERE application_id = ? AND is_published = 1 ORDER BY published_at DESC', [$app['id']]); ?>
  <div class="card shadow-sm mt-3"><div class="card-body p-3 p-md-4">
    <h2 class="h5 mb-3"><i class="bi bi-file-earmark-text me-1"></i>Offer letter</h2>
    <?php if (!$offers): ?><?= empty_state('Your offer letter has not been issued yet. It will appear here as soon as it is ready.', 'hourglass-split') ?><?php endif; ?>
    <?php foreach ($offers as $o): ?>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border rounded-3 p-3 mb-2">
        <div>
          <div class="fw-semibold"><?= e($o['title']) ?></div>
          <div class="small text-muted">Issued <?= e(fmt_date($o['published_at'])) ?> · <?= e(fmt_bytes((int) $o['size_bytes'])) ?></div>
          <?php if ($o['notes']): ?><div class="small mt-1"><?= e($o['notes']) ?></div><?php endif; ?>
        </div>
        <a class="btn btn-brand" href="<?= e(url('track.php', ['t' => $app['tracking_token'], 'offer' => $o['id']])) ?>"><i class="bi bi-download me-1"></i>Download</a>
      </div>
    <?php endforeach; ?>
  </div></div>
  <p class="small text-muted mt-3 mb-0"><i class="bi bi-shield-lock me-1"></i>This link was made for you only – please do not share it. To upload documents, <a href="<?= e(url('login.php')) ?>">sign in</a>.</p>

<?php else: ?>
  <div class="row g-4 align-items-start">
    <div class="col-lg-6">
      <span class="eyebrow"><i class="bi bi-broadcast"></i> Track status</span>
      <h1 class="h2 mt-1">Check your application status</h1>
      <p class="text-muted">Enter the passport number and date of birth you used on your application to see the live status of your:</p>
      <ul class="list-unstyled track-list">
        <li><i class="bi bi-credit-card"></i> Payment</li>
        <li><i class="bi bi-fingerprint"></i> Biometrics</li>
        <li><i class="bi bi-file-earmark-text"></i> Offer letter</li>
        <li><i class="bi bi-briefcase"></i> LMIA</li>
      </ul>
      <p class="small text-muted">Received a status link from the visa office? Just open it – no details needed.</p>
    </div>
    <div class="col-lg-6">
      <div class="card shadow-sm"><div class="card-body p-4">
        <?php if (isset($errors['t'])): ?><div class="alert alert-warning small"><?= e($errors['t']) ?></div><?php endif; ?>
        <?php if (count($matches) > 1): ?>
          <div class="alert alert-info small mb-3">You have more than one application. Choose one:</div>
          <div class="list-group mb-3">
          <?php foreach ($matches as $m): ?>
            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="<?= e(url('track.php', ['t' => $m['tracking_token']])) ?>">
              <span><strong><?= e($m['reference_no']) ?></strong><br><span class="small text-muted"><?= e($m['category_name']) ?></span></span><?= status_badge($m['status']) ?>
            </a>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('track.php')) ?>" novalidate>
          <?= csrf_field() ?>
          <?= bot_trap_fields() ?>
          <?= form_input('passport_number', 'Passport number', '', $errors, 'text', ['required' => true, 'maxlength' => 20, 'autocapitalize' => 'characters', 'autocomplete' => 'off', 'placeholder' => 'e.g. AB1234567']) ?>
          <?= form_input('date_of_birth', 'Date of birth', '', $errors, 'date', ['required' => true]) ?>
          <button class="btn btn-brand w-100" type="submit"><i class="bi bi-search me-1"></i>Check status</button>
        </form>
      </div></div>
    </div>
  </div>
<?php endif; ?>

</div>
</div>
<?php render_footer();
