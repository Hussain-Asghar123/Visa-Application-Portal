<?php
/**
 * LMIA status and letter, used on the per-applicant LMIA link (lmia.php) and the shared LMIA link (portal.php).
 * Expects: $app, $lmiaDownloadUrl (where the letter downloads from).
 */
defined('APP_ROOT') || exit;
[$label, $color] = LMIA_STATUS[$app['lmia_status']] ?? LMIA_STATUS['not_started'];
?>
  <div class="card shadow-sm"><div class="card-body p-4">
    <div class="track-tile tone-<?= e($color) ?> mb-3">
      <div class="small text-muted">Current LMIA status</div>
      <div class="track-status fs-4 text-<?= e($color) ?>-emphasis"><?= e($label) ?></div>
      <?php if ($app['lmia_updated_at']): ?><div class="track-time">Updated <?= e(fmt_date($app['lmia_updated_at'], true)) ?></div><?php endif; ?>
    </div>
    <dl class="detail-list row mb-0">
      <dt class="col-sm-4">LMIA number</dt><dd class="col-sm-8"><?= e($app['lmia_number'] ?: 'Not issued yet') ?></dd>
      <?php if ($app['lmia_note']): ?><dt class="col-sm-4">Message from the visa office</dt><dd class="col-sm-8 pre-wrap"><?= e($app['lmia_note']) ?></dd><?php endif; ?>
    </dl>
  </div></div>

  <div class="card shadow-sm mt-3"><div class="card-body p-4">
    <h2 class="h5 mb-3"><i class="bi bi-file-earmark-text me-1"></i>LMIA letter</h2>
    <?php if ($app['lmia_doc_stored_name']): ?>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border rounded-3 p-3">
        <div>
          <div class="fw-semibold"><?= e($app['lmia_doc_original_name']) ?></div>
          <div class="small text-muted">Added <?= e(fmt_date($app['lmia_doc_uploaded_at'])) ?> · <?= e(fmt_bytes((int) $app['lmia_doc_size'])) ?></div>
        </div>
        <a class="btn btn-brand" href="<?= e($lmiaDownloadUrl) ?>"><i class="bi bi-download me-1"></i>Download</a>
      </div>
    <?php else: ?>
      <?= empty_state('Your LMIA letter is not available yet. It will appear here once the visa office adds it.', 'hourglass-split') ?>
    <?php endif; ?>
  </div></div>
