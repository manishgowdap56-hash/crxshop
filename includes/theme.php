<?php
declare(strict_types=1);

/**
 * MASTER FRONTEND CONTROL - theme module (v67).
 *
 * Responsibilities (one file, small functions, no side effects on include):
 *   - catalogue : the configurable colours and fonts
 *   - storage   : ONE row in the existing `settings` table, key `theme_config` (JSON). No schema change.
 *   - validation: only known keys, only #RRGGBB / known font ids are ever stored
 *   - render    : theme_head($side) returns the <style>/<link> block that is echoed INSIDE <head>, after the site's own CSS,
 *                 so the saved theme is part of the first paint (server-side injection, zero flash, no async load)
 *   - reset     : deletes the theme row only. Nothing else in the database is touched.
 *
 * Data model: {"user":{"colors":{key:"#rrggbb"},"font":"id"},"admin":{...}}  (sparse: a missing key = original CSS untouched)
 * Scope "all" is not a third store: it writes the same values into BOTH "user" and "admin", so the two sides stay independent afterwards.
 * A theme error NEVER breaks a page: theme_head() swallows everything and returns '' (= original theme).
 */

const THEME_SETTING_KEY = 'theme_config';
const THEME_SIDES  = ['user', 'admin'];
const THEME_SCOPES = ['all', 'user', 'admin'];

/** key => [label, group, css variable, original value shown in the picker (display only)] */
function theme_color_catalog(): array
{
    return [
        'bg'            => ['Main Website Background', 'Backgrounds', '--bg',               '#0a0f1c'],
        'bg2'           => ['Secondary Background',    'Backgrounds', '--bg2',              '#111a2c'],
        'card'          => ['Primary Card Background', 'Cards',       '--card',             '#101a2c'],
        'card2'         => ['Secondary Card Background','Cards',      '--card2',            '#141f36'],
        'accent'        => ['Primary Golden / Accent', 'Accent',      '--gold',             '#c9a961'],
        'accent2'       => ['Secondary Accent',        'Accent',      '--gold-dark',        '#a3822f'],
        'btn_bg'        => ['Button Background',       'Buttons',     '--kp-btn-bg',        '#c9a961'],
        'btn_text'      => ['Button Text',             'Buttons',     '--kp-btn-text',      '#1c1408'],
        'text'          => ['Primary Text',            'Text',        '--text',             '#f1f4fa'],
        'muted'         => ['Secondary Text',          'Text',        '--muted',            '#8a94ab'],
        'heading'       => ['Heading',                 'Text',        '--kp-heading',       '#f1f4fa'],
        'input_bg'      => ['Input Background',        'Forms',       '--kp-input-bg',      '#111a2c'],
        'input_border'  => ['Input Border',            'Forms',       '--kp-input-border',  '#25324a'],
        'card_border'   => ['Card Border',             'Cards',       '--border',           '#25324a'],
        'table_bg'      => ['Table Background',        'Tables',      '--kp-table-bg',      '#101a2c'],
        'table_head'    => ['Table Header Background', 'Tables',      '--kp-table-head',    '#141f36'],
        'nav_bg'        => ['Navigation Background',   'Navigation',  '--kp-nav-bg',        '#101726'],
        'sidebar_bg'    => ['Sidebar Background',      'Navigation',  '--kp-sidebar-bg',    '#0d1628'],
        'modal_bg'      => ['Modal Background',        'Overlays',    '--kp-modal-bg',      '#101a2c'],
        'success'       => ['Success',                 'Status',      '--green',            '#22c55e'],
        'warning'       => ['Warning',                 'Status',      '--kp-warning',       '#f59e0b'],
        'error'         => ['Error',                   'Status',      '--red',              '#f2545b'],
        'soft_border'   => ['Soft Accent Border',      'Accent',      '--border-soft',      '#c9a961'],
    ];
}

/** id => [label, css font-family stack for body/display, google font query or '' ]. 'original' = no override at all. */
function theme_font_catalog(): array
{
    return [
        'original'  => ['Normal / Original', '', ''],
        // The dashboard's own pairing (reference.css / base.css): Inter for text, Chakra Petch for display. Monospace stays as is.
        'dashboard' => ['Dashboard Font', "'Inter',-apple-system,Segoe UI,Roboto,sans-serif", ''],
        'poppins'   => ['Premium 1 - Poppins',          "'Poppins',sans-serif",          'Poppins:wght@400;500;600;700'],
        'montserrat'=> ['Premium 2 - Montserrat',       "'Montserrat',sans-serif",       'Montserrat:wght@400;500;600;700'],
        'outfit'    => ['Premium 3 - Outfit',           "'Outfit',sans-serif",           'Outfit:wght@400;500;600;700'],
        'playfair'  => ['Premium 4 - Playfair Display', "'Playfair Display',Georgia,serif", 'Playfair+Display:wght@400;500;600;700'],
    ];
}

