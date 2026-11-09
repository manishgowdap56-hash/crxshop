<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
ensure_configured_admin();
$adminUser = require_admin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e(panel_name()) ?> — Admin</title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_url()) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
<?php $adminCssBaseVer = is_file(__DIR__ . '/assets/css/base.css') ? (string)filemtime(__DIR__ . '/assets/css/base.css') : '1'; ?>
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>?v=<?= e($adminCssBaseVer) ?>">
<?php $adminCssVer = is_file(__DIR__ . '/assets/css/admin.css') ? (string)filemtime(__DIR__ . '/assets/css/admin.css') : '1'; ?>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>?v=<?= e($adminCssVer) ?>">
<?php $adminRefVer = is_file(__DIR__ . '/assets/css/reference.css') ? (string)filemtime(__DIR__ . '/assets/css/reference.css') : '1'; ?>
<link rel="stylesheet" href="<?= e(asset('css/reference.css')) ?>?v=<?= e($adminRefVer) ?>">
<?php $adminNavVer = is_file(__DIR__ . '/assets/css/admin-navigation-fix.css') ? (string)filemtime(__DIR__ . '/assets/css/admin-navigation-fix.css') : '1'; ?>
<link rel="stylesheet" href="<?= e(asset('css/admin-navigation-fix.css')) ?>?v=<?= e($adminNavVer) ?>">
<?php $rmCssVer = is_file(__DIR__ . '/assets/css/reseller-membership.css') ? (string)filemtime(__DIR__ . '/assets/css/reseller-membership.css') : '1'; ?>
<link rel="stylesheet" href="<?= e(asset('css/reseller-membership.css')) ?>?v=<?= e($rmCssVer) ?>">
<?php $psCss = is_file(__DIR__ . '/assets/css/price-sync.css') ? (string)filemtime(__DIR__ . '/assets/css/price-sync.css') : '1'; ?>
<link rel="stylesheet" href="<?= e(asset('css/price-sync.css')) ?>?v=<?= e($psCss) ?>">
<?php $opsV36Css = is_file(__DIR__ . '/assets/css/ops-v36.css') ? (string)filemtime(__DIR__ . '/assets/css/ops-v36.css') : '1'; ?>
<link rel="stylesheet" href="<?= e(asset('css/ops-v36.css')) ?>?v=<?= e($opsV36Css) ?>">
<?php $refV37Css = is_file(__DIR__ . '/assets/css/referral-admin-v37.css') ? (string)filemtime(__DIR__ . '/assets/css/referral-admin-v37.css') : '1'; ?>
<link rel="stylesheet" href="<?= e(asset('css/referral-admin-v37.css')) ?>?v=<?= e($refV37Css) ?>">
<link rel="icon" type="image/jpeg" href="<?= e(panel_logo_url()) ?>">
<?php if (function_exists('theme_head')) { echo theme_head(empty($_GET['safe']) ? 'admin' : ''); } /* v67 Master Frontend Control: saved theme injected last, inside <head> -> first paint, no flash */ ?>
</head>
<body class="auth-body" data-page="admin">
<div id="globalToastStack"></div>
<section aria-hidden="false" class="admin-screen active" id="adminScreen">
<div class="admin-shell">
<header class="admin-header">
<button aria-expanded="false" aria-label="Open admin menu" class="admin-menu" id="adminMenu" type="button"><span></span><span></span><span></span></button>
<img alt="<?= e(panel_name()) ?>" class="admin-logo" id="adminLogo" data-global-logo src="<?= e(panel_logo_url()) ?>"/>
<div class="admin-brand"><h1 class="admin-brand-title">ROYAL <span>ADMIN</span></h1><div class="admin-brand-sub">COMMAND CENTER • ADMINISTRATOR</div></div>
<div class="admin-status"><span class="admin-status-dot"></span>ONLINE</div>
</header>
</div>
<section aria-hidden="false" class="admin-dashboard-page active" id="adminDashboardPage">
<main class="admin-content">
<div class="admin-welcome">
<div><h2>Welcome, <span><?= e(explode(' ', $adminUser['username'])[0]) ?></span> 👑</h2><p>LIVE LOCAL OVERVIEW • ADMIN DASHBOARD</p></div>
<div class="admin-time" id="adminCurrentTime">--</div>
</div>
<div class="fin-banner fin-banner-warn" id="adminMaintBanner" hidden>🛠 MAINTENANCE MODE IS ON — users are blocked and new website / API orders are refused. Admin tools, status checks and refunds keep working.</div>
<div class="fin-banner fin-banner-warn" id="adminControllerBanner" hidden>⚠ Expired pending orders exist but the Refund Controller has not run for 15+ minutes. Set up the cron job (see README_V36.md) or open Pending Orders.</div>
<section class="admin-kpi-grid">
<article class="admin-kpi gold"><div class="admin-kpi-icon">🛒</div><div class="admin-kpi-label">WEBSITE ORDERS</div><div class="admin-kpi-value" id="adminWebsiteOrders">0</div><div class="admin-kpi-note">Orders placed from Store</div></article>
<article class="admin-kpi blue"><div class="admin-kpi-icon">⚡</div><div class="admin-kpi-label">API ORDERS</div><div class="admin-kpi-value" id="adminApiOrders">0</div><div class="admin-kpi-note">Orders received via API</div></article>
<article class="admin-kpi green"><div class="admin-kpi-icon">👥</div><div class="admin-kpi-label">TOTAL USERS</div><div class="admin-kpi-value" id="adminTotalUsers">0</div><div class="admin-kpi-note">Registered reseller accounts</div></article>
<article class="admin-kpi green"><div class="admin-kpi-icon">🟢</div><div class="admin-kpi-label">ACTIVE USERS</div><div class="admin-kpi-value" id="adminActiveUsers">0</div><div class="admin-kpi-note">Active in the last 15 minutes</div></article>
<article class="admin-kpi purple"><div class="admin-kpi-icon">💰</div><div class="admin-kpi-label">TOTAL REVENUE</div><div class="admin-kpi-value" id="adminRevenue">₹0.00</div><div class="admin-kpi-note">Recorded customer spend</div></article>
<article class="admin-kpi gold"><div class="admin-kpi-icon">🔑</div><div class="admin-kpi-label">TOTAL PRODUCT KEYS</div><div class="admin-kpi-value" id="adminProductKeys">0</div><div class="admin-kpi-note">All keys currently stocked in products</div></article>
</section>
<div class="fin-head"><div class="admin-panel-title">TODAY'S FINANCIAL OVERVIEW</div><div class="fin-sub" id="finSub">Loading…</div></div>
<section class="admin-kpi-grid fin-grid" id="adminFinanceGrid">
<article class="admin-kpi green"><div class="admin-kpi-icon">⬇️</div><div class="admin-kpi-label">TODAY'S DEPOSITS</div><div class="admin-kpi-value" id="finDeposits">—</div><div class="admin-kpi-note" id="finDepositsNote">Completed wallet deposits</div></article>
<article class="admin-kpi gold"><div class="admin-kpi-icon">🧾</div><div class="admin-kpi-label">TODAY'S SALES</div><div class="admin-kpi-value" id="finSales">—</div><div class="admin-kpi-note" id="finSalesNote">Completed orders</div></article>
<article class="admin-kpi blue"><div class="admin-kpi-icon">⬆️</div><div class="admin-kpi-label">TODAY'S USER WITHDRAWALS</div><div class="admin-kpi-value" id="finWithdrawals">N/A</div><div class="admin-kpi-note">No withdrawal feature in this project</div></article>
<article class="admin-kpi purple"><div class="admin-kpi-icon">🛍️</div><div class="admin-kpi-label">TODAY'S USER SPENDING</div><div class="admin-kpi-value" id="finSpending">—</div><div class="admin-kpi-note">Wallet debits for completed purchases</div></article>
<article class="admin-kpi green"><div class="admin-kpi-icon">🙋</div><div class="admin-kpi-label">UNIQUE PURCHASING USERS</div><div class="admin-kpi-value" id="finBuyers">—</div><div class="admin-kpi-note">Distinct users with a completed purchase</div></article>
<article class="admin-kpi blue"><div class="admin-kpi-icon">⏳</div><div class="admin-kpi-label">PENDING WITHDRAWAL AMOUNT</div><div class="admin-kpi-value" id="finPendingWd">N/A</div><div class="admin-kpi-note">No withdrawal feature in this project</div></article>
<article class="admin-kpi gold"><div class="admin-kpi-icon">↩️</div><div class="admin-kpi-label">TODAY'S REFUND TOTAL</div><div class="admin-kpi-value" id="finRefundTotal">—</div><div class="admin-kpi-note">Refunds credited to wallets</div></article>
<article class="admin-kpi purple"><div class="admin-kpi-icon">#️⃣</div><div class="admin-kpi-label">TODAY'S REFUND COUNT</div><div class="admin-kpi-value" id="finRefundCount">—</div><div class="admin-kpi-note">Processed refunds</div></article>
<article class="admin-kpi gold fin-clickable" data-go="pending-orders" tabindex="0" role="button"><div class="admin-kpi-icon">📋</div><div class="admin-kpi-label">PENDING ORDERS</div><div class="admin-kpi-value" id="finPending">—</div><div class="admin-kpi-note">Open Pending Orders ›</div></article>
<article class="admin-kpi blue fin-clickable" data-go="pending-orders" tabindex="0" role="button" id="finExpiredCard"><div class="admin-kpi-icon">⏰</div><div class="admin-kpi-label">EXPIRED PENDING ORDERS</div><div class="admin-kpi-value" id="finExpired">—</div><div class="admin-kpi-note" id="finExpiredNote">Past the processing window</div></article>
</section>
<section class="admin-section-grid">
<section class="admin-panel">
<div class="admin-panel-head"><div class="admin-panel-title">USER &amp; ORDER BREAKDOWN</div><div class="admin-panel-badge">LIVE DATA</div></div>
<div class="admin-breakdown">
<div class="admin-break-card"><div class="admin-break-icon">👤</div><div class="admin-break-label">NORMAL USERS</div><div class="admin-break-value" id="adminNormalUsers">0</div><div class="admin-break-sub">Website reseller users</div></div>
<div class="admin-break-card"><div class="admin-break-icon">🔌</div><div class="admin-break-label">API USERS</div><div class="admin-break-value" id="adminApiUsers">0</div><div class="admin-break-sub">Users with API activity</div></div>
<div class="admin-break-card"><div class="admin-break-icon">🔑</div><div class="admin-break-label">TOTAL KEYS</div><div class="admin-break-value" id="adminTotalKeys">0</div><div class="admin-break-sub">Recorded website/API keys</div></div>
</div>
<div class="admin-progress-row"><div class="admin-progress-head"><span>API USER SHARE</span><strong id="adminApiShare">0%</strong></div><div class="admin-progress-track"><div class="admin-progress-bar" id="adminApiShareBar"></div></div></div>
<div class="admin-progress-row"><div class="admin-progress-head"><span>WEBSITE ORDER SHARE</span><strong id="adminWebsiteShare">0%</strong></div><div class="admin-progress-track"><div class="admin-progress-bar" id="adminWebsiteShareBar"></div></div></div>
<div class="admin-note">Server-side data — loaded live from MySQL. All operations are executed through admin-only PHP endpoints.</div>
</section>
<section class="admin-panel">
<div class="admin-panel-head"><div class="admin-panel-title">SYSTEM SNAPSHOT</div><div class="admin-panel-badge"><?= e(strtoupper(panel_name())) ?></div></div>
<div class="admin-live-list">
<div class="admin-live-row"><div class="admin-live-icon">🏪</div><div class="admin-live-main"><strong>Store Order System</strong><span>WEBSITE PURCHASE FLOW</span></div><div class="admin-live-value" id="adminStoreStatus">READY</div></div>
<div class="admin-live-row"><div class="admin-live-icon">🔗</div><div class="admin-live-main"><strong>Reseller API</strong><span>API ORDER CHANNEL</span></div><div class="admin-live-value" id="adminApiStatus">READY</div></div>
<div class="admin-live-row"><div class="admin-live-icon">💳</div><div class="admin-live-main"><strong>Wallet System</strong><span>USER BALANCE</span></div><div class="admin-live-value" id="adminWalletTotal">₹0.00</div></div>
<div class="admin-live-row"><div class="admin-live-icon">📦</div><div class="admin-live-main"><strong>Product Catalog</strong><span>PUBLISHED PRODUCTS</span></div><div class="admin-live-value" id="adminProductCount">0</div></div>
</div>
</section>
</main>
</section>
<section aria-hidden="true" class="admin-users-page" id="adminUsersPage">
<div class="admin-users-top">
<div><h2 class="admin-users-title">USER <span>MANAGEMENT</span></h2><p class="admin-users-sub">SEARCH • REVIEW • BALANCE • ACCESS CONTROL</p></div>
<div class="admin-users-count" id="adminUsersCount">0 USERS</div>
</div>
<input autocomplete="off" class="admin-users-search" id="adminUserSearch" placeholder="Search by User ID / Username..." type="search"/>
<div class="admin-membership-bar">
<select id="adminUserType" aria-label="Filter by account type"><option value="">ALL USERS</option><option value="normal">NORMAL USERS</option><option value="reseller">RESELLERS</option></select>
<button type="button" id="adminBulkReseller" class="admin-user-action success">BULK: GRANT RESELLER (IDs)</button>
<button type="button" id="adminBulkNormal" class="admin-user-action danger">BULK: REVOKE RESELLER (IDs)</button>
</div>
<div class="admin-membership-card" id="adminMembershipCard"></div>
<div class="admin-users-layout">
<div class="admin-user-list" id="adminUserList"></div>
<aside class="admin-user-detail empty" id="adminUserDetail">Select a user to view complete account details.</aside>
</div>
</section>
<section aria-hidden="true" class="admin-products-page" id="adminProductsPage">
<div class="admin-products-top">
<div><h2 class="admin-products-title">CREATE <span>PRODUCT</span></h2><p class="admin-products-sub">CATEGORY • PRODUCT • DURATION • PRICE • KEY INVENTORY</p></div>
</div>
<div class="admin-product-grid">
<section class="admin-product-editor">
<div class="admin-product-section-head"><div><b>PRODUCT BUILDER</b><small>Create a product and add one or more duration plans.</small></div><span class="admin-live-pill">LOCAL + API PROVIDER</span></div>
<div class="admin-category-create-box">
<div class="admin-field"><label>CREATE NEW CATEGORY</label><input id="adminNewCategoryName" placeholder="Enter category name" type="text"/></div>
<div class="admin-field"><label>CATEGORY POSITION (OPTIONAL)</label><input id="adminNewCategoryPosition" inputmode="numeric" placeholder="1 = first. Empty = last" type="number" step="1"/></div>
<button class="admin-small-btn gold admin-save-category-btn" id="adminSaveCategory" type="button">SAVE CATEGORY</button>
<div class="admin-category-manage-actions"><button class="admin-small-btn" id="adminEditCategory" type="button">EDIT SELECTED</button><button class="admin-small-btn danger" id="adminDeleteCategory" type="button">DELETE SELECTED</button></div>
</div>
<div class="admin-field"><label>CATEGORY SELECTED</label><select id="adminProductCategory"><option value="">SELECT CATEGORY</option></select></div>
<div class="admin-field"><label>PRODUCT NAME</label><input id="adminProductName" placeholder="Enter product name" type="text"/><div class="admin-input-actions"><button class="admin-small-btn" id="adminEditSelectedProduct" type="button">EDIT SELECTED</button></div></div>
<div class="admin-field admin-store-image-field">
<label>PRODUCT IMAGE (SHOWN ON STORE CARD)</label>
<div class="admin-store-image-preview"><img alt="Product image preview" hidden id="adminProductImagePreview"/><span id="adminProductImageEmpty">NO IMAGE</span></div>
<input accept="image/png,image/jpeg,image/webp" hidden id="adminProductImageFile" type="file"/>
<div class="admin-input-actions"><button class="admin-small-btn" id="adminProductImagePick" type="button">UPLOAD IMAGE</button><button class="admin-small-btn danger" id="adminProductImageClear" type="button">REMOVE IMAGE</button></div>
<small class="admin-store-hint">PNG / JPG / WEBP. Auto-optimised and fitted to the card without stretching. Applied when you press SAVE PRODUCT.</small>
</div>
<div class="admin-field"><label>DISPLAY ORDER (STORE POSITION)</label><input id="adminProductOrder" inputmode="numeric" placeholder="1 = first in Store. Empty = last" type="number" step="1"/><small class="admin-store-hint">Lower numbers appear first. Equal numbers fall back to creation order.</small></div>
<div class="admin-field admin-store-video-field">
<label>PRODUCT VIDEO (OPTIONAL)</label>
<div class="admin-store-image-preview"><video controls controlslist="nodownload noremoteplayback" disablepictureinpicture disableremoteplayback hidden id="adminProductVideoPreview" muted playsinline preload="metadata"></video><span id="adminProductVideoEmpty">NO VIDEO</span></div>
<input accept="video/mp4,video/webm" hidden id="adminProductVideoFile" type="file"/>
<div class="admin-input-actions"><button class="admin-small-btn" id="adminProductVideoPick" type="button">UPLOAD VIDEO</button><button class="admin-small-btn danger" id="adminProductVideoClear" type="button">REMOVE VIDEO</button></div>
<div class="admin-video-progress" hidden id="adminProductVideoProgress"><i></i></div>
<small class="admin-store-hint" id="adminProductVideoHint">MP4 (H.264 + AAC) recommended; WebM also works. No app size/length limit: your hosting upload limits apply. Applied when you press SAVE PRODUCT.</small>
</div>
<div class="admin-field admin-store-features-field">
<label>FEATURE SECTIONS (SHOWN ON STORE CARD)</label>
<div id="adminFeatureBuilder"></div>
<div class="admin-input-actions"><button class="admin-small-btn gold" id="adminAddFeature" type="button">+ ADD FEATURE SECTION</button></div>
<small class="admin-store-hint">Each section has a header and one feature per line. Leave empty to show no feature box.</small>
</div>
<div class="admin-duration-builder" id="adminDurationBuilder"></div>
<div class="admin-add-duration-row"><button class="admin-add-duration" id="adminAddDuration" type="button">+ ADD ANOTHER DURATION</button></div>
<div class="admin-save-row"><button class="admin-save-product" id="adminSaveProduct" type="button">SAVE PRODUCT</button></div>
<div class="admin-product-message" id="adminProductMessage"></div>
</section>
<section class="admin-key-restock">
<div class="admin-product-section-head"><div><b>RESTOCK EXISTING DURATION</b><small>Select a product + duration and paste multiple keys.</small></div></div>
<div class="admin-form-row">
<div class="admin-field"><label>PRODUCT</label><select id="adminRestockProduct"><option value="">SELECT PRODUCT</option></select></div>
<div class="admin-field"><label>DURATION</label><select disabled="" id="adminRestockDuration"><option value="">SELECT DURATION</option></select></div>
</div>
<div class="admin-field" id="adminRestockKeysWrap"><label>ADD KEYS</label><textarea id="adminRestockKeys" placeholder="Paste one key per line" rows="7"></textarea></div>
<button class="admin-save-product" id="adminRestockBtn" type="button">ADD KEYS TO STOCK</button>
<div class="admin-product-message" id="adminRestockMessage"></div>
</section>
</div>
<section class="admin-product-list-section admin-category-list-section">
<div class="admin-product-list-head"><div><b>ALL CATEGORIES</b><small>Edit or delete categories created in the Product Builder.</small></div><span id="adminCategoryCountLabel">0 CATEGORIES</span></div>
<div class="admin-category-cards" id="adminCategoryCards"></div>
</section>
<section class="admin-product-list-section">
<div class="admin-product-list-head"><div><b>ALL PRODUCTS</b><small>Manage products, durations, stock, visibility and maintenance mode.</small></div><span id="adminProductCountLabel">0 PRODUCTS</span></div>
<div class="admin-product-search"><span aria-hidden="true">🔍</span><input autocomplete="off" id="adminProductSearch" placeholder="Search Products" type="search"/><button hidden id="adminProductSearchClear" type="button">CLEAR</button></div>
<div class="admin-product-cards" id="adminProductCards"></div>
</section>
</section>
<section aria-hidden="true" class="admin-orders-page" id="adminWebsiteOrdersPage">
<div class="admin-orders-wrap">
<div class="admin-orders-top">
<div><h2 class="admin-orders-title">WEBSITE <span>ORDERS</span></h2><p class="admin-orders-sub">LOCAL STORE • INVENTORY • SALES • SELLING PRICE • ORDER HISTORY</p></div>
<div class="admin-orders-badge">🏪 LOCAL STORE</div>
</div>
<div class="admin-orders-summary">
<div class="admin-orders-stat"><div class="admin-orders-stat-label">TOTAL SITE ORDERS</div><div class="admin-orders-stat-value gold" id="adminSiteOrderCount">0</div><div class="admin-orders-stat-sub">Completed website orders</div></div>
<div class="admin-orders-stat"><div class="admin-orders-stat-label">KEYS SOLD</div><div class="admin-orders-stat-value" id="adminSiteKeysSold">0</div><div class="admin-orders-stat-sub">Local-store keys delivered</div></div>
<div class="admin-orders-stat"><div class="admin-orders-stat-label">SALES VALUE</div><div class="admin-orders-stat-value gold" id="adminSiteSalesValue">₹0.00</div><div class="admin-orders-stat-sub">Total website sales</div></div>
<div class="admin-orders-stat"><div class="admin-orders-stat-label">AVAILABLE STOCK</div><div class="admin-orders-stat-value" id="adminSiteAvailableStock">0</div><div class="admin-orders-stat-sub">Current local inventory</div></div>
</div>
<section class="admin-orders-panel">
<div class="admin-orders-panel-head"><div><b>LOCAL STORE INVENTORY BY DURATION</b><small>Every local product + duration shows current available keys, sold quantity and actual selling price.</small></div></div>
<div id="adminLocalInventoryTable"></div>
</section>
<section class="admin-orders-panel">
<div class="admin-orders-panel-head"><div><b>WEBSITE ORDER HISTORY</b><small>Orders placed through the normal Store checkout.</small></div><select class="admin-order-filter" id="adminWebsiteOrderFilter"><option value="all">ALL PRODUCTS</option></select></div>
<div id="adminWebsiteOrderHistory"></div>
</section>
</div>
</section>
<section aria-hidden="true" class="admin-orders-page" id="adminApiOrdersPage">
<div class="admin-orders-wrap">
<div class="admin-orders-top">
<div><h2 class="admin-orders-title">API <span>ORDERS</span></h2><p class="admin-orders-sub">API PURCHASES • USER • PRODUCT • VARIANT • QUANTITY • SELLING PRICE • PROVIDER ORDER</p></div>
<div class="admin-orders-badge">⚡ API CHANNEL</div>
</div>
<div class="admin-orders-summary">
<div class="admin-orders-stat"><div class="admin-orders-stat-label">TOTAL API ORDERS</div><div class="admin-orders-stat-value gold" id="adminApiOrderPageCount">0</div><div class="admin-orders-stat-sub">Recorded API orders</div></div>
<div class="admin-orders-stat"><div class="admin-orders-stat-label">API KEYS SOLD</div><div class="admin-orders-stat-value" id="adminApiKeysSold">0</div><div class="admin-orders-stat-sub">Keys delivered via API</div></div>
<div class="admin-orders-stat"><div class="admin-orders-stat-label">API SALES VALUE</div><div class="admin-orders-stat-value gold" id="adminApiSalesValue">₹0.00</div><div class="admin-orders-stat-sub">Customer-side order value</div></div>
<div class="admin-orders-stat"><div class="admin-orders-stat-label">API USERS</div><div class="admin-orders-stat-value" id="adminApiUsersCount">0</div><div class="admin-orders-stat-sub">Users with API activity</div></div>
</div>
<section class="admin-orders-panel">
<div class="admin-orders-panel-head"><div><b>API ORDER LIST</b><small>Each API order is kept separate from normal website/local-store orders.</small></div></div>
<div id="adminApiOrderHistory"></div>
</section>
</div>
</section>
<section aria-hidden="true" class="admin-orders-page" id="adminAllOrdersPage">
<div class="admin-orders-wrap">
<div class="admin-orders-top">
<div><h2 class="admin-orders-title">ALL <span>ORDERS</span></h2><p class="admin-orders-sub">WEBSITE + API • KEYS • USERS • SEARCH • BAN CONTROL</p></div>
<div class="admin-orders-badge">🗂 UNIFIED</div>
</div>
<div class="ao-summary" id="aoSummary"></div>
<div class="ao-search-grid">
<div class="ao-search ao-search-key"><span aria-hidden="true">🔍</span><input autocomplete="off" id="aoKey" placeholder="Search Key (full or partial)" type="search"/></div>
<div class="ao-search"><span aria-hidden="true">👤</span><input autocomplete="off" id="aoUser" placeholder="Search Username / User ID" type="search"/></div>
<div class="ao-search"><span aria-hidden="true">#</span><input autocomplete="off" id="aoOrder" placeholder="Search Order ID" type="search"/></div>
</div>
<div class="ao-filters">
<div class="ao-seg" id="aoSource"><button class="on" data-v="all" type="button">ALL</button><button data-v="website" type="button">WEBSITE</button><button data-v="api" type="button">API</button></div>
<select id="aoStatus" aria-label="Status"><option value="all">ALL STATUS</option><option value="completed">COMPLETED</option><option value="pending">PENDING</option><option value="failed">FAILED</option><option value="refunded">REFUNDED</option></select>
<select id="aoProvider" aria-label="Provider"><option value="all">ALL PROVIDERS</option></select>
<select id="aoDate" aria-label="Date"><option value="all">ALL DATES</option><option value="today">TODAY</option><option value="yesterday">YESTERDAY</option><option value="7d">LAST 7 DAYS</option><option value="30d">LAST 30 DAYS</option><option value="custom">CUSTOM RANGE</option></select>
<select id="aoSort" aria-label="Sort"><option value="newest">NEWEST</option><option value="oldest">OLDEST</option><option value="highest">HIGHEST AMOUNT</option><option value="lowest">LOWEST AMOUNT</option></select>
<button class="ao-clear" id="aoReset" type="button">RESET</button>
</div>
<div class="ao-custom" hidden id="aoCustom"><label>FROM <input id="aoFrom" type="date"/></label><label>TO <input id="aoTo" type="date"/></label></div>
<div class="ao-count" id="aoCount">Loading…</div>
<div class="ao-list" id="aoList"></div>
<div class="ao-pager" id="aoPager"></div>
</div>
<div class="ao-modal" hidden id="aoModal" role="dialog" aria-modal="true"><div class="ao-modal-card"><button aria-label="Close" class="ao-modal-x" id="aoModalClose" type="button">✕</button><div id="aoModalBody"></div></div></div>
</section>
<section aria-hidden="true" class="admin-join-settings-page" id="adminJoinSettingsPage">
<div class="admin-join-settings-wrap">
<section class="admin-branding-settings-card site-maintenance-admin-settings">
<h2 class="admin-join-settings-title">WEBSITE <span>MAINTENANCE</span></h2>
<p class="admin-join-settings-sub">TEMPORARILY BLOCK USER ACCESS • ADMIN PANEL REMAINS AVAILABLE</p>
<div class="site-maintenance-setting-row">
<div><strong>FULL WEBSITE MAINTENANCE MODE</strong><small>When enabled, users see the maintenance screen instead of Login, Register and Dashboard, and NEW ORDERS are refused on the server for the website, the reseller API and every direct request (HTTP 503, code MAINTENANCE_MODE). Existing orders, status checks, refunds and the admin panel keep working.</small></div>
<button aria-pressed="false" class="site-maintenance-toggle" id="adminSiteMaintenanceToggle" type="button"><span></span><b>OFF</b></button>
</div>
<div class="admin-join-message" id="adminSiteMaintenanceMessage"></div>
</section>
<section class="admin-branding-settings-card">
<h2 class="admin-join-settings-title">SITE <span>BRANDING</span></h2>
<p class="admin-join-settings-sub">ONE LOGO + ONE PANEL NAME • UPDATES ACROSS THE WHOLE WEBSITE</p>
<div class="admin-branding-preview-row">
<div class="admin-branding-preview"><img alt="Logo Preview" id="adminBrandLogoPreview" data-global-logo/></div>
<div class="admin-branding-info">
<strong>GLOBAL LOGO</strong>
<span>Upload once and the same logo is applied to Loading, Login, Create Account, Dashboard, Admin and Product Maintenance.</span>
<small>PNG / JPG / WEBP • UP TO 20 MB • PHP UPLOAD LIMIT CONFIGURED • DISPLAY SIZE REMAINS FIXED BY EACH SCREEN</small>
</div>
</div>
<input accept="image/png,image/jpeg,image/webp" class="admin-join-input" id="adminBrandLogoFile" type="file"/>
<div class="admin-join-actions">
<button class="save" id="adminBrandLogoSave" type="button">SAVE GLOBAL LOGO</button>
<button id="adminBrandLogoClear" type="button">RESTORE DEFAULT LOGO</button>
</div>
<div class="admin-join-message" id="adminBrandLogoMessage"></div>
<div class="admin-branding-divider"></div>
<label class="admin-branding-label" for="adminPanelName">PANEL NAME</label>
<input autocomplete="off" class="admin-join-input" id="adminPanelName" maxlength="60" placeholder="Keys Panel" type="text"/>
<div class="admin-join-actions">
<button class="save" id="adminPanelNameSave" type="button">SAVE PANEL NAME</button>
</div>
<div class="admin-join-message" id="adminPanelNameMessage"></div>
</section>
<section class="admin-branding-settings-card" id="adminDisplayCard">
<h2 class="admin-join-settings-title">DASHBOARD <span>DISPLAY</span></h2>
<p class="admin-join-settings-sub">3 ROTATING SLIDES • SHOWN UNDER THE DASHBOARD HEADER • FIXED 16:7 FRAME • AUTO-OPTIMIZED ON UPLOAD</p>
<div class="admin-display-slide" data-slide="1">
<label class="admin-branding-label">SLIDE 01</label>
<div class="admin-display-preview"><img alt="Slide 01 preview" hidden id="adminDisplayPreview1"/><span class="admin-display-empty" id="adminDisplayEmpty1">NO IMAGE</span></div>
<input accept="image/png,image/jpeg,image/webp" hidden id="adminDisplayFile1" type="file"/>
<div class="admin-join-actions">
<button data-display-pick="1" id="adminDisplayPick1" type="button">UPLOAD IMAGE</button>
<button data-display-clear="1" type="button">REMOVE</button>
</div>
</div>
<div class="admin-display-slide" data-slide="2">
<label class="admin-branding-label">SLIDE 02</label>
<div class="admin-display-preview"><img alt="Slide 02 preview" hidden id="adminDisplayPreview2"/><span class="admin-display-empty" id="adminDisplayEmpty2">NO IMAGE</span></div>
<input accept="image/png,image/jpeg,image/webp" hidden id="adminDisplayFile2" type="file"/>
<div class="admin-join-actions">
<button data-display-pick="2" id="adminDisplayPick2" type="button">UPLOAD IMAGE</button>
<button data-display-clear="2" type="button">REMOVE</button>
</div>
</div>
<div class="admin-display-slide" data-slide="3">
<label class="admin-branding-label">SLIDE 03</label>
<div class="admin-display-preview"><img alt="Slide 03 preview" hidden id="adminDisplayPreview3"/><span class="admin-display-empty" id="adminDisplayEmpty3">NO IMAGE</span></div>
<input accept="image/png,image/jpeg,image/webp" hidden id="adminDisplayFile3" type="file"/>
<div class="admin-join-actions">
<button data-display-pick="3" id="adminDisplayPick3" type="button">UPLOAD IMAGE</button>
<button data-display-clear="3" type="button">REMOVE</button>
</div>
</div>
<div class="admin-join-actions">
<button class="save" id="adminDisplaySave" type="button">SAVE DISPLAY SETTINGS</button>
</div>
<div class="admin-join-message" id="adminDisplayMessage"></div>
</section>
<section class="admin-branding-settings-card" id="adminStoreLinksCard">
<h2 class="admin-join-settings-title">STORE <span>BUTTONS</span></h2>
<p class="admin-join-settings-sub">CHECK UPDATE FILE • CHECK FEEDBACK • SET ONCE, SHOWN ON EVERY STORE PRODUCT • LEAVE EMPTY TO HIDE</p>
<label class="admin-branding-label" for="adminStoreUpdateLink">UPDATE FILE LINK</label>
<input autocomplete="off" class="admin-join-input" id="adminStoreUpdateLink" inputmode="url" placeholder="https://..." type="url"/>
<label class="admin-branding-label" for="adminStoreFeedbackLink" style="margin-top:12px;display:block">FEEDBACK / VIDEO LINK</label>
<input autocomplete="off" class="admin-join-input" id="adminStoreFeedbackLink" inputmode="url" placeholder="https://..." type="url"/>
<div class="admin-join-actions">
<button class="save" id="adminStoreLinksSave" type="button">SAVE STORE BUTTONS</button>
</div>
<div class="admin-join-message" id="adminStoreLinksMessage"></div>
</section>
<section class="admin-branding-settings-card" id="adminTelegramCard">
<h2 class="admin-join-settings-title">TELEGRAM <span>SUPPORT</span></h2>
<p class="admin-join-settings-sub">FLOATING SUPPORT BUTTON ON THE USER DASHBOARD • LEAVE EMPTY TO HIDE IT</p>
<label class="admin-branding-label" for="adminTelegramUsername">TELEGRAM USERNAME</label>
<input autocomplete="off" class="admin-join-input" id="adminTelegramUsername" maxlength="64" placeholder="@username" type="text"/>
<div class="admin-join-actions">
<button class="save" id="adminTelegramSave" type="button">SAVE TELEGRAM SETTINGS</button>
</div>
<div class="admin-join-message" id="adminTelegramMessage"></div>
</section>
<section class="admin-branding-settings-card" id="adminAccessKeyCard">
<h2 class="admin-join-settings-title">ACCESS KEY <span>GENERATE</span></h2>
<p class="admin-join-settings-sub">ONE-TIME CREATE-ACCOUNT KEY • BOUND TO ONE USERNAME • WORKS ONLY ONCE</p>
<div class="site-maintenance-setting-row" id="adminAccessKeyRequireRow">
<div><strong>REQUIRE ACCESS KEY FOR REGISTRATION</strong><small>ON: new accounts need a valid unused key. OFF: normal registration without a key (existing keys, users and logins are not affected).</small></div>
<button aria-pressed="true" class="site-maintenance-toggle on" id="adminAccessKeyRequireToggle" type="button"><span></span><b>ON</b></button>
</div>
<label class="admin-branding-label" for="adminAccessKeyUsername">ASSIGN TO USERNAME</label>
<input autocapitalize="off" autocomplete="off" class="admin-join-input" id="adminAccessKeyUsername" maxlength="40" placeholder="e.g. rahul_123" spellcheck="false" type="text"/>
<div class="admin-join-actions">
<button class="save" id="adminAccessKeyGenerate" type="button">GENERATE ACCESS KEY</button>
</div>
<div class="admin-ak-result" id="adminAccessKeyResult" hidden>
<span class="admin-ak-result-key" id="adminAccessKeyResultKey"></span>
<button class="admin-small-btn gold" id="adminAccessKeyCopy" type="button">COPY</button>
</div>
<div class="admin-join-message" id="adminAccessKeyMessage"></div>
<div class="admin-ak-counts" id="adminAccessKeyCounts"></div>
<div class="admin-ak-list" id="adminAccessKeyList"></div>
</section>
<section class="admin-join-settings-card">
<h2 class="admin-join-settings-title">JOIN <span>LINK SETTINGS</span></h2>
<p class="admin-join-settings-sub">DASHBOARD JOIN BUTTON • DIRECT GROUP / CHANNEL LINK</p>
<input autocomplete="off" class="admin-join-input" id="adminJoinGroupLink" inputmode="url" placeholder="https://t.me/yourgroup" type="url"/>
<div class="admin-join-actions"><button class="save" id="adminJoinSave" type="button">SAVE JOIN LINK</button><button id="adminJoinClear" type="button">CLEAR LINK</button></div>
<div class="admin-join-message" id="adminJoinMessage"></div>
</section>
</div>
</section>
<section aria-hidden="true" class="admin-api-settings-page" id="adminApiSettingsPage">
<div class="admin-api-settings-wrap">
<section class="admin-api-settings-card">
<div class="admin-api-settings-head">
<div><h2 class="admin-api-settings-title">API <span>SETTINGS</span></h2><p class="admin-api-settings-sub">API PROVIDER • BASE URL • API TOKEN • TEST • BALANCE</p></div>
<span class="admin-api-status" id="adminApiSettingsStatus">NOT CONFIGURED</span>
</div>
<div class="admin-api-note">Provider settings are stored server-side. Use the API Token from your provider documentation. A new token replaces the previous token. The provider can be turned ON/OFF without deleting the token.</div>
<div class="admin-field"><label>API SERVICE NAME</label><input id="adminApiServiceName" placeholder="KEYSPANEL API" type="text"/></div>
<div class="admin-field"><label>GATEWAY URL</label><input id="adminApiEndpoint" placeholder="https://keypanel.shop/reseller_gateway.php" type="url"/></div>
<div class="admin-field"><label>API TOKEN / X-API-TOKEN</label><input autocomplete="new-password" id="adminApiMasterToken" placeholder="Enter API token" type="password"/></div>
<div class="admin-field admin-api-toggle-row"><label>PROVIDER STATUS</label><button class="admin-api-toggle" id="adminApiToggle" type="button">ON</button></div>
<div class="admin-api-settings-actions"><button class="admin-save-product" id="adminApiSave" type="button">SAVE API SETTINGS</button><button class="admin-api-test" id="adminApiTest" type="button">TEST CONNECTION</button><button class="admin-api-test" id="adminApiBalance" type="button">CHECK BALANCE</button><button class="admin-api-remove" id="adminApiRemoveToken" type="button">REMOVE TOKEN</button></div>
<div class="admin-api-message" id="adminApiSettingsMessage"></div>
</section>
<section class="admin-api-settings-card" id="adminApi2Card">
<div class="admin-api-settings-head">
<div><h2 class="admin-api-settings-title">API <span>PROVIDER 2</span></h2><p class="admin-api-settings-sub">KEYPANEL GATEWAY • x-master-key • BUY</p></div>
<span class="admin-api-status" id="adminApi2SettingsStatus">NOT CONFIGURED</span>
</div>
<div class="admin-api-note">Second provider, independent of the provider above. The key is stored server-side and is never shown again after saving; entering a new key replaces it. Turning the provider OFF keeps the key and existing product assignments. The provider documentation defines only the BUY action, so there is no balance or live test call: "CHECK CONFIGURATION" validates the saved settings without contacting the provider.</div>
<div class="admin-field"><label>API SERVICE NAME</label><input id="adminApi2ServiceName" placeholder="KEYPANEL API" type="text"/></div>
<div class="admin-field"><label>GATEWAY URL</label><input id="adminApi2Endpoint" placeholder="https://keypanel.shop/reseller_gateway.php" type="url"/></div>
<div class="admin-field"><label>API KEY / X-MASTER-KEY</label><input autocomplete="new-password" id="adminApi2MasterToken" placeholder="Enter API key" type="password"/></div>
<div class="admin-field admin-api-toggle-row"><label>PROVIDER STATUS</label><button class="admin-api-toggle" id="adminApi2Toggle" type="button">OFF</button></div>
<div class="admin-api-settings-actions"><button class="admin-save-product" id="adminApi2Save" type="button">SAVE API SETTINGS</button><button class="admin-api-test" id="adminApi2Test" type="button">CHECK CONFIGURATION</button><button class="admin-api-remove" id="adminApi2RemoveToken" type="button">REMOVE KEY</button></div>
<div class="admin-api-message" id="adminApi2SettingsMessage"></div>
</section>
<section class="admin-api-settings-card" id="adminApi3Card">
<div class="admin-api-settings-head">
<div><h2 class="admin-api-settings-title">API <span>PROVIDER 3</span></h2><p class="admin-api-settings-sub">PANELSTORE • JSON KEY • BALANCE • ORDER BY PID</p></div>
<span class="admin-api-status" id="adminApi3SettingsStatus">NOT CONFIGURED</span>
</div>
<div class="admin-api-note">Additional provider, independent of the providers above. The key is stored server-side and is never shown again after saving; entering a new key replaces it. Turning the provider OFF keeps the key and existing product assignments. Products on this provider use the provider's Product ID (pid) and are limited to 1 key per purchase.</div>
<div class="admin-field"><label>PROVIDER NAME</label><input id="adminApi3ServiceName" placeholder="PANELSTORE API" type="text"/></div>
<div class="admin-field"><label>API URL</label><input id="adminApi3Endpoint" placeholder="https://panelstore.shop/api/v1/index.php" type="url"/></div>
<div class="admin-field"><label>API KEY</label><input autocomplete="new-password" id="adminApi3MasterToken" placeholder="Enter API key" type="password"/></div>
<div class="admin-field admin-api-toggle-row"><label>PROVIDER STATUS</label><button class="admin-api-toggle" id="adminApi3Toggle" type="button">OFF</button></div>
<div class="admin-api-settings-actions"><button class="admin-save-product" id="adminApi3Save" type="button">SAVE API SETTINGS</button><button class="admin-api-test" id="adminApi3Test" type="button">TEST CONNECTION</button><button class="admin-api-test" id="adminApi3Balance" type="button">CHECK BALANCE</button><button class="admin-api-remove" id="adminApi3RemoveToken" type="button">REMOVE KEY</button></div>
<div class="admin-api-message" id="adminApi3SettingsMessage"></div>
</section>
<section class="admin-api-settings-card" id="adminApi4Card">
<div class="admin-api-settings-head">
<div><h2 class="admin-api-settings-title">API <span>PROVIDER 4</span></h2><p class="admin-api-settings-sub">BANTIBHAIYA • api_key + x-master-key • BUY BY PID</p></div>
<span class="admin-api-status" id="adminApi4SettingsStatus">NOT CONFIGURED</span>
</div>
<div class="admin-api-note">Fourth provider, independent of the providers above. Both keys are stored server-side and are never shown again after saving; entering a new value replaces it. Turning the provider OFF keeps the keys and existing product assignments. Products on this provider use the provider's exact Product PID (the duration name sent to the provider is the plan's duration label, e.g. "1 Day"; to send a different name write PID|Duration Name). Device-bound (V1) products that need an android_id are not supported by the Store. The documentation defines only the BUY action, so there is no balance or live test call: "CHECK CONFIGURATION" validates the saved settings without contacting the provider.</div>
<div class="admin-field"><label>PROVIDER NAME</label><input id="adminApi4ServiceName" placeholder="BANTIBHAIYA API" type="text"/></div>
<div class="admin-field"><label>API URL</label><input id="adminApi4Endpoint" placeholder="https://bantibhaiya.to/api/reseller_v1.php" type="url"/></div>
<div class="admin-field"><label>API KEY (api_key)</label><input autocomplete="new-password" id="adminApi4ApiKey" placeholder="Enter API key" type="password"/></div>
<div class="admin-field"><label>MASTER KEY (x-master-key)</label><input autocomplete="new-password" id="adminApi4MasterToken" placeholder="Enter master key" type="password"/></div>
<div class="admin-field admin-api-toggle-row"><label>PROVIDER STATUS</label><button class="admin-api-toggle" id="adminApi4Toggle" type="button">OFF</button></div>
<div class="admin-api-settings-actions"><button class="admin-save-product" id="adminApi4Save" type="button">SAVE API SETTINGS</button><button class="admin-api-test" id="adminApi4Test" type="button">CHECK CONFIGURATION</button><button class="admin-api-remove" id="adminApi4RemoveToken" type="button">REMOVE KEYS</button></div>
<div class="admin-api-message" id="adminApi4SettingsMessage"></div>
</section>
<section class="admin-api-settings-card" id="adminApi5Card">
<div class="admin-api-settings-head">
<div><h2 class="admin-api-settings-title">API <span>PROVIDER 5</span></h2><p class="admin-api-settings-sub">LICENCE DASHBOARD • BEARER TOKEN • BALANCE • CATALOG • GENERATE • HISTORY</p></div>
<span class="admin-api-status" id="adminApi5SettingsStatus">NOT CONFIGURED</span>
</div>
<div class="admin-api-note">Fifth provider, independent of the providers above. The API token is stored server-side and is never shown again after saving; entering a new token replaces it. Turning the provider OFF keeps the token and existing product assignments. Products on this provider use the provider's numeric product_id (use LOAD CATALOG to look it up). Optional mode after the id: <b>76</b> = v2 direct key (default) • <b>76|v1</b> = device-bound (the buyer is asked for an Android ID; no Android ID = no purchase) • <b>76|both</b> = v2 unless v1 is explicitly requested. Each order is sent with its own unique idempotency_key. The per-order quantity limit of the website still applies.</div>
<div class="admin-field"><label>PROVIDER NAME</label><input id="adminApi5ServiceName" placeholder="LICENCE DASHBOARD API" type="text"/></div>
<div class="admin-field"><label>API BASE URL</label><input id="adminApi5Endpoint" placeholder="https://licencedashboard.shop/api/v1" type="url"/></div>
<div class="admin-field"><label>API TOKEN</label><input autocomplete="new-password" id="adminApi5MasterToken" placeholder="Enter API token" type="password"/></div>
<div class="admin-field admin-api-toggle-row"><label>PROVIDER STATUS</label><button class="admin-api-toggle" id="adminApi5Toggle" type="button">OFF</button></div>
<div class="admin-api-settings-actions"><button class="admin-save-product" id="adminApi5Save" type="button">SAVE API SETTINGS</button><button class="admin-api-test" id="adminApi5Test" type="button">TEST CONNECTION</button><button class="admin-api-test" id="adminApi5Balance" type="button">CHECK BALANCE</button><button class="admin-api-test" id="adminApi5Catalog" type="button">LOAD CATALOG</button><button class="admin-api-test" id="adminApi5History" type="button">RECENT KEYS</button><button class="admin-api-remove" id="adminApi5RemoveToken" type="button">REMOVE TOKEN</button></div>
<div class="admin-api-message" id="adminApi5SettingsMessage"></div>
<div class="admin-ld-output" id="adminApi5Output" hidden></div>
</section>
<section class="admin-api-settings-card" id="adminApi6Card">
<div class="admin-api-settings-head">
<div><h2 class="admin-api-settings-title">API <span>PROVIDER 6</span></h2><p class="admin-api-settings-sub">VIP SELLER HUB • X-API-TOKEN • BALANCE • PRODUCTS • GENERATE KEY • KEY HISTORY</p></div>
<span class="admin-api-status" id="adminApi6SettingsStatus">NOT CONFIGURED</span>
</div>
<div class="admin-api-note">Sixth provider, independent of the providers above. The API token is stored server-side and is never shown again after saving; entering a new token replaces it. Turning the provider OFF keeps the token and existing product assignments. Products on this provider use the provider's numeric <b>variant_id</b> (use LOAD CATALOG to look it up). Keys are delivered up to the site's per-order limit.</div>
<div class="admin-field"><label>PROVIDER NAME</label><input id="adminApi6ServiceName" placeholder="VIP SELLER HUB API" type="text"/></div>
<div class="admin-field"><label>API BASE URL</label><input id="adminApi6Endpoint" placeholder="https://whitexmodz.store/api/v1" type="url"/></div>
<div class="admin-field"><label>API TOKEN</label><input autocomplete="new-password" id="adminApi6MasterToken" placeholder="Enter API token" type="password"/></div>
<div class="admin-field admin-api-toggle-row"><label>PROVIDER STATUS</label><button class="admin-api-toggle" id="adminApi6Toggle" type="button">OFF</button></div>
<div class="admin-api-settings-actions"><button class="admin-save-product" id="adminApi6Save" type="button">SAVE API SETTINGS</button><button class="admin-api-test" id="adminApi6Test" type="button">TEST CONNECTION</button><button class="admin-api-test" id="adminApi6Balance" type="button">CHECK BALANCE</button><button class="admin-api-test" id="adminApi6Catalog" type="button">LOAD CATALOG</button><button class="admin-api-test" id="adminApi6History" type="button">RECENT KEYS</button><button class="admin-api-remove" id="adminApi6RemoveToken" type="button">REMOVE TOKEN</button></div>
<div class="admin-api-message" id="adminApi6SettingsMessage"></div>
<div class="admin-ld-output" id="adminApi6Output" hidden></div>
</section>
</div>
</section>

