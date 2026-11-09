<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/*  Output / format helpers                                           */
/* ------------------------------------------------------------------ */

function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money($value): string
{
    return '₹' . number_format((float)$value, 2, '.', '');
}

function now_iso(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

/* ------------------------------------------------------------------ */
/*  Request helpers                                                   */
/* ------------------------------------------------------------------ */

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function input_json(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') { $cache = []; return $cache; }
    $decoded = json_decode($raw, true);
    $cache = is_array($decoded) ? $decoded : [];
    return $cache;
}

function input_param(string $key, $default = null)
{
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key]))  return $_GET[$key];
    $json = input_json();
    if (array_key_exists($key, $json)) return $json[$key];
    return $default;
}

function input_str(string $key, string $default = ''): string
{
    $v = input_param($key, $default);
    if (is_array($v)) return $default;
    return trim((string)$v);
}

function input_int(string $key, int $default = 0): int
{
    return (int)input_param($key, $default);
}

function input_float(string $key, float $default = 0.0): float
{
    return (float)input_param($key, $default);
}

/* ------------------------------------------------------------------ */
/*  Response helpers                                                  */
/* ------------------------------------------------------------------ */

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * v53: optional stale-price guard. The browser may send the unit price it DISPLAYED ('expected_price'). It is never used as the price:
 * the charge always comes from the database. When it differs from the current database price nothing is charged and the caller
 * learns the real price. Returns the new price (float) on mismatch, null when no hint was sent or it matches.
 */
function purchase_price_mismatch(float $dbUnit): ?float
{
    $raw = input_str('expected_price', '');
    if ($raw === '' || !is_numeric($raw)) return null;
    return abs((float)$raw - $dbUnit) > 0.004 ? $dbUnit : null;
}

function json_ok(array $extra = []): void
{
    json_response(array_merge(['ok' => true], $extra));
}

function json_error(string $message, int $status = 400, array $extra = []): void
{
    json_response(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

/* ------------------------------------------------------------------ */
/*  URL helpers (BASE_URL auto-detect if APP_URL blank)               */
/* ------------------------------------------------------------------ */

function base_url(string $path = ''): string
{
    static $root = null;
    if ($root === null) {
        if (APP_URL !== '') {
            // APP_URL may include a deployment sub-folder, e.g. https://example.com/panel
            $root = rtrim(APP_URL, '/');
        } else {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                     || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            $scheme = $https ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

            // Auto-detect the application's deployment directory.  This is
            // essential when the panel is installed at /panel/ rather than /
            // because API responses and JS redirects must stay inside that folder.
            $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $scriptDir = rtrim(str_replace('\\', '/', dirname($script)), '/');
            if (preg_match('#/(?:api)(?:/.*)?$#', $scriptDir)) {
                $scriptDir = dirname($scriptDir);
            }
            $appPath = $scriptDir === '/' ? '' : $scriptDir;
            $root = $scheme . '://' . $host . $appPath;
        }
    }
    return $root . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return base_url('assets/' . ltrim($path, '/'));
}

function upload_url(string $path): string
{
    return base_url('uploads/' . ltrim($path, '/'));
}

/* ------------------------------------------------------------------ */
/*  Redirect                                                          */
/* ------------------------------------------------------------------ */

function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : base_url($path)));
    exit;
}

