<?php
declare(strict_types=1);

/**
 * Link-preview / social-share metadata (WhatsApp, Telegram, Facebook/Messenger, X, ...).
 *
 * Rendered SERVER-SIDE inside <head> so crawlers (which do not run JavaScript) see it.
 * Output: meta description, canonical, favicon links, Open Graph and Twitter card tags.
 *
 * Name, logo and URL are never hard-coded:
 *   - site name   : panel_name()            (Admin setting "panel_name")
 *   - favicon     : panel_logo_url()        (Admin logo, same source as the header)
 *   - share image : a dedicated 1200x630 JPEG, SEPARATE from the header logo
 *       1) if an Admin logo is set, it is composed onto the brand canvas once and cached
 *          as a static file  uploads/share/share-<hash>.jpg  (regenerated automatically
 *          when the logo changes; the logo file itself is only read, never modified)
 *       2) otherwise / on any problem, the shipped  assets/images/share-preview.jpg
 *   Social crawlers do not render SVG, so the SVG default logo is never used as og:image.
 *
 * Optional: settings key "share_description" overrides the default description text.
 *
 * Call from a page <head> as:
 *   <?php try { require_once __DIR__ . '/includes/share_meta.php'; echo share_meta_tags(); } catch (Throwable $e) { error_log('[share-meta] ' . $e->getMessage()); } ?>
 * so a problem here can never break the page itself.
 */

const SHARE_IMG_W = 1200;
const SHARE_IMG_H = 630;

/** Absolute URL for crawlers: always https in production (a proxy may hide HTTPS from PHP). */
function share_abs_url(string $path = ''): string
{
    $url = base_url($path);
    if (APP_ENV !== 'development') {
        $url = (string)preg_replace('#^http://#i', 'https://', $url);
    }
    return $url;
}

/** Canonical URL of the page being served: no query string; the site root for index.php. */
function share_page_url(): string
{
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '' || $script === 'index.php') return share_abs_url('');
    return share_abs_url($script);
}

function share_description(string $name): string
{
    $custom = trim((string)get_setting('share_description', ''));
    if ($custom !== '') return mb_substr($custom, 0, 200);
    return 'Sign in to ' . $name . ' — your reseller panel for digital license keys, wallet top-ups and order tracking.';
}

/**
 * Compose the Admin logo onto the brand canvas. Returns the path relative to the site root
 * (e.g. "uploads/share/share-ab12....jpg") or null when it cannot be done (caller falls back).
 */
