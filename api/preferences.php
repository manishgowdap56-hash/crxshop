<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
if (request_method() !== 'POST') json_error('POST required', 405);
csrf_verify();

$key = input_str('key');
$value = input_str('value');

if ($key === 'tap_sound') {
    $flag = ($value === 'on' || $value === '1') ? 1 : 0;
    db()->prepare(
        'INSERT INTO user_preferences (user_id, tap_sound) VALUES (:u, :v)
         ON DUPLICATE KEY UPDATE tap_sound = VALUES(tap_sound)'
    )->execute([':u' => (int)$user['id'], ':v' => $flag]);
    json_ok(['tap_sound' => (bool)$flag]);
}

json_error('Unknown preference key.', 400);