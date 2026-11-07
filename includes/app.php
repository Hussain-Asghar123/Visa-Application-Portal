<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/** Application statuses: code => [label, bootstrap colour]. */
const APP_STATUSES = [
    'draft'                => ['Draft', 'secondary'],
    'submitted'            => ['Submitted', 'primary'],
    'under_review'         => ['Under review', 'info'],
    'documents_required'   => ['Documents required', 'warning'],
    'biometrics_scheduled' => ['Biometrics scheduled', 'info'],
    'payment_pending'      => ['Payment pending', 'warning'],
    'offer_issued'         => ['Offer issued', 'primary'],
    'approved'             => ['Approved', 'success'],
    'rejected'             => ['Rejected', 'danger'],
    'cancelled'            => ['Cancelled', 'dark'],
];

/** Statuses staff can set manually (draft is applicant-only; cancelled goes through the cancellation workflow). */
const STAFF_SETTABLE_STATUSES = ['submitted', 'under_review', 'documents_required', 'biometrics_scheduled', 'payment_pending', 'offer_issued', 'approved', 'rejected'];

const TERMINAL_STATUSES = ['rejected', 'cancelled'];

const DOC_STATUS = [
    'pending' => ['Awaiting review', 'warning'],
    'accepted' => ['Accepted', 'success'],
    'rejected' => ['Correction needed', 'danger'],
    'superseded' => ['Replaced', 'secondary'],
];

const BIO_STATUS = [
    'scheduled' => ['Scheduled', 'info'],
    'attended' => ['Attended – awaiting decision', 'primary'],
    'approved' => ['Approved', 'success'],
    'rejected' => ['Rejected', 'danger'],
    'missed' => ['Missed', 'warning'],
    'cancelled' => ['Cancelled', 'secondary'],
];

/** Photos the applicant captures on the Biometrics tab: slot => [label, icon, camera]. */
const BIO_SLOTS = [
    'right_thumb'  => ['Right thumb', 'fingerprint', 'environment'],
    'right_index'  => ['Right index finger', 'fingerprint', 'environment'],
    'right_middle' => ['Right middle finger', 'fingerprint', 'environment'],
    'right_ring'   => ['Right ring finger', 'fingerprint', 'environment'],
    'right_little' => ['Right little finger', 'fingerprint', 'environment'],
    'left_thumb'   => ['Left thumb', 'fingerprint', 'environment'],
    'left_index'   => ['Left index finger', 'fingerprint', 'environment'],
    'left_middle'  => ['Left middle finger', 'fingerprint', 'environment'],
    'left_ring'    => ['Left ring finger', 'fingerprint', 'environment'],
    'left_little'  => ['Left little finger', 'fingerprint', 'environment'],
    'face'         => ['Face photo', 'person-bounding-box', 'user'],
];

const BIO_CAPTURE_STATUS = [
    'draft' => ['Added – not submitted', 'secondary'],
    'submitted' => ['Submitted – under review', 'warning'],
    'accepted' => ['Accepted', 'success'],
    'rejected' => ['Retake needed', 'danger'],
];

const PAY_STATUS = [
    'submitted' => ['Awaiting verification', 'warning'],
    'confirmed' => ['Confirmed', 'success'],
    'rejected' => ['Rejected', 'danger'],
];

/** LMIA (Labour Market Impact Assessment) stage, set by staff on the case page. */
const LMIA_STATUS = [
    'not_started' => ['Not started', 'secondary'],
    'applied' => ['Applied – awaiting decision', 'warning'],
    'in_process' => ['In process', 'info'],
    'approved' => ['Approved (positive LMIA)', 'success'],
    'rejected' => ['Refused (negative LMIA)', 'danger'],
    'not_required' => ['Not required', 'secondary'],
];

const TICKET_STATUS = [
    'open' => ['Open', 'primary'],
    'awaiting_staff' => ['Awaiting staff reply', 'warning'],
    'awaiting_applicant' => ['Awaiting your reply', 'info'],
    'closed' => ['Closed', 'secondary'],
];

const TICKET_CATEGORIES = ['general' => 'General question', 'application' => 'My application', 'documents' => 'Documents', 'payment' => 'Payment', 'biometrics' => 'Biometrics', 'travel' => 'Travel & accommodation', 'technical' => 'Technical problem'];

function badge(array $map, ?string $code): string
{
    [$label, $color] = $map[$code] ?? [ucfirst(str_replace('_', ' ', (string) $code)), 'secondary'];
    return '<span class="badge soft bg-' . $color . '-subtle text-' . $color . '-emphasis border-' . $color . '-subtle">' . e($label) . '</span>';
}

function status_badge(?string $status): string
{
    return badge(APP_STATUSES, $status);
}

function status_label(?string $status): string
{
    return APP_STATUSES[$status][0] ?? (string) $status;
}

function is_terminal(array $app): bool
{
    return in_array($app['status'], TERMINAL_STATUSES, true);
}

/** Can the applicant still upload/replace documents? */
function app_accepts_documents(array $app): bool
{
    return !in_array($app['status'], ['approved', 'rejected', 'cancelled'], true);
}

function app_accepts_payments(array $app): bool
{
    return $app['status'] !== 'draft' && !is_terminal($app);
}

// ---------------------------------------------------------------------
// Identifiers
// ---------------------------------------------------------------------
function generate_reference(): string
{
    for ($i = 0; $i < 10; $i++) {
        $ref = 'VA' . date('y') . '-' . random_code(6);
        if (!db_value('SELECT 1 FROM applications WHERE reference_no = ?', [$ref])) {
            return $ref;
        }
    }
    throw new RuntimeException('Could not generate a unique reference number');
}

