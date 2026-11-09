<?php
declare(strict_types=1);

/**
 * Tiny key/value settings accessor with request-level cache.
 * The schema compatibility layer creates the table on legacy installs.
 */
function settings_all(): array
{
    if (isset($GLOBALS['__settings_cache']) && is_array($GLOBALS['__settings_cache'])) {
        return $GLOBALS['__settings_cache'];
    }

    try {
        $rows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $cache = [];
        foreach ($rows as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
        $GLOBALS['__settings_cache'] = $cache;
        return $cache;
    } catch (Throwable $e) {
        error_log('[settings_all] ' . $e->getMessage());
        $GLOBALS['__settings_cache'] = [];
        return [];
    }
}

function get_setting(string $key, ?string $default = null): ?string
{
    $all = settings_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function set_setting(string $key, string $value): void
{
    $sql = 'INSERT INTO settings (setting_key, setting_value, updated_at)
            VALUES (:k, :v, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()';
    db()->prepare($sql)->execute([':k' => $key, ':v' => $value]);
    settings_reload();
}

function settings_reload(): void
{
    unset($GLOBALS['__settings_cache']);
}
