<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
dual_require_api_access($user, false);   // v62: Reseller / Admin only
if (request_method() !== 'POST') json_error('POST required', 405);
csrf_verify();

$newToken = 'psk_' . bin2hex(random_bytes(24));
db()->prepare('UPDATE users SET api_token = :t WHERE id = :id')
    ->execute([':t' => $newToken, ':id' => (int)$user['id']]);

json_ok(['token' => $newToken]);