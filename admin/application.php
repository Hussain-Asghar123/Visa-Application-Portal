<?php
/**
 * Staff case view. Every action re-checks: staff login, access to THIS application
 * (assignment or applications.view_all), and the specific permission for the action.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_staff();
$app = staff_application(query_int('id') ?: post_int('id'), $user);
$appId = (int) $app['id'];
$uid = (int) $user['id'];

$allTabs = [
    'overview'   => ['Overview', null],
    'file'       => ['Client file', ['documents.review', 'applications.review', 'biometrics.manage', 'payments.review']],
    'documents'  => ['Documents', ['documents.review', 'applications.review']],
    'biometrics' => ['Biometrics', ['biometrics.manage']],
    'payments'   => ['Payments', ['payments.review']],
    'offers'     => ['Offer letters', ['offers.manage', 'offers.publish']],
    'lmia'       => ['LMIA', ['applications.review']],
    'travel'     => ['Travel', ['travel.manage']],
    'assignment' => ['Assignment', ['applications.assign']],
    'cancel'     => ['Cancellation', ['cancellations.manage']],
];
$tabs = array_filter($allTabs, static fn ($t) => $t[1] === null || can_any($t[1], $user));
$tab = array_key_exists(query('tab'), $tabs) ? query('tab') : 'overview';
$errors = [];

/** Abort with 403 (and log) unless the user holds one of the permissions. */
function need(string ...$perms): void
{
    if (!can_any($perms)) {
        security_event('permission.denied', 'application', post_int('id') ?: null, ['needed' => $perms, 'action' => post('action')]);
        abort(403);
    }
}

function back_to(int $appId, string $tab): never
{
    redirect('admin/application.php', ['id' => $appId, 'tab' => $tab]);
}

$ACTIVE_FOR_AUTO = ['submitted', 'under_review', 'documents_required', 'biometrics_scheduled', 'payment_pending'];

