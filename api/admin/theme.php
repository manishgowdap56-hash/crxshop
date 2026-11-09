<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
$admin = require_admin();

/*
 * v67 - Master Frontend Control API (admin only; POST needs CSRF)
 *   GET                         saved theme (both sides) + catalogues
 *   POST action=save            scope (all|user|admin), colors {key:#hex|null}, font?, replace (0|1)
 *   POST action=reset_all       removes the theme row ONLY (frontend appearance). No user / wallet / payment data is touched.
 */
header('Cache-Control: no-store');
$catalog = static function (): array {
    $c = []; foreach (theme_color_catalog() as $k => $d) $c[] = ['key' => $k, 'label' => $d[0], 'group' => $d[1], 'original' => $d[3]];
    $f = []; foreach (theme_font_catalog() as $k => $d) $f[] = ['id' => $k, 'label' => $d[0], 'family' => $d[1], 'google' => $d[2]];
    return ['colors' => $c, 'fonts' => $f];
};

if (request_method() === 'GET') json_ok(['theme' => theme_load()] + $catalog());

csrf_verify();
$action = input_str('action');
if ($action === 'save') {
    $scope = input_str('scope');
    $colors = input_param('colors', []);
    if (!is_array($colors)) json_error('Invalid colours.', 422);
    $font = input_param('font', null);
    try {
        $t = theme_save($scope, $colors, is_string($font) && $font !== '' ? $font : null, input_param('replace', '0') == '1');
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 422);
    }
    audit_log('theme.save', 'success', ['actor_id' => (int)$admin['id'], 'reason' => 'scope=' . $scope]);
    json_ok(['theme' => $t]);
}
if ($action === 'reset_all') {
    theme_reset_all();
    audit_log('theme.reset_all', 'success', ['actor_id' => (int)$admin['id']]);
    json_ok(['theme' => theme_load()]);
}
json_error('Unknown action.', 422);
