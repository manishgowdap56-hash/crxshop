<?php
/**
 * ROYAL PANEL — global configuration.
 * Never commit real credentials to git. This file loads first.
 */
declare(strict_types=1);

// ---- Database (YOUR cPanel credentials) -----------------------------
define('DB_HOST', getenv('ROYALPANEL_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('ROYALPANEL_DB_NAME') ?: 'wrtmmole_royalpanel');
define('DB_USER', getenv('ROYALPANEL_DB_USER') ?: 'wrtmmole_royalpanel');
define('DB_PASS', getenv('ROYALPANEL_DB_PASS') ?: 'Hanif816101');
define('DB_PORT', (int)(getenv('ROYALPANEL_DB_PORT') ?: 3306));
define('DB_CHARSET', getenv('ROYALPANEL_DB_CHARSET') ?: 'utf8mb4');

// ---- Application ----------------------------------------------------
define('APP_NAME', 'Keys Panel');
define('APP_URL', '');                 // leave blank to auto-detect host
define('APP_ENV', 'production');       // switch to 'development' while testing
define('APP_TIMEZONE', 'Asia/Kolkata');

// ---- Paths ----------------------------------------------------------
define('BASE_PATH', dirname(__DIR__));
define('UPLOAD_PATH', BASE_PATH . '/uploads');
define('LOGO_PATH', UPLOAD_PATH . '/logo');
define('UPLOAD_URL', '/uploads');

// ---- Sessions -------------------------------------------------------
define('SESSION_NAME', 'royal_panel_sid');
define('SESSION_LIFETIME', 60 * 60 * 24 * 30); // persistent browser session lifetime; explicit logout or cleared browser data ends it
define('SESSION_IDLE_TIMEOUT', 0); // disabled: sessions persist until explicit logout/browser data is cleared

// ---- Initial admin bootstrap ---------------------------------------
// Change these two values before production deployment.
// On first admin login, the account is created in MySQL if it does not exist.
// If the configured admin already exists, its password hash is synchronized.
define('ADMIN_BOOTSTRAP_USERNAME', 'admin');
define('ADMIN_BOOTSTRAP_PASSWORD', 'covtdgjk_owenpanel');

// ---- Security -------------------------------------------------------
define('CSRF_KEY', 'csrf_token');
define('PASSWORD_MIN', 6);

// ---- Upstream API default -------------------------------------------
define('RESELLER_API_DEFAULT_ENDPOINT', 'https://keypanel.shop/reseller_gateway.php');

// ---- Deposits -------------------------------------------------------
// Paytm deposit limits are now stored in the settings table (admin-editable) and
// default to the source values: min 10 / max 100000. Read them with deposit_min()
// and deposit_max() (includes/paytm.php). The payment window is PAYTM_PAYMENT_WINDOW
// (180 seconds). The constants below are kept only for backward compatibility.
define('DEPOSIT_MIN', 10);
define('DEPOSIT_MAX', 100000);
define('DEPOSIT_QR_TTL', 180); // seconds

// ---- Referral -------------------------------------------------------
// First-run DEFAULT only. The active percentage is stored in the database (Admin > Referral Commission) and is what every purchase path uses.
define('REFERRAL_PERCENT', 5.0);

// ---- Error reporting ------------------------------------------------
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('log_errors', '1');
}

date_default_timezone_set(APP_TIMEZONE);