/**
 * Clients added by staff (admin/client-new.php) do not need a portal account: when no email is
 * given they get a placeholder address on this domain, which can never receive mail or sign in.
 */
const CLIENT_NO_LOGIN_DOMAIN = 'no-login.invalid';

function is_no_login_email(?string $email): bool
{
    return str_ends_with(strtolower((string) $email), '@' . CLIENT_NO_LOGIN_DOMAIN);
}

/** Passport number as stored and compared everywhere: upper case, no spaces. */
function normalize_passport(string $passport): string
{
    return strtoupper(preg_replace('/\s+/', '', $passport));
}

/** Open (not cancelled / rejected) application with this passport number, or null. */
function open_application_by_passport(string $passport): ?array
{
    $id = (int) db_value("SELECT id FROM applications WHERE passport_number = ? AND status NOT IN ('draft', 'cancelled', 'rejected') ORDER BY created_at DESC LIMIT 1", [$passport]);
    return $id ? find_application($id) : null;
}

/**
 * Create a submitted application for a client entered by staff. Reuses the applicant account
 * when the email already belongs to one; otherwise creates an account the client never has to use.
 * $data: passport_number, full_name, visa_category_id, and optional email, phone, date_of_birth, nationality.
 */
function create_client_application(array $data, int $byUserId): int
{
    return db_transaction(static function () use ($data, $byUserId) {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $userId = $email !== '' ? (int) db_value("SELECT id FROM users WHERE email = ? AND user_type = 'applicant'", [$email]) : 0;
        if (!$userId) {
            $userId = db_insert('users', [
                'user_type' => 'applicant',
                'email' => $email !== '' ? $email : 'client-' . bin2hex(random_bytes(8)) . '@' . CLIENT_NO_LOGIN_DOMAIN,
                // Random password nobody knows: the client uses the shared links, not a login.
                'password_hash' => hash_password(bin2hex(random_bytes(24))),
                'full_name' => $data['full_name'],
                'phone' => nullable((string) ($data['phone'] ?? '')),
                'status' => 'active',
                'created_by' => $byUserId,
                'created_at' => now(),
            ]);
            db_insert('applicant_profiles', ['user_id' => $userId]);
        }
        $appId = db_insert('applications', [
            'reference_no' => generate_reference(),
            'user_id' => $userId,
            'visa_category_id' => $data['visa_category_id'],
            'status' => 'submitted',
            'current_step' => 1,
            'full_name' => $data['full_name'],
            'date_of_birth' => nullable((string) ($data['date_of_birth'] ?? '')),
            'nationality' => nullable((string) ($data['nationality'] ?? '')),
            'passport_number' => $data['passport_number'],
            'submitted_at' => now(),
            'created_at' => now(),
        ]);
        db_insert('application_status_history', [
            'application_id' => $appId,
            'old_status' => null,
            'new_status' => 'submitted',
            'note' => 'Client added by the visa office.',
            'visible_to_applicant' => 1,
            'changed_by' => $byUserId,
            'created_at' => now(),
        ]);
        return $appId;
    });
}

function generate_ticket_no(): string
{
    for ($i = 0; $i < 10; $i++) {
        $no = 'T-' . random_code(8);
        if (!db_value('SELECT 1 FROM support_tickets WHERE ticket_no = ?', [$no])) {
            return $no;
        }
    }
    throw new RuntimeException('Could not generate a unique ticket number');
}

// ---------------------------------------------------------------------
// Access control for applications (prevents ID tampering / IDOR)
// ---------------------------------------------------------------------
function find_application(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    return db_one(
        'SELECT a.*, c.name AS category_name, c.code AS category_code, c.processing_days_min, c.processing_days_max, c.processing_notes,
                u.email AS applicant_email, u.full_name AS applicant_name, u.phone AS applicant_phone
         FROM applications a
         JOIN visa_categories c ON c.id = a.visa_category_id
         JOIN users u ON u.id = a.user_id
         WHERE a.id = ?',
        [$id]
    );
}

/** Load an application owned by the applicant, or 404 (logged as a security event). */
function applicant_application(int $id, array $user): array
{
    $app = find_application($id);
    if (!$app || (int) $app['user_id'] !== (int) $user['id']) {
        security_event('idor.blocked', 'application', $id ?: null, ['role' => 'applicant']);
        abort(404);
    }
    return $app;
}

function staff_can_access_application(array $user, int $appId): bool
{
    if (!is_staff($user)) {
        return false;
    }
    if (is_super_admin($user) || can('applications.view_all', $user)) {
        return true;
    }
    return (bool) db_value('SELECT 1 FROM application_assignments WHERE application_id = ? AND staff_user_id = ?', [$appId, $user['id']]);
}

/** Load an application the staff member is allowed to see, or 404. */
function staff_application(int $id, array $user): array
{
    $app = find_application($id);
    if (!$app || !staff_can_access_application($user, $id)) {
        security_event('idor.blocked', 'application', $id ?: null, ['role' => 'staff']);
        abort(404);
    }
    return $app;
}

/** SQL fragment restricting a list query (alias a = applications) to applications the staff member may see. */
function staff_application_scope(array $user, string $alias = 'a'): array
{
    if (is_super_admin($user) || can('applications.view_all', $user)) {
        return ['1=1', []];
    }
    return ["EXISTS (SELECT 1 FROM application_assignments aa WHERE aa.application_id = {$alias}.id AND aa.staff_user_id = ?)", [(int) $user['id']]];
}

/**
 * Tickets a support agent may see (alias t = support_tickets): general tickets, tickets
 * assigned to them, and tickets about applications they can access.
 */
