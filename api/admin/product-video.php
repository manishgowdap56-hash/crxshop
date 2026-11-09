<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/video_faststart.php';
require_admin();

/*
 * Product video upload / removal (optional, one per product).
 * Separate from products.php and product-image.php so the product save / plan / provider logic is untouched.
 * No application-level size or duration limit: only the hosting limits (upload_max_filesize, post_max_size, web-server body limit) apply.
 * The video is stored as a file (never in MySQL); the database keeps only the relative path.
 */
if (request_method() !== 'POST') json_error('Method not allowed.', 405);

// Large videos: make sure PHP does not stop this request early (no-op if the host forbids it).
@ini_set('max_execution_time', '600');
@set_time_limit(600);

// A body larger than post_max_size arrives with EMPTY $_POST/$_FILES (and no CSRF token) - report that clearly.
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    json_error('Server upload limit exceeded (post_max_size ' . ini_get('post_max_size') . ', upload_max_filesize ' . ini_get('upload_max_filesize') . '). Required: upload_max_filesize 256M and post_max_size 300M. Check /api/admin/php-limits.php, wait up to 5 min after editing .user.ini, or set the values in the hosting PHP settings.', 413);
}
csrf_verify();

$pdo = db();
$action = input_str('action');
$id = input_int('id');
if ($id <= 0) json_error('Save the product first, then upload its video.', 422);

$q = $pdo->prepare('SELECT video_path FROM products WHERE id = :id');
$q->execute([':id' => $id]);
$row = $q->fetch();
if (!$row) json_error('Product not found.', 404);
$old = (string)($row['video_path'] ?? '');

function delete_old_product_video(string $relative): void
{
    if (!preg_match('#^uploads/product-videos/p\d+-v-[a-f0-9]{24}\.(mp4|webm)$#', $relative)) return;
    $abs = BASE_PATH . '/' . $relative;
    if (is_file($abs)) @unlink($abs);
}

if ($action === 'clear') {
    $pdo->prepare('UPDATE products SET video_path = NULL WHERE id = :id')->execute([':id' => $id]);
    delete_old_product_video($old);
    json_ok(['video_url' => '']);
}
if ($action !== 'upload') json_error('Unknown action.', 400);

$file = $_FILES['video'] ?? null;
if (!is_array($file)) json_error('No video file received by the server. Check PHP upload settings.', 422);

$err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
$errors = [
    UPLOAD_ERR_INI_SIZE   => 'Server upload limit exceeded (upload_max_filesize ' . ini_get('upload_max_filesize') . ').',
    UPLOAD_ERR_FORM_SIZE  => 'Server upload limit exceeded (form limit).',
    UPLOAD_ERR_PARTIAL    => 'Video upload was interrupted. Please try again.',
    UPLOAD_ERR_NO_FILE    => 'No video was selected.',
    UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
    UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded video to disk.',
    UPLOAD_ERR_EXTENSION  => 'A server extension stopped the video upload.',
];
if ($err !== UPLOAD_ERR_OK) json_error($errors[$err] ?? 'Video upload failed.', 422);
if (!is_uploaded_file((string)$file['tmp_name'])) json_error('Invalid uploaded file.', 422);
if ((int)($file['size'] ?? 0) <= 0) json_error('The selected video is empty.', 422);

// Server-side inspection of the real content (never the extension or the browser-supplied type).
$tmp = (string)$file['tmp_name'];
$fh = @fopen($tmp, 'rb');
$head = $fh ? (string)fread($fh, 32) : '';
if ($fh) fclose($fh);
$kind = '';
if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
    $brand = substr($head, 8, 4);
    if ($brand === 'qt  ') json_error('Unsupported video format (QuickTime .mov). Export it as MP4 (H.264 video + AAC audio).', 422);
    $kind = 'mp4';
} elseif (substr($head, 0, 4) === "\x1A\x45\xDF\xA3") {
    $kind = 'webm';   // Matroska/WebM container
}
$mime = '';
if (class_exists('finfo')) $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
elseif (function_exists('mime_content_type')) $mime = (string)mime_content_type($tmp);
$okMime = ['video/mp4', 'video/x-m4v', 'video/webm', 'application/octet-stream', 'video/x-matroska', ''];
if ($kind === '' || !in_array($mime, $okMime, true)) {
    json_error('Unsupported video format. Upload an MP4 (H.264 + AAC) or WebM video.', 422);
}

$dir = UPLOAD_PATH . '/product-videos';
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    json_error('Product video directory could not be created. Make uploads/ writable.', 500);
}
// Never let anything in this folder execute (the shipped .htaccess does the same; this recreates it if missing).
$ht = $dir . '/.htaccess';
if (!is_file($ht)) {
    @file_put_contents($ht, "Options -ExecCGI -Indexes\n<FilesMatch \"\\.(php|phtml|phar|php[0-9]|cgi|pl|py)$\">\n    Require all denied\n</FilesMatch>\n");
}
if (!is_writable($dir)) @chmod($dir, 0755);
if (!is_writable($dir)) json_error('Product video directory is not writable. Make uploads/product-videos writable.', 500);

$name = 'p' . $id . '-v-' . bin2hex(random_bytes(12)) . '.' . $kind;
$dest = $dir . '/' . $name;

// Web playback: MP4 "Fast Start" (moov index in front of the media data) lets the browser start playing after the first
// few KB instead of needing the end of the file. Lossless (no re-encode, identical size); any problem -> keep the original upload.
$stored = false;
if ($kind === 'mp4') {
    $fs = 'skipped';
    try { $fs = mp4_faststart_file($tmp, $dest); } catch (Throwable $e) { $fs = 'skipped:exception'; }
    if ($fs === 'optimized' && is_file($dest)) {
        $stored = true;
        @unlink($tmp);
    } elseif (is_file($dest)) {
        @unlink($dest);   // never keep a partial optimized copy
    }
}
if (!$stored && !move_uploaded_file($tmp, $dest)) {
    json_error('Unable to save video. Make uploads/product-videos writable and try again.', 500);
}
@chmod($dir . '/' . $name, 0644);
$relative = 'uploads/product-videos/' . $name;

$pdo->prepare('UPDATE products SET video_path = :p WHERE id = :id')->execute([':p' => $relative, ':id' => $id]);
delete_old_product_video($old);
json_ok(['video_url' => product_video_url($relative)]);
