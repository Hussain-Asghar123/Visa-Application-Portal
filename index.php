<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = current_user();
if ($user) {
    redirect(home_url_for($user));
}
$categories = active_categories();
$minDays = null;
foreach ($categories as $c) {
    if ($c['processing_days_min'] !== null && ($minDays === null || (int) $c['processing_days_min'] < $minDays)) {
        $minDays = (int) $c['processing_days_min'];
    }
}
$docCount = (int) db_value('SELECT COUNT(*) FROM document_requirements WHERE is_active = 1 AND visa_category_id IS NULL AND is_mandatory = 1');

// "Apply now" on the McChain Food section opens this portal's registration page.
// To send applicants to McChain Food's own portal instead, put its full address here, e.g. 'https://portal.mcchainfood.com'.
$mcchainPortalUrl = url('register.php');
$mcchainExternal = (bool) preg_match('#^https?://#i', $mcchainPortalUrl);

/** Icon + colour for a visa category based on its code/name. */
function category_style(array $c): array
{
    $key = strtolower($c['code'] . ' ' . $c['name']);
    return match (true) {
        str_contains($key, 'stud') => ['mortarboard', 'ic-blue'],
        str_contains($key, 'work'), str_contains($key, 'employ') => ['briefcase', 'ic-teal'],
        str_contains($key, 'visit'), str_contains($key, 'tour') => ['airplane', 'ic-amber'],
        str_contains($key, 'family'), str_contains($key, 'spouse') => ['people', 'ic-violet'],
        str_contains($key, 'business') => ['building', 'ic-green'],
        default => ['passport', 'ic-slate'],
    };
}

render_header('Apply for your visa online', 'home', ['fluid' => true, 'body_class' => 'landing']);
?>
<!-- ============ HERO ============ -->
<section class="hero-xl">
  <div class="container">
    <div class="row align-items-center g-4 g-lg-5">
      <div class="col-lg-6">
        <div class="hero-pill mb-4"><span>Online</span> Secure visa applications, start to finish</div>
        <h1 class="mb-4">Your visa journey, <span class="text-gradient">handled with care.</span></h1>
        <p class="lead mb-4">Apply step by step, upload your documents privately, and follow every stage of your application – document review, biometrics, payment, offer letter and travel – in one place.</p>
        <div class="d-flex flex-wrap gap-3 mb-4">
          <a class="btn btn-accent btn-lg" href="<?= e(url('register.php')) ?>">Start your application <i class="bi bi-arrow-right ms-1"></i></a>
          <a class="btn btn-ghost-light btn-lg" href="<?= e(url('login.php')) ?>">Sign in</a>
        </div>
        <div class="hero-trust">
          <span><i class="bi bi-shield-check"></i>Private document storage</span>
          <span><i class="bi bi-bell"></i>Updates at every stage</span>
          <span><i class="bi bi-phone"></i>Works on your phone</span>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="preview" aria-hidden="true">
          <div class="preview-card">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div>
                <div class="small text-muted">Application</div>
                <div class="ref fs-5">VA26-K7QM2P</div>
              </div>
              <span class="badge soft bg-info-subtle text-info-emphasis border-info-subtle">Under review</span>
            </div>
            <div class="d-flex justify-content-between small fw-semibold mb-1"><span>Progress</span><span class="text-muted">5 of 7</span></div>
            <div class="preview-progress mb-3"><span></span></div>
            <div class="preview-row"><span class="tick"><i class="bi bi-check-lg"></i></span>Application submitted</div>
            <div class="preview-row"><span class="tick"><i class="bi bi-check-lg"></i></span>Documents verified</div>
            <div class="preview-row"><span class="tick"><i class="bi bi-check-lg"></i></span>Payment confirmed</div>
            <div class="preview-row"><span class="tick"><i class="bi bi-check-lg"></i></span>Biometrics approved</div>
            <div class="preview-row"><span class="tick wait"><i class="bi bi-hourglass-split"></i></span>Offer letter – pending</div>
          </div>
          <div class="preview-float">
            <span class="icon-circle ic-green"><i class="bi bi-bell-fill"></i></span>
            <div><div>Biometrics approved</div><div class="text-muted fw-normal small">Example notification</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============ FACTS ============ -->
