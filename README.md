# Visa Application Portal (VAP)

A full-featured, secure, and self-hosted Visa & Immigration Application Management System built with PHP and MySQL. Designed for immigration consultants, visa agencies, law firms, and educational consultants to manage applicant files, biometric verification, document tracking, LMIA processing, and payment workflows.

---

## 🚀 Key Features

### 👤 Applicant Experience
- **Multi-Step Visa Application**: Intuitive application intake form covering personal details, travel history, passport info, and visa categories.
- **Document Checklist & Uploads**: Applicants can upload identity documents, bank statements, photographs, and certificates with strict MIME and size validation.
- **Application Tracking**: Public and authenticated real-time status tracker with visual progress milestones.
- **Shared Access Links (`portal.php`)**: Direct secure links (`?p=<purpose>&k=<key>`) for applicants to upload specific items (passports, biometrics, bank transfer receipts) or download issued job offers, LMIA letters, and visas without needing account login.
- **Support Tickets**: Built-in messaging and ticket system between applicants and processing staff.
- **Security & 2FA**: Email verification, session timeouts, and Time-based One-Time Password (TOTP) two-factor authentication.

### 🛡️ Admin & Staff Management
- **Role-Based Access Control (RBAC)**: Fine-grained staff permissions (Case Officer, Reviewer, Finance, Super Admin).
- **Application Dossier Review**: View applicant documents, verify checklists, issue request for information (RFI), approve or reject applications.
- **Biometric Processing**: Record biometric appointments, verify facial photos and fingerprint captures.
- **LMIA & Job Offer Management**: Upload and track Labour Market Impact Assessment (LMIA) numbers, validity dates, and employment offer letters.
- **Payment Verification**: Manual bank wire/receipt verification with customizable payment instructions by region/country.
- **Audit Logs**: Immutable activity logging tracking all administrative actions, status updates, and document accesses.
- **Site & Security Settings**: Configurable firewall thresholds, rate limiting, SMTP mailers, session durations, and regional rules.

---

## 🛠️ Architecture & Tech Stack

- **Backend**: Native PHP 8.0+ (Strict typing, PDO prepared statements, no heavy runtime dependencies).
- **Database**: MySQL 5.7+ / 8.x or MariaDB 10.4+ (InnoDB, UTF-8 MB4 character set, full relational constraints).
- **Frontend**: Clean responsive interface built with Bootstrap 5, Bootstrap Icons, and vanilla JavaScript.
- **Web Server**: Apache 2.4+ (Utilizes `.htaccess` for rewrite rules, security headers, and protecting private directories).
- **Storage**: Segregated private file storage (`private/storage/`) shielded from direct HTTP access; served only via authenticated streaming scripts (`download.php`).

---

## 📂 Project Structure

```text
HOST-THIS-visa-portal/
├── account/               # User account settings, profile, password & 2FA management
├── admin/                 # Staff & Admin dashboard, applicant dossiers, settings, logs
├── applicant/             # Applicant dashboard, application wizard, document checklists
├── assets/                # Frontend assets (CSS, JS, images, vendor libraries)
├── database/              # SQL schema, seed data, and migration scripts
│   ├── install.sql        # Full database installation file (schema + seed data)
│   ├── schema.sql         # Base database tables and foreign keys
│   ├── seed.sql           # Default permissions, roles, and initial settings
│   └── upgrade-001-*.sql  # Regional upgrade scripts
├── includes/              # Core application logic, bootstrap, auth, firewall, helpers
├── private/               # Sensitive files (config, logs, and encrypted file storage)
│   ├── config.sample.php  # Sample configuration template
│   ├── install.key        # One-time setup key for the web installer
│   ├── logs/              # Application activity and error logs
│   └── storage/           # Uploaded documents, biometrics, LMIA, offers, receipts
├── .htaccess              # Apache root security and routing rules
├── index.php              # Public landing page
├── install.php            # Guided web-based setup wizard
├── login.php              # User & staff authentication
├── portal.php             # Tokenized shared link portal for external applicant actions
├── register.php           # Applicant registration
└── track.php              # Public visa tracking page
```

---

## ⚙️ Installation & Setup

### Option 1: Web Installer (Recommended)

1. **Deploy Files**: Upload all project files to your web server (e.g., `public_html` on Apache/cPanel).
2. **Database Setup**: Create a MySQL/MariaDB database and database user with full privileges in your hosting control panel.
3. **Launch Installer**: Navigate to `https://your-domain.com/install.php` in your browser.
4. **Enter Setup Details**:
   - Provide the **Install Key** found in `private/install.key`.
   - Enter your MySQL database credentials (Host, Port, Database Name, User, Password).
   - Configure the Super Admin credentials (Name, Email, Password).
5. **Finish**: The installer automatically creates tables, populates seeds, generates `private/config.php`, and removes installation keys.

### Option 2: Manual Installation

1. Import `database/install.sql` (or `schema.sql` followed by `seed.sql`) using phpMyAdmin or MySQL CLI:
   ```bash
   mysql -u db_user -p db_name < database/install.sql
   ```
2. Copy `private/config.sample.php` to `private/config.php`:
   ```bash
   cp private/config.sample.php private/config.php
   ```
3. Edit `private/config.php` with your database credentials, app URL, and mailer settings.
4. Ensure `private/storage/` and `private/logs/` are writable by the web server user (`chmod 775` or `chmod 755`).

---

## 🌐 Hosting & Deployment Analysis

### Traditional Shared Hosting & VPS (cPanel, Hostinger, Namecheap, Ubuntu VPS)
- **Verdict**: **100% Compatible (Ideal)**.
- This codebase is tailor-made for traditional LAMP stacks (Linux, Apache, MySQL, PHP).
- All rewrite rules, `.htaccess` security layers, native file uploads, and session handling work natively out of the box.

### Cloud Containers (Railway, Render, Fly.io, Heroku)
- **Verdict**: **Compatible with persistent volumes or S3 storage**.
- Can be run using standard PHP/Apache Dockerfiles with a managed MySQL service.
- Requires mounting a persistent volume for `private/storage/` or modifying `includes/uploads.php` to stream to an S3-compatible bucket.

### Serverless Hosting (Vercel)
- **Verdict**: **Not recommended without architectural rewrites**.
- **Reason**: Vercel is a stateless, serverless platform primarily built for Node.js, Next.js, and static websites.
- **Limitations on Vercel**:
  1. **Ephemeral Filesystem**: Files uploaded by applicants to `private/storage/` will be destroyed as soon as the serverless function execution finishes or recycles.
  2. **No Integrated Database**: Vercel does not host MySQL; you must link to a third-party managed database (e.g. PlanetScale, AWS RDS, Railway MySQL).
  3. **PHP Runtime**: Vercel dropped first-party PHP support and requires experimental community runtimes (`vercel-php`), which can break traditional PHP session persistence and multi-file routing.

---

## 🔒 Security Best Practices

- **Hide Config**: If supported by your host, move `private/config.php` one directory above `public_html` (`../vap-private/config.php`).
- **Enforce HTTPS**: Turn on SSL in your hosting panel, then enable `force_https => true` and `hsts => true` in `config.php`.
- **Install Cleanup**: Delete `install.php` and `setup.php` immediately after successful deployment if not automatically cleaned up.
- **File Upload Limits**: Configure upload limits in `config.php` to comply with server memory and disk policies.

---

## 📄 License
Proprietary / All rights reserved. Configured for Visa Application Portal deployment.
