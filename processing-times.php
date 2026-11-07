<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$categories = active_categories();
$requirements = db_all('SELECT r.*, c.name AS category_name FROM document_requirements r LEFT JOIN visa_categories c ON c.id = r.visa_category_id WHERE r.is_active = 1 ORDER BY r.visa_category_id IS NOT NULL, r.sort_order, r.name');

render_header('Processing times', 'processing');
?>
<h1 class="h3 mb-3">Processing times &amp; requirements</h1>
<p class="text-muted">Estimates are given in calendar days and usually start once your application is submitted with all mandatory documents. They are guidance, not a guarantee.</p>
<div class="table-responsive mb-4">
  <table class="table table-striped bg-white shadow-sm align-middle">
    <thead><tr><th>Visa category</th><th>Typical processing</th><th>Notes</th></tr></thead>
    <tbody>
    <?php foreach ($categories as $c): ?>
      <tr>
        <td><strong><?= e($c['name']) ?></strong><div class="small text-muted"><?= e($c['description']) ?></div></td>
        <td class="text-nowrap"><?= $c['processing_days_max'] ? (int) $c['processing_days_min'] . '–' . (int) $c['processing_days_max'] . ' days' : 'Not specified' ?></td>
        <td class="small"><?= e($c['processing_notes']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$categories): ?><tr><td colspan="3"><?= empty_state('No categories available.') ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<h2 class="h4 mb-3">Required documents</h2>
<div class="table-responsive">
  <table class="table bg-white shadow-sm align-middle">
    <thead><tr><th>Document</th><th>Applies to</th><th>Required?</th></tr></thead>
    <tbody>
    <?php foreach ($requirements as $r): ?>
      <tr>
        <td><strong><?= e($r['name']) ?></strong><div class="small text-muted"><?= e($r['description']) ?></div></td>
        <td><?= e($r['category_name'] ?? 'All categories') ?></td>
        <td><?= (int) $r['is_mandatory'] ? '<span class="badge bg-danger">Mandatory</span>' : '<span class="badge bg-secondary">Optional</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="small text-muted">Accepted file types: PDF, JPG, PNG. Maximum <?= upload_max_mb() ?> MB per file.</p>
<?php render_footer();
