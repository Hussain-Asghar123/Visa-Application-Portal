-- =====================================================================
-- Visa Application Portal - starter data
-- Import AFTER schema.sql. Safe to re-import (INSERT IGNORE).
-- Contains NO user accounts, NO passwords and NO bank details.
-- The first Super Admin is created through setup.php.
-- Visa categories, processing times and fee types below are EXAMPLES:
-- review and edit them in Admin > Configuration before going live.
-- =====================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO permissions (code, label, description) VALUES
('applications.view_all', 'View all applications', 'See every application, not only those assigned to the staff member'),
('applications.review', 'Review applications', 'Review application details and update their status'),
('applications.assign', 'Assign applications', 'Assign applications to staff members'),
('documents.review', 'Review documents', 'View, accept or reject applicant documents'),
('offers.manage', 'Manage offer letters', 'Upload offer letters'),
('offers.publish', 'Publish offer letters', 'Publish or unpublish offer letters to applicants'),
('biometrics.manage', 'Manage biometrics', 'Schedule biometric appointments and approve or reject the stage'),
('payments.instructions', 'Payment configuration', 'Manage fee types and payment instructions'),
('payments.review', 'Review payments', 'Confirm or reject applicant payment receipts'),
('travel.manage', 'Manage travel', 'Manage and publish travel and accommodation details'),
('support.manage', 'Support tickets', 'Respond to applicant support tickets'),
('cancellations.manage', 'Cancellations', 'Approve or reject cancellation requests'),
('applicants.manage', 'Manage applicants', 'Verify, disable or enable applicant accounts and issue reset links'),
('catalog.manage', 'Visa catalogue', 'Manage visa categories, processing times and document requirements'),
('audit.view', 'Audit log', 'View audit logs and security events');

INSERT IGNORE INTO roles (name, description, is_system) VALUES
('Operations Manager', 'Oversees all applications, assigns cases and handles cancellations', 0),
('Case Officer', 'Reviews assigned applications, documents and biometrics', 0),
('Finance Officer', 'Configures payment instructions and verifies payment receipts', 0),
('Offer Letter Officer', 'Uploads and publishes offer letters', 0),
('Travel Coordinator', 'Prepares and publishes travel and accommodation details', 0),
('Support Agent', 'Answers support tickets for assigned applications', 0);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE (r.name = 'Operations Manager' AND p.code IN ('applications.view_all','applications.review','applications.assign','documents.review','cancellations.manage','support.manage','applicants.manage','audit.view'))
   OR (r.name = 'Case Officer' AND p.code IN ('applications.review','documents.review','biometrics.manage','support.manage'))
   OR (r.name = 'Finance Officer' AND p.code IN ('payments.instructions','payments.review'))
   OR (r.name = 'Offer Letter Officer' AND p.code IN ('offers.manage','offers.publish'))
   OR (r.name = 'Travel Coordinator' AND p.code IN ('travel.manage'))
   OR (r.name = 'Support Agent' AND p.code IN ('support.manage'));

INSERT IGNORE INTO visa_categories (code, name, description, processing_days_min, processing_days_max, processing_notes, is_active, sort_order) VALUES
('STUDENT', 'Student visa', 'For applicants admitted to a full-time course of study.', 15, 45, 'Example estimate - edit in Admin > Configuration. Time starts after all mandatory documents are accepted.', 1, 1),
('WORK', 'Work visa', 'For applicants with a confirmed job offer.', 30, 90, 'Example estimate - edit in Admin > Configuration.', 1, 2),
('VISIT', 'Visit visa', 'For short visits, tourism or family visits.', 10, 30, 'Example estimate - edit in Admin > Configuration.', 1, 3);