if (is_post()) {
    $action = post('action');
    switch ($action) {
        // ---------------- status & notes ----------------
        case 'update_status':
            need('applications.review');
            $new = post('status');
            $note = post('note');
            if ($app['status'] === 'draft' || $app['status'] === 'cancelled') {
                flash('error', 'Draft or cancelled applications cannot be changed.');
            } elseif (!in_array($new, STAFF_SETTABLE_STATUSES, true)) {
                flash('error', 'Choose a valid status.');
            } elseif (mb_strlen($note) > 1000) {
                flash('error', 'The note is too long.');
            } elseif (in_array($new, ['rejected', 'documents_required'], true) && $note === '') {
                flash('error', 'Please add a note explaining this status to the applicant.');
            } else {
                change_application_status($app, $new, $note, $uid, true);
                flash('success', 'Status updated to ' . status_label($new) . '.');
            }
            back_to($appId, 'overview');

        case 'add_note':
            need('applications.review');
            $note = post('note');
            if ($note === '' || mb_strlen($note) > 1000) {
                flash('error', 'Enter a note (max 1000 characters).');
            } else {
                $visible = post('visible') === '1';
                add_history_note($appId, $app['status'], $note, $uid, $visible);
                audit_log('application.note_added', 'application', $appId, ['visible' => $visible]);
                if ($visible) {
                    notify_user((int) $app['user_id'], 'Message about ' . $app['reference_no'], $note, 'applicant/application.php?id=' . $appId, true);
                }
                flash('success', 'Note added.');
            }
            back_to($appId, 'overview');

        // ---------------- documents ----------------
        case 'review_document':
            need('documents.review');
            $doc = db_one("SELECT * FROM documents WHERE id = ? AND application_id = ? AND status <> 'superseded'", [post_int('document_id'), $appId]);
            $decision = post('decision');
            $note = post('note');
            if (!$doc || !in_array($decision, ['accepted', 'rejected'], true)) {
                flash('error', 'Invalid document or decision.');
            } elseif ($decision === 'rejected' && ($note === '' || mb_strlen($note) > 1000)) {
                flash('error', 'Explain what needs to be corrected (max 1000 characters).');
            } else {
                db_update('documents', ['status' => $decision, 'review_note' => nullable($note), 'reviewed_by' => $uid, 'reviewed_at' => now()], 'id = ?', [$doc['id']]);
                audit_log('document.' . $decision, 'document', (int) $doc['id'], ['application_id' => $appId, 'doc_type' => $doc['doc_type']]);
                if ($decision === 'rejected') {
                    notify_user((int) $app['user_id'], 'Document correction needed: ' . $app['reference_no'], 'A document needs to be corrected and resubmitted: ' . $note, 'applicant/application.php?id=' . $appId . '&tab=documents', true);
                    if (post('set_documents_required') === '1' && in_array($app['status'], ['submitted', 'under_review'], true) && can('applications.review')) {
                        change_application_status($app, 'documents_required', 'Please correct the documents marked "Correction needed".', $uid);
                    }
                } else {
                    notify_user((int) $app['user_id'], 'Document accepted: ' . $app['reference_no'], 'One of your documents was accepted.', 'applicant/application.php?id=' . $appId . '&tab=documents');
                }
                flash('success', 'Document marked as ' . $decision . '.');
            }
            back_to($appId, 'documents');

        // ---------------- biometrics ----------------
        case 'schedule_biometric':
            need('biometrics.manage');
            $v = new Validator($_POST);
            $v->required('center_name', 'Centre name')->max('center_name', 150, 'Centre name')
              ->max('center_address', 255, 'Centre address')
              ->required('appointment_at', 'Appointment date & time')->datetime('appointment_at', 'Appointment date & time')
              ->max('instructions', 3000, 'Instructions');
            $when = str_replace('T', ' ', substr($v->value('appointment_at'), 0, 16));
            if (!$v->fails() && strtotime($when) < time()) {
                $v->add('appointment_at', 'The appointment must be in the future.');
            }
            if (is_terminal($app) || $app['status'] === 'draft') {
                $v->add('center_name', 'Biometrics cannot be scheduled for this application.');
            }
            $errors = $v->errors();
            if (!$errors) {
                db_run("UPDATE biometric_appointments SET status = 'cancelled', updated_at = ? WHERE application_id = ? AND status = 'scheduled'", [now(), $appId]);
                $bid = db_insert('biometric_appointments', [
                    'application_id' => $appId,
                    'center_name' => $v->value('center_name'),
                    'center_address' => nullable($v->value('center_address')),
                    'appointment_at' => $when . ':00',
                    'instructions' => nullable($v->value('instructions')),
                    'status' => 'scheduled',
                    'created_by' => $uid,
                    'created_at' => now(),
                ]);
                audit_log('biometric.scheduled', 'biometric_appointment', $bid, ['application_id' => $appId, 'at' => $when]);
                if (in_array($app['status'], $ACTIVE_FOR_AUTO, true) && $app['status'] !== 'biometrics_scheduled') {
                    change_application_status($app, 'biometrics_scheduled', 'Biometric appointment booked for ' . fmt_date($when, true) . ' at ' . $v->value('center_name') . '.', $uid);
                } else {
                    notify_user((int) $app['user_id'], 'Biometric appointment: ' . $app['reference_no'], 'Your biometric appointment is on ' . fmt_date($when, true) . ' at ' . $v->value('center_name') . '.', 'applicant/application.php?id=' . $appId . '&tab=biometrics', true);
                }
                flash('success', 'Biometric appointment scheduled.');
                back_to($appId, 'biometrics');
            }
            $tab = 'biometrics';
            break;

        case 'biometric_decision':
            need('biometrics.manage');
            $bio = db_one('SELECT * FROM biometric_appointments WHERE id = ? AND application_id = ?', [post_int('biometric_id'), $appId]);
            $decision = post('decision');
            $note = post('note');
            if (!$bio || !in_array($decision, ['attended', 'approved', 'rejected', 'missed', 'cancelled'], true) || in_array($bio['status'], ['approved', 'rejected', 'cancelled'], true)) {
                flash('error', 'Invalid appointment or decision.');
            } elseif ($decision === 'rejected' && $note === '') {
                flash('error', 'Give a reason when rejecting the biometric stage.');
            } elseif (mb_strlen($note) > 1000) {
                flash('error', 'The note is too long.');
            } else {
                db_update('biometric_appointments', ['status' => $decision, 'decision_note' => nullable($note), 'decided_by' => $uid, 'decided_at' => now()], 'id = ?', [$bio['id']]);
                audit_log('biometric.' . $decision, 'biometric_appointment', (int) $bio['id'], ['application_id' => $appId]);
                notify_user((int) $app['user_id'], 'Biometrics update: ' . $app['reference_no'], 'Your biometric stage is now: ' . BIO_STATUS[$decision][0] . '.' . ($note ? ' Note: ' . $note : ''), 'applicant/application.php?id=' . $appId . '&tab=biometrics', true);
                flash('success', 'Biometric stage updated.');
            }
            back_to($appId, 'biometrics');

        // ---------------- fingerprint photos ----------------
        case 'review_biometric':
        case 'accept_all_biometrics':
            need('biometrics.manage');
            $caps = biometric_captures($appId);
            if ($action === 'accept_all_biometrics') {
                $ids = array_column(array_filter($caps, static fn ($c) => $c['status'] === 'submitted'), 'id');
                $decision = 'accepted';
                $note = '';
            } else {
                $ids = array_column(array_filter($caps, static fn ($c) => (int) $c['id'] === post_int('capture_id') && $c['status'] === 'submitted'), 'id');
                $decision = post('decision');
                $note = post('note');
            }
            if (!$ids || !in_array($decision, ['accepted', 'rejected'], true)) {
                flash('error', 'Nothing to review (the photos may already have been reviewed).');
            } elseif ($decision === 'rejected' && ($note === '' || mb_strlen($note) > 1000)) {
                flash('error', 'Tell the applicant what is wrong with the photo (max 1000 characters).');
            } else {
                $in = implode(',', array_fill(0, count($ids), '?'));
                db_run("UPDATE biometric_captures SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = ? WHERE status = 'submitted' AND id IN ($in)", array_merge([$decision, nullable($note), $uid, now()], $ids));
                $slotNames = array_map(static fn ($c) => BIO_SLOTS[$c['slot']][0] ?? $c['slot'], array_filter($caps, static fn ($c) => in_array($c['id'], $ids)));
                audit_log('biometric.captures_' . $decision, 'application', $appId, ['slots' => array_values($slotNames)]);
                $state = biometric_capture_state(biometric_captures($appId));
                if ($decision === 'rejected') {
                    notify_user((int) $app['user_id'], 'Fingerprint retake needed: ' . $app['reference_no'], 'Please take this photo again: ' . implode(', ', $slotNames) . '. Reason: ' . $note, 'applicant/application.php?id=' . $appId . '&tab=biometrics', true);
                } elseif ($state[0] === 'accepted') {
                    notify_user((int) $app['user_id'], 'Fingerprints accepted: ' . $app['reference_no'], 'All your fingerprints and your photo were accepted.', 'applicant/application.php?id=' . $appId . '&tab=biometrics', true);
                    add_history_note($appId, $app['status'], 'Fingerprints and face photo accepted.', $uid);
                }
                flash('success', $decision === 'accepted' ? count($ids) . ' photo(s) accepted.' : 'Retake requested for ' . implode(', ', $slotNames) . '.');
            }
            back_to($appId, 'biometrics');

        // ---------------- payments ----------------
        case 'review_payment':
            need('payments.review');
            $pay = db_one('SELECT * FROM payments WHERE id = ? AND application_id = ?', [post_int('payment_id'), $appId]);
            $decision = post('decision');
            $note = post('note');
            if (!$pay || $pay['status'] !== 'submitted' || !in_array($decision, ['confirmed', 'rejected'], true)) {
                flash('error', 'This payment cannot be reviewed (it may already have been reviewed).');
            } elseif ($decision === 'rejected' && $note === '') {
                flash('error', 'Give a reason when rejecting a payment.');
            } elseif (mb_strlen($note) > 1000) {
                flash('error', 'The note is too long.');
            } else {
                // Conditional update prevents two reviewers from deciding the same receipt.
                $n = db_run("UPDATE payments SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ? AND status = 'submitted'", [$decision, nullable($note), $uid, now(), $pay['id']])->rowCount();
                if ($n === 1) {
                    audit_log('payment.' . $decision, 'payment', (int) $pay['id'], ['application_id' => $appId, 'amount' => $pay['amount'], 'currency' => $pay['currency'], 'reference' => $pay['transaction_reference']]);
                    notify_user((int) $app['user_id'], 'Payment ' . ($decision === 'confirmed' ? 'confirmed' : 'not accepted') . ': ' . $app['reference_no'],
                        $decision === 'confirmed' ? 'Your payment of ' . $pay['currency'] . ' ' . $pay['amount'] . ' has been verified and confirmed.' : 'Your payment receipt could not be verified: ' . $note,
                        'applicant/application.php?id=' . $appId . '&tab=payments', true);
                    flash('success', 'Payment ' . $decision . '.');
                }
            }
            back_to($appId, 'payments');

        // ---------------- offer letters ----------------
        case 'upload_offer':
            need('offers.manage');
            $v = new Validator($_POST);
            $v->required('title', 'Title')->max('title', 150, 'Title')->max('notes', 1000, 'Notes');
            if (is_terminal($app) || $app['status'] === 'draft') {
                $v->add('title', 'Offer letters cannot be added to this application.');
            }
            $errors = $v->errors();
            if (!$errors) {
                $res = store_upload($_FILES['file'] ?? [], 'offers', ['pdf']);
                if (!$res['ok']) {
                    $errors['file'] = $res['error'];
                } else {
                    register_shutdown_function(static function () use ($res, &$oid) {
                        if (empty($oid)) {
                            delete_stored_file('offers', $res['stored_name']);
                        }
                    });
                    $oid = db_insert('offer_letters', [
                        'application_id' => $appId,
                        'title' => $v->value('title'),
                        'notes' => nullable($v->value('notes')),
                        'original_name' => $res['original_name'],
                        'stored_name' => $res['stored_name'],
                        'mime_type' => $res['mime_type'],
                        'size_bytes' => $res['size_bytes'],
                        'sha256' => $res['sha256'],
                        'is_published' => 0,
                        'uploaded_by' => $uid,
                        'created_at' => now(),
                    ]);
                    audit_log('offer.uploaded', 'offer_letter', $oid, ['application_id' => $appId]);
                    flash('success', 'Offer letter uploaded as UNPUBLISHED. The applicant cannot see it until it is published.');
                    back_to($appId, 'offers');
                }
            }
            $tab = 'offers';
            break;

        case 'publish_offer':
        case 'unpublish_offer':
            need('offers.publish');
            $offer = db_one('SELECT * FROM offer_letters WHERE id = ? AND application_id = ?', [post_int('offer_id'), $appId]);
            if (!$offer) {
                flash('error', 'Offer letter not found.');
                back_to($appId, 'offers');
            }
            if ($action === 'publish_offer') {
                if (is_terminal($app)) {
                    flash('error', 'Cannot publish to a ' . status_label($app['status']) . ' application.');
                    back_to($appId, 'offers');
                }
                db_update('offer_letters', ['is_published' => 1, 'published_at' => now(), 'published_by' => $uid, 'unpublished_at' => null], 'id = ?', [$offer['id']]);
                audit_log('offer.published', 'offer_letter', (int) $offer['id'], ['application_id' => $appId]);
                if (in_array($app['status'], $ACTIVE_FOR_AUTO, true)) {
                    change_application_status($app, 'offer_issued', 'Your offer letter is available to download.', $uid);
                } else {
                    notify_user((int) $app['user_id'], 'Offer letter available: ' . $app['reference_no'], 'Your offer letter has been published and can be downloaded.', 'applicant/application.php?id=' . $appId . '&tab=offer', true);
                }
                flash('success', 'Offer letter published to the applicant.');
            } else {
                db_update('offer_letters', ['is_published' => 0, 'unpublished_at' => now()], 'id = ?', [$offer['id']]);
                audit_log('offer.unpublished', 'offer_letter', (int) $offer['id'], ['application_id' => $appId]);
                flash('success', 'Offer letter unpublished. The applicant can no longer download it.');
            }
            back_to($appId, 'offers');

        case 'delete_offer':
            need('offers.manage');
            $offer = db_one('SELECT * FROM offer_letters WHERE id = ? AND application_id = ? AND is_published = 0', [post_int('offer_id'), $appId]);
            if ($offer) {
                db_run('DELETE FROM offer_letters WHERE id = ?', [$offer['id']]);
                delete_stored_file('offers', $offer['stored_name']);
                audit_log('offer.deleted', 'offer_letter', (int) $offer['id'], ['application_id' => $appId, 'title' => $offer['title']]);
                flash('success', 'Unpublished offer letter deleted.');
            } else {
                flash('error', 'Only unpublished offer letters can be deleted.');
            }
            back_to($appId, 'offers');

        // ---------------- travel ----------------
        case 'save_travel':
            need('travel.manage');
            $v = new Validator($_POST);
            $v->date('arrival_date', 'Arrival date')->max('arrival_airport', 150, 'Airport')->max('flight_details', 255, 'Flight details')
              ->in('airport_pickup', ['0', '1'], 'Airport pickup')->max('pickup_details', 500, 'Pickup details')
              ->max('accommodation_name', 150, 'Accommodation name')->max('accommodation_address', 255, 'Accommodation address')
              ->max('accommodation_contact', 150, 'Accommodation contact')
              ->date('checkin_date', 'Check-in date')->date('checkout_date', 'Check-out date')->max('notes', 3000, 'Notes');
            if ($v->value('checkin_date') !== '' && $v->value('checkout_date') !== '' && $v->value('checkout_date') < $v->value('checkin_date')) {
                $v->add('checkout_date', 'Check-out must be after check-in.');
            }
            $errors = $v->errors();
            if (!$errors) {
                $data = ['airport_pickup' => post('airport_pickup') === '1' ? 1 : 0, 'updated_by' => $uid];
                foreach (['arrival_date', 'arrival_airport', 'flight_details', 'pickup_details', 'accommodation_name', 'accommodation_address', 'accommodation_contact', 'checkin_date', 'checkout_date', 'notes'] as $f) {
                    $data[$f] = nullable($v->value($f));
                }
                $existing = db_one('SELECT * FROM travel_arrangements WHERE application_id = ?', [$appId]);
                if ($existing) {
                    db_update('travel_arrangements', $data, 'id = ?', [$existing['id']]);
                } else {
                    db_insert('travel_arrangements', $data + ['application_id' => $appId, 'is_published' => 0, 'created_at' => now()]);
                }
                audit_log('travel.saved', 'application', $appId);
                if ($existing && (int) $existing['is_published'] === 1) {
                    notify_user((int) $app['user_id'], 'Travel details updated: ' . $app['reference_no'], 'Your travel and accommodation details were updated.', 'applicant/application.php?id=' . $appId . '&tab=travel', true);
                }
                flash('success', 'Travel & accommodation details saved' . ($existing && $existing['is_published'] ? ' (published version updated).' : ' as a draft. Publish them when ready.'));
                back_to($appId, 'travel');
            }
            $tab = 'travel';
            break;

        case 'publish_travel':
        case 'unpublish_travel':
            need('travel.manage');
            $t = db_one('SELECT * FROM travel_arrangements WHERE application_id = ?', [$appId]);
            if (!$t) {
                flash('error', 'Save the travel details first.');
            } elseif ($action === 'publish_travel') {
                db_update('travel_arrangements', ['is_published' => 1, 'published_at' => now(), 'published_by' => $uid], 'id = ?', [$t['id']]);
                audit_log('travel.published', 'application', $appId);
                notify_user((int) $app['user_id'], 'Travel details available: ' . $app['reference_no'], 'Your travel and accommodation details have been published.', 'applicant/application.php?id=' . $appId . '&tab=travel', true);
                flash('success', 'Travel details published to the applicant.');
            } else {
                db_update('travel_arrangements', ['is_published' => 0], 'id = ?', [$t['id']]);
                audit_log('travel.unpublished', 'application', $appId);
                flash('success', 'Travel details hidden from the applicant.');
            }
            back_to($appId, 'travel');

        // ---------------- LMIA ----------------
        case 'update_lmia':
            need('applications.review');
            $new = post('lmia_status');
            $number = strtoupper(post('lmia_number'));
            $note = post('lmia_note');
            if ($app['status'] === 'draft') {
                flash('error', 'LMIA cannot be tracked for a draft application.');
            } elseif (!isset(LMIA_STATUS[$new])) {
                flash('error', 'Choose a valid LMIA status.');
            } elseif (mb_strlen($number) > 40 || mb_strlen($note) > 1000) {
                flash('error', 'The LMIA number or note is too long.');
            } else {
                db_update('applications', ['lmia_status' => $new, 'lmia_number' => nullable($number), 'lmia_note' => nullable($note), 'lmia_updated_at' => now()], 'id = ?', [$appId]);
                audit_log('lmia.updated', 'application', $appId, ['from' => $app['lmia_status'], 'to' => $new]);
                if ($new !== $app['lmia_status']) {
                    notify_user((int) $app['user_id'], 'LMIA update: ' . $app['reference_no'], 'Your LMIA status is now: ' . LMIA_STATUS[$new][0] . '.' . ($note !== '' ? ' Note: ' . $note : ''), 'applicant/application.php?id=' . $appId, true);
                }
                flash('success', 'LMIA status saved.');
            }
            back_to($appId, 'lmia');

        case 'upload_lmia_doc':
            need('applications.review');
            if ($app['status'] === 'draft') {
                flash('error', 'Draft applications cannot have an LMIA letter.');
                back_to($appId, 'lmia');
            }
            $res = store_upload($_FILES['file'] ?? [], 'lmia', ['pdf', 'jpg', 'jpeg', 'png']);
            if (!$res['ok']) {
                flash('error', $res['error']);
                back_to($appId, 'lmia');
            }
            if (!empty($app['lmia_doc_stored_name'])) {
                delete_stored_file('lmia', $app['lmia_doc_stored_name']);
            }
            db_update('applications', ['lmia_doc_original_name' => $res['original_name'], 'lmia_doc_stored_name' => $res['stored_name'], 'lmia_doc_mime' => $res['mime_type'], 'lmia_doc_size' => $res['size_bytes'], 'lmia_doc_uploaded_at' => now()], 'id = ?', [$appId]);
            audit_log('lmia.letter_uploaded', 'application', $appId, ['replaced' => !empty($app['lmia_doc_stored_name'])]);
            notify_user((int) $app['user_id'], 'LMIA letter available: ' . $app['reference_no'], 'Your LMIA letter has been added. Open your LMIA link to download it.', 'applicant/application.php?id=' . $appId, true);
            flash('success', 'LMIA letter uploaded. The applicant can download it from the LMIA link.');
            back_to($appId, post('return_tab') === 'file' ? 'file' : 'lmia');

        // ---------------- member file (offer letters tab) ----------------
        case 'staff_upload_document':
            need('documents.review');
            $docType = post('doc_type');
            if (!isset(LINK_DOC_TYPES[$docType])) {
                abort(400);
            }
            [$ok, $msg] = document_store($app, $docType, $_FILES['file'] ?? [], $uid, true);
            flash($ok ? 'success' : 'error', $ok ? $msg . ' The client can now see it on the ' . SHARED_LINKS[$docType][0] . ' link.' : $msg);
            back_to($appId, 'file');

        case 'set_biometrics_done':
            need('biometrics.manage');
            $done = post('done') === '1';
            if ($app['status'] === 'draft') {
                flash('error', 'Draft applications cannot be changed.');
            } else {
                db_update('applications', ['biometrics_done_at' => $done ? now() : null, 'biometrics_done_by' => $done ? $uid : null], 'id = ?', [$appId]);
                audit_log($done ? 'biometric.marked_done' : 'biometric.unmarked_done', 'application', $appId);
                if ($done) {
                    add_history_note($appId, $app['status'], 'Biometrics marked as complete.', $uid);
                    notify_user((int) $app['user_id'], 'Biometrics complete: ' . $app['reference_no'], 'Your biometrics have been marked as complete by the visa office.', 'applicant/application.php?id=' . $appId . '&tab=biometrics', true);
                }
                flash('success', $done ? 'Biometrics marked as done.' : 'Biometrics set back to not done.');
            }
            back_to($appId, post('return_tab') === 'biometrics' ? 'biometrics' : 'file');

        case 'remove_lmia_doc':
            need('applications.review');
            if (!empty($app['lmia_doc_stored_name'])) {
                delete_stored_file('lmia', $app['lmia_doc_stored_name']);
                db_update('applications', ['lmia_doc_original_name' => null, 'lmia_doc_stored_name' => null, 'lmia_doc_mime' => null, 'lmia_doc_size' => null, 'lmia_doc_uploaded_at' => null], 'id = ?', [$appId]);
                audit_log('lmia.letter_removed', 'application', $appId);
            }
            flash('success', 'LMIA letter removed.');
            back_to($appId, 'lmia');

        // ---------------- status link ----------------
        case 'create_link':
        case 'send_link':
        case 'reset_link':
            $purpose = post('purpose');
            if (!isset(ACCESS_LINKS[$purpose])) {
                abort(400);
            }
            need(ACCESS_LINKS[$purpose][5]);
            $title = ACCESS_LINKS[$purpose][2];
            if ($app['status'] === 'draft') {
                flash('error', 'Links can only be made after the application is submitted.');
            } elseif ($action === 'send_link' && is_no_login_email($app['applicant_email'])) {
                flash('error', 'This client has no email address. Copy the link or send it on WhatsApp.');
            } elseif ($action === 'send_link') {
                [$subject, $body] = access_link_message($purpose, $app['reference_no'], access_url($app, $purpose));
                notify_user((int) $app['user_id'], $subject, $body, 'applicant/application.php?id=' . $appId, true);
                audit_log('link.sent', 'application', $appId, ['purpose' => $purpose]);
                flash('success', $title . ' sent to ' . $app['applicant_email'] . '.');
            } else {
                access_token($app, $purpose, $action === 'reset_link');
                audit_log($action === 'reset_link' ? 'link.reset' : 'link.created', 'application', $appId, ['purpose' => $purpose]);
                flash('success', $action === 'reset_link' ? 'A new ' . strtolower($title) . ' was created. The old link no longer works.' : $title . ' created. Copy it or send it to the applicant.');
            }
            redirect(absolute_url('admin/application.php', ['id' => $appId, 'tab' => 'overview']) . '#links');

        // ---------------- assignment ----------------
        case 'assign':
            need('applications.assign');
            $staffId = post_int('staff_user_id');
            $staff = db_one("SELECT * FROM users WHERE id = ? AND user_type = 'staff' AND status = 'active'", [$staffId]);
            if (!$staff) {
                flash('error', 'Choose an active staff member.');
            } else {
                db_run('INSERT IGNORE INTO application_assignments (application_id, staff_user_id, assigned_by, assigned_at) VALUES (?, ?, ?, ?)', [$appId, $staffId, $uid, now()]);
                audit_log('application.assigned', 'application', $appId, ['staff_user_id' => $staffId]);
                notify_user($staffId, 'Application assigned: ' . $app['reference_no'], 'You were assigned to ' . $app['reference_no'] . ' (' . $app['category_name'] . ').', 'admin/application.php?id=' . $appId);
                flash('success', $staff['full_name'] . ' assigned.');
            }
            back_to($appId, 'assignment');

        case 'unassign':
            need('applications.assign');
            $staffId = post_int('staff_user_id');
            db_run('DELETE FROM application_assignments WHERE application_id = ? AND staff_user_id = ?', [$appId, $staffId]);
            audit_log('application.unassigned', 'application', $appId, ['staff_user_id' => $staffId]);
            flash('success', 'Assignment removed.');
            back_to($appId, 'assignment');

        // ---------------- cancellation ----------------
        case 'cancellation_decision':
            need('cancellations.manage');
            $req = db_one("SELECT * FROM cancellation_requests WHERE id = ? AND application_id = ? AND status = 'pending'", [post_int('request_id'), $appId]);
            $decision = post('decision');
            $note = post('note');
            if (!$req || !in_array($decision, ['approved', 'rejected'], true)) {
                flash('error', 'Invalid request or decision.');
            } elseif ($decision === 'rejected' && $note === '') {
                flash('error', 'Explain why the cancellation is rejected.');
            } elseif (mb_strlen($note) > 1000) {
                flash('error', 'The note is too long.');
            } else {
                db_update('cancellation_requests', ['status' => $decision, 'review_note' => nullable($note), 'reviewed_by' => $uid, 'reviewed_at' => now()], 'id = ?', [$req['id']]);
                audit_log('cancellation.' . $decision, 'cancellation_request', (int) $req['id'], ['application_id' => $appId]);
                if ($decision === 'approved') {
                    change_application_status($app, 'cancelled', 'Cancellation approved.' . ($note ? ' ' . $note : ''), $uid);
                } else {
                    notify_user((int) $app['user_id'], 'Cancellation request declined: ' . $app['reference_no'], 'Your cancellation request was declined: ' . $note, 'applicant/application.php?id=' . $appId . '&tab=cancel', true);
                }
                flash('success', 'Cancellation request ' . $decision . '.');
            }
            back_to($appId, 'cancel');

        default:
            abort(400);
    }
}