function theme_empty_side(): array { return ['colors' => [], 'font' => 'original']; }

function theme_valid_hex($v): bool { return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/D', $v) === 1; }

/** Returns the sanitised side config. Unknown keys / bad values are dropped, never stored. */
function theme_sanitize_side($in): array
{
    $out = theme_empty_side();
    if (!is_array($in)) return $out;
    $cat = theme_color_catalog();
    if (isset($in['colors']) && is_array($in['colors'])) {
        foreach ($in['colors'] as $k => $v) {
            if (is_string($k) && isset($cat[$k]) && theme_valid_hex($v)) $out['colors'][$k] = strtolower($v);
        }
    }
    $f = $in['font'] ?? 'original';
    $out['font'] = (is_string($f) && isset(theme_font_catalog()[$f])) ? $f : 'original';
    return $out;
}

/** Whole saved config (both sides). Any problem = both sides original. */
function theme_load(): array
{
    $cfg = ['user' => theme_empty_side(), 'admin' => theme_empty_side()];
    try {
        $raw = (string)get_setting(THEME_SETTING_KEY, '');
        $j = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($j)) foreach (THEME_SIDES as $s) $cfg[$s] = theme_sanitize_side($j[$s] ?? null);
    } catch (Throwable $e) {
        error_log('[theme_load] ' . $e->getMessage());
    }
    return $cfg;
}

function theme_store(array $cfg): void
{
    $clean = [];
    foreach (THEME_SIDES as $s) $clean[$s] = theme_sanitize_side($cfg[$s] ?? null);
    set_setting(THEME_SETTING_KEY, (string)json_encode($clean, JSON_UNESCAPED_SLASHES));
    unset($GLOBALS['__settings_cache']);   // next read in this request sees the new value
}

/**
 * Save for a scope. $replace=true replaces the scope's colours+font with the submitted set (SAVE & APPLY ALL);
 * $replace=false merges only the submitted keys (the per-colour SAVE button). A null colour value removes that override.
 * 'all' writes the same result to both sides; 'user' / 'admin' touch only their own side.
 */
function theme_save(string $scope, array $colors, ?string $font, bool $replace): array
{
    if (!in_array($scope, THEME_SCOPES, true)) throw new InvalidArgumentException('Unknown scope.');
    $cat = theme_color_catalog();
    $add = [];
    foreach ($colors as $k => $v) {
        if (!is_string($k) || !isset($cat[$k])) throw new InvalidArgumentException('Unknown colour setting.');
        if ($v === null || $v === '') { $add[$k] = null; continue; }
        if (!theme_valid_hex($v)) throw new InvalidArgumentException('Colour "' . $cat[$k][0] . '" must be a HEX value like #1a2b3c.');
        $add[$k] = strtolower($v);
    }
    if ($font !== null && !isset(theme_font_catalog()[$font])) throw new InvalidArgumentException('Unknown font.');

    $cfg = theme_load();
    foreach (($scope === 'all' ? THEME_SIDES : [$scope]) as $side) {
        $cur = $cfg[$side];
        if ($replace) $cur['colors'] = [];
        foreach ($add as $k => $v) { if ($v === null) unset($cur['colors'][$k]); else $cur['colors'][$k] = $v; }
        if ($font !== null) $cur['font'] = $font;
        $cfg[$side] = $cur;
    }
    theme_store($cfg);
    return theme_load();
}

/** ALL RESET: removes ONLY the theme row. Users, wallets, deposits, orders, gateway settings are never touched. */
function theme_reset_all(): void
{
    db()->prepare('DELETE FROM settings WHERE setting_key = :k')->execute([':k' => THEME_SETTING_KEY]);
    unset($GLOBALS['__settings_cache']);
}

function theme_rgba(string $hex, float $a): string
{
    return 'rgba(' . hexdec(substr($hex, 1, 2)) . ',' . hexdec(substr($hex, 3, 2)) . ',' . hexdec(substr($hex, 5, 2)) . ',' . $a . ')';
}

/**
 * Selectors that receive each colour. Curated on purpose (not a blind search/replace): only components that exist in this project.
 * Rules are emitted ONLY for colours the admin actually set, so an untouched colour leaves the original CSS byte-for-byte in charge.
 * Functional colours (QR white quiet zone, status badges, key text) are deliberately not listed.
 */