function share_generate_from_logo(): ?string
{
    $logoRel = ltrim((string)get_setting('logo_path', ''), '/');
    if ($logoRel === '' || strpos($logoRel, '..') !== false) return null;
    $logoAbs = BASE_PATH . '/' . $logoRel;
    if (!is_file($logoAbs) || !function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) return null;

    $key  = substr(sha1($logoRel . '|' . (int)@filemtime($logoAbs) . '|' . (int)@filesize($logoAbs) . '|v1'), 0, 16);
    $rel  = 'uploads/share/share-' . $key . '.jpg';
    $dir  = UPLOAD_PATH . '/share';
    $file = $dir . '/share-' . $key . '.jpg';
    if (is_file($file) && filesize($file) > 0) return $rel;          // already generated

    $dim = @getimagesize($logoAbs);
    if (!$dim || $dim[0] < 1 || $dim[1] < 1 || ($dim[0] * $dim[1]) > 16000000) return null;   // also protects memory_limit

    $src = false;
    if ($dim[2] === IMAGETYPE_PNG)       $src = @imagecreatefrompng($logoAbs);
    elseif ($dim[2] === IMAGETYPE_JPEG)  $src = @imagecreatefromjpeg($logoAbs);
    elseif (defined('IMAGETYPE_WEBP') && $dim[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($logoAbs);
    if (!$src) return null;                                          // e.g. SVG or unsupported format -> fallback

    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { imagedestroy($src); return null; }
    if (!is_writable($dir)) { imagedestroy($src); return null; }

    $W = SHARE_IMG_W; $H = SHARE_IMG_H;
    $canvas = imagecreatetruecolor($W, $H);

    // Same brand colours as assets/images/default-logo.svg: dark green gradient + gold frame.
    for ($y = 0; $y < $H; $y++) {
        $t = $y / ($H - 1);
        $col = imagecolorallocate($canvas, (int)round(11 + (5 - 11) * $t), (int)round(36 + (12 - 36) * $t), (int)round(26 + (10 - 26) * $t));
        imageline($canvas, 0, $y, $W - 1, $y, $col);
    }
    $gold = imagecolorallocate($canvas, 217, 180, 91);
    foreach ([24, 25, 26, 30] as $o) imagerectangle($canvas, $o, $o, $W - 1 - $o, $H - 1 - $o, $gold);

    // Logo: contained in a 440x440 box, centred, aspect ratio kept, upscale limited.
    $sw = (int)$dim[0]; $sh = (int)$dim[1];
    $scale = min(440 / $sw, 440 / $sh, 3.0);
    $nw = max(1, (int)round($sw * $scale));
    $nh = max(1, (int)round($sh * $scale));
    imagealphablending($canvas, true);
    imagecopyresampled($canvas, $src, (int)(($W - $nw) / 2), (int)(($H - $nh) / 2), 0, 0, $nw, $nh, $sw, $sh);
    imagedestroy($src);

    // Write to a temp file then rename, so a crawler never sees a half-written image.
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = @imagejpeg($canvas, $tmp, 86);
    if ($ok && (int)@filesize($tmp) > 280000) $ok = @imagejpeg($canvas, $tmp, 70);   // keep it small for WhatsApp
    imagedestroy($canvas);
    if (!$ok || !is_file($tmp) || filesize($tmp) <= 0) { @unlink($tmp); return null; }
    if (!@rename($tmp, $file)) { @unlink($tmp); return null; }

    foreach (glob($dir . '/share-*.jpg') ?: [] as $old) {           // drop images of previous logos
        if ($old !== $file) @unlink($old);
    }
    return $rel;
}

/** @return array{url:string,type:string,width:int,height:int} */
function share_image_info(): array
{
    static $info = null;
    if ($info !== null) return $info;
    $url = share_abs_url('assets/images/share-preview.jpg');         // shipped, always present
    try {
        $gen = share_generate_from_logo();
        if ($gen !== null) $url = share_abs_url($gen);
    } catch (Throwable $e) {
        error_log('[share-image] ' . $e->getMessage());
    }
    return $info = ['url' => $url, 'type' => 'image/jpeg', 'width' => SHARE_IMG_W, 'height' => SHARE_IMG_H];
}

/** Favicon MIME type from the logo URL's extension ('' when unknown). */
function share_icon_type(string $url): string
{
    $ext = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    return ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$ext] ?? '';
}

/** Full <head> block. Everything is escaped; nothing here needs JavaScript. */
function share_meta_tags(?string $ogTitle = null): string
{
    $name  = panel_name();
    $title = $ogTitle !== null && $ogTitle !== '' ? $ogTitle : (strtoupper($name) . ' — Reseller Panel');
    $desc  = share_description($name);
    $url   = share_page_url();
    $img   = share_image_info();
    $alt   = $name . ' logo';

    $logo     = panel_logo_url();
    if (APP_ENV !== 'development') $logo = (string)preg_replace('#^http://#i', 'https://', $logo);
    $iconType = share_icon_type($logo);

    $t = [];
    $t[] = '<meta name="description" content="' . e($desc) . '">';
    $t[] = '<link rel="canonical" href="' . e($url) . '">';
    // Favicon (browser tab) - independent of the share image below.
    $t[] = '<link rel="icon"' . ($iconType !== '' ? ' type="' . e($iconType) . '"' : '') . ' href="' . e($logo) . '">';
    if ($iconType !== '' && $iconType !== 'image/svg+xml') {
        $t[] = '<link rel="apple-touch-icon" href="' . e($logo) . '">';   // iOS does not support SVG
    }
    // Open Graph
    $t[] = '<meta property="og:type" content="website">';
    $t[] = '<meta property="og:url" content="' . e($url) . '">';
    $t[] = '<meta property="og:title" content="' . e($title) . '">';
    $t[] = '<meta property="og:description" content="' . e($desc) . '">';
    $t[] = '<meta property="og:image" content="' . e($img['url']) . '">';
    $t[] = '<meta property="og:image:secure_url" content="' . e($img['url']) . '">';
    $t[] = '<meta property="og:image:type" content="' . e($img['type']) . '">';
    $t[] = '<meta property="og:image:width" content="' . (int)$img['width'] . '">';
    $t[] = '<meta property="og:image:height" content="' . (int)$img['height'] . '">';
    $t[] = '<meta property="og:image:alt" content="' . e($alt) . '">';
    $t[] = '<meta property="og:site_name" content="' . e($name) . '">';
    // Twitter / X
    $t[] = '<meta name="twitter:card" content="summary_large_image">';
    $t[] = '<meta name="twitter:title" content="' . e($title) . '">';
    $t[] = '<meta name="twitter:description" content="' . e($desc) . '">';
    $t[] = '<meta name="twitter:image" content="' . e($img['url']) . '">';
    $t[] = '<meta name="twitter:image:alt" content="' . e($alt) . '">';

    return implode("\n", $t) . "\n";
}