// ---------------------------------------------------------------------
// View data
// ---------------------------------------------------------------------
$app = find_application($appId);
$history = db_all('SELECT h.*, u.full_name FROM application_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.application_id = ? ORDER BY h.created_at DESC, h.id DESC', [$appId]);
$assigned = db_all('SELECT u.id, u.full_name, u.email, r.name AS role_name, aa.assigned_at FROM application_assignments aa JOIN users u ON u.id = aa.staff_user_id LEFT JOIN roles r ON r.id = u.role_id WHERE aa.application_id = ?', [$appId]);
$profile = db_one('SELECT * FROM applicant_profiles WHERE user_id = ?', [$app['user_id']]) ?? [];
$pendingCancel = (int) db_value("SELECT COUNT(*) FROM cancellation_requests WHERE application_id = ? AND status = 'pending'", [$appId]);

render_header('Case ' . $app['reference_no'], 'applications');
?>
<a class="small" href="<?= e(url('admin/applications.php')) ?>">&larr; Applications</a>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 my-2">
  <div>
    <h1 class="h4 mb-0"><?= e($app['reference_no']) ?> · <?= e($app['applicant_name']) ?></h1>
    <div class="small text-muted"><?= e($app['category_name']) ?> · <?= is_no_login_email($app['applicant_email']) ? 'no email' : e($app['applicant_email']) ?> · passport <?= e($app['passport_number'] ?: '—') ?><?= $app['applicant_phone'] ? ' · ' . e($app['applicant_phone']) : '' ?></div>
  </div>
  <div class="text-end"><span class="fs-5"><?= status_badge($app['status']) ?></span><?php if ($pendingCancel): ?><div class="small text-danger">Cancellation requested</div><?php endif; ?></div>