<section aria-hidden="true" class="admin-orders-page" id="adminAlertsPage">
<div class="admin-orders-wrap">
<div class="admin-orders-top">
<div><h2 class="admin-orders-title">ADMIN <span>ALERTS</span></h2><p class="admin-orders-sub">STOCK • PROVIDER ERRORS • FAILED ORDERS • REFUNDS • WALLET CHECKS</p></div>
<div class="admin-orders-badge">🚨 MONITOR</div>
</div>
<div class="ao-summary" id="alSummary"></div>
<div class="ao-search-grid"><div class="ao-search ao-search-key"><span aria-hidden="true">🔍</span><input autocomplete="off" id="alSearch" placeholder="Search product, product ID, order ID, provider, reason" type="search"/></div></div>
<div class="ao-filters">
<select id="alStatus" aria-label="Status"><option value="open">OPEN</option><option value="resolved">RESOLVED</option><option value="all">ALL STATUS</option></select>
<select id="alType" aria-label="Type"><option value="all">ALL TYPES</option><option value="stock_zero">LOCAL STOCK ZERO</option><option value="provider_stock">PROVIDER OUT OF STOCK</option><option value="generation_failed">KEY GENERATION FAILED</option><option value="uncertain_order">UNCERTAIN ORDER</option><option value="provider_unreachable">PROVIDER UNREACHABLE</option><option value="provider_balance">PROVIDER BALANCE</option><option value="refund_failed">REFUND FAILED</option><option value="order_inconsistent">ORDER CHECK</option><option value="wallet_inconsistent">WALLET CHECK</option></select>
<select id="alSeverity" aria-label="Severity"><option value="all">ALL SEVERITY</option><option value="critical">CRITICAL</option><option value="warning">WARNING</option><option value="info">INFO</option></select>
<button class="ao-clear" id="alReset" type="button">RESET</button>
<button class="ao-clear" id="alScan" type="button">RUN CHECK</button>
</div>
<div class="ao-count" id="alCount">Loading…</div>
<div class="ao-list" id="alList"></div>
<div class="ao-pager" id="alPager"></div>
</div>
</section>
<section aria-hidden="true" class="admin-orders-page" id="adminPendingOrdersPage">
<div class="admin-orders-wrap">
<div class="admin-orders-top">
<div><h2 class="admin-orders-title">PENDING <span>ORDERS</span></h2><p class="admin-orders-sub">EXPIRY CHECKS • REFUND CONTROLLER • ADMIN REVIEW</p></div>
<div class="admin-orders-badge">⏳ CONTROLLER</div>
</div>
<div class="po-banners" id="poBanners"></div>
<div class="ao-summary" id="poSummary"></div>
<div class="ao-summary po-states" id="poStates"></div>
<div class="po-config">
<label>PROCESSING WINDOW (SECONDS)<input id="poWindow" type="number" min="60" max="86400" step="30" inputmode="numeric"/></label>
<label>AUTOMATIC REFUNDS<select id="poAuto"><option value="1">ON (confirmed failures only)</option><option value="0">OFF (review everything)</option></select></label>
<button class="ao-clear" id="poSave" type="button">SAVE</button>
<button class="ao-clear" id="poRun" type="button">RUN CHECK NOW</button>
</div>
<div class="po-meta" id="poMeta"></div>
<div class="ao-search-grid"><div class="ao-search ao-search-key"><span aria-hidden="true">🔍</span><input autocomplete="off" id="poSearch" placeholder="Search order ID, username, user ID, product" type="search"/></div></div>
<div class="ao-filters">
<select id="poFilter" aria-label="Filter">
<optgroup label="Open orders"><option value="all">ALL PENDING</option><option value="active">WITHIN WINDOW</option><option value="expired">EXPIRED / OVERDUE</option><option value="processing">PROCESSING / CHECKING</option><option value="review">NEEDS REVIEW / REFUND STATE</option></optgroup>
<optgroup label="Failures and refunds"><option value="confirmed_failed">CONFIRMED FAILED</option><option value="refund_eligible">REFUND ELIGIBLE</option><option value="not_refunded">NOT YET REFUNDED</option><option value="refund_failed">REFUND FAILED</option><option value="manual_review">MANUAL REVIEW REQUIRED</option><option value="auto_refunded">AUTO REFUNDED</option><option value="manual_refunded">MANUAL REFUND COMPLETED</option></optgroup>
<optgroup label="Everything"><option value="completed">COMPLETED / DELIVERED</option><option value="every">ALL ORDERS</option></optgroup>
</select>
<input id="poFrom" type="date" aria-label="Created from"/><input id="poTo" type="date" aria-label="Created to"/>
<button class="ao-clear" id="poReset" type="button">RESET</button>
</div>
<div class="ao-count" id="poCount">Loading…</div>
<div class="ao-list" id="poList"></div>
<div class="ao-pager" id="poPager"></div>
<h3 class="po-h">RECENT CONTROLLER DECISIONS</h3>
<div class="ao-filters po-dfilters">
<input id="pdOrder" type="search" placeholder="Order ID / ref" aria-label="Order" autocomplete="off"/>
<select id="pdType" aria-label="Decision type"><option value="">ALL DECISIONS</option><option value="wait">WAIT</option><option value="retry">RE-CHECK (AUTO)</option><option value="recheck">RE-CHECK (ADMIN)</option><option value="review">MANUAL REVIEW</option><option value="blocked">BLOCKED</option><option value="eligible">REFUND ELIGIBLE / AUTO</option><option value="manual">MANUAL REFUND</option><option value="completed">COMPLETED BY ADMIN</option></select>
<input id="pdFrom" type="date" aria-label="From"/><input id="pdTo" type="date" aria-label="To"/>
<button class="ao-clear" id="pdReset" type="button">RESET</button>
</div>
<div class="po-recent" id="poRecent"></div>
<div class="ao-pager" id="poDecPager"></div>
</div>
</section>
<section aria-hidden="true" class="admin-orders-page" id="adminPriceSyncPage">
<div class="admin-orders-wrap ps2">
<div class="admin-orders-top">
<div><h2 class="admin-orders-title">PRICE <span>SYNC</span></h2><p class="admin-orders-sub">AUTOMATIC PROVIDER PRICE CHECK • <span id="psSubRates">LOADING RATES…</span> • WRONG WEBSITE PRICES ARE CORRECTED ON THE SERVER</p></div>
<div class="admin-orders-badge">💱 PRICE SYNC</div>
</div>
<div class="po-banners" id="psBanners"></div>