<div class="container overlap">
  <div class="stats-band row g-0 text-center">
    <div class="col-6 col-lg-3 stat"><strong><?= count($categories) ?></strong><span>Visa categories open</span></div>
    <div class="col-6 col-lg-3 stat"><strong><?= $minDays !== null ? 'From ' . $minDays . ' days' : '—' ?></strong><span>Typical processing</span></div>
    <div class="col-6 col-lg-3 stat"><strong>5 steps</strong><span>Simple online form</span></div>
    <div class="col-6 col-lg-3 stat"><strong>24/7</strong><span>Track from any device</span></div>
  </div>
</div>

<!-- ============ McCHAIN FOOD ============ -->
<section class="section pb-0" id="mcchainfood">
  <div class="container">
    <div class="security-panel">
      <div class="row g-4 g-lg-5 align-items-center">
        <div class="col-lg-7">
          <span class="eyebrow text-white-50"><i class="bi bi-building"></i> Visa sponsor</span>
          <h2 class="h1 mt-2 mb-3 fw-bolder">McChain Food – work visa opportunities</h2>
          <p class="mb-4">McChain Food provides visas for the people who join its team. Apply through the McChain Food portal: fill in your details, upload your documents and follow your visa application at every stage until you travel.</p>
          <ul class="list-unstyled mb-4">
            <li><i class="bi bi-check2-circle me-2"></i>Employer-sponsored visa provided by McChain Food</li>
            <li><i class="bi bi-check2-circle me-2"></i>Apply online from your phone in a few steps</li>
            <li><i class="bi bi-check2-circle me-2"></i>Live updates on documents, biometrics, payment and offer letter</li>
          </ul>
          <a class="btn btn-accent btn-lg" href="<?= e($mcchainPortalUrl) ?>"<?= $mcchainExternal ? ' target="_blank" rel="noopener"' : '' ?>>Apply now <i class="bi bi-<?= $mcchainExternal ? 'box-arrow-up-right' : 'arrow-right' ?> ms-1 text-reset"></i></a>
        </div>
        <div class="col-lg-5">
          <div class="row g-3">
            <?php foreach ([
                ['person-plus', 'Create your account'],
                ['file-earmark-text', 'Fill in the application'],
                ['cloud-arrow-up', 'Upload your documents'],
                ['airplane', 'Get your visa and travel'],
            ] as $i => [$icon, $label]): ?>
            <div class="col-6">
              <div class="step-tile rounded-4 p-3 h-100">
                <i class="bi bi-<?= $icon ?> fs-3"></i>
                <div class="small text-white-50 mt-2">Step <?= $i + 1 ?></div>
                <div class="fw-semibold text-white"><?= e($label) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============ McCHAIN FOOD GALLERY ============ -->
<section class="section pb-0" id="workplaces">
  <div class="container">
    <div class="section-title">
      <span class="eyebrow"><i class="bi bi-buildings"></i> Where you will work</span>
      <h2 class="mt-2">Building projects and modern offices</h2>
      <p class="text-muted mb-0">McChain Food teams work on large construction projects and in professional, modern offices.</p>
    </div>
    <div class="row g-3 g-lg-4">
      <div class="col-lg-7">
        <figure class="photo-tile tall">
          <img src="<?= e(asset('img/photos/construction-workers.jpg')) ?>" alt="Construction team in hard hats and safety vests on a building site" width="1200" height="800" loading="lazy">
          <figcaption><span class="photo-chip"><i class="bi bi-cone-striped"></i> Construction</span><strong>On-site project teams</strong></figcaption>
        </figure>
      </div>
      <div class="col-lg-5 d-flex flex-column gap-3 gap-lg-4">
        <figure class="photo-tile">
          <img src="<?= e(asset('img/photos/construction-crane.jpg')) ?>" alt="Tower crane above a building under construction" width="1200" height="800" loading="lazy">
          <figcaption><span class="photo-chip"><i class="bi bi-cone-striped"></i> Construction</span><strong>Large-scale building works</strong></figcaption>
        </figure>
        <figure class="photo-tile">
          <img src="<?= e(asset('img/photos/construction-team.jpg')) ?>" alt="Two construction workers working on a site" width="1200" height="800" loading="lazy">
          <figcaption><span class="photo-chip"><i class="bi bi-cone-striped"></i> Construction</span><strong>Skilled trades</strong></figcaption>
        </figure>
      </div>
      <?php foreach ([
          ['office-interior', 'Bright modern office with meeting table', 'Modern workspaces'],
          ['office-hallway', 'Office corridor with glass-walled rooms', 'Professional offices'],
          ['office-team', 'Colleagues working together in an open office', 'Collaborative teams'],
      ] as [$file, $alt, $title]): ?>
      <div class="col-md-4">
        <figure class="photo-tile">
          <img src="<?= e(asset('img/photos/' . $file . '.jpg')) ?>" alt="<?= e($alt) ?>" width="1200" height="800" loading="lazy">
          <figcaption><span class="photo-chip office"><i class="bi bi-building"></i> Offices</span><strong><?= e($title) ?></strong></figcaption>
        </figure>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============ VISA TYPES ============ -->
