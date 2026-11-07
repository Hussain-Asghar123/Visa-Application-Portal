<?php
/**
 * Published bank accounts and the fee list, used on the applicant's Payments tab and the shared payment link (portal.php).
 * Expects: $app, $instructions (payment_instructions_for()), $fees.
 */
defined('APP_ROOT') || exit;
?>
      <?php if (!$instructions): ?>
        <div class="alert alert-secondary mb-0">Payment instructions <?= $app['region_id'] ? 'for ' . e(region_name((int) $app['region_id'])) . ' ' : '' ?>have not been published yet. Please do not send any payment until official instructions appear here. If anyone contacts you with different bank details, contact support first.</div>
      <?php endif; ?>
      <?php foreach ($instructions as $pi): ?>
        <div class="border rounded p-3 mb-3 bg-light-subtle">
          <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
            <h3 class="h6 mb-0"><?= e($pi['title']) ?></h3>
            <?= $pi['region_id'] ? '<span class="badge soft bg-primary-subtle text-primary-emphasis border-primary-subtle"><i class="bi bi-geo-alt me-1"></i>' . e($pi['region_name']) . '</span>' : '' ?>
          </div>
          <dl class="row small mb-2 detail-list">
            <?php foreach (['Bank' => 'bank_name', 'Account name' => 'account_name', 'Account number' => 'account_number', 'IBAN' => 'iban', 'SWIFT/BIC' => 'swift_code', 'Branch' => 'branch', 'Payment reference' => 'reference_format'] as $label => $col): ?>
              <?php if ($pi[$col]): ?><dt class="col-5"><?= e($label) ?></dt><dd class="col-7 user-select-all"><?= e($pi[$col]) ?></dd><?php endif; ?>
            <?php endforeach; ?>
          </dl>
          <?php if ($pi['instructions_text']): ?><div class="small pre-wrap"><?= e($pi['instructions_text']) ?></div><?php endif; ?>
          <div class="small text-muted mt-2">Always include your reference <strong><?= e($app['reference_no']) ?></strong> with the payment. Last updated <?= e(fmt_date($pi['updated_at'] ?? $pi['created_at'])) ?>.</div>
        </div>
      <?php endforeach; ?>
      <?php if ($fees): ?>
        <h3 class="h6 mt-3">Fees</h3>
        <ul class="small mb-0">
        <?php foreach ($fees as $f): ?><li><?= e($f['name']) ?>: <strong><?= fmt_money($f['amount'], $f['currency']) ?></strong><?= $f['description'] ? ' – ' . e($f['description']) : '' ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