</div>

<ul class="nav nav-tabs mb-3">
<?php foreach ($tabs as $k => [$label]): ?>
  <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= e(url('admin/application.php', ['id' => $appId, 'tab' => $k])) ?>"><?= e($label) ?></a></li>
<?php endforeach; ?>
</ul>
<?= errors_summary($errors) ?>

<?php if ($tab === 'overview'): ?>
<div class="card shadow-sm mb-3"><div class="card-body">
  <h2 class="h6 text-uppercase text-muted mb-3">Live status (what the applicant sees)</h2>
  <?= tracking_panel_html($app) ?>
</div></div>
<?php $linkPurposes = array_filter(['offer' => ACCESS_LINKS['offer'], 'biometrics' => ACCESS_LINKS['biometrics'], 'lmia' => ACCESS_LINKS['lmia']], static fn ($l) => can($l[5])); ?>
<?php if ($linkPurposes && $app['status'] !== 'draft'): ?>
<?php $wa = preg_replace('/\D+/', '', (string) $app['applicant_phone']); ?>
<div class="card shadow-sm mb-3" id="links"><div class="card-body">
  <h2 class="h6 text-uppercase text-muted mb-1">Special links for <?= e($app['applicant_name']) ?></h2>
  <p class="small text-muted mb-3">Each link shows only its own thing (offer letter, fingerprints or LMIA) for this applicant, after they type their passport number<?= $app['passport_number'] ? ' (' . e($app['passport_number']) . ')' : '' ?>.</p>
  <?php if (!$app['passport_number']): ?><div class="alert alert-warning small">This application has no passport number, so the links cannot be opened yet.</div><?php endif; ?>
  <div class="row g-3">
  <?php foreach ($linkPurposes as $purpose => [$col, $page, $title, $icon, $shows]): ?>
    <div class="col-lg-4">
      <div class="link-card h-100">
        <div class="d-flex align-items-center gap-2 mb-1"><span class="icon-circle sm ic-blue"><i class="bi bi-<?= e($icon) ?>"></i></span><h3 class="h6 mb-0"><?= e($title) ?></h3></div>
        <p class="small text-muted mb-2">Shows <?= e($shows) ?>.</p>
        <?php if (empty($app[$col])): ?>
          <?= post_button(url('admin/application.php'), ['id' => $appId, 'action' => 'create_link', 'purpose' => $purpose], 'Create link', 'btn btn-sm btn-brand w-100') ?>
        <?php else: ?>
          <?php $link = absolute_url($page, ['t' => $app[$col]]); [$waSubject, $waBody] = access_link_message($purpose, $app['reference_no'], $link); ?>
          <div class="share-url mb-2"><?= e($link) ?></div>
          <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-outline-primary" data-copy="<?= e($link) ?>"><i class="bi bi-link-45deg me-1"></i>Copy</button>
            <a class="btn btn-sm btn-outline-success" target="_blank" rel="noopener noreferrer" href="<?= e('https://wa.me/' . $wa . '?text=' . rawurlencode($waBody)) ?>"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>
            <?= post_button(url('admin/application.php'), ['id' => $appId, 'action' => 'send_link', 'purpose' => $purpose], 'Email', 'btn btn-sm btn-brand') ?>
            <?= post_button(url('admin/application.php'), ['id' => $appId, 'action' => 'reset_link', 'purpose' => $purpose], 'Reset', 'btn btn-sm btn-outline-danger', 'Create a new link? The current ' . strtolower($title) . ' will stop working.') ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div></div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted mb-3">Application details</h2>
      <?php include APP_ROOT . '/applicant/partials/application-summary.php'; ?>
      <hr>
      <div class="row small">
        <div class="col-md-6"><strong>Applicant address:</strong> <?= e(implode(', ', array_filter([$profile['address_line'] ?? null, $profile['city'] ?? null, $profile['postal_code'] ?? null, $profile['country'] ?? null]))) ?: '—' ?></div>
        <div class="col-md-6"><strong>Emergency contact:</strong> <?= e(trim(($profile['emergency_contact_name'] ?? '') . ' ' . ($profile['emergency_contact_phone'] ?? ''))) ?: '—' ?></div>
        <div class="col-md-6"><strong>Submitted:</strong> <?= e(fmt_date($app['submitted_at'], true)) ?></div>
        <div class="col-md-6"><strong>Declaration accepted:</strong> <?= e(fmt_date($app['declaration_accepted_at'], true)) ?></div>
      </div>
    </div></div>
    <div class="card shadow-sm"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted mb-3">History</h2>
      <ul class="list-unstyled mb-0">
      <?php foreach ($history as $h): ?>
        <li class="border-bottom py-2">
          <div class="d-flex justify-content-between flex-wrap gap-1">
            <span><?= $h['old_status'] !== $h['new_status'] ? status_badge($h['new_status']) : '<span class="badge bg-light text-dark border">Note</span>' ?> <?= $h['visible_to_applicant'] ? '' : '<span class="badge bg-dark">Internal</span>' ?></span>
            <span class="small text-muted"><?= e(fmt_date($h['created_at'], true)) ?> · <?= e($h['full_name'] ?? 'System') ?></span>
          </div>
          <?php if ($h['note']): ?><div class="small mt-1 pre-wrap"><?= e($h['note']) ?></div><?php endif; ?>
        </li>
      <?php endforeach; ?>
      </ul>
    </div></div>
  </div>
  <div class="col-lg-4">
    <?php if (can('applications.review') && !in_array($app['status'], ['draft', 'cancelled'], true)): ?>
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h2 class="h6">Update status</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $appId ?>"><input type="hidden" name="action" value="update_status">
        <?php $opts = []; foreach (STAFF_SETTABLE_STATUSES as $s) { $opts[$s] = status_label($s); } ?>
        <?= form_select('status', 'New status', $opts, $app['status'], [], ['required' => true], null) ?>
        <?= form_textarea('note', 'Note to applicant (required for rejection / documents required)', '', [], ['rows' => 3, 'maxlength' => 1000]) ?>
        <button class="btn btn-brand w-100" type="submit">Update status</button>
      </form>
    </div></div>
    <div class="card shadow-sm mb-3"><div class="card-body">
      <h2 class="h6">Add note</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $appId ?>"><input type="hidden" name="action" value="add_note">
        <?= form_textarea('note', 'Note', '', [], ['rows' => 3, 'maxlength' => 1000, 'required' => true]) ?>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="visible" value="1" id="visible"><label class="form-check-label small" for="visible">Visible to applicant (sends a notification)</label></div>
        <button class="btn btn-outline-primary w-100" type="submit">Add note</button>
      </form>
    </div></div>
    <?php endif; ?>
    <div class="card shadow-sm"><div class="card-body">
      <h2 class="h6">Progress</h2>
      <ul class="milestones mt-3 mb-0">
      <?php foreach (application_milestones($app) as [$label, $done]): ?><li class="<?= $done ? 'done' : '' ?>"><span class="dot"></span><?= e($label) ?></li><?php endforeach; ?>
      </ul>
      <p class="small text-muted mb-0">Assigned: <?= $assigned ? e(implode(', ', array_column($assigned, 'full_name'))) : 'nobody' ?></p>
    </div></div>
  </div>