-- Requirements with visa_category_id NULL apply to every category.
-- (NOT EXISTS guards keep re-imports from creating duplicates, because NULLs are never equal in UNIQUE keys.)
INSERT INTO document_requirements (visa_category_id, doc_type, name, description, is_mandatory, is_active, sort_order)
SELECT NULL, 'passport', 'Passport bio-data page', 'Clear colour scan of the passport page with your photo and details. Passport must be valid for at least 6 months.', 1, 1, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM document_requirements WHERE visa_category_id IS NULL AND doc_type = 'passport');
INSERT INTO document_requirements (visa_category_id, doc_type, name, description, is_mandatory, is_active, sort_order)
SELECT NULL, 'photo', 'Passport-size photograph', 'Recent colour photo with a plain light background (JPG or PNG).', 1, 1, 2 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM document_requirements WHERE visa_category_id IS NULL AND doc_type = 'photo');
INSERT INTO document_requirements (visa_category_id, doc_type, name, description, is_mandatory, is_active, sort_order)
SELECT NULL, 'national_id', 'National identity card', 'Front and back of your national ID card in one PDF, if applicable.', 0, 1, 3 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM document_requirements WHERE visa_category_id IS NULL AND doc_type = 'national_id');
INSERT INTO document_requirements (visa_category_id, doc_type, name, description, is_mandatory, is_active, sort_order)
SELECT NULL, 'medical', 'Medical examination report', 'Report from your medical examination (PDF, or a clear photo of each page).', 0, 1, 4 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM document_requirements WHERE visa_category_id IS NULL AND doc_type = 'medical');

INSERT IGNORE INTO document_requirements (visa_category_id, doc_type, name, description, is_mandatory, is_active, sort_order)
SELECT id, 'education', 'Academic transcripts and certificates', 'Your latest degree / certificates and transcripts in one PDF.', 1, 1, 10 FROM visa_categories WHERE code = 'STUDENT';
INSERT IGNORE INTO document_requirements (visa_category_id, doc_type, name, description, is_mandatory, is_active, sort_order)
SELECT id, 'admission', 'Admission / acceptance letter', 'Letter of acceptance from the institution, if already received.', 0, 1, 11 FROM visa_categories WHERE code = 'STUDENT';
INSERT IGNORE INTO document_requirements (visa_category_id, doc_type, name, description, is_mandatory, is_active, sort_order)
SELECT id, 'bank_statement', 'Bank statement', 'Bank statement for the last 6 months showing sufficient funds.', 1, 1, 12 FROM visa_categories WHERE code IN ('STUDENT','VISIT');
INSERT IGNORE INTO document_requirements (visa_category_id, doc_type, name, description, is_mandatory, is_active, sort_order)
SELECT id, 'job_offer', 'Job offer / employment contract', 'Signed job offer or employment contract.', 1, 1, 10 FROM visa_categories WHERE code = 'WORK';
INSERT IGNORE INTO document_requirements (visa_category_id, doc_type, name, description, is_mandatory, is_active, sort_order)
SELECT id, 'cv', 'CV / resume', 'Up-to-date CV.', 1, 1, 11 FROM visa_categories WHERE code = 'WORK';
INSERT IGNORE INTO document_requirements (visa_category_id, doc_type, name, description, is_mandatory, is_active, sort_order)
SELECT id, 'itinerary', 'Travel itinerary', 'Planned itinerary or invitation letter.', 0, 1, 10 FROM visa_categories WHERE code = 'VISIT';

-- Fee types are created INACTIVE with amount 0.00. A Finance Officer / Super Admin must set
-- the real amounts and activate them before applicants can select them.
INSERT IGNORE INTO fee_types (code, name, description, amount, currency, visa_category_id, is_active) VALUES
('PROCESSING', 'Processing fee', 'Application processing fee (set the amount before activating).', 0.00, 'USD', NULL, 0),
('VISA', 'Visa fee', 'Visa fee (set the amount before activating).', 0.00, 'USD', NULL, 0),
('BIOMETRIC', 'Biometric fee', 'Biometric appointment fee (set the amount before activating).', 0.00, 'USD', NULL, 0);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('schema_version', '8'),
('require_2fa_staff', '0');
