<?php
/**
 * Shared links: one link per purpose (passport, biometrics, offer letter, LMIA, payment, medical)
 * that staff copy once and send to many applicants. Each applicant opens it, types their own
 * passport number and works on their own application only (portal.php).
 * Below the links: what every member has sent so far.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_staff();
if (!can_any(['applications.review', 'documents.review', 'biometrics.manage', 'payments.review'], $user)) {
    security_event('permission.denied', null, null, ['page' => 'shared-links']);
    abort(403);
}

if (is_post() && post('action') === 'reset_shared_link') {
    $purpose = post('purpose');
    if (!isset(SHARED_LINKS[$purpose]) || !can('applications.review', $user)) {
        abort(403);
    }
    shared_link_key($purpose, true, (int) $user['id']);
    audit_log('shared_link.reset', null, null, ['purpose' => $purpose]);
    flash('success', 'A new ' . SHARED_LINKS[$purpose][0] . ' link was created. The old link no longer works – send the new one to the applicants.');
    redirect('admin/shared-links.php');
}

$links = [];
foreach (SHARED_LINKS as $purpose => [$title, $icon, $does]) {
    $link = shared_link_url($purpose);
    $links[$purpose] = [$title, $icon, $does, $link, shared_link_message($purpose, $link)];
}
$allMessage = implode("\n\n", array_map(static fn ($l) => $l[0] . ': ' . $l[3], $links));

$q = strtoupper(trim(query('q')));
[$scope, $params] = staff_application_scope($user);
$where = "a.status <> 'draft' AND $scope";
if ($q !== '') {
    $where .= ' AND (a.passport_number LIKE ? OR a.reference_no LIKE ? OR UPPER(u.full_name) LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}
$members = db_all(
    "SELECT a.*, u.full_name AS applicant_name FROM applications a JOIN users u ON u.id = a.user_id
     WHERE $where ORDER BY COALESCE(a.submitted_at, a.created_at) DESC LIMIT 100",
    $params
);

render_header('Shared links', 'shared-links');
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <h1 class="h3 mb-1">Shared links</h1>
    <p class="text-muted mb-0">Send the <strong>same link</strong> to all your applicants. Each person types their own passport number and sees only their own application.</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if (can('applications.review', $user)): ?><a class="btn btn-brand" href="<?= e(url('admin/client-new.php')) ?>"><i class="bi bi-person-plus me-1"></i>Add client by passport</a><?php endif; ?>
    <button type="button" class="btn btn-outline-primary" data-copy="<?= e($allMessage) ?>"><i class="bi bi-clipboard me-1"></i>Copy all links</button>
  </div>
</div>

<div class="row g-3 mb-4">
<?php foreach ($links as $purpose => [$title, $icon, $does, $link, $message]): ?>
  <div class="col-md-6 col-xl-4">
    <div class="link-card h-100 d-flex flex-column">
      <div class="d-flex align-items-center gap-2 mb-1"><span class="icon-circle sm ic-blue"><i class="bi bi-<?= e($icon) ?>"></i></span><h2 class="h6 mb-0"><?= e($title) ?> link</h2></div>
      <p class="small text-muted mb-2">The applicant can <?= e($does) ?>.</p>
      <div class="share-url mb-2 mt-auto"><?= e($link) ?></div>
      <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm btn-outline-primary" data-copy="<?= e($link) ?>"><i class="bi bi-link-45deg me-1"></i>Copy link</button>
        <button type="button" class="btn btn-sm btn-outline-primary" data-copy="<?= e($message) ?>"><i class="bi bi-chat-text me-1"></i>Copy message</button>
        <a class="btn btn-sm btn-outline-success" target="_blank" rel="noopener noreferrer" href="<?= e('https://wa.me/?text=' . rawurlencode($message)) ?>"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>
        <?php if (can('applications.review', $user)): ?>
          <?= post_button(url('admin/shared-links.php'), ['action' => 'reset_shared_link', 'purpose' => $purpose], 'Reset', 'btn btn-sm btn-outline-danger', 'Create a new ' . $title . ' link? The current link stops working for EVERY applicant.') ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="card shadow-sm"><div class="card-body">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h2 class="h5 mb-0">What each member has sent</h2>
    <form class="d-flex gap-2" method="get" action="<?= e(url('admin/shared-links.php')) ?>" role="search">
      <input class="form-control form-control-sm" type="search" name="q" value="<?= e(query('q')) ?>" placeholder="Passport, reference or name" aria-label="Search members">
      <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-search"></i></button>
    </form>
  </div>
  <?php if (!$members): ?><?= empty_state($q !== '' ? 'No member matches your search.' : 'No submitted applications yet.') ?><?php endif; ?>
  <?php if ($members): ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0 member-table">
      <thead><tr><th>Member</th><?php foreach (SHARED_LINKS as [$title, $icon]): ?><th class="text-nowrap"><i class="bi bi-<?= e($icon) ?> me-1" aria-hidden="true"></i><?= e($title) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($members as $m): ?>
        <tr>
          <td>
            <a class="fw-semibold" href="<?= e(url('admin/application.php', ['id' => $m['id'], 'tab' => 'file'])) ?>#member-file"><?= e($m['applicant_name']) ?></a>
            <div class="small text-muted text-nowrap"><?= e($m['reference_no']) ?> · <?= e($m['passport_number'] ?: 'no passport no.') ?></div>
          </td>
          <?php foreach (member_file_status($m) as [$title, , $label, $color, $detail]): ?>
            <td><span class="badge soft bg-<?= e($color) ?>-subtle text-<?= e($color) ?>-emphasis border-<?= e($color) ?>-subtle" title="<?= e($title . ': ' . $detail) ?>"><?= e($label) ?></span></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div></div>
<?php render_footer();