</div>

<?php elseif ($tab === 'documents'): ?>
  <?php $checklist = document_checklist($app); $old = db_all("SELECT * FROM documents WHERE application_id = ? AND status = 'superseded' ORDER BY created_at DESC", [$appId]); ?>
  <div class="list-group shadow-sm mb-3">
  <?php foreach ($checklist as $item): $r = $item['requirement']; $d = $item['document']; ?>
    <div class="list-group-item py-3">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div><strong><?= e($r['name']) ?></strong> <?= (int) $r['is_mandatory'] ? '<span class="badge bg-danger-subtle text-danger-emphasis">Required</span>' : '' ?></div>
        <?= $d ? badge(DOC_STATUS, $d['status']) : '<span class="badge bg-light text-dark border">Not uploaded</span>' ?>
      </div>
      <?php if ($d): ?>
        <div class="small mt-1">
          <a href="<?= e(url('download.php', ['type' => 'document', 'id' => $d['id']])) ?>"><?= e($d['original_name']) ?></a>
          <span class="text-muted">· <?= e(fmt_bytes((int) $d['size_bytes'])) ?> · <?= e($d['mime_type']) ?> · <?= e(fmt_date($d['created_at'], true)) ?> · SHA-256 <?= e(substr($d['sha256'], 0, 12)) ?>…</span>
        </div>
        <?php if ($d['review_note']): ?><div class="small text-muted">Review note: <?= e($d['review_note']) ?></div><?php endif; ?>
        <?php if (can('documents.review') && $d['status'] !== 'superseded'): ?>
        <form method="post" class="row g-2 mt-1 align-items-center">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $appId ?>"><input type="hidden" name="action" value="review_document"><input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
          <div class="col-md-6"><input class="form-control form-control-sm" name="note" maxlength="1000" placeholder="Note (required when requesting a correction)" aria-label="Review note"></div>
          <div class="col-md-6 d-flex flex-wrap gap-2 align-items-center">
            <button class="btn btn-sm btn-success" name="decision" value="accepted" type="submit" data-no-disable>Accept</button>
            <button class="btn btn-sm btn-outline-danger" name="decision" value="rejected" type="submit" data-no-disable>Request correction</button>
            <?php if (can('applications.review') && in_array($app['status'], ['submitted', 'under_review'], true)): ?>
              <span class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="set_documents_required" value="1" id="sdr<?= (int) $d['id'] ?>" checked><label class="form-check-label" for="sdr<?= (int) $d['id'] ?>">set status "Documents required"</label></span>
            <?php endif; ?>
          </div>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
  <?php if ($old): ?>
    <details class="small"><summary>Previous versions (<?= count($old) ?>)</summary>
      <ul class="mt-2"><?php foreach ($old as $o): ?><li><?= e($o['doc_type']) ?>: <a href="<?= e(url('download.php', ['type' => 'document', 'id' => $o['id']])) ?>"><?= e($o['original_name']) ?></a> (<?= e(fmt_date($o['created_at'], true)) ?>)</li><?php endforeach; ?></ul>
    </details>
  <?php endif; ?>

