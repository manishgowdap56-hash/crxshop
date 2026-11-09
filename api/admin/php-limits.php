<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

/*
 * Admin-only, read-only report of the EFFECTIVE PHP limits used by Product Video upload.
 * Open while logged in as admin:  /api/admin/php-limits.php
 * Changes nothing; reads ini_get() from inside the same PHP runtime that handles uploads.
 */

function ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '') return 0;
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;   // no break
        case 'm': $n *= 1024;   // no break
        case 'k': $n *= 1024;
    }
    return (int)$n;
}

$want = [
    'upload_max_filesize' => '256M',
    'post_max_size'       => '300M',
    'max_input_time'      => '600',
    'max_execution_time'  => '600',
];

$checks = [];
$allOk = true;
foreach ($want as $key => $target) {
    $have = (string)ini_get($key);
    if ($key === 'upload_max_filesize' || $key === 'post_max_size') {
        $ok = ini_bytes($have) >= ini_bytes($target);
    } else {
        $ok = (int)$have >= (int)$target || (int)$have === 0 || (int)$have === -1;   // 0 / -1 = unlimited
    }
    if (!$ok) $allOk = false;
    $checks[$key] = ['required' => $target, 'effective' => $have, 'ok' => $ok];
}
$postOk = ini_bytes((string)ini_get('post_max_size')) >= ini_bytes((string)ini_get('upload_max_filesize'));
if (!$postOk) $allOk = false;

$videoDir = UPLOAD_PATH . '/product-videos';
$free = @disk_free_space(is_dir($videoDir) ? $videoDir : UPLOAD_PATH);

json_ok([
    'all_ok'                     => $allOk,
    'checks'                     => $checks,
    'post_max_size_ge_upload'    => $postOk,
    'file_uploads'               => (bool)ini_get('file_uploads'),
    'max_file_uploads'           => (string)ini_get('max_file_uploads'),
    'memory_limit'               => (string)ini_get('memory_limit'),
    'upload_tmp_dir'             => (string)(ini_get('upload_tmp_dir') ?: sys_get_temp_dir()),
    'upload_tmp_dir_writable'    => is_writable((string)(ini_get('upload_tmp_dir') ?: sys_get_temp_dir())),
    'video_dir_writable'         => is_dir($videoDir) ? is_writable($videoDir) : null,
    'disk_free_mb'               => $free === false ? null : (int)round($free / 1048576),
    'php_version'                => PHP_VERSION,
    'sapi'                       => PHP_SAPI,
    'server_software'            => (string)($_SERVER['SERVER_SOFTWARE'] ?? ''),
    'loaded_php_ini'             => (string)php_ini_loaded_file(),
    'scanned_ini_files'          => (string)php_ini_scanned_files(),
    'user_ini_filename'          => (string)ini_get('user_ini.filename'),
    'user_ini_cache_ttl_sec'     => (string)ini_get('user_ini.cache_ttl'),
    'hint'                       => $allOk
        ? 'All limits are effective. Product Video upload can accept files up to ' . ini_get('upload_max_filesize') . '.'
        : 'One or more limits are lower than required. .user.ini can take up to user_ini.cache_ttl seconds (default 300) to apply; if it still does not change, set these values in the hosting panel (cPanel > MultiPHP INI Editor / Select PHP Version > Options).',
]);