<div class="ps2-status" id="psStatus">
<div class="ps2-status-head"><div><span class="ps2-dot" id="psDot"></span><b id="psStateText">LOADING…</b><small id="psStateSub"></small></div>
<button class="ao-clear" id="psCheckNow" type="button">CHECK NOW</button></div>
<div class="ps-tiles" id="psTiles"></div>
</div>

<div class="ps-card ps2-card">
<h4>PRICE RULE</h4>
<p class="ps2-desc"><span id="psRuleRates">Loading the current rates…</span> Always recalculated from the original provider price (converted once, no markup). A price that differs is corrected automatically, even when it was typed by hand. A missing, zero or invalid provider price, or a failed request, never changes a stored price. This page never adds, imports, restores or removes products.</p>
<details class="ps2-settings"><summary>PRICE SYNC SETTINGS</summary>
<div class="ps-grid">
<label>AUTO PRICE SYNC<select id="psEnabled"><option value="1">ON</option><option value="0">OFF</option></select></label>
<label>TARGET INTERVAL (SECONDS, MIN 4)<input id="psInterval" type="number" min="4" max="86400" step="1"/></label>
<label>RESELLER RATE (SET IN MONITOR)<input id="psRateReseller" type="text" value="…" readonly aria-readonly="true"/></label>
<label>NORMAL USER RATE (SET IN MONITOR)<input id="psRateNormal" type="text" value="…" readonly aria-readonly="true"/></label>
</div>
<div class="ps-actions"><button class="ao-clear" id="psSave" type="button">SAVE SETTINGS</button></div>
</details>
</div>