function staff_ticket_scope(array $user): array
{
    if (is_super_admin($user) || can('applications.view_all', $user)) {
        return ['1=1', []];
    }
    return ['(t.application_id IS NULL OR t.assigned_to = ? OR EXISTS (SELECT 1 FROM application_assignments aa WHERE aa.application_id = t.application_id AND aa.staff_user_id = ?))', [(int) $user['id'], (int) $user['id']]];
}

function staff_can_access_ticket(array $user, array $ticket): bool
{
    if (!can('support.manage', $user)) {
        return false;
    }
    if (is_super_admin($user) || can('applications.view_all', $user) || $ticket['application_id'] === null || (int) $ticket['assigned_to'] === (int) $user['id']) {
        return true;
    }
    return staff_can_access_application($user, (int) $ticket['application_id']);
}

// ---------------------------------------------------------------------
// Status changes, history, notifications
// ---------------------------------------------------------------------
function change_application_status(array $app, string $newStatus, ?string $note, ?int $byUserId, bool $visibleToApplicant = true): void
{
    if (!isset(APP_STATUSES[$newStatus])) {
        throw new InvalidArgumentException('Unknown status');
    }
    $old = $app['status'];
    $fields = ['status' => $newStatus];
    if (in_array($newStatus, ['approved', 'rejected'], true)) {
        $fields['decided_at'] = now();
    }
    if ($newStatus === 'submitted' && empty($app['submitted_at'])) {
        $fields['submitted_at'] = now();
    }
    db_update('applications', $fields, 'id = ?', [$app['id']]);
    db_insert('application_status_history', [
        'application_id' => $app['id'],
        'old_status' => $old,
        'new_status' => $newStatus,
        'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 1000) : null,
        'visible_to_applicant' => $visibleToApplicant ? 1 : 0,
        'changed_by' => $byUserId,
        'created_at' => now(),
    ]);
    audit_log('application.status_changed', 'application', (int) $app['id'], ['from' => $old, 'to' => $newStatus]);
    if ($visibleToApplicant && $old !== $newStatus && $newStatus !== 'draft') {
        notify_user(
            (int) $app['user_id'],
            'Application ' . $app['reference_no'] . ': ' . status_label($newStatus),
            'The status of your application changed to "' . status_label($newStatus) . '".' . ($note ? ' Note: ' . $note : ''),
            'applicant/application.php?id=' . $app['id'],
            true
        );
    }
}

function add_history_note(int $appId, string $status, string $note, ?int $by, bool $visible = true): void
{
    db_insert('application_status_history', [
        'application_id' => $appId,
        'old_status' => $status,
        'new_status' => $status,
        'note' => mb_substr($note, 0, 1000),
        'visible_to_applicant' => $visible ? 1 : 0,
        'changed_by' => $by,
        'created_at' => now(),
    ]);
}

/** Create an in-app notification and optionally email it. */
function notify_user(int $userId, string $title, string $body = '', ?string $link = null, bool $email = false): void
{
    db_insert('notifications', [
        'user_id' => $userId,
        'title' => mb_substr($title, 0, 200),
        'body' => mb_substr($body, 0, 1000),
        'link' => $link,
        'is_read' => 0,
        'created_at' => now(),
    ]);
    if ($email && config('mail.notify_by_email', true)) {
        $u = db_one("SELECT email, full_name, status FROM users WHERE id = ?", [$userId]);
        if ($u && $u['status'] === 'active') {
            $text = "Hello {$u['full_name']},\n\n{$body}";
            if ($link) {
                $text .= "\n\nView details: " . absolute_url($link);
            }
            send_mail($u['email'], $title, $text);
        }
    }
}

/** Notify the staff assigned to an application; if none, notify Super Admins. */
function notify_application_staff(array $app, string $title, string $body, ?string $permission = null): void
{
    $staff = db_all(
        "SELECT u.* FROM application_assignments aa JOIN users u ON u.id = aa.staff_user_id WHERE aa.application_id = ? AND u.status = 'active'",
        [$app['id']]
    );
    if (!$staff) {
        $staff = db_all("SELECT * FROM users WHERE user_type = 'staff' AND is_super_admin = 1 AND status = 'active'");
    }
    foreach ($staff as $s) {
        if ($permission === null || can($permission, $s)) {
            notify_user((int) $s['id'], $title, $body, 'admin/application.php?id=' . $app['id']);
        }
    }
}

function unread_notification_count(int $userId): int
{
    return (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$userId]);
}

// ---------------------------------------------------------------------
// Document checklist
// ---------------------------------------------------------------------
function requirements_for_category(int $categoryId): array
{
    return db_all(
        'SELECT * FROM document_requirements
         WHERE is_active = 1 AND (visa_category_id IS NULL OR visa_category_id = ?)
         ORDER BY sort_order, name',
        [$categoryId]
    );
}

/** Latest non-superseded document for each requirement + any extra documents. */
function document_checklist(array $app): array
{
    $reqs = requirements_for_category((int) $app['visa_category_id']);
    $docs = db_all("SELECT * FROM documents WHERE application_id = ? AND status <> 'superseded' ORDER BY created_at DESC", [$app['id']]);
    $byType = [];
    foreach ($docs as $d) {
        $byType[$d['doc_type']] ??= $d;
    }
    $list = [];
    foreach ($reqs as $r) {
        $list[] = ['requirement' => $r, 'document' => $byType[$r['doc_type']] ?? null];
        unset($byType[$r['doc_type']]);
    }
    foreach ($byType as $type => $d) {
        $list[] = ['requirement' => ['id' => null, 'doc_type' => $type, 'name' => LINK_DOC_TYPES[$type] ?? 'Additional document (' . $type . ')', 'description' => null, 'is_mandatory' => 0], 'document' => $d];
    }
    return $list;
}