/* ------------------------------------------------------------------ */
/*  Random ids                                                        */
/* ------------------------------------------------------------------ */

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function order_id(string $prefix = 'ORD'): string
{
    return $prefix . '-' . strtoupper(base_convert((string)time(), 10, 36))
         . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/* ------------------------------------------------------------------ */
/*  Branding helpers                                                  */
/* ------------------------------------------------------------------ */

function panel_name(): string
{
    $name = trim((string)get_setting('panel_name', APP_NAME));
    return $name !== '' ? $name : APP_NAME;
}

function panel_logo_url(): string
{
    $custom = (string)get_setting('logo_path', '');
    if ($custom !== '' && is_file(BASE_PATH . '/' . ltrim($custom, '/'))) {
        $absolute = BASE_PATH . '/' . ltrim($custom, '/');
        $url = base_url($custom);
        $mtime = @filemtime($absolute);
        return $mtime ? ($url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . (int)$mtime) : $url;
    }
    return asset('images/default-logo.svg');
}

/* ------------------------------------------------------------------ */
/*  Dashboard display (3 slides) + Telegram support                   */
/* ------------------------------------------------------------------ */

/** Slides that are configured AND exist on disk, in order: [['n'=>1,'url'=>'...'], ...] */
function display_slides(): array
{
    $out = [];
    for ($n = 1; $n <= 3; $n++) {
        $rel = (string)get_setting('display_slide_' . $n, '');
        if ($rel === '' || strpos($rel, 'uploads/display/') !== 0) continue;
        $abs = BASE_PATH . '/' . $rel;
        if (!is_file($abs)) continue;
        $url = base_url($rel);
        $mtime = @filemtime($abs);
        $out[] = ['n' => $n, 'url' => $mtime ? $url . '?v=' . (int)$mtime : $url];
    }
    return $out;
}

/** Normalise "name", "@name", "t.me/name" or "https://t.me/name". '' = empty, null = invalid. */
function normalize_telegram_username(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    $raw = preg_replace('#^(https?://)?(www\.)?(t\.me|telegram\.me)/#i', '', $raw);
    if (isset($raw[0]) && $raw[0] === '@') $raw = substr($raw, 1);
    // Telegram usernames: 5-32 chars, letters/digits/underscore, must start with a letter.
    return preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $raw) ? $raw : null;
}

/** The configured (and valid) support username, or '' when not set. */
function telegram_support_username(): string
{
    $v = normalize_telegram_username((string)get_setting('telegram_username', ''));
    return $v ?? '';
}

/* ------------------------------------------------------------------ */
/*  Client IP                                                         */
/* ------------------------------------------------------------------ */

function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', (string)$_SERVER[$k])[0];
            return trim($ip);
        }
    }
    return '0.0.0.0';
}

/* ------------------------------------------------------------------ */
/*  Store UI helpers (product image, feature sections, global links)  */
/* ------------------------------------------------------------------ */

/** Public URL of a stored product image, or '' when none/missing. Only files inside uploads/products. */
function product_image_url(?string $rel): string
{
    $rel = (string)$rel;
    if ($rel === '' || strpos($rel, 'uploads/products/') !== 0 || strpos($rel, '..') !== false) return '';
    $abs = BASE_PATH . '/' . $rel;
    if (!is_file($abs)) return '';
    $mtime = @filemtime($abs);
    $url = base_url($rel);
    return $mtime ? $url . '?v=' . (int)$mtime : $url;
}

/** Public URL of a stored product video, or '' when none/missing. Only files inside uploads/product-videos. */
function product_video_url(?string $rel): string
{
    $rel = (string)$rel;
    if ($rel === '' || strpos($rel, 'uploads/product-videos/') !== 0 || strpos($rel, '..') !== false) return '';
    $abs = BASE_PATH . '/' . $rel;
    if (!is_file($abs)) return '';
    $mtime = @filemtime($abs);
    $url = base_url($rel);
    return $mtime ? $url . '?v=' . (int)$mtime : $url;
}

/** Decode the stored features JSON into [['header'=>string,'items'=>string[]], ...]. Never throws. */
function product_features_decode(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') return [];
    $data = json_decode($json, true);
    return is_array($data) ? product_features_clean($data) : [];
}

/** Sanitise admin-supplied feature sections (bounded count/length, plain text only). */
function product_features_clean(array $in): array
{
    $out = [];
    foreach ($in as $sec) {
        if (!is_array($sec)) continue;
        $header = trim(mb_substr(strip_tags((string)($sec['header'] ?? '')), 0, 80));
        $items = [];
        $rawItems = $sec['items'] ?? [];
        if (is_string($rawItems)) $rawItems = preg_split('/\r?\n/', $rawItems);
        if (!is_array($rawItems)) $rawItems = [];
        foreach ($rawItems as $it) {
            $t = trim(mb_substr(strip_tags((string)$it), 0, 200));
            if ($t !== '') $items[] = $t;
            if (count($items) >= 40) break;
        }
        if ($header === '' && !$items) continue;
        $out[] = ['header' => $header, 'items' => $items];
        if (count($out) >= 20) break;
    }
    return $out;
}

/** A global Store link (update file / feedback). Only http(s) URLs are ever returned. */
function store_link_setting(string $key): string
{
    $v = trim((string)get_setting($key, ''));
    return preg_match('#^https?://#i', $v) ? $v : '';
}