<div class="ps-tabs"><button type="button" class="ps-tab active" data-ps-tab="history">PRICE HISTORY</button><button type="button" class="ps-tab" data-ps-tab="problems">PROBLEMS <b id="psProbCount"></b></button><button type="button" class="ps-tab" data-ps-tab="errors">ERRORS <b id="psErrCount"></b></button></div>
<div id="psBody" class="ps-body"></div>
</div>
</section>
<section aria-hidden="true" class="admin-orders-page" id="adminReferralPage">
<div class="admin-orders-wrap">
<div class="admin-orders-top">
<div><h2 class="admin-orders-title">REFERRAL <span>COMMISSION</span></h2><p class="admin-orders-sub">ACTIVE RATE • USER EARNINGS • COMMISSION LEDGER</p></div>
<div class="admin-orders-badge">👥 REFERRALS</div>
</div>
<div class="po-banners" id="rcBanners"></div>
<div class="po-config">
<label>ACTIVE COMMISSION<input id="rcActive" type="text" readonly aria-readonly="true"/></label>
<label>SELECT PERCENTAGE<select id="rcSelect"><option value="">Choose…</option><option value="1">1%</option><option value="2">2%</option><option value="3">3%</option><option value="4">4%</option><option value="5">5%</option><option value="6">6%</option><option value="7">7%</option><option value="8">8%</option><option value="9">9%</option><option value="10">10%</option><option value="custom">CUSTOM…</option></select></label>
<label id="rcCustomWrap" hidden>CUSTOM % <span id="rcRange"></span><input id="rcCustom" type="number" step="0.01" inputmode="decimal"/></label>
<button class="ao-clear" id="rcSave" type="button">UPDATE</button>
</div>
<div class="po-meta" id="rcMeta"></div>
<div class="ao-summary rc-cards" id="rcSummary"></div>
<h3 class="po-h">USER-WISE EARNINGS</h3>
<div class="ao-search-grid rc-one"><div class="ao-search ao-search-key"><span aria-hidden="true">🔍</span><input autocomplete="off" id="rcUserSearch" placeholder="Search username or user ID" type="search"/></div></div>
<div class="ao-count" id="rcUsersCount">Loading…</div>
<div class="ao-list" id="rcUsers"></div>
<div class="ao-pager" id="rcUsersPager"></div>
<h3 class="po-h">COMMISSION HISTORY</h3>
<div class="ao-search-grid rc-one"><div class="ao-search"><span aria-hidden="true">🔍</span><input autocomplete="off" id="rcSearch" placeholder="Search user, user ID, or order" type="search"/></div></div>
<div class="ao-filters rc-filters">
<input id="rcFrom" type="date" aria-label="From date"/><input id="rcTo" type="date" aria-label="To date"/>
<select id="rcStatus" aria-label="Status"><option value="">ALL STATUS</option><option value="credited">CREDITED</option><option value="pending">PENDING</option><option value="failed">FAILED</option><option value="reversed">REVERSED</option></select>
<input id="rcPercent" type="number" step="0.01" min="0" placeholder="% applied" aria-label="Percentage applied" inputmode="decimal"/>
<input id="rcReferrer" type="text" placeholder="Referrer (name/ID)" aria-label="Referring user" autocomplete="off"/>
<input id="rcReferred" type="text" placeholder="Referred (name/ID)" aria-label="Referred user" autocomplete="off"/>
<button class="ao-clear" id="rcApply" type="button">APPLY</button>
<button class="ao-clear" id="rcReset" type="button">RESET</button>
<button class="ao-clear" id="rcExport" type="button">EXPORT CSV</button>
</div>
<div class="po-meta" id="rcScope" hidden></div>
<div class="ao-count" id="rcCount">Loading…</div>
<div class="ao-list" id="rcList"></div>
<div class="ao-pager" id="rcPager"></div>
</div>
</section>
<section aria-hidden="true" class="admin-orders-page" id="adminAuditPage">
<div class="admin-orders-wrap">
<div class="admin-orders-top">
<div><h2 class="admin-orders-title">AUDIT <span>&amp; PRICE LOG</span></h2><p class="admin-orders-sub">ADMIN-ONLY • ORDERS • WALLET • DEPOSITS • PRICES • SECURITY</p></div>
<div class="admin-orders-badge">📜 LOG</div>
</div>
<div class="ao-filters" style="margin-top:0"><div class="ao-seg" id="auTabs"><button class="on" data-v="audit" type="button">AUDIT LOG</button><button data-v="prices" type="button">PRICE CHANGES</button><button data-v="archived" type="button">ARCHIVED PRODUCTS</button></div></div>
<div id="auAuditPanel">
<div class="ao-search-grid"><div class="ao-search ao-search-key"><span aria-hidden="true">🔍</span><input autocomplete="off" id="auSearch" placeholder="Search event, order ID, reason, record ID" type="search"/></div></div>
<div class="ao-filters">
<select id="auEvent" aria-label="Event"><option value="">ALL EVENTS</option><option value="order.">ORDERS</option><option value="provider.">PROVIDERS</option><option value="wallet.">WALLET / ADMIN BALANCE</option><option value="deposit.">DEPOSITS</option><option value="price.">PRICE CHANGES</option><option value="access_key.">ACCESS KEYS</option><option value="product.">PRODUCT ARCHIVE / DELETE</option><option value="alert.">ALERTS</option><option value="user.">USERS</option><option value="security.">SECURITY</option></select>
<select id="auResult" aria-label="Result"><option value="all">ALL RESULTS</option><option value="success">SUCCESS</option><option value="failure">FAILURE</option><option value="denied">DENIED</option><option value="info">INFO</option></select>
<label class="ao-date-field">FROM <input id="auFrom" type="date"/></label>
<label class="ao-date-field">TO <input id="auTo" type="date"/></label>
<button class="ao-clear" id="auReset" type="button">RESET</button>
</div>
</div>
<div id="auPricePanel" hidden>
<div class="ao-search-grid"><div class="ao-search ao-search-key"><span aria-hidden="true">🔍</span><input autocomplete="off" id="auPriceSearch" placeholder="Search product, duration, admin" type="search"/></div></div>
</div>
<div class="ao-count" id="auCount">Loading…</div>
<div class="ao-list" id="auList"></div>
<div class="ao-pager" id="auPager"></div>
</div>
</section>
<div class="ao-modal" hidden id="opsModal" role="dialog" aria-modal="true"><div class="ao-modal-card"><button aria-label="Close" class="ao-modal-x" id="opsModalClose" type="button">✕</button><div id="opsModalBody"></div></div></div>
<section aria-hidden="true" class="admin-orders-page" id="adminDepositsPage">
<div class="admin-orders-wrap">
<div class="admin-orders-top">
<div>
<h2 class="admin-orders-title">DEPOSIT <span>MANAGEMENT</span></h2>
<p class="admin-orders-sub">PENDING • APPROVED • REJECTED • EXPIRED — PAYTM AUTO CREDIT</p>
</div>
<div class="admin-orders-badge">💰 WALLET</div>
</div>
<div class="admin-orders-summary">
<div class="admin-orders-stat">
<div class="admin-orders-stat-label">PENDING</div>
<div class="admin-orders-stat-value gold" id="adminDepPending">0</div>
<div class="admin-orders-stat-sub">Awaiting review</div>
</div>
<div class="admin-orders-stat">
<div class="admin-orders-stat-label">APPROVED TODAY</div>
<div class="admin-orders-stat-value" id="adminDepApproved">0</div>
<div class="admin-orders-stat-sub">Credited today</div>
</div>
<div class="admin-orders-stat">
<div class="admin-orders-stat-label">TOTAL CREDITED</div>
<div class="admin-orders-stat-value gold" id="adminDepTotal">₹0.00</div>
<div class="admin-orders-stat-sub">Lifetime approved value</div>
</div>
<div class="admin-orders-stat">
<div class="admin-orders-stat-label">REJECTED</div>
<div class="admin-orders-stat-value" id="adminDepRejected">0</div>
<div class="admin-orders-stat-sub">Lifetime rejected</div>
</div>
</div>
<section class="admin-api-settings-card" id="adminPaytmCard">
<div class="admin-api-settings-head">
<div><h2 class="admin-api-settings-title">PAYTM <span>DEPOSITS</span></h2><p class="admin-api-settings-sub">UPI QR • AUTO VERIFIED • DEPOSIT LIMITS</p></div>
<span class="admin-api-status" id="adminPaytmStatus">NOT CONFIGURED</span>
</div>
<div class="admin-api-note">Enter your Paytm Business <b>MID</b> and <b>UPI ID</b>. The website creates the UPI QR, checks the payment with Paytm from the server, and credits the wallet only once after Paytm confirms it. Deposits made through Paytm cannot be approved or rejected manually.</div>
<div class="admin-field"><label>PAYTM MERCHANT ID (MID)</label><input autocomplete="off" id="adminPaytmMid" placeholder="Paytm Merchant ID (MID)" type="text"/></div>
<div class="admin-field"><label>PAYTM UPI ID</label><input autocomplete="off" id="adminPaytmUpi" placeholder="yourid@paytm" type="text"/></div>
<div class="admin-field admin-api-toggle-row"><label>PAYTM DEPOSITS</label><button class="admin-api-toggle" id="adminPaytmToggle" type="button">OFF</button></div>
<div class="admin-api-settings-actions"><button class="admin-save-product" id="adminPaytmSave" type="button">SAVE PAYTM SETTINGS</button></div>
<div class="admin-field" style="margin-top:14px"><label>MINIMUM DEPOSIT (₹)</label><input id="adminDepositMin" inputmode="decimal" placeholder="10" type="number" step="0.01" min="0.01"/></div>
<div class="admin-field"><label>MAXIMUM DEPOSIT (₹)</label><input id="adminDepositMax" inputmode="decimal" placeholder="100000" type="number" step="0.01" min="0.01"/></div>
<div class="admin-api-settings-actions"><button class="admin-save-product" id="adminDepositLimitsSave" type="button">SAVE DEPOSIT LIMITS</button></div>
<div class="admin-api-message" id="adminPaytmMessage"></div>
</section>
<section class="admin-api-settings-card" id="adminZapupiCard">
<div class="admin-api-settings-head">
<div><h2 class="admin-api-settings-title">ZAPUPI <span>GATEWAY</span></h2><p class="admin-api-settings-sub">REDIRECT CHECKOUT • WEBHOOK + SERVER VERIFIED • INDEPENDENT OF PAYTM</p></div>
<span class="admin-api-status" id="adminZapStatus">NOT CONFIGURED</span>
</div>
<div class="admin-api-note">ZapUPI is an additional deposit method. Turning it ON or OFF never changes Paytm, and saving here never touches Paytm credentials. A payment is credited only after <b>this server</b> confirms it with ZapUPI's Order Status API (the webhook is only a trigger), exactly once. Test payments are never credited on a live site.</div>
<div class="admin-field admin-api-toggle-row"><label>ZAPUPI DEPOSITS</label><button class="admin-api-toggle" id="adminZapToggle" type="button">OFF</button></div>
<div class="admin-field"><label>ZAPUPI API KEY <small id="adminZapKeyNote"></small></label><input autocomplete="new-password" id="adminZapKey" placeholder="Leave blank to keep the saved key" type="password"/></div>
<div class="admin-field"><label>API ENDPOINT</label><input autocomplete="off" id="adminZapEndpoint" placeholder="https://pay.zapupi.com/api" type="text"/></div>
<div class="admin-field"><label>PAYMENT GATEWAY NAME (SHOWN TO USERS)</label><input autocomplete="off" id="adminZapName" maxlength="40" placeholder="ZapUPI" type="text"/></div>
<div class="admin-field"><label>MINIMUM DEPOSIT (₹)</label><input id="adminZapMin" inputmode="decimal" placeholder="10" type="number" step="0.01" min="0.01"/></div>
<div class="admin-field"><label>MAXIMUM DEPOSIT (₹)</label><input id="adminZapMax" inputmode="decimal" placeholder="100000" type="number" step="0.01" min="0.01"/></div>
<div class="admin-field"><label>PAYMENT INSTRUCTIONS (SHOWN ON DEPOSIT PAGE, PLAIN TEXT)</label><textarea id="adminZapInstr" maxlength="500" rows="3" style="width:100%;box-sizing:border-box" placeholder="Optional"></textarea></div>
<div class="admin-field"><label>ENVIRONMENT</label><select class="admin-order-filter" id="adminZapMode"><option value="production">PRODUCTION (test payments never credited)</option><option value="test">TEST (still never credits while the site APP_ENV is production)</option></select></div>
<div class="admin-field"><label>WEBHOOK URL (register this in your ZapUPI dashboard)</label><input id="adminZapWebhook" readonly type="text"/></div>
<div class="admin-api-settings-actions"><button class="admin-save-product" id="adminZapSave" type="button">SAVE ZAPUPI SETTINGS</button> <button class="admin-save-product" id="adminZapTest" type="button">TEST API CONNECTION</button></div>
<div class="admin-api-note" id="adminZapLast">Last API response: —</div>
<div class="admin-api-message" id="adminZapMessage"></div>
</section>
<section class="admin-orders-panel">
<div class="admin-orders-panel-head">
<div><b>DEPOSIT MONITOR</b><small>Search and inspect every deposit. Paytm deposits are credited only after Paytm confirms them server-to-server; a paid-but-uncredited deposit can be re-verified and credited once.</small></div>
</div>
<div class="ao-search-grid"><div class="ao-search ao-search-key"><span aria-hidden="true">🔍</span><input autocomplete="off" id="adminDepositSearch" placeholder="Username, user ID, deposit ID, UTR, transaction ID, gateway order ID" type="search"/></div></div>
<div class="ao-filters">
<select class="admin-order-filter" id="adminDepositFilter" aria-label="Status">
<option value="all">ALL STATUS</option>
<option value="pending">PENDING</option>
<option value="approved">APPROVED</option>
<option value="rejected">REJECTED</option>
<option value="expired">EXPIRED</option>
<option value="credit_missing">PAID — CREDIT MISSING</option>
<option value="verification_failed">VERIFICATION FAILED</option>
</select>
<select class="admin-order-filter" id="adminDepositGateway" aria-label="Gateway">
<option value="all">ALL GATEWAYS</option>
<option value="paytm">PAYTM BUSINESS</option>
<option value="zapupi">ZAPUPI</option>
</select>
<label class="ao-date-field">FROM <input id="adminDepositFrom" type="date"/></label>
<label class="ao-date-field">TO <input id="adminDepositTo" type="date"/></label>
<button class="ao-clear" id="adminDepositReset" type="button">RESET</button>
</div>
<div class="ao-count" id="adminDepositCount"></div>
<div class="ao-list" id="adminDepositList"></div>
<div class="ao-pager" id="adminDepositPager"></div>
</section>
</div>
</section>
</section>
<div aria-hidden="true" class="admin-drawer-overlay" id="adminDrawerOverlay"></div>
<aside aria-hidden="true" class="admin-drawer" id="adminDrawer">
<div class="admin-drawer-head">
<div><div class="admin-drawer-title">ADMIN MENU</div><div class="admin-drawer-sub">COMMAND CENTER</div></div>
<button aria-label="Close menu" class="admin-drawer-close" id="adminDrawerClose" type="button">×</button>
</div>
<div class="admin-drawer-body">
<button class="admin-nav-btn active" data-admin-page="dashboard" type="button"><span>⌂</span><strong>Dashboard</strong></button>
<button class="admin-nav-btn" data-admin-page="users" type="button"><span>👥</span><strong>Users</strong></button>
<button class="admin-nav-btn" data-admin-page="products" type="button"><span>📦</span><strong>Create Product</strong></button>
<button class="admin-nav-btn" data-admin-page="api-settings" type="button"><span>🔗</span><strong>API Settings</strong></button>
<button class="admin-nav-btn" data-admin-page="all-orders" type="button"><span>🗂</span><strong>All Orders</strong></button>
<button class="admin-nav-btn" data-admin-page="website-orders" type="button"><span>🛒</span><strong>Website Orders</strong></button>
<button class="admin-nav-btn" data-admin-page="api-orders" type="button"><span>⚡</span><strong>API Orders</strong></button>
<button class="admin-nav-btn" data-admin-page="deposits" type="button"><span>💰</span><strong>Wallet &amp; Deposits</strong></button>
<button class="admin-nav-btn" data-admin-page="alerts" type="button"><span>🚨</span><strong>Alerts <b id="adminAlertsBadge" class="admin-nav-badge" hidden></b></strong></button>
<button class="admin-nav-btn" data-admin-page="pending-orders" type="button"><span>⏳</span><strong>Pending Orders <b id="adminPendingBadge" class="admin-nav-badge" hidden></b></strong></button>
<button class="admin-nav-btn" data-admin-page="price-sync" type="button"><span>💱</span><strong>Price Sync <b id="adminSyncBadge" class="admin-nav-badge" hidden></b></strong></button>
<a class="admin-nav-btn admin-nav-link" href="<?= e(base_url('admin-monitor.php')) ?>" style="text-decoration:none"><span>📈</span><strong>Monitor (Orders / Prices / Durations)</strong></a>
<a class="admin-nav-btn admin-nav-link" href="<?= e(base_url('admin-theme.php')) ?>" style="text-decoration:none"><span>🎨</span><strong>Master Frontend Control</strong></a>
<button class="admin-nav-btn" data-admin-page="referral-commission" type="button"><span>🤝</span><strong>Referral Commission</strong></button>
<button class="admin-nav-btn" data-admin-page="audit" type="button"><span>📜</span><strong>Audit &amp; Price Log</strong></button>
<button class="admin-nav-btn" data-admin-page="settings" type="button"><span>⚙️</span><strong>Settings</strong></button>
</div>
<div class="admin-drawer-footer">
<button class="admin-logout drawer-logout" id="adminLogout" type="button"><span>↪</span> LOG OUT</button>
</div>
</aside>

