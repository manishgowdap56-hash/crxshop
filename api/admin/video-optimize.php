<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/video_faststart.php';
require_admin();

/*
 * Admin-only video DELIVERY audit + one-click fix for already-uploaded product videos.
 *
 *   1) Report (read-only):   /api/admin/video-optimize.php
 *        - per stored video: size, duration, average bitrate, "fast start" yes/no
 *        - live HTTP Range self-test per video (expects 206 + Accept-Ranges + Content-Range + video/* type)
 *   2) Fix existing videos:  open the "fix_url" shown in the report (it carries your CSRF token).
 *        - moves the MP4 index (moov) to the front, losslessly (no re-encode, same file name + same size)
 *        - touches only files in uploads/product-videos/; database, orders and API are not touched.
 *
 * Changing nothing but the stored video files: the file is replaced atomically and its new modification
 * time automatically changes the ?v= cache-buster in the Store URL.
 */
if (!in_array(request_method(), ['GET', 'HEAD'], true)) json_error('Method not allowed.', 405);
@ini_set('max_execution_time', '600');
@set_time_limit(600);

function http_range_probe(string $url): array
{
    if (!function_exists('curl_init')) return ['skipped' => 'PHP curl extension not available'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RANGE          => '0-1',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['Accept-Encoding: identity'],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($raw)) return ['error' => $err !== '' ? $err : 'request failed'];

    $headers = [];
    foreach (preg_split('/\r?\n/', substr($raw, 0, $hsize)) ?: [] as $line) {
        $p = strpos($line, ':');
        if ($p !== false) $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
    }
    $type = strtolower((string)($headers['content-type'] ?? ''));
    $range206 = $status === 206 && isset($headers['content-range']);
    $mimeOk = strpos($type, 'video/') === 0;
    return [
        'status'         => $status,
        'range_206'      => $range206,
        'accept_ranges'  => $headers['accept-ranges'] ?? '',
        'content_range'  => $headers['content-range'] ?? '',
        'content_type'   => $headers['content-type'] ?? '',
        'content_encoding' => $headers['content-encoding'] ?? '',
        'cache_control'  => $headers['cache-control'] ?? '',
        'ok'             => $range206 && $mimeOk && !isset($headers['content-encoding']),
    ];
}

$dir = UPLOAD_PATH . '/product-videos';
$files = [];
foreach (array_merge(glob($dir . '/p*-v-*.mp4') ?: [], glob($dir . '/p*-v-*.webm') ?: []) as $f) {
    if (preg_match('#/p\d+-v-[a-f0-9]{24}\.(mp4|webm)$#', $f)) $files[] = $f;
}
sort($files);
$files = array_slice($files, 0, 200);

$fix = ((string)($_GET['fix'] ?? '') === '1');
if ($fix) {
    $tok = (string)($_GET['t'] ?? '');
    if (!hash_equals(csrf_token(), $tok)) json_error('Invalid or missing token. Open the fix_url from the report page.', 419);
}

// Site root URL computed from this script's own path (the generic URL helper mis-detects the root for /api/admin/* scripts).
if (APP_URL !== '') {
    $siteRoot = rtrim(APP_URL, '/');
} else {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $sub = (string)preg_replace('#/api/admin/[^/]*$#', '', str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $siteRoot = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $sub;
}

$rows = [];
$probeCount = 0;
$needFix = 0;
$rangeBad = 0;
$highBitrate = 0;
foreach ($files as $f) {
    $rel = 'uploads/product-videos/' . basename($f);
    $row = ['file' => basename($f)];
    $info = mp4_inspect($f);
    $row += $info;

    if ($fix && $info['kind'] === 'mp4' && $info['faststart'] === false) {
        $tmp = $f . '.fs.tmp';
        $res = 'skipped';
        try { $res = mp4_faststart_file($f, $tmp); } catch (Throwable $e) { $res = 'skipped:exception'; }
        if ($res === 'optimized' && is_file($tmp) && @rename($tmp, $f)) {
            @chmod($f, 0644);
            $row['fix_result'] = 'optimized';
            $info = mp4_inspect($f);
            $row['faststart'] = $info['faststart'];
        } else {
            if (is_file($tmp)) @unlink($tmp);
            $row['fix_result'] = $res === 'optimized' ? 'skipped:replace-failed' : $res;
        }
    }
    if ($info['kind'] === 'mp4' && $row['faststart'] === false) $needFix++;
    if (($info['avg_mbps'] ?? 0) > 4.0) $highBitrate++;

    if ($probeCount < 5) {                                  // keep the report fast: probe the first 5 files
        $probeCount++;
        $row['range_test'] = http_range_probe($siteRoot . '/' . $rel);
        if (isset($row['range_test']['ok']) && $row['range_test']['ok'] === false) $rangeBad++;
    }
    $rows[] = $row;
}

$notes = [];
if ($needFix > 0) $notes[] = $needFix . ' MP4 file(s) have the index at the END (slow start / buffering). Open fix_url to optimize them losslessly.';
if ($highBitrate > 0) $notes[] = $highBitrate . ' video(s) average above 4 Mbps. A server cannot out-run a bitrate higher than the visitor\'s connection: for mobile networks re-export at about 720p, H.264, 1.5-3 Mbps (this is the single biggest factor left once Range + fast start are fine).';
if ($rangeBad > 0) $notes[] = 'Range self-test failed for ' . $rangeBad . ' file(s): check range_test.* (status must be 206, Accept-Ranges bytes, Content-Range present, no Content-Encoding, video/* type). A CDN/proxy or a host-level setting may be altering responses.';
if (!$files) $notes[] = 'No stored product videos found yet.';

json_ok([
    'videos'         => count($rows),
    'need_fast_start'=> $needFix,
    'fixed_now'      => $fix,
    'fix_url'        => $siteRoot . '/api/admin/video-optimize.php?fix=1&t=' . csrf_token(),
    'notes'          => $notes ?: ['All checked videos look good for streaming.'],
    'files'          => $rows,
]);
