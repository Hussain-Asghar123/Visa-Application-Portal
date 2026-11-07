-- =====================================================================
-- Visa Application Portal - database schema
-- Compatible with MySQL 5.7+/8.x and MariaDB 10.4+/11.x (InnoDB, utf8mb4)
-- Import this file first (phpMyAdmin > Import), then import seed.sql.
-- Safe for phpMyAdmin: no DELIMITER, triggers, procedures, JSON columns
-- or functional defaults.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Roles & permissions (staff RBAC)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(80) NOT NULL,
  label VARCHAR(150) NOT NULL,
  description VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_rp_permission (permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Users (applicants and staff share one table)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_type ENUM('applicant','staff') NOT NULL DEFAULT 'applicant',
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  phone VARCHAR(40) NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  email_verified_at DATETIME NULL,
  role_id INT UNSIGNED NULL,
  is_super_admin TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(45) NULL,
  password_changed_at DATETIME NULL,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  totp_secret VARCHAR(255) NULL,
  totp_enabled_at DATETIME NULL,
  totp_last_step BIGINT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_type_status (user_type, status),
  KEY idx_users_role (role_id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE SET NULL,
  CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS applicant_profiles (
  user_id INT UNSIGNED NOT NULL,
  date_of_birth DATE NULL,
  gender ENUM('female','male','other','undisclosed') NULL,
  nationality VARCHAR(80) NULL,
  address_line VARCHAR(255) NULL,
  city VARCHAR(100) NULL,
  country VARCHAR(80) NULL,
  postal_code VARCHAR(20) NULL,
  alt_phone VARCHAR(40) NULL,
  emergency_contact_name VARCHAR(150) NULL,
  emergency_contact_phone VARCHAR(40) NULL,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email verification and password reset tokens (only SHA-256 hashes are stored)
CREATE TABLE IF NOT EXISTS user_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  purpose ENUM('verify_email','reset_password') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  requested_ip VARCHAR(45) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_hash (token_hash),
  KEY idx_tokens_user_purpose (user_id, purpose),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic rate limiting (login failures, password reset requests, etc.)
CREATE TABLE IF NOT EXISTS rate_limit_hits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket VARCHAR(40) NOT NULL,
  identifier CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rl_lookup (bucket, identifier, created_at),
  KEY idx_rl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Configuration managed by the Super Admin
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(80) NOT NULL,
  setting_value TEXT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visa_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  processing_days_min SMALLINT UNSIGNED NULL,
  processing_days_max SMALLINT UNSIGNED NULL,
  processing_notes VARCHAR(500) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_code (code),
  CONSTRAINT fk_categories_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Required-document checklist. visa_category_id NULL = applies to every category.
CREATE TABLE IF NOT EXISTS document_requirements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  visa_category_id INT UNSIGNED NULL,
  doc_type VARCHAR(40) NOT NULL,
  name VARCHAR(150) NOT NULL,
  description VARCHAR(500) NULL,
  is_mandatory TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_requirement (visa_category_id, doc_type),
  KEY idx_req_active (is_active),
  CONSTRAINT fk_req_category FOREIGN KEY (visa_category_id) REFERENCES visa_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  visa_category_id INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fee_code (code),
  KEY idx_fee_category (visa_category_id),
  CONSTRAINT fk_fee_category FOREIGN KEY (visa_category_id) REFERENCES visa_categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_fee_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment regions (e.g. Pakistan, UAE, UK). Each region can have its own bank accounts.
CREATE TABLE IF NOT EXISTS regions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bank / payment instructions. Only rows with is_published = 1 are shown to applicants.
-- region_id NULL = shown to applicants of every region.
CREATE TABLE IF NOT EXISTS payment_instructions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(150) NOT NULL,
  region_id INT UNSIGNED NULL,
  bank_name VARCHAR(150) NULL,
  account_name VARCHAR(150) NULL,
  account_number VARCHAR(80) NULL,
  iban VARCHAR(80) NULL,
  swift_code VARCHAR(30) NULL,
  branch VARCHAR(150) NULL,
  reference_format VARCHAR(255) NULL,
  instructions_text TEXT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pi_published (is_published),
  KEY idx_pi_region (region_id),
  CONSTRAINT fk_pi_region FOREIGN KEY (region_id) REFERENCES regions (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pi_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pi_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Applications
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS applications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference_no VARCHAR(30) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  visa_category_id INT UNSIGNED NOT NULL,
  region_id INT UNSIGNED NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  current_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
  -- personal details (snapshot at time of application)
  full_name VARCHAR(150) NULL,
  date_of_birth DATE NULL,
  place_of_birth VARCHAR(120) NULL,
  gender ENUM('female','male','other','undisclosed') NULL,
  nationality VARCHAR(80) NULL,
  marital_status VARCHAR(30) NULL,
  occupation VARCHAR(120) NULL,
  employer_or_institution VARCHAR(150) NULL,
  -- passport
  passport_number VARCHAR(20) NULL,
  passport_issuing_country VARCHAR(80) NULL,
  passport_issue_date DATE NULL,
  passport_expiry_date DATE NULL,
  -- travel intent
  purpose_of_travel VARCHAR(500) NULL,
  intended_entry_date DATE NULL,
  intended_duration_days SMALLINT UNSIGNED NULL,
  destination_address VARCHAR(255) NULL,
  previous_refusals TINYINT(1) NULL,
  previous_refusal_details VARCHAR(500) NULL,
  -- LMIA (Labour Market Impact Assessment) tracking, managed by staff
  lmia_status VARCHAR(30) NOT NULL DEFAULT 'not_started',
  lmia_number VARCHAR(40) NULL,
  lmia_note VARCHAR(1000) NULL,
  lmia_updated_at DATETIME NULL,
  -- secret for the public status link (track.php?t=...)
  tracking_token CHAR(32) NULL,
  -- secrets for the passport-protected fingerprint and LMIA links (biometrics.php / lmia.php)
  biometric_token CHAR(32) NULL,
  lmia_token CHAR(32) NULL,
  -- secret for the passport-protected offer letter link (offer.php)
  offer_token CHAR(32) NULL,
  -- LMIA letter uploaded by staff, shown on the LMIA link
  lmia_doc_original_name VARCHAR(255) NULL,
  lmia_doc_stored_name CHAR(64) NULL,
  lmia_doc_mime VARCHAR(100) NULL,
  lmia_doc_size INT UNSIGNED NULL,
  lmia_doc_uploaded_at DATETIME NULL,
  -- staff marked the biometrics stage as complete
  biometrics_done_at DATETIME NULL,
  biometrics_done_by INT UNSIGNED NULL,
  declaration_accepted_at DATETIME NULL,
  submitted_at DATETIME NULL,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_app_reference (reference_no),
  UNIQUE KEY uq_app_tracking (tracking_token),
  UNIQUE KEY uq_app_bio_link (biometric_token),
  UNIQUE KEY uq_app_lmia_link (lmia_token),
  UNIQUE KEY uq_app_offer_link (offer_token),
  KEY idx_app_passport (passport_number),
  KEY idx_app_user (user_id),
  KEY idx_app_status (status),
  KEY idx_app_category (visa_category_id),
  KEY idx_app_region (region_id),
  CONSTRAINT fk_app_region FOREIGN KEY (region_id) REFERENCES regions (id) ON DELETE SET NULL,
  CONSTRAINT fk_app_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_app_category FOREIGN KEY (visa_category_id) REFERENCES visa_categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_assignments (
  application_id INT UNSIGNED NOT NULL,
  staff_user_id INT UNSIGNED NOT NULL,
  assigned_by INT UNSIGNED NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (application_id, staff_user_id),
  KEY idx_assign_staff (staff_user_id),
  CONSTRAINT fk_assign_app FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_staff FOREIGN KEY (staff_user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_by FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_status_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id INT UNSIGNED NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NOT NULL,
  note VARCHAR(1000) NULL,
  visible_to_applicant TINYINT(1) NOT NULL DEFAULT 1,
  changed_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hist_app (application_id, created_at),
  CONSTRAINT fk_hist_app FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE,
  CONSTRAINT fk_hist_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Applicant documents (passport scans, photos, certificates...). Files are stored privately
-- with random names; stored_name is never exposed in a public URL.
CREATE TABLE IF NOT EXISTS documents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id INT UNSIGNED NOT NULL,
  requirement_id INT UNSIGNED NULL,
  doc_type VARCHAR(40) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name CHAR(64) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  status ENUM('pending','accepted','rejected','superseded') NOT NULL DEFAULT 'pending',
  review_note VARCHAR(1000) NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  supersedes_id INT UNSIGNED NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_doc_stored (stored_name),
  KEY idx_doc_app (application_id, doc_type),
  KEY idx_doc_status (status),
  CONSTRAINT fk_doc_app FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_req FOREIGN KEY (requirement_id) REFERENCES document_requirements (id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_doc_supersedes FOREIGN KEY (supersedes_id) REFERENCES documents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Offer letters: applicants can only download rows with is_published = 1.
CREATE TABLE IF NOT EXISTS offer_letters (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  notes VARCHAR(1000) NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name CHAR(64) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  published_by INT UNSIGNED NULL,
  unpublished_at DATETIME NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_offer_stored (stored_name),
  KEY idx_offer_app (application_id, is_published),
  CONSTRAINT fk_offer_app FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE,
  CONSTRAINT fk_offer_pub FOREIGN KEY (published_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_offer_up FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biometric_appointments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id INT UNSIGNED NOT NULL,
  center_name VARCHAR(150) NOT NULL,
  center_address VARCHAR(255) NULL,
  appointment_at DATETIME NOT NULL,
  instructions TEXT NULL,
  status ENUM('scheduled','attended','approved','rejected','missed','cancelled') NOT NULL DEFAULT 'scheduled',
  decision_note VARCHAR(1000) NULL,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bio_app (application_id),
  CONSTRAINT fk_bio_app FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE,
  CONSTRAINT fk_bio_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_bio_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fingerprint and face photos the applicant captures and submits (Biometrics tab).
-- One row per photo; a retake supersedes the previous row for the same slot.
CREATE TABLE IF NOT EXISTS biometric_captures (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment evidence uploaded by applicants. Status only becomes 'confirmed'
-- when a permitted finance administrator reviews it.
CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id INT UNSIGNED NOT NULL,
  fee_type_id INT UNSIGNED NULL,
  region_id INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  payer_name VARCHAR(150) NOT NULL,
  transaction_reference VARCHAR(100) NOT NULL,
  paid_on DATE NOT NULL,
  receipt_original_name VARCHAR(255) NOT NULL,
  receipt_stored_name CHAR(64) NOT NULL,
  receipt_mime VARCHAR(100) NOT NULL,
  receipt_size INT UNSIGNED NOT NULL,
  receipt_sha256 CHAR(64) NOT NULL,
  status ENUM('submitted','confirmed','rejected') NOT NULL DEFAULT 'submitted',
  review_note VARCHAR(1000) NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pay_stored (receipt_stored_name),
  KEY idx_pay_app (application_id),
  KEY idx_pay_status (status),
  KEY idx_pay_region (region_id),
  CONSTRAINT fk_pay_region FOREIGN KEY (region_id) REFERENCES regions (id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_app FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_fee FOREIGN KEY (fee_type_id) REFERENCES fee_types (id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Travel & accommodation arrangements published by staff to the applicant.
CREATE TABLE IF NOT EXISTS travel_arrangements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id INT UNSIGNED NOT NULL,
  arrival_date DATE NULL,
  arrival_airport VARCHAR(150) NULL,
  flight_details VARCHAR(255) NULL,
  airport_pickup TINYINT(1) NOT NULL DEFAULT 0,
  pickup_details VARCHAR(500) NULL,
  accommodation_name VARCHAR(150) NULL,
  accommodation_address VARCHAR(255) NULL,
  accommodation_contact VARCHAR(150) NULL,
  checkin_date DATE NULL,
  checkout_date DATE NULL,
  notes TEXT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  published_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_travel_app (application_id),
  CONSTRAINT fk_travel_app FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE,
  CONSTRAINT fk_travel_pub FOREIGN KEY (published_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_travel_upd FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cancellation_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id INT UNSIGNED NOT NULL,
  requested_by INT UNSIGNED NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  previous_status VARCHAR(30) NOT NULL,
  status ENUM('pending','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending',
  review_note VARCHAR(1000) NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cancel_app (application_id, status),
  CONSTRAINT fk_cancel_app FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE,
  CONSTRAINT fk_cancel_req FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_cancel_rev FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Communication
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  body VARCHAR(1000) NULL,
  link VARCHAR(255) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user (user_id, is_read, created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_tickets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_no VARCHAR(20) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  application_id INT UNSIGNED NULL,
  subject VARCHAR(200) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'general',
  status ENUM('open','awaiting_applicant','awaiting_staff','closed') NOT NULL DEFAULT 'open',
  assigned_to INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_no (ticket_no),
  KEY idx_ticket_user (user_id),
  KEY idx_ticket_status (status),
  CONSTRAINT fk_ticket_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_app FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE SET NULL,
  CONSTRAINT fk_ticket_assignee FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  is_staff TINYINT(1) NOT NULL DEFAULT 0,
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tmsg_ticket (ticket_id, created_at),
  CONSTRAINT fk_tmsg_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE,
  CONSTRAINT fk_tmsg_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit trail & security events
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category ENUM('audit','security') NOT NULL DEFAULT 'audit',
  user_id INT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id INT UNSIGNED NULL,
  details TEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_category (category, created_at),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_user (user_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Security: 2-step login recovery codes and the IP firewall
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_recovery_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recovery_hash (code_hash),
  KEY idx_recovery_user (user_id),
  CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ip_blocks (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
