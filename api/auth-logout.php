<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
logout_user();
json_ok(['redirect' => base_url('login.php')]);