<section class="section" id="visas">
  <div class="container">
    <div class="section-title">
      <span class="eyebrow"><i class="bi bi-passport"></i> Visa types</span>
      <h2 class="mt-2">Choose the visa that fits your plans</h2>
      <p class="text-muted mb-0">Each category has its own checklist of required documents and an estimated processing time.</p>
    </div>
    <div class="row g-4">
    <?php foreach ($categories as $c): [$icon, $tone] = category_style($c); ?>
      <div class="col-md-6 col-lg-4">
        <div class="cat-card">
          <span class="icon-circle <?= $tone ?>"><i class="bi bi-<?= e($icon) ?>"></i></span>
          <h3><?= e($c['name']) ?></h3>
          <p class="flex-grow-1"><?= e($c['description'] ?: 'Apply online and track every stage of your application.') ?></p>
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
            <?php if ($c['processing_days_max']): ?>
              <span class="time"><i class="bi bi-clock"></i><?= (int) $c['processing_days_min'] ?>–<?= (int) $c['processing_days_max'] ?> days</span>
            <?php else: ?><span></span><?php endif; ?>
            <a class="fw-semibold text-decoration-none" href="<?= e(url('register.php')) ?>">Apply now <i class="bi bi-arrow-right"></i></a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$categories): ?><div class="col-12"><?= empty_state('No visa categories are open for applications at the moment.', 'passport') ?></div><?php endif; ?>
    </div>
    <p class="text-center mt-4 mb-0"><a class="fw-semibold" href="<?= e(url('processing-times.php')) ?>">See processing times and required documents <i class="bi bi-arrow-right"></i></a></p>
  </div>
</section>

<!-- ============ FEATURES ============ -->
<section class="section bg-soft pt-0">
  <div class="container">
    <div class="section-title">
      <span class="eyebrow"><i class="bi bi-stars"></i> Everything in one place</span>
      <h2 class="mt-2">No more emails back and forth</h2>
      <p class="text-muted mb-0">Your personal dashboard shows exactly where your application stands and what to do next.</p>
    </div>
    <div class="row g-4">
      <?php foreach ([
          ['cloud-arrow-up', 'ic-blue', 'Secure document upload', 'Upload your passport and documents from your phone camera or files. They are stored privately and checked by our team.'],
          ['list-check', 'ic-teal', 'Clear document checklist', 'See which documents are required, which were accepted and exactly what needs correcting if something is wrong.'],
          ['activity', 'ic-violet', 'Live status tracking', 'Follow your application through review, biometrics, payment and decision, with a notification at every step.'],
          ['fingerprint', 'ic-amber', 'Biometric appointments', 'See your appointment centre, date and instructions, and the outcome as soon as it is recorded.'],
          ['receipt', 'ic-green', 'Verified payments', 'Pay using the official instructions shown in your account and upload the receipt. Our finance team confirms every payment.'],
          ['airplane-engines', 'ic-red', 'Offer letter & travel', 'Download your offer letter when it is issued and view your arrival and accommodation details before you travel.'],
      ] as [$icon, $tone, $title, $text]): ?>
      <div class="col-md-6 col-lg-4">
        <div class="feature-card">
          <span class="icon-circle <?= $tone ?>"><i class="bi bi-<?= $icon ?>"></i></span>
          <h3><?= e($title) ?></h3>
          <p><?= e($text) ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============ HOW IT WORKS ============ -->