function missing_mandatory_documents(array $app): array
{
    $missing = [];
    foreach (document_checklist($app) as $item) {
        $doc = $item['document'];
        if ((int) $item['requirement']['is_mandatory'] === 1 && (!$doc || $doc['status'] === 'rejected')) {
            $missing[] = $item['requirement']['name'];
        }
    }
    return $missing;
}

/**
 * Documents on the client file (shared links / staff uploads): doc_type => name if there is no requirement.
 * Passport and medical can come from the client or staff; the visa only from staff (the client downloads it).
 */
const LINK_DOC_TYPES = [
    'passport' => 'Passport bio-data page',
    'medical' => 'Medical examination report',
    'visa' => 'Visa',
];

/** Current (non-superseded) document of one type, or null. */
function current_document(int $appId, string $docType): ?array
{
    return db_one("SELECT * FROM documents WHERE application_id = ? AND doc_type = ? AND status <> 'superseded' ORDER BY id DESC LIMIT 1", [$appId, $docType]);
}

/**
 * Store a checklist document from a shared link (applicant) or from staff, replacing the current one.
 * Staff uploads ($byStaff) are recorded as already accepted. Returns [ok, message].
 */
function document_store(array $app, string $docType, array $file, int $byUserId, bool $byStaff = false): array
{
    $appId = (int) $app['id'];
    $req = null;
    foreach (requirements_for_category((int) $app['visa_category_id']) as $r) {
        if ($r['doc_type'] === $docType) {
            $req = $r;
        }
    }
    if (!$req && !isset(LINK_DOC_TYPES[$docType])) {
        return [false, 'Unknown document type.'];
    }
    $req ??= ['id' => null, 'name' => LINK_DOC_TYPES[$docType]];
    // Staff may still add files (e.g. the visa) after approval; only a cancelled application is closed.
    if ($byStaff ? $app['status'] === 'cancelled' : !app_accepts_documents($app)) {
        return [false, 'Documents can no longer be changed for this application.'];
    }
    $current = current_document($appId, $docType);
    if (!$byStaff && $current && $current['status'] === 'accepted') {
        return [false, $req['name'] . ' has already been accepted. Contact the visa office if it needs to be changed.'];
    }
    $res = store_upload($file, 'documents');
    if (!$res['ok']) {
        return [false, $req['name'] . ': ' . $res['error']];
    }
    // If the database write fails, remove the stored file so no orphan remains.
    register_shutdown_function(static function () use ($res, &$docId) {
        if (empty($docId)) {
            delete_stored_file('documents', $res['stored_name']);
        }
    });
    $docId = db_transaction(static function () use ($appId, $docType, $req, $res, $byUserId, $byStaff, $current) {
        db_run("UPDATE documents SET status = 'superseded' WHERE application_id = ? AND doc_type = ? AND status <> 'superseded'", [$appId, $docType]);
        return db_insert('documents', [
            'application_id' => $appId,
            'requirement_id' => $req['id'],
            'doc_type' => $docType,
            'original_name' => $res['original_name'],
            'stored_name' => $res['stored_name'],
            'mime_type' => $res['mime_type'],
            'size_bytes' => $res['size_bytes'],
            'sha256' => $res['sha256'],
            'status' => $byStaff ? 'accepted' : 'pending',
            'reviewed_by' => $byStaff ? $byUserId : null,
            'reviewed_at' => $byStaff ? now() : null,
            'supersedes_id' => $current['id'] ?? null,
            'uploaded_by' => $byUserId,
            'created_at' => now(),
        ]);
    });
    audit_log($byStaff ? 'document.uploaded_by_staff' : ($current ? 'document.resubmitted' : 'document.uploaded'), 'document', $docId, ['application_id' => $appId, 'doc_type' => $docType], 'audit', $byUserId);
    if (!$byStaff) {
        notify_application_staff($app, $req['name'] . ' ' . ($current ? 'resubmitted' : 'uploaded') . ': ' . $app['reference_no'], $req['name'] . ' was ' . ($current ? 'resubmitted' : 'uploaded') . ' and needs review.', 'documents.review');
    }
    return [true, $req['name'] . ' uploaded.'];
}

/** [label, colour, detail] of a checklist document for the status overviews. */
function document_state(?array $doc): array
{
    if (!$doc) {
        return ['Not uploaded', 'secondary', 'Not uploaded yet.'];
    }
    [$label, $color] = DOC_STATUS[$doc['status']] ?? DOC_STATUS['pending'];
    return [$label, $color, $doc['original_name'] . ($doc['status'] === 'rejected' && $doc['review_note'] ? ' – ' . $doc['review_note'] : '')];
}

// ---------------------------------------------------------------------
// Fingerprint & face capture (Biometrics tab)
// ---------------------------------------------------------------------
/** Current (non-superseded) photo per slot: slot => row. */
function biometric_captures(int $appId): array
{
    $rows = db_all("SELECT * FROM biometric_captures WHERE application_id = ? AND status <> 'superseded' ORDER BY id DESC", [$appId]);
    $bySlot = [];
    foreach ($rows as $r) {
        $bySlot[$r['slot']] ??= $r;
    }
    return $bySlot;
}

/**
 * Overall state of the fingerprint set: [code, label, colour, photos present].
 * code: none | in_progress | ready | submitted | retake | accepted
 */
