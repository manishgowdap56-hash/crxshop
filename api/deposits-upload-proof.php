<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
if (request_method() !== 'POST') json_error('POST required', 405);
csrf_verify();

$depositId = input_int('deposit_id');
if ($depositId <= 0) json_error('Invalid deposit.', 422);
if (empty($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
    json_error('Please attach a payment screenshot.', 422);
}

$file = $_FILES['proof'];
if ($file['size'] > 5 * 1024 * 1024) json_error('File too large (max 5 MB).', 422);

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];
if (!isset($allowed[$mime])) json_error('Only JPG, PNG, WEBP images are allowed.', 422);

// Verify deposit belongs to this user and is still pending
$stmt = db()->prepare('SELECT id, order_ref, status FROM deposits WHERE id = :id AND user_id = :uid LIMIT 1');
$stmt->execute([':id' => $depositId, ':uid' => (int)$user['id']]);
$dep = $stmt->fetch();
if (!$dep) json_error('Deposit not found.', 404);
if ($dep['status'] !== 'pending') json_error('This deposit is no longer pending.', 409);

$dir = UPLOAD_PATH . '/proofs';
if (!is_dir($dir)) @mkdir($dir, 0755, true);

$name = $dep['order_ref'] . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
$dest = $dir . '/' . $name;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    json_error('Could not save the uploaded file.', 500);
}
@chmod($dest, 0644);

db()->prepare('UPDATE deposits SET proof_path = :p WHERE id = :id')
    ->execute([':p' => 'uploads/proofs/' . $name, ':id' => $depositId]);

json_ok(['proof_url' => upload_url('proofs/' . $name)]);