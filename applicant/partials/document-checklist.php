<?php
/** Expects $app, $checklist, and optional $returnTo ('apply'|'application'). */
defined('APP_ROOT') || exit;
$returnTo ??= 'apply';
$canUpload = app_accepts_documents($app);
?>
<div class="list-group mb-2">
<?php foreach ($checklist as $item): $r = $item['requirement']; $d = $item['document']; ?>
  <div class="list-group-item py-3">
    <div class="d-flex flex-wrap justify-content-between gap-2">
      <div class="min-w-0">
        <div class="fw-semibold"><?= e($r['name']) ?>
          <?= (int) $r['is_mandatory'] ? '<span class="badge bg-danger-subtle text-danger-emphasis ms-1">Required</span>' : '<span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">Optional</span>' ?>
        </div>
        <?php if (!empty($r['description'])): ?><div class="small text-muted"><?= e($r['description']) ?></div><?php endif; ?>
      </div>
      <div><?= $d ? badge(DOC_STATUS, $d['status']) : '<span class="badge bg-light text-dark border">Not uploaded</span>' ?></div>
    </div>
    <?php if ($d): ?>
      <div class="small mt-2">
        <a href="<?= e(url('download.php', ['type' => 'document', 'id' => $d['id']])) ?>"><?= e($d['original_name']) ?></a>
        <span class="text-muted">· <?= e(fmt_bytes((int) $d['size_bytes'])) ?> · uploaded <?= e(fmt_date($d['created_at'], true)) ?></span>
      </div>
      <?php if ($d['status'] === 'rejected' && $d['review_note']): ?>
        <div class="alert alert-danger py-2 px-3 small mt-2 mb-0"><strong>Correction needed:</strong> <?= e($d['review_note']) ?></div>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($canUpload && (!$d || $d['status'] !== 'accepted')): ?>
      <form method="post" action="<?= e(url('applicant/application.php')) ?>" enctype="multipart/form-data" class="row g-2 align-items-center mt-1">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_document">
        <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
        <input type="hidden" name="doc_type" value="<?= e($r['doc_type']) ?>">
        <input type="hidden" name="return" value="<?= e($returnTo) ?>">
        <div class="col-sm-8">
          <label class="visually-hidden" for="file_<?= e($r['doc_type']) ?>">File for <?= e($r['name']) ?></label>
          <input class="form-control form-control-sm" type="file" id="file_<?= e($r['doc_type']) ?>" name="file" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" data-max-mb="<?= upload_max_mb() ?>">
        </div>
        <div class="col-sm-4"><button class="btn btn-sm btn-outline-primary w-100" type="submit"><?= $d ? 'Replace / resubmit' : 'Upload' ?></button></div>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$checklist): ?><div class="list-group-item text-muted">No documents are required for this category.</div><?php endif; ?>
</div>
