<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Passport check shared by the special links (track.php, offer.php, biometrics.php, lmia.php).
 * The link token finds the application; the visitor must then type the passport number
 * on that application before anything is shown. Unlocking lasts for the browser session
 * and is tied to the token, so "Reset link" locks everyone out again.
 */
const LINK_MAX_FAILURES = 8;   // wrong passport numbers per IP or per link ...
const LINK_WINDOW = 900;       // ... in 15 minutes

/** Application for ?t=<token> of this link type, or null. Drafts never have a working link. */
function link_application(string $purpose): ?array
{
    $token = query('t');
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $col = ACCESS_LINKS[$purpose][0];
    $id = (int) db_value("SELECT id FROM applications WHERE `$col` = ? AND status <> 'draft'", [$token]);
    return $id ? find_application($id) : null;
}

function link_unlocked(array $app, string $purpose): bool
{
    $token = (string) $app[ACCESS_LINKS[$purpose][0]];
    if (!empty($_SESSION['link_ok'][$purpose][$token])) {
        return true;
    }
    // The applicant signed in to their own account does not need to type it again.
    $u = safe_current_user();
    return $u && !is_staff($u) && (int) $u['id'] === (int) $app['user_id'];
}

function link_unlock(array $app, string $purpose): void
{
    $_SESSION['link_ok'][$purpose][(string) $app[ACCESS_LINKS[$purpose][0]]] = true;
}

/** Handle the passport form (POST action=unlock_link). Redirects on success, returns an error otherwise. */
function handle_link_unlock(array $app, string $purpose): ?string
{
    if (!is_post() || post('action') !== 'unlock_link') {
        return null;
    }
    $ip = client_ip();
    $token = (string) $app[ACCESS_LINKS[$purpose][0]];
    if (rl_exceeded('link', $ip, LINK_MAX_FAILURES, LINK_WINDOW) || rl_exceeded('link', 't:' . $token, LINK_MAX_FAILURES, LINK_WINDOW)) {
        security_event('link.rate_limited', 'application', (int) $app['id'], ['purpose' => $purpose]);
        return 'Too many wrong attempts. Please wait 15 minutes and try again.';
    }
    if ($botError = bot_trap_check('link')) {
        return $botError;
    }
    $typed = strtoupper(preg_replace('/\s+/', '', post('passport_number')));
    $real = strtoupper((string) $app['passport_number']);
    if ($typed === '' || $real === '' || !hash_equals($real, $typed)) {
        rl_hit('link', $ip);
        rl_hit('link', 't:' . $token);
        security_event('link.wrong_passport', 'application', (int) $app['id'], ['purpose' => $purpose]);
        return $real === '' ? 'This application has no passport number yet. Please contact the visa office.' : 'That passport number does not match this link.';
    }
    link_unlock($app, $purpose);
    audit_log('link.opened', 'application', (int) $app['id'], ['purpose' => $purpose], 'audit', (int) $app['user_id']);
    redirect(ACCESS_LINKS[$purpose][1], ['t' => $token]);
}

/** Full page asking for the passport number. */
function render_link_gate(array $app, string $purpose, ?string $error): void
{
    [, $page, $title, $icon] = ACCESS_LINKS[$purpose];
    $shows = match ($purpose) {
        'biometrics' => 'the page where you scan your 10 fingerprints and face photo',
        'lmia' => 'your LMIA status and LMIA letter',
        'offer' => 'your offer letter',
        default => 'your live application status and offer letter',
    };
    render_header($title);
    ?>
<div class="row justify-content-center">
  <div class="col-md-8 col-lg-6 col-xl-5">
    <div class="card shadow-sm"><div class="card-body p-4">
      <span class="icon-circle ic-blue mb-3"><i class="bi bi-<?= e($icon) ?>"></i></span>
      <h1 class="h4 mb-1"><?= e($title) ?></h1>
      <p class="text-muted small mb-3">Application <strong><?= e($app['reference_no']) ?></strong> · <?= e(mask_name((string) ($app['full_name'] ?: $app['applicant_name']))) ?></p>
      <p class="mb-3">To open <?= e($shows) ?>, enter the passport number used on this application.</p>
      <?php if ($error): ?><div class="alert alert-danger small" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="<?= e(url($page, ['t' => $app[ACCESS_LINKS[$purpose][0]]])) ?>" novalidate>
        <?= csrf_field() ?>
        <?= bot_trap_fields() ?>
        <input type="hidden" name="action" value="unlock_link">
        <?= form_input('passport_number', 'Passport number', '', [], 'text', ['required' => true, 'maxlength' => 20, 'autocapitalize' => 'characters', 'autocomplete' => 'off', 'placeholder' => 'e.g. AB1234567', 'autofocus' => true]) ?>
        <button class="btn btn-brand w-100" type="submit"><i class="bi bi-unlock me-1"></i>Continue</button>
      </form>
      <p class="small text-muted mt-3 mb-0"><i class="bi bi-shield-lock me-1"></i>This link was made for one applicant only. Do not share it.</p>
    </div></div>
  </div>
</div>
<?php
    render_footer();
}

