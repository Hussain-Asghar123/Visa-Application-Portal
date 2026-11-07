<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Email delivery. Drivers (config mail.driver):
 *   smtp – external SMTP provider (recommended; free hosts usually block mail())
 *   mail – PHP mail()
 *   log  – write messages to private/logs/mail-YYYY-MM.log (testing / no email available)
 *   none – do not send (in-app notifications still work)
 * Returns true when the message was handed to the transport.
 */
function send_mail(string $to, string $subject, string $textBody): bool
{
    $driver = (string) config('mail.driver', 'log');
    $to = trim($to);
    // Clients added by staff without an email have a placeholder address that must never be mailed.
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || is_no_login_email($to)) {
        return false;
    }
    $subject = preg_replace('/[\r\n]+/', ' ', config('app.name', 'Visa Portal') . ': ' . $subject);
    $footer = "\n\n--\n" . config('app.name', 'Visa Application Portal') . "\nThis is an automated message. Please do not reply directly.";
    $body = $textBody . $footer;

    try {
        return match ($driver) {
            'smtp' => smtp_send($to, $subject, $body),
            'mail' => mail_send($to, $subject, $body),
            'log' => mail_log($to, $subject, $body),
            default => false,
        };
    } catch (Throwable $e) {
        app_log('error', 'Mail send failed: ' . $e->getMessage(), ['to_domain' => substr((string) strrchr($to, '@'), 1)]);
        return false;
    }
}

function mail_from(): array
{
    $email = (string) config('mail.from_email', '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = 'no-reply@' . (parse_url((string) config('app.url', 'http://localhost'), PHP_URL_HOST) ?: 'localhost');
    }
    $name = preg_replace('/[\r\n"]+/', '', (string) config('mail.from_name', config('app.name', 'Visa Portal')));
    return [$email, $name];
}

function mail_headers(string $to, string $subject): array
{
    [$fromEmail, $fromName] = mail_from();
    $domain = substr((string) strrchr($fromEmail, '@'), 1) ?: 'localhost';
    return [
        'Date' => date('r'),
        'From' => '"' . $fromName . '" <' . $fromEmail . '>',
        'To' => '<' . $to . '>',
        'Subject' => mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n"),
        'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => 'base64',
    ];
}

function mail_log(string $to, string $subject, string $body): bool
{
    $dir = log_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $entry = "==== " . date('Y-m-d H:i:s') . " ====\nTo: {$to}\nSubject: {$subject}\n\n{$body}\n\n";
    return @file_put_contents($dir . '/mail-' . date('Y-m') . '.log', $entry, FILE_APPEND | LOCK_EX) !== false;
}

function mail_send(string $to, string $subject, string $body): bool
{
    $h = mail_headers($to, $subject);
    $headers = 'From: ' . $h['From'] . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
    return mail($to, $h['Subject'], chunk_split(base64_encode($body)), $headers);
}

/** Minimal SMTP client supporting SSL (465) and STARTTLS (587) with AUTH LOGIN. */
function smtp_send(string $to, string $subject, string $body): bool
{
    $host = (string) config('mail.smtp.host', '');
    $port = (int) config('mail.smtp.port', 587);
    $enc = strtolower((string) config('mail.smtp.encryption', 'tls'));
    $user = (string) config('mail.smtp.username', '');
    $pass = (string) config('mail.smtp.password', '');
    $timeout = (int) config('mail.smtp.timeout', 15);
    if ($host === '') {
        throw new RuntimeException('SMTP host not configured');
    }
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
    }
    stream_set_timeout($fp, $timeout);

    $read = static function () use ($fp): array {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return [(int) substr($data, 0, 3), $data];
    };
    $cmd = static function (string $command, array $expect) use ($fp, $read): string {
        fwrite($fp, $command . "\r\n");
        [$code, $resp] = $read();
        if (!in_array($code, $expect, true)) {
            $safe = str_starts_with($command, 'AUTH') || strlen($command) > 60 ? '[credentials]' : $command;
            throw new RuntimeException("SMTP error after {$safe}: " . trim($resp));
        }
        return $resp;
    };

    [$code] = $read();
    if ($code !== 220) {
        throw new RuntimeException('SMTP server not ready');
    }
    [$fromEmail] = mail_from();
    $ehloHost = parse_url((string) config('app.url', ''), PHP_URL_HOST) ?: 'localhost';
    $cmd('EHLO ' . $ehloHost, [250]);
    if ($enc === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0))) {
            throw new RuntimeException('STARTTLS negotiation failed');
        }
        $cmd('EHLO ' . $ehloHost, [250]);
    }
    if ($user !== '') {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($user), [334]);
        $cmd(base64_encode($pass), [235]);
    }
    $cmd('MAIL FROM:<' . $fromEmail . '>', [250]);
    $cmd('RCPT TO:<' . $to . '>', [250, 251]);
    $cmd('DATA', [354]);

    $headers = '';
    foreach (mail_headers($to, $subject) as $k => $v) {
        $headers .= $k . ': ' . $v . "\r\n";
    }
    $payload = $headers . "\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
    // Dot-stuffing is not needed for base64 bodies, but headers are safe too.
    $cmd($payload . "\r\n.", [250]);
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return true;
}