<?php $appJsVer = is_file(__DIR__ . '/assets/js/app.js') ? (string)filemtime(__DIR__ . '/assets/js/app.js') : '1'; $adminJsVer = is_file(__DIR__ . '/assets/js/admin.js') ? (string)filemtime(__DIR__ . '/assets/js/admin.js') : '1'; ?>
<script src="<?= e(asset('js/app.js')) ?>?v=<?= e($appJsVer) ?>"></script>
<script src="<?= e(asset('js/page-entry.js')) ?>?v=<?= e((string)(is_file(__DIR__ . '/assets/js/page-entry.js') ? filemtime(__DIR__ . '/assets/js/page-entry.js') : 1)) ?>"></script>
<script src="<?= e(asset('js/admin.js')) ?>?v=<?= e($adminJsVer) ?>"></script>
<?php $opsV36Js = is_file(__DIR__ . '/assets/js/admin-ops-v36.js') ? (string)filemtime(__DIR__ . '/assets/js/admin-ops-v36.js') : '1'; ?>
<script src="<?= e(asset('js/admin-ops-v36.js')) ?>?v=<?= e($opsV36Js) ?>"></script>
<?php $psJs = is_file(__DIR__ . '/assets/js/admin-price-sync.js') ? (string)filemtime(__DIR__ . '/assets/js/admin-price-sync.js') : '1'; ?>
<script src="<?= e(asset('js/admin-price-sync.js')) ?>?v=<?= e($psJs) ?>"></script>
<?php $refV37Js = is_file(__DIR__ . '/assets/js/admin-referral-v37.js') ? (string)filemtime(__DIR__ . '/assets/js/admin-referral-v37.js') : '1'; ?>
<script src="<?= e(asset('js/admin-referral-v37.js')) ?>?v=<?= e($refV37Js) ?>"></script>
<script src="<?= e(asset('js/admin-membership.js')) ?>?v=<?= e((string)(is_file(__DIR__ . '/assets/js/admin-membership.js') ? filemtime(__DIR__ . '/assets/js/admin-membership.js') : 1)) ?>"></script>
<script>
// The reference admin drawer deliberately keeps Wallet & Deposits connected to the real PHP deposits page.
// This is an explicit bridge because the supplied static reference did not carry the data-admin-page attribute.
(function(){
  const b=document.querySelector('.admin-nav-btn strong') ? Array.from(document.querySelectorAll('.admin-nav-btn')).find(x=>x.textContent.includes('Wallet & Deposits')) : null;
  if(b) b.dataset.adminPage='deposits';
  const logout=document.getElementById('adminLogout');
  if(logout) logout.addEventListener('click', async function(){
    try{ await window.KP.api('api/auth-logout.php',{method:'POST',body:{}}); }catch(e){}
    window.location.href='<?= e(base_url('login.php?user=1')) ?>';
  });

  // Hard fallback router: if a cached/failed admin.js ever prevents its own
  // handler from attaching, the admin pages still switch cleanly instead of
  // leaving the screen blank. This only toggles the already-rendered sections.
  const pageMap={dashboard:'adminDashboardPage',users:'adminUsersPage',products:'adminProductsPage',
    'website-orders':'adminWebsiteOrdersPage','all-orders':'adminAllOrdersPage','api-orders':'adminApiOrdersPage',deposits:'adminDepositsPage',
    'api-settings':'adminApiSettingsPage',alerts:'adminAlertsPage','pending-orders':'adminPendingOrdersPage','referral-commission':'adminReferralPage','price-sync':'adminPriceSyncPage',audit:'adminAuditPage',settings:'adminJoinSettingsPage'};
  document.querySelectorAll('.admin-nav-btn').forEach(function(btn){
    btn.addEventListener('click',function(){
      const key=btn.dataset.adminPage; const targetId=pageMap[key]; const target=document.getElementById(targetId);
      if(!target) return;
      document.querySelectorAll('#adminScreen > section[id^="admin"]').forEach(function(sec){
        if(sec.id==='adminScreen') return;
        const active=sec===target;
        sec.classList.toggle('active',active);
        sec.setAttribute('aria-hidden',active?'false':'true');
      });
      document.querySelectorAll('.admin-nav-btn').forEach(function(b){b.classList.toggle('active',b===btn)});
      document.getElementById('adminDrawer')?.classList.remove('open');
      document.getElementById('adminDrawerOverlay')?.classList.remove('open');
      document.body.classList.remove('admin-drawer-open');
      window.scrollTo(0,0);
    });
  });
})();
</script>
</body>
</html>