function theme_bridge_rules(string $side, array $c): string
{
    $card  = $side === 'admin'
        ? '.card,.stat-card,.admin-product-card,.admin-api-settings-card,.admin-api-provider-card,.admin-branding-settings-card,.admin-duration-card,.admin-category-card,.admin-feature-card,.ao-card,.admin-break-card'
        : '.card,.stat-card,.store-card,.wallet-card,.deposit-wallet-card,.deposit-add-card,.deposit-history-card,.payment-card,.pay-result-card,.api-card,.key-card,.referral-card,.profile-hero-card,.profile-password-card,.update-card,.sp-card,.auth-card,.register-card';
    $btn   = $side === 'admin'
        ? '.admin-save-product,.admin-small-btn,.btn-indigo'
        : '.btn-indigo,.deposit-pay-btn,.auth-submit,.wallet-add-btn,.join-btn,.referral-claim-btn';
    $input = 'input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=color]):not([type=file]):not([type=button]):not([type=submit]),select,textarea';
    $nav   = $side === 'admin' ? '.admin-header' : '.dash-header,.bottomnav';
    $side_ = $side === 'admin' ? '.admin-drawer' : '.dash-drawer';
    $modal = $side === 'admin' ? '.ao-modal-card' : '.modal-box,.delivery-modal,.plan-picker-sheet';
    $r = [];
    if (isset($c['bg']))         $r[] = 'html,body,body.auth-body,.dash-shell,.balance-page{background:var(--bg)!important}';
    if (isset($c['bg2']))        $r[] = '.admin-shell{background:var(--bg2)}';
    if (isset($c['card']))       $r[] = $card . '{background:var(--card)!important}';
    if (isset($c['card2']))      $r[] = '.key-card-box,.admin-duration-fields,.quickBuyCard{background:var(--card2)!important}';
    if (isset($c['card_border']))$r[] = $card . '{border-color:var(--border)!important}';
    if (isset($c['btn_bg']))     $r[] = $btn . '{background:var(--kp-btn-bg)!important;border-color:var(--kp-btn-bg)!important}';
    if (isset($c['btn_text']))   $r[] = $btn . '{color:var(--kp-btn-text)!important}';
    if (isset($c['text']))       $r[] = 'body{color:var(--text)}';
    if (isset($c['heading']))    $r[] = 'h1,h2,h3,.page-heading,.auth-card-title,.admin-drawer-title{color:var(--kp-heading)!important}';
    if (isset($c['input_bg']))   $r[] = $input . '{background:var(--kp-input-bg)!important}';
    if (isset($c['input_border']))$r[] = $input . '{border-color:var(--kp-input-border)!important}';
    if (isset($c['table_bg']))   $r[] = 'table,.admin-orders-table td,.admin-orders-table-wrap{background:var(--kp-table-bg)!important}';
    if (isset($c['table_head'])) $r[] = 'th,.admin-orders-table th{background:var(--kp-table-head)!important}';
    if (isset($c['nav_bg']))     $r[] = $nav . '{background:var(--kp-nav-bg)!important}';
    if (isset($c['sidebar_bg'])) $r[] = $side_ . '{background:var(--kp-sidebar-bg)!important}' . ($side === 'admin' ? '.admin-nav-btn{background:var(--kp-sidebar-bg)!important}' : '');
    if (isset($c['modal_bg']))   $r[] = $modal . '{background:var(--kp-modal-bg)!important}';
    return implode("\n", $r);
}

/** The <style>/<link> block for one side. '' when nothing is customised (original theme, nothing added to the page). */
function theme_head(string $side): string
{
    try {
        if (!in_array($side, THEME_SIDES, true)) return '';
        $s = theme_load()[$side];
        $cat = theme_color_catalog();
        $vars = [];
        foreach ($s['colors'] as $k => $hex) {
            $v = $cat[$k][2];
            $vars[] = $v . ':' . $hex;
            if ($k === 'accent')  $vars[] = '--gold-soft:' . theme_rgba($hex, .14);
            if ($k === 'success') $vars[] = '--green-soft:' . theme_rgba($hex, .14);
            if ($k === 'error')   $vars[] = '--red-soft:' . theme_rgba($hex, .12);
            if ($k === 'soft_border') { array_pop($vars); $vars[] = '--border-soft:' . theme_rgba($hex, .4); }
        }
        $out = '';
        $fonts = theme_font_catalog();
        $f = $fonts[$s['font']] ?? $fonts['original'];
        if ($s['font'] !== 'original') {
            if ($f[2] !== '') {
                $out .= '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
                      . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=' . $f[2] . '&display=swap">' . "\n";
            }
            $vars[] = '--font-body:' . $f[1];
            if ($s['font'] !== 'dashboard') $vars[] = '--font-display:' . $f[1];   // dashboard option keeps Chakra Petch for display text
        }
        if (!$vars) return '';
        $css = ':root{' . implode(';', $vars) . "}\n" . theme_bridge_rules($side, $s['colors']);
        return $out . '<style id="kp-theme" data-side="' . $side . '">' . "\n" . $css . "\n</style>\n";
    } catch (Throwable $e) {
        error_log('[theme_head] ' . $e->getMessage());
        return '';
    }
}
