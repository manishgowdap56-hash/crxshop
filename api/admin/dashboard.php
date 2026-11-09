<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$pdo = db();

$websiteOrders = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE source = "website" AND status = "completed"')->fetchColumn();
$apiOrders     = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE source = "api" AND status = "completed"')->fetchColumn();
$totalUsers    = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "user"')->fetchColumn();
$activeUsers   = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE last_active_at >= (NOW() - INTERVAL 15 MINUTE)')->fetchColumn();
$revenue       = (float)$pdo->query('SELECT COALESCE(SUM(total_price),0) FROM orders WHERE status = "completed"')->fetchColumn();
$productKeys   = (int)$pdo->query('SELECT COUNT(*) FROM product_keys WHERE status = "available"')->fetchColumn();
$totalKeys     = (int)$pdo->query('SELECT COUNT(*) FROM order_keys')->fetchColumn();
$apiUsers      = (int)$pdo->query('SELECT COUNT(DISTINCT user_id) FROM api_logs')->fetchColumn();
$normalUsers   = max(0, $totalUsers - $apiUsers);
$walletTotal   = (float)$pdo->query('SELECT COALESCE(SUM(balance),0) FROM users')->fetchColumn();
$productCount  = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE enabled = 1')->fetchColumn();

$totalOrders = $websiteOrders + $apiOrders;

// v36: daily financial overview + controller health. Isolated: a failure here can never break the existing cards.
rc_maybe_run($pdo);   // fallback trigger when the cron job is not set up yet (throttled to once / 2 min)
$finance = null;
try { $finance = fin_today($pdo); } catch (Throwable $e) { error_log('[admin/dashboard finance] ' . $e->getMessage()); }
$lastRun = (int)get_setting('refund_controller_last_run', '0');
$apiShare    = $totalUsers > 0 ? (int)round($apiUsers / $totalUsers * 100) : 0;
$siteShare   = $totalOrders > 0 ? (int)round($websiteOrders / $totalOrders * 100) : 0;

json_ok([
    'website_orders'  => $websiteOrders,
    'api_orders'      => $apiOrders,
    'total_users'     => $totalUsers,
    'active_users'    => $activeUsers,
    'revenue'         => $revenue,
    'product_keys'    => $productKeys,
    'total_keys'      => $totalKeys,
    'api_users'       => $apiUsers,
    'normal_users'    => $normalUsers,
    'wallet_total'    => $walletTotal,
    'product_count'   => $productCount,
    'api_share'       => $apiShare,
    'website_share'   => $siteShare,
    'server_time'     => date('D, d M Y H:i:s'),
    // v36 additions (new keys only; every existing key above is unchanged)
    'finance'         => $finance,
    'maintenance_mode' => maintenance_is_on(),
    'controller'      => [
        'last_run' => $lastRun > 0 ? date('Y-m-d H:i:s', $lastRun) : null,
        'stale'    => $finance && ($finance['expired_pending'] ?? 0) > 0 && ($lastRun === 0 || (time() - $lastRun) > 900),
    ],
]);