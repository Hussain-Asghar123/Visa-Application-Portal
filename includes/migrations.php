<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Automatic database upgrades.
 * New installs get the latest schema from database/install.sql. Older databases are upgraded
 * here automatically on the first request after new files are uploaded – nobody needs to run
 * SQL by hand. Every step checks first, so it is safe to run more than once (MySQL & MariaDB).
 */
const SCHEMA_VERSION = 8;

function db_table_exists(string $table): bool
{
    return (bool) db_value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
}

function db_column_exists(string $table, string $column): bool
{
    return (bool) db_value('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $column]);
}

/** Add a column (plus optional index / foreign key) only if it is missing. */
function db_add_column(string $table, string $column, string $definition, ?string $after = null, ?string $extra = null): void
{
    if (!db_column_exists($table, $column)) {
        db()->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition . ($after ? ' AFTER `' . $after . '`' : '') . ($extra ? ', ' . $extra : ''));
    }
}

function run_migrations(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $version = (int) (db_value("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'") ?? 1);
    } catch (Throwable) {
        return; // not installed yet (installer / setup will create the tables)
    }
    if ($version >= SCHEMA_VERSION) {
        return;
    }
    // Only one request performs the upgrade; others continue normally.
    if ((int) db_value("SELECT GET_LOCK('vap_schema_upgrade', 10)") !== 1) {
        return;
    }
    try {
        $version = (int) (db_value("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'") ?? 1);
        if ($version < 2) {
            migrate_to_v2();
        }
        if ($version < 3) {
            migrate_to_v3();
        }
        if ($version < 4) {
            migrate_to_v4();
        }
        if ($version < 5) {
            migrate_to_v5();
        }
        if ($version < 6) {
            migrate_to_v6();
        }
        if ($version < 7) {
            migrate_to_v7();
        }
        if ($version < 8) {
            migrate_to_v8();
        }
        db_run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            ['schema_version', (string) SCHEMA_VERSION]
        );
        app_log('info', 'Database upgraded automatically', ['from' => $version, 'to' => SCHEMA_VERSION]);
        audit_log('system.database_upgraded', null, null, ['from' => $version, 'to' => SCHEMA_VERSION], 'audit', null);
    } catch (Throwable $e) {
        app_log('error', 'Automatic database upgrade failed: ' . $e->getMessage());
    } finally {
        db_value("SELECT RELEASE_LOCK('vap_schema_upgrade')");
    }
}

