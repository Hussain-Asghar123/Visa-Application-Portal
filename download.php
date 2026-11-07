<?php
/**
 * Authorised download endpoint for every private file.
 *   download.php?type=document|offer|receipt|biometric|lmia&id=N
 * Files live in private storage with random names; this script is the ONLY way to read them.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$type = query('type');
$id = query_int('id');
$inline = query('inline') === '1';

$deny = static function (string $reason) use ($type, $id): never {
    security_event('download.denied', $type ?: null, $id ?: null, ['reason' => $reason]);
    abort(404);
};

if ($user['user_type'] === 'applicant' && config('security.require_email_verification', true) && empty($user['email_verified_at'])) {
    $deny('email not verified');
}

switch ($type) {
    case 'document':
        $row = db_one('SELECT d.*, a.user_id FROM documents d JOIN applications a ON a.id = d.application_id WHERE d.id = ?', [$id]);
        if (!$row) {
            $deny('not found');
        }
        if (is_staff($user)) {
            if (!staff_can_access_application($user, (int) $row['application_id']) || !can_any(['documents.review', 'applications.review'], $user)) {
                $deny('staff not permitted');
            }
            audit_log('file.download', 'document', $id, ['application_id' => (int) $row['application_id'], 'doc_type' => $row['doc_type']]);
        } elseif ((int) $row['user_id'] !== (int) $user['id']) {
            $deny('not owner');
        }
        stream_stored_file('documents', $row['stored_name'], $row['mime_type'], $row['original_name'], $inline);

    case 'offer':
        $row = db_one('SELECT o.*, a.user_id FROM offer_letters o JOIN applications a ON a.id = o.application_id WHERE o.id = ?', [$id]);
        if (!$row) {
            $deny('not found');
        }
        if (is_staff($user)) {
            if (!staff_can_access_application($user, (int) $row['application_id']) || !can_any(['offers.manage', 'offers.publish', 'applications.review'], $user)) {
                $deny('staff not permitted');
            }
            audit_log('file.download', 'offer_letter', $id, ['application_id' => (int) $row['application_id']]);
        } else {
            // Applicants may only download PUBLISHED offer letters for their OWN application.
            if ((int) $row['user_id'] !== (int) $user['id'] || (int) $row['is_published'] !== 1) {
                $deny('offer not owned or not published');
            }
            audit_log('offer.downloaded', 'offer_letter', $id, ['application_id' => (int) $row['application_id']]);
        }
        stream_stored_file('offers', $row['stored_name'], $row['mime_type'], $row['original_name'], $inline);

    case 'receipt':
        $row = db_one('SELECT p.*, a.user_id FROM payments p JOIN applications a ON a.id = p.application_id WHERE p.id = ?', [$id]);
        if (!$row) {
            $deny('not found');
        }
        if (is_staff($user)) {
            if (!staff_can_access_application($user, (int) $row['application_id']) || !can('payments.review', $user)) {
                $deny('staff not permitted');
            }
            audit_log('file.download', 'payment', $id, ['application_id' => (int) $row['application_id']]);
        } elseif ((int) $row['user_id'] !== (int) $user['id']) {
            $deny('not owner');
        }
        stream_stored_file('receipts', $row['receipt_stored_name'], $row['receipt_mime'], $row['receipt_original_name'], $inline);

    case 'biometric':
        $row = db_one('SELECT b.*, a.user_id FROM biometric_captures b JOIN applications a ON a.id = b.application_id WHERE b.id = ?', [$id]);
        if (!$row) {
            $deny('not found');
        }
        if (is_staff($user)) {
            if (!staff_can_access_application($user, (int) $row['application_id']) || !can('biometrics.manage', $user)) {
                $deny('staff not permitted');
            }
            audit_log('file.download', 'biometric_capture', $id, ['application_id' => (int) $row['application_id'], 'slot' => $row['slot']]);
        } elseif ((int) $row['user_id'] !== (int) $user['id']) {
            $deny('not owner');
        }
        stream_stored_file('biometrics', $row['stored_name'], $row['mime_type'], $row['original_name'], $inline);

    case 'lmia': // id = application id; one LMIA letter per application
        $row = db_one('SELECT id, user_id, lmia_doc_stored_name, lmia_doc_mime, lmia_doc_original_name FROM applications WHERE id = ?', [$id]);
        if (!$row || empty($row['lmia_doc_stored_name'])) {
            $deny('not found');
        }
        if (is_staff($user)) {
            if (!staff_can_access_application($user, $id) || !can('applications.review', $user)) {
                $deny('staff not permitted');
            }
            audit_log('file.download', 'lmia_letter', $id, ['application_id' => $id]);
        } elseif ((int) $row['user_id'] !== (int) $user['id']) {
            $deny('not owner');
        }
        stream_stored_file('lmia', $row['lmia_doc_stored_name'], $row['lmia_doc_mime'], $row['lmia_doc_original_name'], $inline);

    default:
        $deny('bad type');
}