function render_invalid_link(string $purpose): never
{
    rl_hit('link', client_ip());
    http_response_code(404);
    render_header('Link not valid');
    echo '<div class="row justify-content-center"><div class="col-md-8 col-lg-6"><div class="card shadow-sm"><div class="card-body p-4">'
        . '<h1 class="h4">This link is not valid any more</h1><p class="mb-3">It may have been replaced by a new link. Please ask the visa office to send you the current ' . e(strtolower(ACCESS_LINKS[$purpose][2])) . '.</p>'
        . '<a class="btn btn-outline-primary" href="' . e(url('track.php')) . '">Check status with passport &amp; date of birth</a></div></div></div></div>';
    render_footer();
    exit;
}

// ---------------------------------------------------------------------
// Shared links: ONE link per purpose that staff send to many applicants at once
// (portal.php?p=<purpose>&k=<key>). Each visitor types their own passport number and
// then works only on the submitted application with that passport number.
// ---------------------------------------------------------------------
/** purpose => [title, icon, what the applicant does there] */
const SHARED_LINKS = [
    'passport'   => ['Passport', 'person-vcard', 'upload a scan of their passport bio-data page'],
    'biometrics' => ['Biometrics', 'fingerprint', 'scan their 10 fingerprints and face photo'],
    'offer'      => ['Offer letter', 'file-earmark-text', 'see and download only their offer letter'],
    'lmia'       => ['LMIA', 'briefcase', 'see their LMIA status and download the LMIA letter'],
    'payment'    => ['Payment', 'credit-card', 'see the bank details and upload their payment screenshot'],
    'medical'    => ['Medical', 'heart-pulse', 'see their medical report status, download it, or upload their own'],
    'visa'       => ['Visa', 'passport', 'see and download their visa'],
];

/** Secret part of a shared link; created the first time it is needed, replaced by "Reset". */
function shared_link_key(string $purpose, bool $regenerate = false, ?int $byUserId = null): string
{
    $key = setting('shared_link_' . $purpose);
    if ($regenerate || !$key) {
        $key = bin2hex(random_bytes(16));
        set_setting('shared_link_' . $purpose, $key, $byUserId);
    }
    return $key;
}

function shared_link_url(string $purpose): string
{
    return absolute_url('portal.php', ['p' => $purpose, 'k' => shared_link_key($purpose)]);
}

/** Text for WhatsApp / copy – the same for every applicant. */
function shared_link_message(string $purpose, string $link): string
{
    return match ($purpose) {
        'passport' => 'Please open this link, enter your passport number and upload a clear photo or scan of your passport bio-data page: ' . $link,
        'biometrics' => 'Please open this link on your phone, enter your passport number, take photos of your 10 fingerprints and your face, then press Submit: ' . $link,
        'offer' => 'Open this link and enter your passport number to download your offer letter: ' . $link,
        'lmia' => 'Open this link and enter your passport number to see your LMIA status and download your LMIA letter: ' . $link,
        'payment' => 'Open this link, enter your passport number, check the bank details and upload the screenshot of your payment: ' . $link,
        'visa' => 'Open this link and enter your passport number to download your visa: ' . $link,
        default => 'Open this link and enter your passport number to see and download your medical report (or upload it if we ask you to): ' . $link,
    };
}

/** The purpose of the shared link being opened, or null when the link is wrong or was reset. */
function shared_link_purpose(): ?string
{
    $purpose = query('p');
    $key = query('k');
    if (!isset(SHARED_LINKS[$purpose]) || !preg_match('/^[a-f0-9]{32}$/', $key)) {
        return null;
    }
    $real = (string) setting('shared_link_' . $purpose, '');
    return $real !== '' && hash_equals($real, $key) ? $purpose : null;
}

