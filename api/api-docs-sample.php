<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_user();
dual_require_api_access($user, false);   // v62

json_ok([
    'token'    => (string)$user['api_token'],
    'endpoint' => base_url('api/api/reseller_gateway.php'),
    'base_url' => base_url(''),
]);