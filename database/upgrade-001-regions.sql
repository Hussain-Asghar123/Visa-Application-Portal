-- =====================================================================
-- UPGRADE 001 – payment regions
-- Only for databases created BEFORE regions existed (import ONCE in phpMyAdmin).
-- New installations already get this from install.sql / schema.sql – do not run it there.
-- =====================================================================
SET NAMES utf8mb4;

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

ALTER TABLE payment_instructions
  ADD COLUMN region_id INT UNSIGNED NULL AFTER title,
  ADD KEY idx_pi_region (region_id),
  ADD CONSTRAINT fk_pi_region FOREIGN KEY (region_id) REFERENCES regions (id) ON DELETE RESTRICT;

ALTER TABLE applications
  ADD COLUMN region_id INT UNSIGNED NULL AFTER visa_category_id,
  ADD KEY idx_app_region (region_id),
  ADD CONSTRAINT fk_app_region FOREIGN KEY (region_id) REFERENCES regions (id) ON DELETE SET NULL;

ALTER TABLE payments
  ADD COLUMN region_id INT UNSIGNED NULL AFTER fee_type_id,
  ADD KEY idx_pay_region (region_id),
  ADD CONSTRAINT fk_pay_region FOREIGN KEY (region_id) REFERENCES regions (id) ON DELETE SET NULL;

INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', '2')
  ON DUPLICATE KEY UPDATE setting_value = '2';