function biometric_capture_state(array $captures): array
{
    $n = count(array_intersect_key($captures, BIO_SLOTS));
    $total = count(BIO_SLOTS);
    $statuses = array_column($captures, 'status');
    if ($n === 0) {
        return ['none', 'Fingerprints not added', 'secondary', 0];
    }
    if (in_array('rejected', $statuses, true)) {
        return ['retake', 'Retake needed', 'danger', $n];
    }
    if ($n === $total && !array_diff($statuses, ['accepted'])) {
        return ['accepted', 'Fingerprints accepted', 'success', $n];
    }
    if ($n === $total && !in_array('draft', $statuses, true)) {
        return ['submitted', 'Fingerprints submitted – under review', 'warning', $n];
    }
    return [$n === $total ? 'ready' : 'in_progress', $n === $total ? 'Ready to submit' : "Fingerprints in progress ($n of $total)", 'info', $n];
}

/** The applicant may add or retake photos while the case is open and not yet fully accepted (or marked done by staff). */
function app_accepts_biometrics(array $app, array $state): bool
{
    return $app['status'] !== 'draft' && !is_terminal($app) && $app['status'] !== 'approved' && empty($app['biometrics_done_at']) && !in_array($state[0], ['submitted', 'accepted'], true);
}

/** Add or retake one photo. Returns [ok, message]. $byUserId is the applicant (also when sent via the fingerprint link). */
function biometric_store_photo(array $app, string $slot, array $file, int $byUserId): array
{
    $appId = (int) $app['id'];
    $caps = biometric_captures($appId);
    $current = $caps[$slot] ?? null;
    if (!isset(BIO_SLOTS[$slot])) {
        return [false, 'Unknown fingerprint.'];
    }
    if (!app_accepts_biometrics($app, biometric_capture_state($caps)) || ($current && !in_array($current['status'], ['draft', 'rejected'], true))) {
        return [false, 'This photo can no longer be changed.'];
    }
    $res = store_upload($file, 'biometrics', ['jpg', 'jpeg', 'png']);
    if (!$res['ok']) {
        return [false, BIO_SLOTS[$slot][0] . ': ' . $res['error']];
    }
    if ($current && $current['status'] === 'draft') {
        // Never reviewed: delete it entirely rather than keep extra fingerprint images.
        db_run('DELETE FROM biometric_captures WHERE id = ?', [$current['id']]);
        delete_stored_file('biometrics', $current['stored_name']);
    } elseif ($current) {
        db_update('biometric_captures', ['status' => 'superseded'], 'id = ?', [$current['id']]);
    }
    $cid = db_insert('biometric_captures', [
        'application_id' => $appId,
        'slot' => $slot,
        'original_name' => $res['original_name'],
        'stored_name' => $res['stored_name'],
        'mime_type' => $res['mime_type'],
        'size_bytes' => $res['size_bytes'],
        'sha256' => $res['sha256'],
        'status' => 'draft',
        'uploaded_by' => $byUserId,
        'created_at' => now(),
    ]);
    audit_log('biometric.capture_added', 'biometric_capture', $cid, ['application_id' => $appId, 'slot' => $slot, 'retake' => (bool) $current], 'audit', $byUserId);
    return [true, BIO_SLOTS[$slot][0] . ' added.'];
}

/** Submit the complete set for review. Returns [ok, message]. */
function biometric_submit_set(array $app): array
{
    $appId = (int) $app['id'];
    $state = biometric_capture_state(biometric_captures($appId));
    if ($state[0] !== 'ready' || !app_accepts_biometrics($app, $state)) {
        return [false, $state[0] === 'retake' ? 'Retake the photos marked "Retake needed" first.' : 'Add all ' . count(BIO_SLOTS) . ' photos before submitting.'];
    }
    db_run("UPDATE biometric_captures SET status = 'submitted', submitted_at = ? WHERE application_id = ? AND status = 'draft'", [now(), $appId]);
    audit_log('biometric.captures_submitted', 'application', $appId, [], 'audit', (int) $app['user_id']);
    notify_application_staff($app, 'Fingerprints submitted: ' . $app['reference_no'], $app['applicant_name'] . ' submitted fingerprints and a face photo for review.', 'biometrics.manage');
    notify_user((int) $app['user_id'], 'Fingerprints received: ' . $app['reference_no'], 'Thank you – we received your fingerprints and photo and will check them shortly.', 'applicant/application.php?id=' . $appId . '&tab=biometrics');
    return [true, 'Your fingerprints and photo were submitted for review.'];
}