<?php elseif ($tab === 'biometrics'): ?>
  <?php $bios = db_all('SELECT b.*, u.full_name AS decided_name FROM biometric_appointments b LEFT JOIN users u ON u.id = b.decided_by WHERE b.application_id = ? ORDER BY b.appointment_at DESC', [$appId]); ?>
  <?php $caps = biometric_captures($appId); $capState = biometric_capture_state($caps); $pendingCaps = count(array_filter($caps, static fn ($c) => $c['status'] === 'submitted')); ?>
  <div class="card shadow-sm mb-3"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h2 class="h6 mb-1"><i class="bi bi-fingerprint me-1"></i>Fingerprints &amp; face photo from the applicant</h2>
        <div class="small text-muted"><?= $capState[3] ?> of <?= count(BIO_SLOTS) ?> photos · <span class="badge soft bg-<?= $capState[2] ?>-subtle text-<?= $capState[2] ?>-emphasis border-<?= $capState[2] ?>-subtle"><?= e($capState[1]) ?></span></div>
      </div>
      <div class="d-flex flex-wrap gap-2">
      <?php if ($pendingCaps): ?>
        <?= post_button(url('admin/application.php'), ['id' => $appId, 'action' => 'accept_all_biometrics'], 'Accept all ' . $pendingCaps . ' submitted', 'btn btn-sm btn-success', 'Accept every submitted fingerprint and photo? Check each image first.') ?>
      <?php endif; ?>
      <?php if ($app['status'] !== 'draft'): ?>
        <?= empty($app['biometrics_done_at'])
            ? post_button(url('admin/application.php'), ['id' => $appId, 'action' => 'set_biometrics_done', 'done' => '1', 'return_tab' => 'biometrics'], 'Mark biometrics done', 'btn btn-sm btn-outline-success', 'Mark the biometrics as done? The applicant will be notified.')
            : '<span class="badge soft bg-success-subtle text-success-emphasis border-success-subtle align-self-center"><i class="bi bi-check-circle me-1"></i>Marked done ' . e(fmt_date($app['biometrics_done_at'])) . '</span>'
              . post_button(url('admin/application.php'), ['id' => $appId, 'action' => 'set_biometrics_done', 'done' => '0', 'return_tab' => 'biometrics'], 'Undo', 'btn btn-sm btn-outline-secondary', 'Set biometrics back to not done?') ?>
      <?php endif; ?>
      </div>
    </div>
    <?php if (!$caps): ?><?= empty_state('The applicant has not added any fingerprints yet.', 'fingerprint') ?><?php endif; ?>
    <?php if ($caps): ?>
    <div class="bio-grid">
    <?php foreach (BIO_SLOTS as $slot => [$label, $icon]): $c = $caps[$slot] ?? null; ?>
      <div class="bio-slot<?= $c ? ' has-photo status-' . e($c['status']) : '' ?>">
        <?php if ($c): ?>
          <a class="bio-thumb d-grid" href="<?= e(url('download.php', ['type' => 'biometric', 'id' => $c['id'], 'inline' => 1])) ?>" target="_blank" rel="noopener" title="Open full size">
            <img src="<?= e(url('download.php', ['type' => 'biometric', 'id' => $c['id'], 'inline' => 1])) ?>" alt="<?= e($label) ?>" loading="lazy">
          </a>
        <?php else: ?>
          <div class="bio-thumb"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i></div>
        <?php endif; ?>
        <div class="fw-semibold small mt-2"><?= e($label) ?></div>
        <div class="mt-1"><?= $c ? badge(BIO_CAPTURE_STATUS, $c['status']) : '<span class="badge soft bg-light text-muted border">Missing</span>' ?></div>
        <?php if ($c && $c['review_note']): ?><div class="small text-muted mt-1"><?= e($c['review_note']) ?></div><?php endif; ?>
        <?php if ($c && $c['status'] === 'submitted'): ?>
        <form method="post" class="mt-2">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $appId ?>"><input type="hidden" name="action" value="review_biometric"><input type="hidden" name="capture_id" value="<?= (int) $c['id'] ?>">
          <input class="form-control form-control-sm mb-1" name="note" maxlength="1000" placeholder="Reason if retake needed" aria-label="Reason for retake">
          <div class="d-flex gap-1">
            <button class="btn btn-sm btn-success flex-fill" name="decision" value="accepted" data-no-disable>Accept</button>
            <button class="btn btn-sm btn-outline-danger flex-fill" name="decision" value="rejected" data-no-disable>Retake</button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div></div>
  <div class="row g-3">
    <div class="col-lg-7">
      <?php if (!$bios): ?><?= empty_state('No appointments yet.') ?><?php endif; ?>
      <?php foreach ($bios as $b): ?>
        <div class="card shadow-sm mb-2"><div class="card-body">
          <div class="d-flex justify-content-between flex-wrap gap-1"><strong><?= e(fmt_date($b['appointment_at'], true)) ?> · <?= e($b['center_name']) ?></strong><?= badge(BIO_STATUS, $b['status']) ?></div>
          <div class="small text-muted"><?= e($b['center_address']) ?></div>
          <?php if ($b['decision_note']): ?><div class="small">Note: <?= e($b['decision_note']) ?></div><?php endif; ?>
          <?php if ($b['decided_at']): ?><div class="small text-muted">Decided by <?= e($b['decided_name'] ?? '—') ?> on <?= e(fmt_date($b['decided_at'], true)) ?></div><?php endif; ?>
          <?php if (!in_array($b['status'], ['approved', 'rejected', 'cancelled'], true)): ?>
          <form method="post" class="row g-2 mt-1">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $appId ?>"><input type="hidden" name="action" value="biometric_decision"><input type="hidden" name="biometric_id" value="<?= (int) $b['id'] ?>">
            <div class="col-12"><input class="form-control form-control-sm" name="note" maxlength="1000" placeholder="Note (required for rejection)" aria-label="Decision note"></div>
            <div class="col-12 d-flex flex-wrap gap-2">
              <?php if ($b['status'] === 'scheduled'): ?><button class="btn btn-sm btn-outline-primary" name="decision" value="attended" data-no-disable>Mark attended</button><?php endif; ?>
              <button class="btn btn-sm btn-success" name="decision" value="approved" data-no-disable>Approve stage</button>
              <button class="btn btn-sm btn-outline-danger" name="decision" value="rejected" data-no-disable>Reject stage</button>
              <?php if ($b['status'] === 'scheduled'): ?><button class="btn btn-sm btn-outline-warning" name="decision" value="missed" data-no-disable>Missed</button><button class="btn btn-sm btn-outline-secondary" name="decision" value="cancelled" data-no-disable>Cancel</button><?php endif; ?>
            </div>
          </form>
          <?php endif; ?>
        </div></div>
      <?php endforeach; ?>
    </div>
    <div class="col-lg-5">
      <div class="card shadow-sm"><div class="card-body">
        <h2 class="h6">Schedule appointment</h2>
        <form method="post" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $appId ?>"><input type="hidden" name="action" value="schedule_biometric">
          <?= form_input('center_name', 'Centre name', '', $errors, 'text', ['required' => true, 'maxlength' => 150]) ?>
          <?= form_input('center_address', 'Centre address', '', $errors, 'text', ['maxlength' => 255]) ?>
          <?= form_input('appointment_at', 'Date & time', '', $errors, 'datetime-local', ['required' => true]) ?>
          <?= form_textarea('instructions', 'Instructions for the applicant', '', $errors, ['rows' => 3, 'maxlength' => 3000]) ?>
          <button class="btn btn-brand w-100" type="submit">Schedule &amp; notify</button>
        </form>
      </div></div>
    </div>
  </div>

<?php elseif ($tab === 'payments'): ?>
  <?php $payments = db_all('SELECT p.*, f.name AS fee_name, u.full_name AS reviewer, g.name AS region_name FROM payments p LEFT JOIN fee_types f ON f.id = p.fee_type_id LEFT JOIN users u ON u.id = p.reviewed_by LEFT JOIN regions g ON g.id = p.region_id WHERE p.application_id = ? ORDER BY p.created_at DESC', [$appId]); ?>
  <p class="small text-muted"><i class="bi bi-geo-alt me-1"></i>Applicant's payment region: <strong><?= e(region_name($app['region_id'] ? (int) $app['region_id'] : null) ?? 'not chosen') ?></strong></p>
  <?php if (!$payments): ?><?= empty_state('No payment receipts uploaded.') ?><?php endif; ?>
  <?php foreach ($payments as $p): ?>
    <div class="card shadow-sm mb-2"><div class="card-body">
      <div class="d-flex justify-content-between flex-wrap gap-1"><strong><?= fmt_money($p['amount'], $p['currency']) ?> · <?= e($p['fee_name'] ?? 'Fee') ?></strong><?= badge(PAY_STATUS, $p['status']) ?></div>
      <div class="small">Region: <strong><?= e($p['region_name'] ?? '—') ?></strong> · Payer: <?= e($p['payer_name']) ?> · Bank ref: <strong><?= e($p['transaction_reference']) ?></strong> · Paid on <?= e(fmt_date($p['paid_on'])) ?> · Uploaded <?= e(fmt_date($p['created_at'], true)) ?></div>
      <div class="small"><a href="<?= e(url('download.php', ['type' => 'receipt', 'id' => $p['id']])) ?>">Receipt: <?= e($p['receipt_original_name']) ?></a></div>
      <?php if ($p['reviewed_at']): ?><div class="small text-muted">Reviewed by <?= e($p['reviewer'] ?? '—') ?> on <?= e(fmt_date($p['reviewed_at'], true)) ?><?= $p['review_note'] ? ': ' . e($p['review_note']) : '' ?></div><?php endif; ?>
      <?php if ($p['status'] === 'submitted'): ?>
        <form method="post" class="row g-2 mt-1" data-confirm="Record this decision? Confirm only after checking the bank record.">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $appId ?>"><input type="hidden" name="action" value="review_payment"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
          <div class="col-md-7"><input class="form-control form-control-sm" name="note" maxlength="1000" placeholder="Note (required when rejecting)" aria-label="Review note"></div>
          <div class="col-md-5 d-flex gap-2">
            <button class="btn btn-sm btn-success" name="decision" value="confirmed" data-no-disable>Confirm payment</button>
            <button class="btn btn-sm btn-outline-danger" name="decision" value="rejected" data-no-disable>Reject</button>
          </div>
        </form>
      <?php endif; ?>
    </div></div>
  <?php endforeach; ?>

