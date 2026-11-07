<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/** Phone numbers in the footer: [as shown, digits for tel: and WhatsApp links]. */
const CONTACT_PHONES = [
    ['+1 876 283 0918', '18762830918'],
    ['+30 697 279 8994', '306972798994'],
    ['+44 7778 229520', '447778229520'],
    ['+44 7586 326598', '447586326598'],
];

function app_name(): string
{
    return (string) config('app.name', 'Visa Application Portal');
}

function nav_link(string $path, string $label, string $active, string $key, string $extra = '', string $icon = ''): string
{
    $cls = $active === $key ? ' active" aria-current="page' : '';
    $ic = $icon !== '' ? '<i class="bi bi-' . e($icon) . '" aria-hidden="true"></i>' : '';
    return '<li class="nav-item"><a class="nav-link' . $cls . '" href="' . e(url($path)) . '" title="' . e($label) . '">' . $ic . '<span class="nav-label">' . e($label) . '</span>' . $extra . '</a></li>';
}

function brand_html(): string
{
    return '<img class="brand-logo" src="' . e(asset('img/logo.svg')) . '" width="38" height="38" alt=""><span>' . e(app_name()) . '</span>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '?', 0, 1);
    $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/**
 * Page header. $opts: 'body_class' (string), 'fluid' (bool – full-width main without container padding).
 */
function render_header(string $title, string $active = '', array $opts = []): void
{
    $user = PHP_SAPI === 'cli' ? null : (isset($GLOBALS['__vap_config']['db']) ? safe_current_user() : null);
    $staff = is_staff($user);
    $fluid = !empty($opts['fluid']);
    $GLOBALS['__vap_layout_fluid'] = $fluid;
    $bodyClass = trim(($staff ? 'area-admin' : 'area-public') . ' ' . ($opts['body_class'] ?? ''));
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0a2340">
<title><?= e($title) ?> · <?= e(app_name()) ?></title>
<link rel="preload" href="<?= e(asset('vendor/fonts/plus-jakarta-sans-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="<?= e($bodyClass) ?>">
<a class="visually-hidden-focusable" href="#main">Skip to content</a>
<header class="site-header<?= $staff ? ' staff' : '' ?>">
<nav class="navbar navbar-expand-xl<?= $staff ? ' navbar-dark' : '' ?>" aria-label="Main">
  <div class="container">
    <a class="navbar-brand" href="<?= e(url($user ? home_url_for($user) : 'index.php')) ?>"><?= brand_html() ?><?= $staff ? ' <span class="staff-chip">Staff</span>' : '' ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav mx-xl-auto gap-xl-1">
      <?php if (!$user): ?>
        <?= nav_link('index.php', 'Home', $active, 'home', '', 'house') ?>
        <?= nav_link('index.php#visas', 'Visa types', $active, 'visas', '', 'passport') ?>
        <?= nav_link('index.php#mcchainfood', 'McChain Food', $active, 'mcchainfood', '', 'building') ?>
        <?= nav_link('index.php#how', 'How it works', $active, 'how', '', 'signpost-split') ?>
        <?= nav_link('processing-times.php', 'Processing times', $active, 'processing', '', 'clock-history') ?>
        <?= nav_link('track.php', 'Track status', $active, 'track', '', 'broadcast') ?>
      <?php elseif (!$staff): ?>
        <?php $unread = unread_notification_count((int) $user['id']); ?>
        <?= nav_link('applicant/index.php', 'Dashboard', $active, 'dashboard', '', 'grid-1x2') ?>
        <?= nav_link('applicant/applications.php', 'My applications', $active, 'applications', '', 'folder2-open') ?>
        <?= nav_link('account/notifications.php', 'Notifications', $active, 'notifications', $unread ? ' <span class="badge rounded-pill bg-danger nav-count">' . $unread . '</span>' : '', 'bell') ?>
        <?= nav_link('applicant/tickets.php', 'Support', $active, 'tickets', '', 'chat-dots') ?>
      <?php else: ?>
        <?php $unread = unread_notification_count((int) $user['id']); ?>
        <?= nav_link('admin/index.php', 'Dashboard', $active, 'dashboard', '', 'speedometer2') ?>
        <?= nav_link('admin/applications.php', 'Applications', $active, 'applications', '', 'folder2-open') ?>
        <?php if (can_any(['applications.review', 'documents.review', 'biometrics.manage', 'payments.review'], $user)): ?><?= nav_link('admin/shared-links.php', 'Shared links', $active, 'shared-links', '', 'share') ?><?php endif; ?>
        <?php if (can('payments.review', $user)): ?><?= nav_link('admin/payments.php', 'Payments', $active, 'payments', '', 'credit-card') ?><?php endif; ?>
        <?php if (can('cancellations.manage', $user)): ?><?= nav_link('admin/cancellations.php', 'Cancellations', $active, 'cancellations', '', 'x-octagon') ?><?php endif; ?>
        <?php if (can('support.manage', $user)): ?><?= nav_link('admin/tickets.php', 'Support', $active, 'tickets', '', 'chat-dots') ?><?php endif; ?>
        <?php if (can('applicants.manage', $user)): ?><?= nav_link('admin/applicants.php', 'Applicants', $active, 'applicants', '', 'people') ?><?php endif; ?>
        <?php if (can_any(['catalog.manage', 'payments.instructions'], $user) || is_super_admin($user)): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $active === 'config' ? ' active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-sliders" aria-hidden="true"></i><span class="nav-label">Configuration</span></a>
          <ul class="dropdown-menu">
            <?php if (can('catalog.manage', $user)): ?>
            <li><a class="dropdown-item" href="<?= e(url('admin/categories.php')) ?>"><i class="bi bi-passport me-2"></i>Visa categories &amp; processing times</a></li>
            <li><a class="dropdown-item" href="<?= e(url('admin/requirements.php')) ?>"><i class="bi bi-list-check me-2"></i>Document requirements</a></li>
            <?php endif; ?>
            <?php if (can('payments.instructions', $user)): ?>
            <li><a class="dropdown-item" href="<?= e(url('admin/fees.php')) ?>"><i class="bi bi-tags me-2"></i>Fee types</a></li>
            <li><a class="dropdown-item" href="<?= e(url('admin/payment-instructions.php')) ?>"><i class="bi bi-bank me-2"></i>Payment instructions</a></li>
            <li><a class="dropdown-item" href="<?= e(url('admin/regions.php')) ?>"><i class="bi bi-globe2 me-2"></i>Payment regions</a></li>
            <?php endif; ?>
            <?php if (is_super_admin($user)): ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= e(url('admin/staff.php')) ?>"><i class="bi bi-person-badge me-2"></i>Staff accounts</a></li>
            <li><a class="dropdown-item" href="<?= e(url('admin/roles.php')) ?>"><i class="bi bi-shield-lock me-2"></i>Roles &amp; permissions</a></li>
            <li><a class="dropdown-item" href="<?= e(url('admin/settings.php')) ?>"><i class="bi bi-gear me-2"></i>Site settings (domain, HTTPS, email)</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>
        <?php if (can('audit.view', $user)): ?><?= nav_link('admin/security.php', 'Security', $active, 'security', '', 'shield-check') ?><?= nav_link('admin/audit.php', 'Audit log', $active, 'audit', '', 'journal-text') ?><?php endif; ?>
        <?= nav_link('account/notifications.php', 'Alerts', $active, 'notifications', $unread ? ' <span class="badge rounded-pill bg-danger nav-count">' . $unread . '</span>' : '', 'bell') ?>
      <?php endif; ?>
      </ul>
      <ul class="navbar-nav align-items-xl-center gap-2">
      <?php if ($user): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="user-avatar" aria-hidden="true"><?= e(initials((string) $user['full_name'])) ?></span>
            <span class="d-xl-none d-xxl-inline"><?= e(str_limit($user['full_name'], 22)) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li class="px-3 py-2 small text-muted"><?= e($user['email']) ?></li>
            <?php if (!$staff): ?><li><a class="dropdown-item" href="<?= e(url('applicant/profile.php')) ?>"><i class="bi bi-person me-2"></i>My profile</a></li><?php endif; ?>
            <li><a class="dropdown-item" href="<?= e(url('account/security.php')) ?>"><i class="bi bi-shield-lock me-2"></i>Security &amp; 2-step login</a></li>
            <li><a class="dropdown-item" href="<?= e(url('account/password.php')) ?>"><i class="bi bi-key me-2"></i>Change password</a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <form method="post" action="<?= e(url('logout.php')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button>
              </form>
            </li>
          </ul>
        </li>
      <?php else: ?>
        <li class="nav-item"><a class="nav-link<?= $active === 'login' ? ' active' : '' ?>" href="<?= e(url('login.php')) ?>"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i><span>Sign in</span></a></li>
        <li class="nav-item"><a class="btn btn-brand btn-sm px-3" href="<?= e(url('register.php')) ?>">Start application</a></li>
      <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
</header>
<main id="main" class="<?= $fluid ? '' : 'container py-4 py-lg-5' ?>">
<?php if ($flashes = take_flashes()): ?>
<div class="<?= $fluid ? 'container pt-3' : '' ?>">
<?php foreach ($flashes as $f): ?>
  <div class="alert alert-<?= e($f['type'] === 'error' ? 'danger' : $f['type']) ?> alert-dismissible fade show" role="alert">
    <?= e($f['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php
}

/** Branded side panel used on the sign-in / registration screens (hidden on small screens). */
function auth_side(string $title, array $points, string $quote = ''): string
{
    $html = '<aside class="auth-side"><div><a class="navbar-brand text-white mb-5" href="' . e(url('index.php')) . '">' . brand_html() . '</a>'
        . '<h2 class="h3 mt-5">' . e($title) . '</h2><ul>';
    foreach ($points as [$icon, $text]) {
        $html .= '<li><i class="bi bi-' . e($icon) . '" aria-hidden="true"></i><span>' . e($text) . '</span></li>';
    }
    $html .= '</ul></div>';
    if ($quote !== '') {
        $html .= '<p class="quote mb-0"><i class="bi bi-shield-lock me-2"></i>' . e($quote) . '</p>';
    }
    return $html . '</aside>';
}

/** current_user() that never throws while rendering (e.g. on DB error pages). */
function safe_current_user(): ?array
{
    try {
        return current_user();
    } catch (Throwable) {
        return null;
    }
}

function render_footer(): void
{
    $user = PHP_SAPI === 'cli' ? null : safe_current_user();
    ?>
</main>
<?php if ($user): ?>
<footer class="site-footer compact">
  <div class="container footer-bottom d-flex flex-column flex-md-row justify-content-between gap-2">
    <span>&copy; <?= date('Y') ?> <?= e(app_name()) ?></span>
    <span class="contact-inline"><a href="<?= e(url('processing-times.php')) ?>">Processing times</a><?php foreach (CONTACT_PHONES as [$shown, $digits]): ?> · <a href="tel:+<?= e($digits) ?>"><i class="bi bi-telephone me-1" aria-hidden="true"></i><?= e($shown) ?></a><?php endforeach; ?></span>
  </div>
</footer>
<?php else: ?>
<footer class="site-footer">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-5">
        <a class="navbar-brand mb-3" href="<?= e(url('index.php')) ?>"><?= brand_html() ?></a>
        <p class="mb-0 pe-lg-5">Apply online, upload documents securely and follow every stage of your visa application – from review to biometrics, payment, offer letter and travel.</p>
      </div>
      <div class="col-6 col-lg-2">
        <h3>Portal</h3>
        <ul class="list-unstyled mb-0">
          <li class="mb-2"><a href="<?= e(url('register.php')) ?>">Create account</a></li>
          <li class="mb-2"><a href="<?= e(url('login.php')) ?>">Sign in</a></li>
          <li class="mb-2"><a href="<?= e(url('track.php')) ?>">Track application status</a></li>
          <li class="mb-2"><a href="<?= e(url('forgot-password.php')) ?>">Forgot password</a></li>
        </ul>
      </div>
      <div class="col-6 col-lg-2">
        <h3>Information</h3>
        <ul class="list-unstyled mb-0">
          <li class="mb-2"><a href="<?= e(url('index.php#visas')) ?>">Visa types</a></li>
          <li class="mb-2"><a href="<?= e(url('index.php#mcchainfood')) ?>">McChain Food visas</a></li>
          <li class="mb-2"><a href="<?= e(url('processing-times.php')) ?>">Processing times</a></li>
          <li class="mb-2"><a href="<?= e(url('index.php#faq')) ?>">FAQ</a></li>
        </ul>
      </div>
      <div class="col-lg-3">
        <h3>Contact</h3>
        <ul class="list-unstyled contact-phones mb-3">
        <?php foreach (CONTACT_PHONES as [$shown, $digits]): ?>
          <li>
            <a href="tel:+<?= e($digits) ?>"><i class="bi bi-telephone me-2" aria-hidden="true"></i><?= e($shown) ?></a>
            <a class="wa" href="https://wa.me/<?= e($digits) ?>" target="_blank" rel="noopener noreferrer" title="WhatsApp <?= e($shown) ?>" aria-label="WhatsApp <?= e($shown) ?>"><i class="bi bi-whatsapp" aria-hidden="true"></i></a>
          </li>
        <?php endforeach; ?>
        </ul>
        <p class="mb-0"><i class="bi bi-shield-lock me-2"></i>Your documents are stored privately and only visible to you and authorised staff.</p>
      </div>
    </div>
    <div class="footer-bottom d-flex flex-column flex-md-row justify-content-between gap-2">
      <span>&copy; <?= date('Y') ?> <?= e(app_name()) ?>. All rights reserved.</span>
      <span>Secure connection · Private document storage</span>
    </div>
  </div>
</footer>
<?php endif; ?>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
<?php
}

function render_error_page(int $code, string $title, string $message, ?string $debug = null): void
{
    if (function_exists('render_header') && session_status() === PHP_SESSION_ACTIVE && isset($GLOBALS['__vap_config']['app'])) {
        try {
            render_header($title);
            echo '<div class="row justify-content-center"><div class="col-lg-7"><div class="card shadow-sm"><div class="card-body p-4">';
            echo '<p class="text-muted mb-1">Error ' . $code . '</p><h1 class="h3">' . e($title) . '</h1><p>' . e($message) . '</p>';
            echo '<a class="btn btn-primary" href="' . e(url('index.php')) . '">Go to home page</a>';
            if ($debug) {
                echo '<pre class="mt-3 small bg-light p-2 border">' . e($debug) . '</pre>';
            }
            echo '</div></div></div></div>';
            render_footer();
            return;
        } catch (Throwable) {
            // fall through to the minimal page
        }
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($title) . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:10vh auto;padding:0 16px;color:#222}h1{font-size:1.5rem}</style></head><body>'
        . '<p>Error ' . $code . '</p><h1>' . e($title) . '</h1><p>' . e($message) . '</p></body></html>';
}

// ---------------------------------------------------------------------
// Form helpers
// ---------------------------------------------------------------------
function field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<div class="invalid-feedback d-block">' . e($errors[$field]) . '</div>' : '';
}

function invalid(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' is-invalid' : '';
}

function errors_summary(array $errors): string
{
    if (!$errors) {
        return '';
    }
    $html = '<div class="alert alert-danger" role="alert"><strong>Please correct the following:</strong><ul class="mb-0">';
    foreach ($errors as $msg) {
        $html .= '<li>' . e($msg) . '</li>';
    }
    return $html . '</ul></div>';
}

/** <input> with label, value retention and error display. */
function form_input(string $name, string $label, mixed $value = '', array $errors = [], string $type = 'text', array $attrs = []): string
{
    $id = 'f_' . $name;
    $attrHtml = '';
    foreach ($attrs as $k => $v) {
        $attrHtml .= $v === true ? ' ' . e($k) : ' ' . e($k) . '="' . e($v) . '"';
    }
    $req = !empty($attrs['required']) ? ' <span class="text-danger" aria-hidden="true">*</span>' : '';
    $val = $type === 'password' ? '' : old($name, $value);
    return '<div class="mb-3"><label class="form-label" for="' . $id . '">' . e($label) . $req . '</label>'
        . '<input type="' . e($type) . '" class="form-control' . invalid($errors, $name) . '" id="' . $id . '" name="' . e($name) . '" value="' . e($val) . '"' . $attrHtml . '>'
        . field_error($errors, $name) . '</div>';
}

function form_textarea(string $name, string $label, mixed $value = '', array $errors = [], array $attrs = []): string
{
    $id = 'f_' . $name;
    $attrHtml = '';
    foreach ($attrs as $k => $v) {
        $attrHtml .= $v === true ? ' ' . e($k) : ' ' . e($k) . '="' . e($v) . '"';
    }
    $req = !empty($attrs['required']) ? ' <span class="text-danger" aria-hidden="true">*</span>' : '';
    return '<div class="mb-3"><label class="form-label" for="' . $id . '">' . e($label) . $req . '</label>'
        . '<textarea class="form-control' . invalid($errors, $name) . '" id="' . $id . '" name="' . e($name) . '" rows="' . (int) ($attrs['rows'] ?? 3) . '"' . $attrHtml . '>' . e(old($name, $value)) . '</textarea>'
        . field_error($errors, $name) . '</div>';
}

/** <select>. $options = [value => label]. */
function form_select(string $name, string $label, array $options, mixed $value = '', array $errors = [], array $attrs = [], ?string $placeholder = 'Select…'): string
{
    $id = 'f_' . $name;
    $current = old($name, $value);
    $req = !empty($attrs['required']) ? ' <span class="text-danger" aria-hidden="true">*</span>' : '';
    $attrHtml = '';
    foreach ($attrs as $k => $v) {
        $attrHtml .= $v === true ? ' ' . e($k) : ' ' . e($k) . '="' . e($v) . '"';
    }
    $html = '<div class="mb-3"><label class="form-label" for="' . $id . '">' . e($label) . $req . '</label>'
        . '<select class="form-select' . invalid($errors, $name) . '" id="' . $id . '" name="' . e($name) . '"' . $attrHtml . '>';
    if ($placeholder !== null) {
        $html .= '<option value="">' . e($placeholder) . '</option>';
    }
    foreach ($options as $v => $l) {
        $html .= '<option value="' . e($v) . '"' . ((string) $v === (string) $current ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $html . '</select>' . field_error($errors, $name) . '</div>';
}

function country_options(): array
{
    return array_combine(countries(), countries());
}

function empty_state(string $message, string $icon = 'inbox'): string
{
    return '<div class="empty-state"><i class="bi bi-' . e($icon) . '" aria-hidden="true"></i>' . e($message) . '</div>';
}

/** A POST button form with CSRF and optional confirmation prompt. */
function post_button(string $action, array $fields, string $label, string $class = 'btn btn-sm btn-outline-primary', ?string $confirm = null): string
{
    $html = '<form method="post" action="' . e($action) . '" class="d-inline"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field();
    foreach ($fields as $k => $v) {
        $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    return $html . '<button type="submit" class="' . e($class) . '">' . e($label) . '</button></form>';
}