<section class="section" id="how">
  <div class="container">
    <div class="row g-4 g-lg-5 align-items-center">
      <div class="col-lg-6">
        <span class="eyebrow"><i class="bi bi-signpost-split"></i> How it works</span>
        <h2 class="mt-2 mb-4 fw-bolder">Five simple steps to your visa</h2>
        <div class="how-step"><span class="num">1</span><h3>Create your account</h3><p>Register with your email and mobile number. It takes less than a minute.</p></div>
        <div class="how-step"><span class="num">2</span><h3>Fill in the application</h3><p>Personal, passport and travel details in a guided form. Save a draft and continue any time.</p></div>
        <div class="how-step"><span class="num">3</span><h3>Upload your documents</h3><p>Follow the checklist for your visa type – at least <?= max(1, $docCount) ?> core documents such as your passport bio-data page and photo.</p></div>
        <div class="how-step"><span class="num">4</span><h3>Biometrics &amp; payment</h3><p>Attend your appointment and pay using the official instructions in your account.</p></div>
        <div class="how-step"><span class="num">5</span><h3>Decision &amp; travel</h3><p>Download your offer letter and see your travel and accommodation details.</p></div>
      </div>
      <div class="col-lg-6">
        <div class="security-panel">
          <span class="icon-circle ic-teal mb-3"><i class="bi bi-shield-lock-fill"></i></span>
          <h2 class="h3 mb-3">Your documents stay private</h2>
          <ul class="list-unstyled mb-4">
            <li><i class="bi bi-check2-circle me-2"></i>Files are stored privately and can't be opened by a public link.</li>
            <li><i class="bi bi-check2-circle me-2"></i>Only you and the staff handling your case can view them.</li>
            <li><i class="bi bi-check2-circle me-2"></i>Every access and decision is recorded in an audit log.</li>
            <li><i class="bi bi-check2-circle me-2"></i>Payments are only confirmed after a finance officer checks them.</li>
          </ul>
          <a class="btn btn-accent" href="<?= e(url('register.php')) ?>">Create your free account</a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============ FAQ ============ -->
<section class="section bg-soft pt-0" id="faq">
  <div class="container">
    <div class="section-title">
      <span class="eyebrow"><i class="bi bi-question-circle"></i> FAQ</span>
      <h2 class="mt-2">Frequently asked questions</h2>
    </div>
    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="accordion" id="faqAcc">
          <?php foreach ([
              ['What file types can I upload?', 'PDF, JPG and PNG files up to ' . upload_max_mb() . ' MB each. You can take a photo with your phone camera directly from the upload button.'],
              ['Can I save my application and finish later?', 'Yes. Every step is saved as a draft when you press a button, so you can continue on any device after signing in.'],
              ['How do I pay the fees?', 'Payment instructions appear in your application once they are published. Never send money to details you received any other way. After paying, upload your receipt; our finance team verifies it before it is marked as confirmed.'],
              ['How long does processing take?', 'It depends on the visa type – see the processing times page for current estimates. Your dashboard also shows an estimated decision window once you submit.'],
              ['What if a document is rejected?', 'You will see a note explaining what to correct. Upload a corrected version from the Documents tab and it goes back to our team for review.'],
              ['Can I cancel my application?', 'Drafts can be discarded at any time. For submitted applications you can send a cancellation request, which our team reviews.'],
          ] as $i => [$q, $a]): ?>
          <div class="accordion-item">
            <h3 class="accordion-header">
              <button class="accordion-button<?= $i ? ' collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>" aria-expanded="<?= $i ? 'false' : 'true' ?>" aria-controls="faq<?= $i ?>"><?= e($q) ?></button>
            </h3>
            <div id="faq<?= $i ?>" class="accordion-collapse collapse<?= $i ? '' : ' show' ?>" data-bs-parent="#faqAcc">
              <div class="accordion-body text-muted"><?= e($a) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============ CTA ============ -->
<section class="section-sm">
  <div class="container">
    <div class="cta-band">
      <h2 class="mb-2">Ready to start your application?</h2>
      <p class="mb-4">Create your account in a minute and continue whenever it suits you.</p>
      <div class="d-flex flex-wrap justify-content-center gap-3">
        <a class="btn btn-accent btn-lg" href="<?= e(url('register.php')) ?>">Start now <i class="bi bi-arrow-right ms-1"></i></a>
        <a class="btn btn-ghost-light btn-lg" href="<?= e(url('processing-times.php')) ?>">View requirements</a>
      </div>
    </div>
  </div>
</section>
<?php render_footer();