<?php elseif ($tab === 'offers'): ?>
  <?php $offers = db_all('SELECT o.*, u.full_name AS uploader, p.full_name AS publisher FROM offer_letters o LEFT JOIN users u ON u.id = o.uploaded_by LEFT JOIN users p ON p.id = o.published_by WHERE o.application_id = ? ORDER BY o.created_at DESC', [$appId]); ?>
  <div class="row g-3">
    <div class="col-lg-7">
      <?php if (!$offers): ?><?= empty_state('No offer letters uploaded. The applicant sees a "pending" message.') ?><?php endif; ?>
      <?php foreach ($offers as $o): ?>
        <div class="card shadow-sm mb-2"><div class="card-body">
          <div class="d-flex justify-content-between flex-wrap gap-1"><strong><?= e($o['title']) ?></strong><?= (int) $o['is_published'] ? '<span class="badge bg-success">Published</span>' : '<span class="badge bg-secondary">Unpublished – hidden from applicant</span>' ?></div>
          <div class="small"><a href="<?= e(url('download.php', ['type' => 'offer', 'id' => $o['id']])) ?>"><?= e($o['original_name']) ?></a> · uploaded by <?= e($o['uploader'] ?? '—') ?> on <?= e(fmt_date($o['created_at'], true)) ?></div>
          <?php if ($o['published_at'] && $o['is_published']): ?><div class="small text-muted">Published by <?= e($o['publisher'] ?? '—') ?> on <?= e(fmt_date($o['published_at'], true)) ?></div><?php endif; ?>
          <?php if ($o['notes']): ?><div class="small"><?= e($o['notes']) ?></div><?php endif; ?>
          <div class="d-flex flex-wrap gap-2 mt-2">
            <?php if (can('offers.publish')): ?>
              <?= (int) $o['is_published']
                  ? post_button(url('admin/application.php'), ['id' => $appId, 'action' => 'unpublish_offer', 'offer_id' => $o['id']], 'Unpublish', 'btn btn-sm btn-outline-warning', 'Hide this offer letter from the applicant?')
                  : post_button(url('admin/application.php'), ['id' => $appId, 'action' => 'publish_offer', 'offer_id' => $o['id']], 'Publish to applicant', 'btn btn-sm btn-success', 'Publish this offer letter? The applicant will be notified and can download it.') ?>
            <?php endif; ?>
            <?php if (can('offers.manage') && !(int) $o['is_published']): ?>
              <?= post_button(url('admin/application.php'), ['id' => $appId, 'action' => 'delete_offer', 'offer_id' => $o['id']], 'Delete', 'btn btn-sm btn-outline-danger', 'Delete this unpublished offer letter?') ?>
            <?php endif; ?>
          </div>
        </div></div>
      <?php endforeach; ?>
    </div>
    <?php if (can('offers.manage')): ?>
    <div class="col-lg-5">
      <div class="card shadow-sm"><div class="card-body">
        <h2 class="h6">Upload offer letter (PDF)</h2>
        <p class="small text-muted">Uploads are unpublished until someone with the publish permission releases them.</p>
        <form method="post" enctype="multipart/form-data" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $appId ?>"><input type="hidden" name="action" value="upload_offer">
          <?= form_input('title', 'Title', 'Offer letter', $errors, 'text', ['required' => true, 'maxlength' => 150]) ?>
          <?= form_textarea('notes', 'Note for applicant (optional)', '', $errors, ['rows' => 2, 'maxlength' => 1000]) ?>
          <div class="mb-3"><label class="form-label" for="offer_file">PDF file</label><input class="form-control<?= invalid($errors, 'file') ?>" type="file" name="file" id="offer_file" accept=".pdf,application/pdf" required data-max-mb="<?= upload_max_mb() ?>"><?= field_error($errors, 'file') ?></div>
          <button class="btn btn-brand w-100" type="submit">Upload (unpublished)</button>
        </form>
      </div></div>
    </div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'file'): ?>
  <?php $docs = []; foreach (array_keys(LINK_DOC_TYPES) as $t) { $docs[$t] = current_document($appId, $t); } ?>
  <div class="card shadow-sm" id="member-file"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <h2 class="h6 mb-1"><i class="bi bi-person-vcard me-1"></i>Client file – <?= e($app['applicant_name']) ?></h2>
        <div class="small text-muted">Passport number <strong><?= e($app['passport_number'] ?: 'not entered') ?></strong> · upload the visa, medical, passport and LMIA letter here. The client sees each one on its own <a href="<?= e(url('admin/shared-links.php')) ?>">shared link</a> after typing this passport number.</div>
      </div>
    </div>
    <div class="track-grid member-grid">
    <?php foreach (member_file_status($app) as $key => [$mTitle, $mIcon, $mLabel, $mColor, $mDetail]): ?>
      <div class="track-tile tone-<?= e($mColor) ?>" data-stage="<?= e($key) ?>">
        <div class="d-flex align-items-center gap-2 mb-2"><span class="track-icon"><i class="bi bi-<?= e($mIcon) ?>" aria-hidden="true"></i></span><h3 class="h6 mb-0"><?= e($mTitle) ?></h3></div>
        <div class="track-status text-<?= e($mColor) ?>-emphasis"><?= e($mLabel) ?></div>
        <div class="small text-muted mt-1"><?= e($mDetail) ?></div>
        <div class="mt-2">
        <?php if (array_key_exists($key, $docs)): ?>
          <?php if ($docs[$key]): ?><a class="small d-inline-block mb-2" href="<?= e(url('download.php', ['type' => 'document', 'id' => $docs[$key]['id'], 'inline' => 1])) ?>" target="_blank" rel="noopener"><i class="bi bi-eye me-1"></i>View file</a><?php endif; ?>
          <?php if (can('documents.review') && $app['status'] !== 'cancelled'): ?>
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $appId ?>"><input type="hidden" name="action" value="staff_upload_document"><input type="hidden" name="doc_type" value="<?= e($key) ?>">
            <input class="form-control form-control-sm mb-1" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required data-max-mb="<?= upload_max_mb() ?>" aria-label="<?= e($mTitle) ?> file">
            <button class="btn btn-sm btn-outline-primary w-100" type="submit"><i class="bi bi-upload me-1"></i><?= $docs[$key] ? 'Replace' : 'Upload' ?> <?= e(strtolower($mTitle)) ?></button>
          </form>
          <?php endif; ?>
        <?php elseif ($key === 'biometrics' && can('biometrics.manage') && $app['status'] !== 'draft'): ?>
          <?= empty($app['biometrics_done_at'])
              ? post_button(url('admin/application.php'), ['id' => $appId, 'action' => 'set_biometrics_done', 'done' => '1'], 'Mark biometrics done', 'btn btn-sm btn-success w-100', 'Mark the biometrics of ' . $app['applicant_name'] . ' as done? The applicant will be notified.')
              : post_button(url('admin/application.php'), ['id' => $appId, 'action' => 'set_biometrics_done', 'done' => '0'], 'Undo – not done', 'btn btn-sm btn-outline-secondary w-100', 'Set biometrics back to not done?') ?>
        <?php elseif ($key === 'lmia' && can('applications.review') && $app['status'] !== 'draft'): ?>
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $appId ?>"><input type="hidden" name="action" value="upload_lmia_doc"><input type="hidden" name="return_tab" value="file">
            <input class="form-control form-control-sm mb-1" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required data-max-mb="<?= upload_max_mb() ?>" aria-label="LMIA letter file">
            <button class="btn btn-sm btn-outline-primary w-100" type="submit"><i class="bi bi-upload me-1"></i><?= $app['lmia_doc_stored_name'] ? 'Replace' : 'Upload' ?> LMIA letter</button>
          </form>
          <a class="small d-inline-block mt-1" href="<?= e(url('admin/application.php', ['id' => $appId, 'tab' => 'lmia'])) ?>">Change LMIA status &rarr;</a>
        <?php elseif ($key === 'payment' && can('payments.review')): ?>
          <a class="small" href="<?= e(url('admin/application.php', ['id' => $appId, 'tab' => 'payments'])) ?>">Check payments &rarr;</a>
        <?php elseif ($key === 'offer' && can_any(['offers.manage', 'offers.publish'])): ?>
          <a class="small" href="<?= e(url('admin/application.php', ['id' => $appId, 'tab' => 'offers'])) ?>">Upload / publish offer letter &rarr;</a>
        <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div></div>

