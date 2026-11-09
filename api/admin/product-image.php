<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

/*
 * Store UI: product image upload / removal.
 * Kept separate from products.php so the product save + plan + stock logic is untouched.
 * Validation mirrors the logo / dashboard-display uploads (PNG/JPG/WEBP, real decodable image, 20 MB cap).
 * The browser already optimises the image (same canvas -> WebP ladder as the logo flow).
 */
if (request_method() !== 'POST') json_error('Method not allowed.', 405);
csrf_verify();

$pdo = db();
$action = input_str('action');
$id = input_int('id');
if ($id <= 0) json_error('Save the product first, then upload its image.', 422);

$q = $pdo->prepare('SELECT image_path FROM products WHERE id = :id');
$q->execute([':id' => $id]);
$row = $q->fetch();
if (!$row) json_error('Product not found.', 404);
$old = (string)($row['image_path'] ?? '');

function delete_old_product_image(string $relative): void
{
    if (!preg_match('#^uploads/products/p\d+-[a-f0-9]{24}\.(jpg|png|webp)$#', $relative)) return;
    $abs = BASE_PATH . '/' . $relative;
    if (is_file($abs)) @unlink($abs);
}

if ($action === 'clear') {
    $pdo->prepare('UPDATE products SET image_path = NULL WHERE id = :id')->execute([':id' => $id]);
    delete_old_product_image($old);
    json_ok(['image_url' => '']);
}

if ($action !== 'upload') json_error('Unknown action.', 400);

$file = $_FILES['image'] ?? null;
if (!is_array($file)) json_error('No image file received by the server. Check PHP upload settings.', 422);

$err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
$errors = [
    UPLOAD_ERR_INI_SIZE   => 'Image is larger than the server upload limit.',
    UPLOAD_ERR_FORM_SIZE  => 'Image is larger than the form upload limit.',
    UPLOAD_ERR_PARTIAL    => 'Image upload was interrupted. Please try again.',
    UPLOAD_ERR_NO_FILE    => 'No image was selected.',
    UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
    UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded image to disk.',
    UPLOAD_ERR_EXTENSION  => 'A server extension stopped the image upload.',
];
if ($err !== UPLOAD_ERR_OK) json_error($errors[$err] ?? 'Image upload failed.', 422);
if (!is_uploaded_file((string)$file['tmp_name'])) json_error('Invalid uploaded file.', 422);
$size = (int)($file['size'] ?? 0);
if ($size <= 0) json_error('The selected image is empty.', 422);
if ($size > 20 * 1024 * 1024) json_error('Image is too large (max 20 MB).', 422);

$mime = '';
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
}
if ($mime === '' && function_exists('mime_content_type')) $mime = (string)mime_content_type($file['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) {
    $info = @getimagesize($file['tmp_name']);
    $byType = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];
    $type = is_array($info) ? (int)($info[2] ?? 0) : 0;
    if (isset($byType[$type])) $mime = $byType[$type];
}
if (!isset($allowed[$mime])) json_error('Invalid image type. Use PNG, JPG or WEBP.', 422);
if (@getimagesize($file['tmp_name']) === false) json_error('The selected file is not a valid image.', 422);

$dir = UPLOAD_PATH . '/products';
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    json_error('Product image directory could not be created. Make uploads/products writable.', 500);
}
if (!is_writable($dir)) @chmod($dir, 0755);
if (!is_writable($dir)) json_error('Product image directory is not writable. Make uploads/products writable on the server.', 500);

$name = 'p' . $id . '-' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
    json_error('Could not save the product image. Make uploads/products writable and try again.', 500);
}
@chmod($dir . '/' . $name, 0644);
$relative = 'uploads/products/' . $name;

$pdo->prepare('UPDATE products SET image_path = :p WHERE id = :id')->execute([':p' => $relative, ':id' => $id]);
delete_old_product_image($old);
json_ok(['image_url' => product_image_url($relative)]);
