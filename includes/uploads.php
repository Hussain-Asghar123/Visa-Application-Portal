<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/** Allowed upload types: extension => [mime types accepted, magic-byte check]. */
const UPLOAD_TYPES = [
    'pdf'  => ['application/pdf'],
    'jpg'  => ['image/jpeg', 'image/pjpeg'],
    'jpeg' => ['image/jpeg', 'image/pjpeg'],
    'png'  => ['image/png'],
];

const STORAGE_KINDS = ['documents', 'offers', 'receipts', 'biometrics', 'lmia'];

function upload_max_mb(): int
{
    return max(1, min(9, (int) config('uploads.max_mb', 5)));
}

function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the allowed maximum of ' . upload_max_mb() . ' MB.',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Please choose a file to upload.',
        default => 'The file could not be uploaded (server error ' . $code . ').',
    };
}

/** Detect the real file type from its first bytes (independent of the client). */
function sniff_file_type(string $path): ?string
{
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return null;
    }
    $head = (string) fread($fh, 16);
    fclose($fh);
    if (str_starts_with($head, '%PDF-')) {
        return 'application/pdf';
    }
    if (str_starts_with($head, "\xFF\xD8\xFF")) {
        return 'image/jpeg';
    }
    if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
        return 'image/png';
    }
    return null;
}

/**
 * Validate and store an uploaded file privately.
 * @param array  $file    One entry of $_FILES
 * @param string $kind    documents|offers|receipts
 * @param array  $allowedExt e.g. ['pdf','jpg','jpeg','png']
 * @return array{ok:bool,error?:string,original_name?:string,stored_name?:string,mime_type?:string,size_bytes?:int,sha256?:string}
 */
function store_upload(array $file, string $kind, array $allowedExt = ['pdf', 'jpg', 'jpeg', 'png']): array
{
    if (!in_array($kind, STORAGE_KINDS, true)) {
        throw new InvalidArgumentException('Unknown storage kind');
    }
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => upload_error_message((int) $file['error'])];
    }
    $tmp = (string) $file['tmp_name'];
    if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    $size = (int) filesize($tmp);
    if ($size <= 0) {
        return ['ok' => false, 'error' => 'The file is empty.'];
    }
    if ($size > upload_max_mb() * 1024 * 1024) {
        return ['ok' => false, 'error' => 'The file is larger than the allowed maximum of ' . upload_max_mb() . ' MB.'];
    }

    $original = (string) $file['name'];
    $original = preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', basename(str_replace('\\', '/', $original))) ?: 'file';
    $original = mb_substr($original, 0, 200);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true) || !isset(UPLOAD_TYPES[$ext])) {
        return ['ok' => false, 'error' => 'File type not allowed. Allowed types: ' . strtoupper(implode(', ', $allowedExt)) . '.'];
    }

    // Content checks: magic bytes must match the extension, and finfo (when available) must agree.
    $sniffed = sniff_file_type($tmp);
    if ($sniffed === null || !in_array($sniffed, UPLOAD_TYPES[$ext], true)) {
        return ['ok' => false, 'error' => 'The file content does not match its extension. Please upload a genuine PDF, JPG or PNG file.'];
    }
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = (string) $finfo->file($tmp);
        if (!in_array($detected, UPLOAD_TYPES[$ext], true)) {
            return ['ok' => false, 'error' => 'The file type could not be verified (' . e($detected) . ').'];
        }
    }
    // Reject PDFs carrying JavaScript or auto-launch actions.
    if ($sniffed === 'application/pdf') {
        $content = (string) file_get_contents($tmp);
        if (preg_match('#/(JavaScript|JS|Launch)\b#', $content)) {
            return ['ok' => false, 'error' => 'This PDF contains active content (scripts) and cannot be accepted. Please export or print it to a plain PDF and try again.'];
        }
    }

    $dir = storage_dir($kind);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        app_log('error', 'Storage directory missing and could not be created', ['kind' => $kind]);
        return ['ok' => false, 'error' => 'File storage is not available. Please contact support.'];
    }
    $stored = bin2hex(random_bytes(32)); // 64 hex chars, no extension, not guessable
    $dest = $dir . '/' . $stored;
    $moved = PHP_SAPI === 'cli' ? @rename($tmp, $dest) : @move_uploaded_file($tmp, $dest);
    if (!$moved) {
        app_log('error', 'move_uploaded_file failed', ['kind' => $kind]);
        return ['ok' => false, 'error' => 'The file could not be saved. Please try again.'];
    }
    @chmod($dest, 0640);
    if ($sniffed !== 'application/pdf') {
        strip_image_metadata($dest, $sniffed);
        $size = (int) filesize($dest);
    }

    return [
        'ok' => true,
        'original_name' => $original,
        'stored_name' => $stored,
        'mime_type' => $sniffed,
        'size_bytes' => $size,
        'sha256' => (string) hash_file('sha256', $dest),
    ];
}

/**
 * Re-save JPG/PNG images with GD: removes hidden metadata (GPS location, camera details)
 * and any data appended to the image. Skipped when GD is missing or the image is huge.
 */
function strip_image_metadata(string $path, string $mime): void
{
    if (!config('uploads.strip_metadata', true) || !function_exists('imagecreatefromjpeg')) {
        return;
    }
    $info = @getimagesize($path);
    if (!$info || $info[0] * $info[1] > 25_000_000) {
        return;
    }
    try {
        $img = $mime === 'image/png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if (!$img) {
            return;
        }
        $tmp = $path . '.tmp';
        if ($mime === 'image/png') {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $ok = imagepng($img, $tmp, 6);
        } else {
            $ok = imagejpeg($img, $tmp, 90);
        }
        imagedestroy($img);
        if ($ok && filesize($tmp) > 0) {
            @rename($tmp, $path);
            @chmod($path, 0640);
        } else {
            @unlink($tmp);
        }
    } catch (Throwable $e) {
        app_log('warning', 'Image metadata could not be removed: ' . $e->getMessage());
    }
}

function delete_stored_file(string $kind, string $stored): void
{
    if (in_array($kind, STORAGE_KINDS, true) && preg_match('/^[a-f0-9]{64}$/', $stored)) {
        @unlink(storage_dir($kind) . '/' . $stored);
    }
}

/** Stream a privately stored file to an authorised user. */
function stream_stored_file(string $kind, string $stored, string $mime, string $originalName, bool $inline = false): never
{
    if (!in_array($kind, STORAGE_KINDS, true) || !preg_match('/^[a-f0-9]{64}$/', $stored)) {
        abort(404);
    }
    $path = storage_dir($kind) . '/' . $stored;
    if (!is_file($path)) {
        app_log('error', 'Stored file missing', ['kind' => $kind, 'stored' => substr($stored, 0, 8)]);
        abort(404, 'File missing', 'The file could not be found on the server. Please contact support.');
    }
    $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
    $mime = in_array($mime, $allowedMimes, true) ? $mime : 'application/octet-stream';
    $safeName = preg_replace('/[^A-Za-z0-9._\-]/', '_', $originalName) ?: 'download';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: sandbox; default-src \'none\'; img-src \'self\'; style-src \'unsafe-inline\'');
    readfile($path);
    exit;
}