<?php elseif ($tab === 'lmia'): ?>
  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card shadow-sm"><div class="card-body">
        <h2 class="h5">LMIA (Labour Market Impact Assessment)</h2>
        <p class="mb-1">Current status: <?= badge(LMIA_STATUS, $app['lmia_status']) ?></p>
        <?php if ($app['lmia_number']): ?><p class="mb-1 small">LMIA number: <strong><?= e($app['lmia_number']) ?></strong></p><?php endif; ?>
        <?php if ($app['lmia_note']): ?><p class="mb-1 small pre-wrap"><?= e($app['lmia_note']) ?></p><?php endif; ?>
        <p class="small text-muted mb-0">Last updated <?= e(fmt_date($app['lmia_updated_at'], true)) ?>. The applicant sees this status on their dashboard, the status link and the LMIA link.</p>
      </div></div>
      <div class="card shadow-sm mt-3"><div class="card-body">
        <h2 class="h6"><i class="bi bi-file-earmark-text me-1"></i>LMIA letter (shown on the LMIA link)</h2>
        <?php if ($app['lmia_doc_stored_name']): ?>
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border rounded-3 p-2 mb-3">
            <div class="small"><strong><?= e($app['lmia_doc_original_name']) ?></strong><br><span class="text-muted">Added <?= e(fmt_date($app['lmia_doc_uploaded_at'], true)) ?> · <?= e(fmt_bytes((int) $app['lmia_doc_size'])) ?></span></div>
            <div class="d-flex gap-2">
              <a class="btn btn-sm btn-outline-primary" href="<?= e(url('download.php', ['type' => 'lmia', 'id' => $appId])) ?>">Download</a>
              <?= post_button(url('admin/application.php'), ['id' => $appId, 'action' => 'remove_lmia_doc'], 'Remove', 'btn btn-sm btn-outline-danger', 'Remove the LMIA letter? The applicant will no longer be able to download it.') ?>
            </div>
          </div>
        <?php else: ?>
          <p class="small text-muted">No LMIA letter yet.</p>
        <?php endif; ?>
        <?php if ($app['status'] !== 'draft'): ?>
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $appId ?>"><input type="hidden" name="action" value="upload_lmia_doc">
          <label class="form-label small" for="lmia_file"><?= $app['lmia_doc_stored_name'] ? 'Replace with a new file' : 'Upload the LMIA letter' ?> (PDF, JPG or PNG, max <?= upload_max_mb() ?> MB)</label>
          <div class="d-flex gap-2">
            <input class="form-control form-control-sm" type="file" id="lmia_file" name="file" accept=".pdf,.jpg,.jpeg,.png" required data-max-mb="<?= upload_max_mb() ?>">
            <button class="btn btn-sm btn-brand" type="submit">Upload</button>
          </div>
        </form>
        <?php endif; ?>
      </div></div>
    </div>
    <div class="col-lg-5">
      <?php if ($app['status'] !== 'draft'): ?>
      <div class="card shadow-sm"><div class="card-body">
        <h2 class="h6">Update LMIA</h2>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $appId ?>"><input type="hidden" name="action" value="update_lmia">
          <?php $lo = []; foreach (LMIA_STATUS as $k => [$l]) { $lo[$k] = $l; } ?>
          <?= form_select('lmia_status', 'LMIA status', $lo, $app['lmia_status'], [], ['required' => true], null) ?>
          <?= form_input('lmia_number', 'LMIA number (optional)', $app['lmia_number'], [], 'text', ['maxlength' => 40]) ?>
          <?= form_textarea('lmia_note', 'Note shown to the applicant (optional)', $app['lmia_note'], [], ['rows' => 3, 'maxlength' => 1000]) ?>
          <button class="btn btn-brand w-100" type="submit">Save LMIA status</button>
        </form>
      </div></div>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($tab === 'travel'): ?>
  <?php $t = db_one('SELECT t.*, u.full_name AS publisher FROM travel_arrangements t LEFT JOIN users u ON u.id = t.published_by WHERE t.application_id = ?', [$appId]) ?? []; ?>
  <div class="card shadow-sm"><div class="card-body">
    <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
      <h2 class="h6 mb-0">Travel &amp; accommodation</h2>
      <?= $t ? ((int) $t['is_published'] ? '<span class="badge bg-success">Published ' . e(fmt_date($t['published_at'])) . '</span>' : '<span class="badge bg-secondary">Draft – hidden from applicant</span>') : '' ?>
    </div>
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $appId ?>"><input type="hidden" name="action" value="save_travel">
      <div class="row">
        <div class="col-md-4"><?= form_input('arrival_date', 'Arrival date', $t['arrival_date'] ?? '', $errors, 'date') ?></div>
        <div class="col-md-4"><?= form_input('arrival_airport', 'Arrival airport', $t['arrival_airport'] ?? '', $errors, 'text', ['maxlength' => 150]) ?></div>
        <div class="col-md-4"><?= form_input('flight_details', 'Flight details', $t['flight_details'] ?? '', $errors, 'text', ['maxlength' => 255]) ?></div>
        <div class="col-md-4"><?= form_select('airport_pickup', 'Airport pickup', ['0' => 'No', '1' => 'Yes'], (string) ($t['airport_pickup'] ?? '0'), $errors, [], null) ?></div>
        <div class="col-md-8"><?= form_input('pickup_details', 'Pickup details', $t['pickup_details'] ?? '', $errors, 'text', ['maxlength' => 500]) ?></div>
        <div class="col-md-6"><?= form_input('accommodation_name', 'Accommodation name', $t['accommodation_name'] ?? '', $errors, 'text', ['maxlength' => 150]) ?></div>
        <div class="col-md-6"><?= form_input('accommodation_contact', 'Accommodation contact', $t['accommodation_contact'] ?? '', $errors, 'text', ['maxlength' => 150]) ?></div>
        <div class="col-12"><?= form_input('accommodation_address', 'Accommodation address', $t['accommodation_address'] ?? '', $errors, 'text', ['maxlength' => 255]) ?></div>
        <div class="col-md-6"><?= form_input('checkin_date', 'Check-in date', $t['checkin_date'] ?? '', $errors, 'date') ?></div>
        <div class="col-md-6"><?= form_input('checkout_date', 'Check-out date', $t['checkout_date'] ?? '', $errors, 'date') ?></div>
      </div>
      <?= form_textarea('notes', 'Additional notes', $t['notes'] ?? '', $errors, ['rows' => 3, 'maxlength' => 3000]) ?>
      <button class="btn btn-brand" type="submit">Save</button>
    </form>
    <?php if ($t): ?>
      <div class="mt-3">
        <?= (int) $t['is_published']
            ? post_button(url('admin/application.php'), ['id' => $appId, 'action' => 'unpublish_travel'], 'Unpublish', 'btn btn-outline-warning', 'Hide travel details from the applicant?')
            : post_button(url('admin/application.php'), ['id' => $appId, 'action' => 'publish_travel'], 'Publish to applicant', 'btn btn-success', 'Publish these travel details to the applicant?') ?>
      </div>
    <?php endif; ?>
  </div></div>

<?php elseif ($tab === 'assignment'): ?>
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card shadow-sm"><div class="card-body">
        <h2 class="h6">Assigned staff</h2>
        <?php if (!$assigned): ?><p class="text-muted small">Nobody is assigned. Only staff with "View all applications" can see this case.</p><?php endif; ?>
        <ul class="list-unstyled mb-0">
        <?php foreach ($assigned as $a): ?>
          <li class="d-flex justify-content-between align-items-center border-bottom py-2 gap-2">
            <span><?= e($a['full_name']) ?> <span class="small text-muted"><?= e($a['role_name'] ?? '') ?></span></span>
            <?= post_button(url('admin/application.php'), ['id' => $appId, 'action' => 'unassign', 'staff_user_id' => $a['id']], 'Remove', 'btn btn-sm btn-outline-danger', 'Remove this assignment?') ?>
          </li>
        <?php endforeach; ?>
        </ul>
      </div></div>
    </div>
    <div class="col-lg-6">
      <div class="card shadow-sm"><div class="card-body">
        <h2 class="h6">Assign a staff member</h2>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $appId ?>"><input type="hidden" name="action" value="assign">
          <?php $opts = []; foreach (staff_members() as $s) { $opts[$s['id']] = $s['full_name'] . ' – ' . ($s['is_super_admin'] ? 'Super Admin' : ($s['role_name'] ?? 'No role')); } ?>
          <?= form_select('staff_user_id', 'Staff member', $opts, '', [], ['required' => true]) ?>
          <button class="btn btn-brand" type="submit">Assign</button>
        </form>
      </div></div>
    </div>
  </div>

<?php elseif ($tab === 'cancel'): ?>
  <?php $reqs = db_all('SELECT c.*, u.full_name AS reviewer FROM cancellation_requests c LEFT JOIN users u ON u.id = c.reviewed_by WHERE c.application_id = ? ORDER BY c.created_at DESC', [$appId]); ?>
  <?php if (!$reqs): ?><?= empty_state('No cancellation requests.') ?><?php endif; ?>
  <?php foreach ($reqs as $c): ?>
    <div class="card shadow-sm mb-2"><div class="card-body">
      <div class="d-flex justify-content-between flex-wrap gap-1"><strong>Requested <?= e(fmt_date($c['created_at'], true)) ?></strong><span class="badge bg-<?= $c['status'] === 'pending' ? 'warning text-dark' : ($c['status'] === 'approved' ? 'success' : 'secondary') ?>"><?= e(ucfirst($c['status'])) ?></span></div>
      <div class="small pre-wrap mt-1"><?= e($c['reason']) ?></div>
      <?php if ($c['reviewed_at'] && $c['reviewer']): ?><div class="small text-muted">Reviewed by <?= e($c['reviewer']) ?> on <?= e(fmt_date($c['reviewed_at'], true)) ?><?= $c['review_note'] ? ': ' . e($c['review_note']) : '' ?></div><?php endif; ?>
      <?php if ($c['status'] === 'pending'): ?>
        <form method="post" class="row g-2 mt-1">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $appId ?>"><input type="hidden" name="action" value="cancellation_decision"><input type="hidden" name="request_id" value="<?= (int) $c['id'] ?>">
          <div class="col-md-7"><input class="form-control form-control-sm" name="note" maxlength="1000" placeholder="Note to applicant (required when declining)" aria-label="Note"></div>
          <div class="col-md-5 d-flex gap-2">
            <button class="btn btn-sm btn-danger" name="decision" value="approved" data-no-disable>Approve cancellation</button>
            <button class="btn btn-sm btn-outline-secondary" name="decision" value="rejected" data-no-disable>Decline</button>
          </div>
        </form>
      <?php endif; ?>
    </div></div>
  <?php endforeach; ?>
<?php endif; ?>
<?php render_footer();