/** Progress milestones for the applicant tracker. */
function application_milestones(array $app): array
{
    $id = (int) $app['id'];
    $checklist = document_checklist($app);
    $mandatory = array_filter($checklist, static fn ($i) => (int) $i['requirement']['is_mandatory'] === 1);
    $accepted = array_filter($mandatory, static fn ($i) => $i['document'] && $i['document']['status'] === 'accepted');
    $docsDone = $mandatory && count($accepted) === count($mandatory);
    $offer = (bool) db_value('SELECT 1 FROM offer_letters WHERE application_id = ? AND is_published = 1', [$id]);
    $paid = (bool) db_value("SELECT 1 FROM payments WHERE application_id = ? AND status = 'confirmed'", [$id]);
    $bio = db_value('SELECT status FROM biometric_appointments WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$id]);
    $travel = (bool) db_value('SELECT 1 FROM travel_arrangements WHERE application_id = ? AND is_published = 1', [$id]);
    $decided = in_array($app['status'], ['approved', 'rejected'], true);
    $lmia = ($app['lmia_status'] ?? 'not_started') === 'not_required' ? [] : [['LMIA approved', ($app['lmia_status'] ?? '') === 'approved']];
    return [
        ['Application submitted', $app['status'] !== 'draft'],
        ['Documents verified', $docsDone],
        ...$lmia,
        ['Offer letter issued', $offer],
        ['Payment confirmed', $paid],
        ['Biometrics approved', !empty($app['biometrics_done_at']) || $bio === 'approved' || biometric_capture_state(biometric_captures($id))[0] === 'accepted'],
        [$decided ? 'Decision: ' . status_label($app['status']) : 'Final decision', $decided],
        ['Travel & accommodation', $travel],
    ];
}

// ---------------------------------------------------------------------
// Live status tracker (payment, biometrics, offer letter, LMIA)
// ---------------------------------------------------------------------
/**
 * The four stages shown on the public tracker and the dashboards:
 * key => [title, icon, label, bootstrap colour, detail line, last change].
 * Only applicant-safe facts are returned (no unpublished offers, no internal notes).
 */
function tracking_summary(array $app): array
{
    $id = (int) $app['id'];

    $pays = db_all('SELECT status, amount, currency, paid_on, reviewed_at, created_at FROM payments WHERE application_id = ? ORDER BY id DESC', [$id]);
    $confirmed = array_values(array_filter($pays, static fn ($p) => $p['status'] === 'confirmed'));
    $pay = $confirmed[0] ?? $pays[0] ?? null;
    if ($pay) {
        [$pl, $pc] = PAY_STATUS[$pay['status']];
        $payment = [$pl, $pc, $pay['currency'] . ' ' . number_format((float) $pay['amount'], 2) . ' paid on ' . fmt_date($pay['paid_on']), $pay['reviewed_at'] ?? $pay['created_at']];
    } else {
        $payment = ['Not paid yet', 'secondary', 'No payment receipt received yet.', null];
    }

    $bio = db_one('SELECT status, center_name, appointment_at, decision_note, decided_at, created_at FROM biometric_appointments WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$id]);
    $capState = biometric_capture_state($caps = biometric_captures($id));
    $capChanged = $caps ? max(array_map(static fn ($c) => $c['reviewed_at'] ?? $c['submitted_at'] ?? $c['created_at'], $caps)) : null;
    // A final appointment decision wins; otherwise the fingerprint set (if started) is the most useful news.
    $useCaptures = $capState[0] !== 'none' && !($bio && in_array($bio['status'], ['approved', 'rejected'], true));
    if (!empty($app['biometrics_done_at'])) {
        $biometrics = ['Completed', 'success', 'Your biometrics are complete.', $app['biometrics_done_at']];
    } elseif ($useCaptures) {
        $capDetail = match ($capState[0]) {
            'accepted' => 'All 10 fingerprints and your photo were accepted.',
            'submitted' => 'Our team is checking your fingerprints.',
            'retake' => 'Some photos need to be taken again.',
            default => $capState[3] . ' of ' . count(BIO_SLOTS) . ' photos added – finish and submit them.',
        };
        $biometrics = [$capState[1], $capState[2], $capDetail, $capChanged];
    } elseif ($bio) {
        [$bl, $bc] = BIO_STATUS[$bio['status']];
        $detail = $bio['status'] === 'scheduled'
            ? 'Appointment ' . fmt_date($bio['appointment_at'], true) . ' at ' . $bio['center_name']
            : ($bio['decision_note'] ?: $bio['center_name'] . ', ' . fmt_date($bio['appointment_at']));
        $biometrics = [$bl, $bc, $detail, $bio['decided_at'] ?? $bio['created_at']];
    } else {
        $biometrics = ['Not submitted', 'secondary', 'No fingerprints received yet.', null];
    }

    $offer = db_one('SELECT title, published_at FROM offer_letters WHERE application_id = ? AND is_published = 1 ORDER BY published_at DESC LIMIT 1', [$id]);
    $offerRow = $offer
        ? ['Issued', 'success', $offer['title'] . ' is ready to download.', $offer['published_at']]
        : ['Not issued yet', 'secondary', 'The offer letter will appear here once it is issued.', null];

    $lmiaCode = $app['lmia_status'] ?? 'not_started';
    [$ll, $lc] = LMIA_STATUS[$lmiaCode] ?? LMIA_STATUS['not_started'];
    $lmiaDetail = trim(($app['lmia_number'] ? 'LMIA no. ' . $app['lmia_number'] . '. ' : '') . ($app['lmia_note'] ?? ''));
    if (!empty($app['lmia_doc_stored_name'])) {
        $lmiaDetail = trim($lmiaDetail . ' LMIA letter is ready to download.');
    }

    return [
        'payment'    => ['Payment', 'credit-card', $payment[0], $payment[1], $payment[2], $payment[3]],
        'biometrics' => ['Biometrics', 'fingerprint', $biometrics[0], $biometrics[1], $biometrics[2], $biometrics[3]],
        'offer'      => ['Offer letter', 'file-earmark-text', $offerRow[0], $offerRow[1], $offerRow[2], $offerRow[3]],
        'lmia'       => ['LMIA', 'briefcase', $ll, $lc, $lmiaDetail !== '' ? $lmiaDetail : 'Labour Market Impact Assessment.', $app['lmia_updated_at'] ?? null],
    ];
}

/**
 * Everything the shared links collect, per member (staff views):
 * key => [title, icon, label, bootstrap colour, detail line]. Keys match SHARED_LINKS.
 */
function member_file_status(array $app): array
{
    $t = tracking_summary($app);
    $doc = static function (string $type, string $title) use ($app): array {
        [$label, $color, $detail] = document_state(current_document((int) $app['id'], $type));
        return [$title, SHARED_LINKS[$type][1], $label, $color, $detail];
    };
    return [
        'passport'   => $doc('passport', 'Passport'),
        'biometrics' => array_slice($t['biometrics'], 0, 5),
        'offer'      => array_slice($t['offer'], 0, 5),
        'lmia'       => array_slice($t['lmia'], 0, 5),
        'payment'    => array_slice($t['payment'], 0, 5),
        'medical'    => $doc('medical', 'Medical'),
        'visa'       => array_slice(visa_state($app), 0, 5),
    ];
}

/** [title, icon, label, colour, detail, current document] of the visa, which only staff upload. */
function visa_state(array $app): array
{
    $doc = current_document((int) $app['id'], 'visa');
    return $doc
        ? ['Visa', 'passport', 'Issued', 'success', 'Your visa is ready to download.', $doc]
        : ['Visa', 'passport', 'Not issued yet', 'secondary', 'Your visa will appear here once the visa office adds it.', null];
}

/**
 * A document the client only receives (the visa, or an admin-uploaded medical report): its status and
 * a download button. $downloadUrl(int $documentId) gives the download address. Re-fetched by app.js.
 */
function received_document_panel_html(string $label, string $color, string $detail, ?array $doc, callable $downloadUrl): string
{
    $html = '<div class="track-tile tone-' . $color . ' mb-3">'
        . '<div class="track-status fs-4 text-' . $color . '-emphasis">' . e($label) . '</div>'
        . '<div class="small text-muted mt-1">' . e($detail) . '</div>'
        . ($doc ? '<div class="track-time">Updated ' . e(fmt_date($doc['created_at'], true)) . '</div>' : '')
        . '</div>';
    if (!$doc) {
        return $html . empty_state('Not issued yet – please check again later.', 'hourglass-split');
    }
    return $html . '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border rounded-3 p-3">'
        . '<div><div class="fw-semibold">' . e($doc['original_name']) . '</div><div class="small text-muted">' . e(fmt_bytes((int) $doc['size_bytes'])) . '</div></div>'
        . '<a class="btn btn-brand" href="' . e($downloadUrl((int) $doc['id'])) . '"><i class="bi bi-download me-1"></i>Download</a></div>';
}

/** Small coloured badges for the four stages (dashboards / lists). */
function tracking_badges(array $summary): string
{
    $html = '<div class="track-badges">';
    foreach ($summary as [$title, $icon, $label, $color]) {
        $html .= '<span class="badge soft bg-' . $color . '-subtle text-' . $color . '-emphasis border-' . $color . '-subtle" title="' . e($title . ': ' . $label) . '">'
            . '<i class="bi bi-' . e($icon) . ' me-1" aria-hidden="true"></i>' . e($title) . ': ' . e($label) . '</span>';
    }
    return $html . '</div>';
}

/** The live status panel: overall status + one tile per stage. Re-fetched by app.js every few seconds. */
function tracking_panel_html(array $app): string
{
    $html = '<div class="track-head"><div><div class="small text-muted">Reference</div><div class="fw-bold fs-5">' . e($app['reference_no']) . '</div></div>'
        . '<div><div class="small text-muted">Applicant</div><div class="fw-semibold">' . e(mask_name((string) $app['full_name'] ?: (string) $app['applicant_name'])) . '</div></div>'
        . '<div><div class="small text-muted">Visa type</div><div class="fw-semibold">' . e($app['category_name']) . '</div></div>'
        . '<div><div class="small text-muted">Application status</div><div class="fs-6">' . status_badge($app['status']) . '</div></div></div>';
    $html .= '<div class="track-grid">';
    foreach (tracking_summary($app) as $key => [$title, $icon, $label, $color, $detail, $changed]) {
        $html .= '<div class="track-tile tone-' . e($color) . '" data-stage="' . e($key) . '">'
            . '<div class="d-flex align-items-center gap-2 mb-2"><span class="track-icon"><i class="bi bi-' . e($icon) . '" aria-hidden="true"></i></span><h3 class="h6 mb-0">' . e($title) . '</h3></div>'
            . '<div class="track-status text-' . e($color) . '-emphasis">' . e($label) . '</div>'
            . '<div class="small text-muted mt-1">' . e($detail) . '</div>'
            . ($changed ? '<div class="track-time">Updated ' . e(fmt_date($changed, true)) . '</div>' : '')
            . '</div>';
    }
    return $html . '</div>';
}

/**
 * Offer letter only (offer letter links): its status and the published letters with a download
 * button each. $downloadUrl(int $offerId) gives the download address. Re-fetched by app.js.
 */
function offer_panel_html(array $app, callable $downloadUrl): string
{
    $offers = db_all('SELECT id, title, notes, size_bytes, published_at FROM offer_letters WHERE application_id = ? AND is_published = 1 ORDER BY published_at DESC', [$app['id']]);
    [$label, $color, $detail] = $offers
        ? ['Issued', 'success', 'Your offer letter is ready to download.']
        : ['Not issued yet', 'secondary', 'Your offer letter will appear here as soon as the visa office issues it.'];
    $html = '<div class="track-tile tone-' . $color . ' mb-3" data-stage="offer">'
        . '<div class="small text-muted">Offer letter</div>'
        . '<div class="track-status fs-4 text-' . $color . '-emphasis">' . e($label) . '</div>'
        . '<div class="small text-muted mt-1">' . e($detail) . '</div>'
        . ($offers ? '<div class="track-time">Updated ' . e(fmt_date($offers[0]['published_at'], true)) . '</div>' : '')
        . '</div>';
    if (!$offers) {
        return $html . empty_state('Not issued yet – please check again later.', 'hourglass-split');
    }
    foreach ($offers as $o) {
        $html .= '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border rounded-3 p-3 mb-2"><div>'
            . '<div class="fw-semibold">' . e($o['title']) . '</div>'
            . '<div class="small text-muted">Issued ' . e(fmt_date($o['published_at'])) . ' · ' . e(fmt_bytes((int) $o['size_bytes'])) . '</div>'
            . ($o['notes'] ? '<div class="small mt-1">' . e($o['notes']) . '</div>' : '')
            . '</div><a class="btn btn-brand" href="' . e($downloadUrl((int) $o['id'])) . '"><i class="bi bi-download me-1"></i>Download</a></div>';
    }
    return $html;
}

/**
 * Special links staff send to one applicant. Each opens one page and asks for the passport number first.
 * purpose => [token column, page, title, icon, what it shows, permission needed to manage it]
 */
const ACCESS_LINKS = [
    'status'     => ['tracking_token', 'track.php', 'Status & offer letter link', 'broadcast', 'live status of payment, biometrics, offer letter and LMIA, and the offer letter download', 'applications.review'],
    'biometrics' => ['biometric_token', 'biometrics.php', 'Fingerprint link', 'fingerprint', 'a page to scan their 10 fingerprints and face photo and submit them', 'biometrics.manage'],
    'lmia'       => ['lmia_token', 'lmia.php', 'LMIA link', 'briefcase', 'their LMIA status and the LMIA letter', 'applications.review'],
    'offer'      => ['offer_token', 'offer.php', 'Offer letter link', 'file-earmark-text', 'only their offer letter (status and download)', 'applications.review'],
];

/** [subject, body] for sending a special link to the applicant (email and WhatsApp). */
function access_link_message(string $purpose, string $reference, string $link): array
{
    return match ($purpose) {
        'biometrics' => ['Scan your fingerprints: ' . $reference, 'Please open this private link on your phone, enter your passport number, take photos of your 10 fingerprints and your face, then press Submit: ' . $link],
        'offer' => ['Your offer letter: ' . $reference, 'Open this private link and enter your passport number to download your offer letter: ' . $link],
        'lmia' => ['Your LMIA link: ' . $reference, 'Open this private link and enter your passport number to see your LMIA status and download your LMIA letter: ' . $link],
        default => ['Your status & offer letter link: ' . $reference, 'Open this private link and enter your passport number to see the live status of your application and download your offer letter: ' . $link],
    };
}

function access_token(array &$app, string $purpose, bool $regenerate = false): string
{
    $col = ACCESS_LINKS[$purpose][0];
    if (!$regenerate && !empty($app[$col])) {
        return $app[$col];
    }
    $token = bin2hex(random_bytes(16));
    db_update('applications', [$col => $token], 'id = ?', [$app['id']]);
    return $app[$col] = $token;
}

function access_url(array &$app, string $purpose): string
{
    return absolute_url(ACCESS_LINKS[$purpose][1], ['t' => access_token($app, $purpose)]);
}

function tracking_token(array &$app, bool $regenerate = false): string
{
    return access_token($app, 'status', $regenerate);
}

function tracking_url(array &$app): string
{
    return access_url($app, 'status');
}

/** "Muhammad Khan" -> "M****** K***" for the public tracker. */
function mask_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    return implode(' ', array_map(static fn ($p) => mb_substr($p, 0, 1) . str_repeat('*', max(1, mb_strlen($p) - 1)), $parts));
}

function processing_estimate(array $app): ?string
{
    if (empty($app['submitted_at']) || empty($app['processing_days_max'])) {
        return null;
    }
    $min = (int) ($app['processing_days_min'] ?: $app['processing_days_max']);
    $from = strtotime($app['submitted_at']);
    return fmt_date(date('Y-m-d', $from + $min * 86400)) . ' – ' . fmt_date(date('Y-m-d', $from + (int) $app['processing_days_max'] * 86400));
}

// ---------------------------------------------------------------------
// Payment regions
// ---------------------------------------------------------------------
function active_regions(): array
{
    return db_all('SELECT * FROM regions WHERE is_active = 1 ORDER BY sort_order, name');
}

/** [id => name] of active regions, for select boxes. */
function region_options(): array
{
    $opts = [];
    foreach (active_regions() as $r) {
        $opts[$r['id']] = $r['name'];
    }
    return $opts;
}

function region_name(?int $id): ?string
{
    if (!$id) {
        return null;
    }
    $name = db_value('SELECT name FROM regions WHERE id = ?', [$id]);
    return $name === null ? null : (string) $name;
}

/**
 * Published payment instructions an applicant may see: accounts for their region
 * plus accounts marked "All regions" (region_id NULL). Inactive regions are hidden.
 */
function payment_instructions_for(?int $regionId): array
{
    return db_all(
        'SELECT p.*, r.name AS region_name FROM payment_instructions p
         LEFT JOIN regions r ON r.id = p.region_id
         WHERE p.is_published = 1 AND (p.region_id IS NULL OR (p.region_id = ? AND r.is_active = 1))
         ORDER BY p.region_id IS NULL, p.id',
        [$regionId ?: 0]
    );
}

function active_categories(): array
{
    return db_all('SELECT * FROM visa_categories WHERE is_active = 1 ORDER BY sort_order, name');
}

function staff_members(bool $activeOnly = true): array
{
    return db_all("SELECT u.id, u.full_name, u.email, u.is_super_admin, u.status, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.user_type = 'staff'" . ($activeOnly ? " AND u.status = 'active'" : '') . ' ORDER BY u.full_name');
}