/** v2: payment regions. */
function migrate_to_v2(): void
{
    if (!db_table_exists('regions')) {
        db()->exec("CREATE TABLE regions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            code VARCHAR(20) NOT NULL,
            description VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order SMALLINT NOT NULL DEFAULT 0,
            updated_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_regions_code (code),
            UNIQUE KEY uq_regions_name (name),
            CONSTRAINT fk_regions_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    db_add_column('payment_instructions', 'region_id', 'INT UNSIGNED NULL', 'title',
        'ADD KEY idx_pi_region (region_id), ADD CONSTRAINT fk_pi_region FOREIGN KEY (region_id) REFERENCES regions (id) ON DELETE RESTRICT');
    db_add_column('applications', 'region_id', 'INT UNSIGNED NULL', 'visa_category_id',
        'ADD KEY idx_app_region (region_id), ADD CONSTRAINT fk_app_region FOREIGN KEY (region_id) REFERENCES regions (id) ON DELETE SET NULL');
    db_add_column('payments', 'region_id', 'INT UNSIGNED NULL', 'fee_type_id',
        'ADD KEY idx_pay_region (region_id), ADD CONSTRAINT fk_pay_region FOREIGN KEY (region_id) REFERENCES regions (id) ON DELETE SET NULL');
}

/** v3: two-step login, recovery codes, IP firewall. */
function migrate_to_v3(): void
{
    db_add_column('users', 'totp_secret', 'VARCHAR(255) NULL', 'session_version');
    db_add_column('users', 'totp_enabled_at', 'DATETIME NULL', 'totp_secret');
    db_add_column('users', 'totp_last_step', 'BIGINT UNSIGNED NULL', 'totp_enabled_at');
    if (!db_table_exists('user_recovery_codes')) {
        db()->exec("CREATE TABLE user_recovery_codes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            code_hash CHAR(64) NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_recovery_hash (code_hash),
            KEY idx_recovery_user (user_id),
            CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if (!db_table_exists('ip_blocks')) {
        db()->exec("CREATE TABLE ip_blocks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_address VARCHAR(45) NOT NULL,
            reason VARCHAR(255) NULL,
            source ENUM('auto','manual') NOT NULL DEFAULT 'auto',
            expires_at DATETIME NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ip_blocks_ip (ip_address),
            KEY idx_ip_blocks_expires (expires_at),
            CONSTRAINT fk_ip_blocks_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    db_run("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('require_2fa_staff', '0')");
}

/** v4: LMIA status and the public status-tracking link. */
function migrate_to_v4(): void
{
    db_add_column('applications', 'lmia_status', "VARCHAR(30) NOT NULL DEFAULT 'not_started'", 'previous_refusal_details');
    db_add_column('applications', 'lmia_number', 'VARCHAR(40) NULL', 'lmia_status');
    db_add_column('applications', 'lmia_note', 'VARCHAR(1000) NULL', 'lmia_number');
    db_add_column('applications', 'lmia_updated_at', 'DATETIME NULL', 'lmia_note');
    db_add_column('applications', 'tracking_token', 'CHAR(32) NULL', 'lmia_updated_at',
        'ADD UNIQUE KEY uq_app_tracking (tracking_token)');
    if (!db_value("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'applications' AND index_name = 'idx_app_passport'")) {
        db()->exec('ALTER TABLE applications ADD KEY idx_app_passport (passport_number)');
    }
}

/** v5: fingerprint and face photos submitted by the applicant. */
function migrate_to_v5(): void
{
    if (!db_table_exists('biometric_captures')) {
        db()->exec("CREATE TABLE biometric_captures (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id INT UNSIGNED NOT NULL,
            slot VARCHAR(20) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name CHAR(64) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            status ENUM('draft','submitted','accepted','rejected','superseded') NOT NULL DEFAULT 'draft',
            review_note VARCHAR(1000) NULL,
            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            uploaded_by INT UNSIGNED NOT NULL,
            submitted_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_biocap_stored (stored_name),
            KEY idx_biocap_app (application_id, slot, status),
            CONSTRAINT fk_biocap_app FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE,
            CONSTRAINT fk_biocap_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_biocap_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

/** v6: passport-protected fingerprint and LMIA links, LMIA letter. */
function migrate_to_v6(): void
{
    db_add_column('applications', 'biometric_token', 'CHAR(32) NULL', 'tracking_token', 'ADD UNIQUE KEY uq_app_bio_link (biometric_token)');
    db_add_column('applications', 'lmia_token', 'CHAR(32) NULL', 'biometric_token', 'ADD UNIQUE KEY uq_app_lmia_link (lmia_token)');
    db_add_column('applications', 'lmia_doc_original_name', 'VARCHAR(255) NULL', 'lmia_token');
    db_add_column('applications', 'lmia_doc_stored_name', 'CHAR(64) NULL', 'lmia_doc_original_name');
    db_add_column('applications', 'lmia_doc_mime', 'VARCHAR(100) NULL', 'lmia_doc_stored_name');
    db_add_column('applications', 'lmia_doc_size', 'INT UNSIGNED NULL', 'lmia_doc_mime');
    db_add_column('applications', 'lmia_doc_uploaded_at', 'DATETIME NULL', 'lmia_doc_size');
}

/** v7: "biometrics done" set by staff, medical report on every checklist (shared links). */
function migrate_to_v7(): void
{
    db_add_column('applications', 'biometrics_done_at', 'DATETIME NULL', 'lmia_doc_uploaded_at');
    db_add_column('applications', 'biometrics_done_by', 'INT UNSIGNED NULL', 'biometrics_done_at');
    db_run("INSERT INTO document_requirements (visa_category_id, doc_type, name, description, is_mandatory, is_active, sort_order)
            SELECT NULL, 'medical', 'Medical examination report', 'Report from your medical examination (PDF, or a clear photo of each page).', 0, 1, 4 FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM document_requirements WHERE visa_category_id IS NULL AND doc_type = 'medical')");
}

/** v8: passport-protected offer letter link (offer.php) that shows only the offer letter. */
function migrate_to_v8(): void
{
    db_add_column('applications', 'offer_token', 'CHAR(32) NULL', 'lmia_token', 'ADD UNIQUE KEY uq_app_offer_link (offer_token)');
}
