<?php
/**
 * Fingerprint & face capture card, used on the applicant's Biometrics tab and on the
 * passport-protected fingerprint link (biometrics.php).
 * Expects: $app, $bioPostUrl (form target), $bioHidden (extra hidden fields), $bioImgUrl (fn(int id): string).
 */
defined('APP_ROOT') || exit;
?>
  <?php $caps = biometric_captures((int) $app['id']); $capState = biometric_capture_state($caps); $canCapture = app_accepts_biometrics($app, $capState); $total = count(BIO_SLOTS); ?>
  <div class="card shadow-sm mb-3"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
      <div>
        <h2 class="h5 mb-1"><i class="bi bi-fingerprint me-1"></i>Your fingerprints &amp; photo</h2>
        <p class="small text-muted mb-0">Take a clear photo of each finger and one face photo, then submit them for review.</p>
      </div>
      <span class="fs-6"><?= '<span class="badge soft bg-' . $capState[2] . '-subtle text-' . $capState[2] . '-emphasis border-' . $capState[2] . '-subtle">' . e($capState[1]) . '</span>' ?></span>
    </div>
    <progress class="bio-progress my-3" value="<?= $capState[3] ?>" max="<?= $total ?>" aria-label="Photos added"><?= $capState[3] ?> of <?= $total ?></progress>
    <p class="small fw-semibold mb-3"><?= $capState[3] ?> of <?= $total ?> photos added</p>

    <?php if ($app['status'] === 'draft'): ?>
      <div class="alert alert-info small">Submit your application first – then you can add your fingerprints here.</div>
    <?php elseif ($canCapture): ?>
      <details class="bio-tips mb-3" <?= $capState[3] === 0 ? 'open' : '' ?>>
        <summary class="fw-semibold"><i class="bi bi-lightbulb me-1"></i>How to take a good fingerprint photo</summary>
        <ul class="small mb-0 mt-2">
          <li>Wash and dry your hands. Use bright daylight or a lamp – no shadows.</li>
          <li>Lay your hand flat on a plain white sheet of paper, palm up.</li>
          <li>Hold the phone 10–15 cm above the finger; tap the screen to focus on the fingertip. The ridges must be sharp.</li>
          <li>One finger per photo, in the order shown. For the face photo: plain light background, look straight at the camera, no glasses or hat.</li>
        </ul>
      </details>
    <?php endif; ?>

    <?php foreach (['Right hand' => 'right_', 'Left hand' => 'left_', 'Photo' => 'face'] as $group => $prefix): ?>
      <h3 class="h6 text-uppercase text-muted mt-3 mb-2"><?= e($group) ?></h3>
      <div class="bio-grid">
      <?php foreach (BIO_SLOTS as $slot => [$label, $icon, $camera]): if (!str_starts_with($slot, $prefix)) { continue; } $c = $caps[$slot] ?? null; $editable = $canCapture && (!$c || in_array($c['status'], ['draft', 'rejected'], true)); ?>
        <div class="bio-slot<?= $c ? ' has-photo status-' . e($c['status']) : '' ?>" id="bio-<?= e($slot) ?>">
          <div class="bio-thumb">
            <?php if ($c): ?>
              <img src="<?= e($bioImgUrl((int) $c['id'])) ?>" alt="<?= e($label) ?> photo" loading="lazy">
            <?php else: ?>
              <i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i>
            <?php endif; ?>
          </div>
          <div class="fw-semibold small mt-2"><?= e($label) ?></div>
          <div class="mt-1"><?= $c ? badge(BIO_CAPTURE_STATUS, $c['status']) : '<span class="badge soft bg-light text-muted border">Not added</span>' ?></div>
          <?php if ($c && $c['status'] === 'rejected' && $c['review_note']): ?><div class="small text-danger mt-1"><?= e($c['review_note']) ?></div><?php endif; ?>
          <?php if ($editable): ?>
          <form method="post" action="<?= e($bioPostUrl) ?>" enctype="multipart/form-data" class="mt-2" data-bio-form>
            <?= csrf_field() ?>
            <?php foreach ($bioHidden as $hk => $hv): ?><input type="hidden" name="<?= e($hk) ?>" value="<?= e($hv) ?>"><?php endforeach; ?><input type="hidden" name="action" value="upload_biometric"><input type="hidden" name="slot" value="<?= e($slot) ?>">
            <label class="btn btn-sm btn-outline-primary w-100 mb-0">
              <i class="bi bi-camera me-1"></i><span data-bio-label><?= $c ? 'Retake' : 'Take photo' ?></span>
              <input class="visually-hidden" type="file" name="photo" accept="image/jpeg,image/png" capture="<?= e($camera) ?>" required data-bio-input>
            </label>
            <button type="submit" class="btn btn-sm btn-brand w-100 mt-1" data-bio-submit>Upload</button>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($canCapture): ?>
      <form method="post" action="<?= e($bioPostUrl) ?>" class="mt-4" data-confirm="Submit all <?= $total ?> photos for review? You cannot change them while they are being checked.">
        <?= csrf_field() ?>
        <?php foreach ($bioHidden as $hk => $hv): ?><input type="hidden" name="<?= e($hk) ?>" value="<?= e($hv) ?>"><?php endforeach; ?><input type="hidden" name="action" value="submit_biometrics">
        <button class="btn btn-brand btn-lg w-100" type="submit"<?= $capState[0] === 'ready' ? '' : ' disabled' ?>><i class="bi bi-send-check me-1"></i>Submit fingerprints for review</button>
        <?php if ($capState[0] !== 'ready'): ?><p class="small text-muted text-center mt-2 mb-0"><?= $capState[0] === 'retake' ? 'Retake the photos marked "Retake needed" to submit again.' : 'Add all ' . $total . ' photos to submit.' ?></p><?php endif; ?>
      </form>
    <?php elseif ($capState[0] === 'submitted'): ?>
      <div class="alert alert-warning small mt-3 mb-0"><i class="bi bi-hourglass-split me-1"></i>Your fingerprints are being checked. We will notify you of the result.</div>
    <?php elseif ($capState[0] === 'accepted'): ?>
      <div class="alert alert-success small mt-3 mb-0"><i class="bi bi-patch-check me-1"></i>Your fingerprints and photo were accepted. Nothing more to do here.</div>
    <?php endif; ?>
    <p class="small text-muted mt-3 mb-0"><i class="bi bi-shield-lock me-1"></i>Your fingerprint photos are stored privately and only the biometrics team handling your case can see them.</p>
  </div></div>

