<?php
/**
 * Visa Application Portal – production configuration TEMPLATE.
 *
 * 1. Copy this file to  private/config.php  (same folder) – or, if your host allows it,
 *    to  ../vap-private/config.php  one level ABOVE the web root (most secure).
 * 2. Fill in the values from your hosting control panel.
 * 3. NEVER commit config.php to public source control or share it.
 *
 * This folder is protected by private/.htaccess; config.php is PHP, so even if the
 * protection failed, the server would execute it (output nothing) rather than reveal it.
 */
return [
    'app' => [
        'name'          => 'Visa Application Portal',
        // Full public address WITHOUT a trailing slash, e.g. https://www.example.com
        // Used to build links in emails. Change it when you connect your custom domain.
        'url'           => 'https://your-domain.example',
        'timezone'      => 'UTC',            // e.g. Asia/Karachi, Europe/London
        'support_email' => '',               // optional (the footer shows the phone numbers in includes/layout.php)
        'debug'         => false,            // NEVER true on a live site
        'force_https'   => false,            // set true AFTER the SSL certificate is active
        'hsts'          => false,            // set true only after HTTPS works on every page
    ],

    'db' => [
        // Copy these from the hosting control panel (MySQL Databases page).
        'host' => '',      // e.g. sqlXXX.your-host.com  (NOT "localhost" on most free hosts)
        'port' => 3306,
        'name' => '',      // e.g. if0_12345678_visa
        'user' => '',      // e.g. if0_12345678
        'pass' => '',      // the database / hosting account password from the panel
    ],

    'paths' => [
        // Leave empty to use private/storage and private/logs inside this project.
        // If your host allows folders outside the web root, point these there.
        'storage' => '',
        'logs'    => '',
    ],

    'uploads' => [
        'max_mb' => 5,   // per file. InfinityFree's hard limit is 10 MB per file.
    ],

    'mail' => [
        // 'smtp' (recommended), 'mail' (PHP mail(), blocked on most free hosts),
        // 'log' (writes emails to private/logs/mail-*.log), or 'none'.
        'driver'          => 'log',
        'from_email'      => '',
        'from_name'       => 'Visa Application Portal',
        'notify_by_email' => true,  // also email status-change notifications
        'smtp' => [
            'host'       => '',     // e.g. smtp.gmail.com / smtp-relay.brevo.com
            'port'       => 587,    // 587 with 'tls', or 465 with 'ssl'
            'encryption' => 'tls',
            'username'   => '',
            'password'   => '',     // use an app password / SMTP key, never your main password
            'timeout'    => 15,
        ],
    ],

    'security' => [
        'require_email_verification' => false, // true = applicants must click an emailed link before using the portal
        'session_idle_minutes'       => 30,
        'session_absolute_hours'     => 8,
        'login_max_attempts'         => 5,    // failed attempts per email …
        'login_window_minutes'       => 15,   // … within this window
        // Only set this if your host puts visitors behind a proxy and documents the header,
        // e.g. 'HTTP_X_FORWARDED_FOR'. Leave empty otherwise (prevents IP spoofing).
        'client_ip_header'           => '',
    ],

    'setup' => [
        // One-time Super Admin setup (setup.php). Set enabled => true and paste a random
        // secret of at least 32 characters, run setup.php once, then set enabled => false,
        // clear the secret, and delete setup.php from the server.
        'enabled' => false,
        'secret'  => '',
    ],
];