/** The application this browser unlocked on this shared link, or null. */
function shared_link_application(string $purpose): ?array
{
    $ok = $_SESSION['shared_ok'][$purpose] ?? null;
    if (!$ok || !hash_equals((string) $ok['k'], query('k'))) {
        return null;
    }
    $app = find_application((int) $ok['id']);
    return $app && $app['status'] !== 'draft' ? $app : null;
}

/** "Not you?" – forget the passport on every shared link in this browser. */
function shared_link_forget(): void
{
    unset($_SESSION['shared_ok']);
}

/**
 * Passport form on a shared link (POST action=unlock_shared). The newest submitted application
 * with that passport number is opened. Redirects on success, returns an error otherwise.
 */
function handle_shared_unlock(string $purpose): ?string
{
    if (!is_post() || post('action') !== 'unlock_shared') {
        return null;
    }
    $ip = client_ip();
    if (rl_exceeded('link', $ip, LINK_MAX_FAILURES, LINK_WINDOW)) {
        security_event('shared_link.rate_limited', null, null, ['purpose' => $purpose]);
        return 'Too many wrong attempts. Please wait 15 minutes and try again.';
    }
    if ($botError = bot_trap_check('link')) {
        return $botError;
    }
    $passport = strtoupper(preg_replace('/\s+/', '', post('passport_number')));
    if (!preg_match('/^[A-Z0-9]{5,20}$/', $passport)) {
        return 'Enter your passport number (5–20 letters or digits).';
    }
    $id = (int) db_value(
        "SELECT id FROM applications WHERE passport_number = ? AND status <> 'draft'
         ORDER BY status = 'cancelled', created_at DESC LIMIT 1",
        [$passport]
    );
    if (!$id) {
        rl_hit('link', $ip);
        security_event('shared_link.unknown_passport', null, null, ['purpose' => $purpose]);
        return 'No submitted application was found with this passport number. Check the number, or contact the visa office.';
    }
    session_regenerate_id(true);
    $_SESSION['shared_ok'][$purpose] = ['k' => query('k'), 'id' => $id];
    audit_log('shared_link.opened', 'application', $id, ['purpose' => $purpose], 'audit', (int) db_value('SELECT user_id FROM applications WHERE id = ?', [$id]));
    redirect('portal.php', ['p' => $purpose, 'k' => query('k')]);
}

/** Full page asking for the passport number on a shared link. */
function render_shared_gate(string $purpose, ?string $error): void
{
    [$title, $icon, $does] = SHARED_LINKS[$purpose];
    render_header($title);
    ?>
<div class="row justify-content-center">
  <div class="col-md-8 col-lg-6 col-xl-5">
    <div class="card shadow-sm"><div class="card-body p-4">
      <span class="icon-circle ic-blue mb-3"><i class="bi bi-<?= e($icon) ?>"></i></span>
      <h1 class="h4 mb-1"><?= e($title) ?></h1>
      <p class="mb-3">Enter your passport number to <?= e(str_replace('their', 'your', $does)) ?>.</p>
      <?php if ($error): ?><div class="alert alert-danger small" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="<?= e(url('portal.php', ['p' => $purpose, 'k' => query('k')])) ?>" novalidate>
        <?= csrf_field() ?>
        <?= bot_trap_fields() ?>
        <input type="hidden" name="action" value="unlock_shared">
        <?= form_input('passport_number', 'Passport number', '', [], 'text', ['required' => true, 'maxlength' => 20, 'autocapitalize' => 'characters', 'autocomplete' => 'off', 'placeholder' => 'e.g. AB1234567', 'autofocus' => true]) ?>
        <button class="btn btn-brand w-100" type="submit"><i class="bi bi-unlock me-1"></i>Continue</button>
      </form>
      <p class="small text-muted mt-3 mb-0"><i class="bi bi-shield-lock me-1"></i>Use your own passport number. You will only see your own application.</p>
    </div></div>
  </div>
</div>
<?php
    render_footer();
}

function render_invalid_shared_link(): never
{
    rl_hit('link', client_ip());
    http_response_code(404);
    render_header('Link not valid');
    echo '<div class="row justify-content-center"><div class="col-md-8 col-lg-6"><div class="card shadow-sm"><div class="card-body p-4">'
        . '<h1 class="h4">This link is not valid any more</h1><p class="mb-3">It may have been replaced by a new link. Please ask the visa office to send you the current link.</p>'
        . '<a class="btn btn-outline-primary" href="' . e(url('track.php')) . '">Check status with passport &amp; date of birth</a></div></div></div></div>';
    render_footer();
    exit;
}
