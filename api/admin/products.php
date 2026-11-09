<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$pdo = db();
$method = request_method();

if ($method === 'GET') {
    // Archived products (soft-deleted because they have order history) are listed only on request: ?archived=1
    $archivedView = input_str('archived', '') === '1';
    $products = $pdo->query(
        'SELECT p.id, p.category_id, p.name, p.enabled, p.maintenance, p.image_path, p.video_path, p.sort_order, p.features, p.archived_at, c.name AS category_name,
                (SELECT COUNT(*) FROM product_keys k WHERE k.product_id = p.id AND k.status = "available") AS total_stock,
                (SELECT COUNT(*) FROM orders o WHERE o.product_id = p.id) AS order_count
         FROM products p LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.archived_at IS ' . ($archivedView ? 'NOT NULL' : 'NULL') . '
         ORDER BY p.sort_order ASC, p.id ASC'
    )->fetchAll();

    $plans = $pdo->query(
        'SELECT id, product_id, duration, price, source, provider_variant_id, provider_api_version, provider_price, provider_currency, price_rate, price_manual, provider_key, allow_quantity, enabled, sort_order,
                custom_price_enabled, custom_price, inr_cost, lifecycle_state, price_increase_deadline, pending_provider_price, pending_calculated_inr,
                reseller_price, reseller_custom_price_enabled, reseller_custom_price, reseller_price_rate, reseller_inr_cost, reseller_provider_price, reseller_increase_deadline, reseller_pending_provider_price, reseller_pending_calculated_inr
         FROM product_plans ORDER BY product_id, sort_order, id'
    )->fetchAll();

    $plansByProduct = [];
    foreach ($plans as $pl) {
        $plansByProduct[(int)$pl['product_id']][] = [
            'id'                  => (int)$pl['id'],
            'duration'            => $pl['duration'],
            'price'               => (float)$pl['price'],
            'source'              => $pl['source'],
            'provider_variant_id' => $pl['provider_variant_id'],
            'provider_api_version'=> $pl['provider_api_version'] ?: null,          // v42: separate from the id
            'provider_price'      => $pl['provider_price'] === null ? null : (string)$pl['provider_price'],   // the provider's own price (e.g. USD)
            'provider_currency'   => $pl['provider_currency'],
            'price_rate'          => $pl['price_rate'] === null ? null : (string)$pl['price_rate'],
            'price_manual'        => (int)($pl['price_manual'] ?? 0) === 1,
            // v59: the six price values are kept apart (provider price / currency / rate above; calculated INR; custom price; selling price = 'price')
            'inr_cost'            => $pl['inr_cost'] === null ? null : number_format((float)$pl['inr_cost'], 2, '.', ''),
            'custom_price_enabled'=> (int)($pl['custom_price_enabled'] ?? 0) === 1,
            'custom_price'        => $pl['custom_price'] === null ? null : number_format((float)$pl['custom_price'], 2, '.', ''),
            // v61: the Reseller category (admin-only endpoint). 'price' / custom_* above are the NORMAL USER category.
            'normal_price'        => (float)$pl['price'],
            'reseller_price'      => $pl['reseller_price'] === null ? (float)$pl['price'] : (float)$pl['reseller_price'],
            'reseller_price_set'  => $pl['reseller_price'] !== null,
            'reseller_custom_price_enabled' => (int)($pl['reseller_custom_price_enabled'] ?? 0) === 1,
            'reseller_custom_price' => $pl['reseller_custom_price'] === null ? null : number_format((float)$pl['reseller_custom_price'], 2, '.', ''),
            'reseller_inr_cost'   => $pl['reseller_inr_cost'] === null ? null : number_format((float)$pl['reseller_inr_cost'], 2, '.', ''),
            'reseller_price_rate' => $pl['reseller_price_rate'] === null ? null : (string)$pl['reseller_price_rate'],
            'reseller_price_increase' => ($pl['reseller_increase_deadline'] ?? null) === null ? null : [
                'deadline' => $pl['reseller_increase_deadline'], 'new_provider_price' => $pl['reseller_pending_provider_price'], 'new_inr' => $pl['reseller_pending_calculated_inr'],
            ],
            'lifecycle_state'     => (string)($pl['lifecycle_state'] ?? 'active'),
            'price_increase'      => ($pl['price_increase_deadline'] ?? null) === null ? null : [
                'deadline' => $pl['price_increase_deadline'], 'new_provider_price' => $pl['pending_provider_price'], 'new_inr' => $pl['pending_calculated_inr'],
            ],
            'provider'            => provider_normalize($pl['provider_key'] ?? null),
            'enabled'             => (int)$pl['enabled'] === 1,
            'allow_quantity'      => (int)($pl['allow_quantity'] ?? 1) === 1,
        ];
    }

    // v41: provider mapping per product (service id / source / sync state). Absent until the sync schema exists.
    $syncInfo = [];
    try {
        foreach ($pdo->query('SELECT id, source, provider_service_id, sync_status FROM products')->fetchAll() as $r) $syncInfo[(int)$r['id']] = $r;
    } catch (Throwable $e) { /* sync columns not installed yet */ }

    $out = array_map(function ($p) use ($plansByProduct, $syncInfo) {
        return [
            'source'              => $syncInfo[(int)$p['id']]['source'] ?? 'manual',
            'provider_service_id' => $syncInfo[(int)$p['id']]['provider_service_id'] ?? null,
            'sync_status'         => $syncInfo[(int)$p['id']]['sync_status'] ?? 'live',
            'id'             => (int)$p['id'],
            'category_id'    => (int)$p['category_id'],
            'category_name'  => $p['category_name'],
            'name'           => $p['name'],
            'enabled'        => (int)$p['enabled'] === 1,
            'maintenance'    => (int)$p['maintenance'] === 1,
            'image_url'      => product_image_url($p['image_path'] ?? ''),
            'video_url'      => product_video_url($p['video_path'] ?? ''),
            'sort_order'     => (int)$p['sort_order'],
            'features'       => product_features_decode($p['features'] ?? ''),
            'total_stock'    => (int)$p['total_stock'],
            'archived_at'    => $p['archived_at'],
            'order_count'    => (int)$p['order_count'],
            'plans'          => $plansByProduct[(int)$p['id']] ?? [],
        ];
    }, $products);

    json_ok(['products' => $out, 'providers' => provider_list_public()]);
}

csrf_verify();

$action = input_str('action');

if ($action === 'find_variant' || $action === 'provider_products') {
    // v57: Create Product -> provider product NAME + DURATION -> exact provider ID and price. Read-only; the browser supplies only the two texts.
    $prov = (string)input_param('provider', PROVIDER_LICENCEDASH);
    if ($prov !== PriceSyncService::PROVIDER) json_error('Automatic ID detection is available for Provider 5 (Licence Dashboard) only. Enter the ID manually for this provider.', 422);
    @set_time_limit(90);
    try {
        if ($action === 'provider_products') json_ok(PriceSyncService::providerProductNames($pdo));
        json_ok(PriceSyncService::findVariants($pdo, trim((string)input_param('product_name', '')), trim((string)input_param('duration', '')), input_param('fresh', '0') == '1'));
    } catch (RuntimeException $e) {
        $code = (int)$e->getCode();
        json_error($e->getMessage(), ($code === 422 || $code === 502) ? $code : 502);
    } catch (Throwable $e) {
        error_log('[admin/products:' . $action . '] ' . $e->getMessage());
        json_error('The provider catalog could not be searched (server error). You can retry.', 500);
    }
}

if ($action === 'check_prices' || $action === 'apply_price') {
    // v64: CHECK AGAIN verifies BOTH price categories in one operation (Normal with normal_usd_inr_rate, Reseller with sync_usd_inr_rate, each against ITS OWN
    // selling price) and returns two independent results. apply_price corrects exactly ONE explicitly named category. The browser never supplies a price to store.
    $prov = (string)input_param('provider', PROVIDER_LICENCEDASH);
    if (!in_array($prov, provider_keys(), true)) json_error('Unknown API provider.', 422);
    if ($prov !== PriceSyncService::PROVIDER) json_error('Automatic price verification is available for Provider 5 (Licence Dashboard) only.', 422);
    $vid = trim((string)input_param('variant_id', ''));
    if ($vid === '') json_error('Enter the Provider Variant ID first.', 422);
    $planIdIn = (int)input_param('plan_id', 0);
    $checkAdminId = (int)current_user()['id'];
    @set_time_limit(90);
    if ($action === 'apply_price') {
        $catIn = dual_cat_try((string)input_param('category', ''));
        if ($catIn === null) json_error('A price category (normal or reseller) is required.', 422);
        $r = PriceSyncService::applyCategory($pdo, $planIdIn, $catIn, $vid, $checkAdminId, input_param('confirm_manual', '0') == '1');
        if (!$r['ok']) json_error((string)$r['error'], (int)($r['code'] ?? 409), isset($r['status']) ? ['status' => $r['status']] : []);
        audit_log('price.check_again_apply', 'success', ['entity_type' => 'plan', 'entity_id' => $planIdIn, 'actor_id' => $checkAdminId, 'reason' => dual_cat_label($catIn) . ($r['changed'] ? ' price set to ' . $r['price'] : ' already correct')]);
        json_ok($r);
    }
    try {
        $v = PriceSyncService::verifyBoth($pdo, $vid, $planIdIn, (int)input_param('product_id', 0), (string)input_param('entered_price', ''), (string)input_param('entered_reseller_price', ''));
    } catch (RuntimeException $e) {
        $code = (int)$e->getCode();
        json_error($e->getMessage(), ($code === 422 || $code === 502) ? $code : 502);
    } catch (Throwable $e) {
        error_log('[admin/products:check_prices] ' . $e->getMessage());
        json_error('The provider price could not be verified (server error). You can retry.', 500);
    }
    $warn = null;
    $adminDur = trim((string)input_param('duration', ''));
    $dayRe = '/(\d{1,4})\s*(?:days?|d)\b/i';
    if ($adminDur !== '' && preg_match($dayRe, $adminDur, $a) && preg_match($dayRe, (string)$v['provider']['name'], $b) && (int)$a[1] !== (int)$b[1]) {
        $warn = 'The provider lists this ID as "' . mb_substr((string)$v['provider']['name'], 0, 80) . '", which does not match the duration "' . mb_substr($adminDur, 0, 40) . '". Check the ID.';
    }
    json_ok(['variant_id' => $v['provider']['id'], 'provider' => $v['provider'], 'categories' => $v['categories'], 'duration_warning' => $warn, 'fetched_at' => $v['provider']['fetched_at']]);
}

if ($action === 'lookup_price') {
    // v56: Create Product -> price of ONE provider variant, by its exact provider id, using the Price Sync engine's own catalog read and price rule
    // (USD x the category rate once; explicit INR as is). Read-only: nothing is saved here. The browser only supplies the id; the price is never taken from it.
    $prov = (string)input_param('provider', PROVIDER_LICENCEDASH);
    if (!in_array($prov, provider_keys(), true)) json_error('Unknown API provider.', 422);
    if ($prov !== PriceSyncService::PROVIDER) {
        json_error('Automatic price lookup is available for Provider 5 (Licence Dashboard) only. Enter the selling price manually for this provider.', 422);
    }
    $vid = trim((string)input_param('variant_id', ''));
    if ($vid === '') json_error('Enter the Provider Variant ID first.', 422);
    @set_time_limit(90);
    try {
        $r = PriceSyncService::lookupVariant($pdo, $vid, 2);
    } catch (RuntimeException $e) {
        $code = (int)$e->getCode();
        json_error($e->getMessage(), ($code === 422 || $code === 502) ? $code : 502);
    } catch (Throwable $e) {
        error_log('[admin/products:lookup_price] ' . $e->getMessage());
        json_error('The provider price could not be verified (server error). You can retry.', 500);
    }
    // Soft duration check (warning only): both the provider's variant name and the admin's duration name a number of days and they differ.
    $warn = null;
    $adminDur = trim((string)input_param('duration', ''));
    $dayRe = '/(\d{1,4})\s*(?:days?|d)\b/i';
    if ($adminDur !== '' && preg_match($dayRe, $adminDur, $a) && preg_match($dayRe, (string)$r['name'], $b) && (int)$a[1] !== (int)$b[1]) {
        $warn = 'The provider lists this ID as "' . mb_substr((string)$r['name'], 0, 80) . '", which does not match the duration "' . mb_substr($adminDur, 0, 40) . '". Check the ID.';
    }
    json_ok(['variant_id' => $r['id'], 'provider_name' => $r['name'], 'provider_price' => $r['provider_price'], 'currency' => $r['currency'],
             'rate' => $r['normal_rate'], 'inr_price' => $r['normal_inr'], 'reseller_rate' => $r['rate'], 'reseller_inr_price' => $r['inr'], 'duration_warning' => $warn, 'fetched_at' => $r['fetched_at']]);
}

if ($action === 'save') {
    // Handles create AND update of a product together with its plans.
    $id          = input_int('id');
    $categoryId  = input_int('category_id');
    $name        = trim((string)input_param('name', ''));
    $plans       = input_param('plans', []); // array of {duration, price, source, provider_variant_id}

    // Store UI feature sections. null = field not sent (older clients) -> existing value is left untouched.
    $featuresIn   = input_param('features', null);
    $featuresJson = is_array($featuresIn) ? json_encode(product_features_clean($featuresIn), JSON_UNESCAPED_UNICODE) : null;

    // Store position (lower = earlier). null/'' = not sent -> unchanged on update, appended last on create.
    $orderIn = input_param('sort_order', null);
    $orderVal = null;
    if ($orderIn !== null && $orderIn !== '') {
        if (!is_scalar($orderIn) || !preg_match('/^-?\d{1,6}$/', trim((string)$orderIn))) json_error('Display order must be a whole number.', 422);
        $orderVal = (int)trim((string)$orderIn);
    }

    if ($categoryId <= 0 || $name === '') json_error('Category and name are required.', 422);
    if (!is_array($plans) || count($plans) === 0) json_error('At least one plan is required.', 422);

    if ($id > 0) {
        $ar = $pdo->prepare('SELECT archived_at FROM products WHERE id = :id');
        $ar->execute([':id' => $id]);
        $arv = $ar->fetchColumn();
        if ($arv !== false && $arv !== null) json_error('This product is archived. Restore it before editing.', 409);
    }

    $priceChanges = [];      // recorded atomically with the price update, audited after commit
    $customOps = [];         // v59: [plan_id, enabled, price|null, category] applied after the product transaction commits (pp_admin_set_custom has its own transaction)
    $reviewOps = [];         // v61: [plan_id, category, price] - an admin typed a different price while a review is open: preserved as a custom price
    $verifyQueue = [];       // v56: Provider 5 plans that are new or got a new Variant ID -> provider price metadata verified server-side after the save
    $restockedPlans = [];

    try {
        $pdo->beginTransaction();

        if ($id > 0) {
            $pdo->prepare('UPDATE products SET category_id = :c, name = :n WHERE id = :id')
                ->execute([':c' => $categoryId, ':n' => $name, ':id' => $id]);
            if ($featuresJson !== null) {
                $pdo->prepare('UPDATE products SET features = :f WHERE id = :id')->execute([':f' => $featuresJson, ':id' => $id]);
            }
            if ($orderVal !== null) {
                $pdo->prepare('UPDATE products SET sort_order = :o WHERE id = :id')->execute([':o' => $orderVal, ':id' => $id]);
            }
        } else {
            $pdo->prepare('INSERT INTO products (category_id, name, features, enabled, maintenance, created_at) VALUES (:c, :n, :f, 1, 0, NOW())')
                ->execute([':c' => $categoryId, ':n' => $name, ':f' => $featuresJson ?? '']);
            $id = (int)$pdo->lastInsertId();
            if ($orderVal === null) {
                $orderVal = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM products')->fetchColumn();
            }
            $pdo->prepare('UPDATE products SET sort_order = :o WHERE id = :id')->execute([':o' => $orderVal, ':id' => $id]);
        }

        // Replace plans atomically: delete plans not in the new list, upsert the rest.
        $keepIds = [];
        $order = 0;
        $addedKeys = 0;
        foreach ($plans as $pl) {
            $dur    = trim((string)($pl['duration'] ?? ''));
            $price  = (float)($pl['price'] ?? 0);
            // v61: Reseller price (independent of the Normal price). Absent from an older cached admin page = keep the stored value (existing plan) / copy the Normal price (new plan).
            $rpIn = array_key_exists('reseller_price', $pl) ? $pl['reseller_price'] : null;
            $rPrice = null;
            if ($rpIn !== null && $rpIn !== '') {
                if (is_array($rpIn) || !is_numeric($rpIn) || (float)$rpIn < 0 || (float)$rpIn > 9999999) json_error('Reseller price must be a number of 0 or more.', 422);
                $rPrice = round((float)$rpIn, 2);
            }
            $source = (($pl['source'] ?? 'local') === 'api') ? 'api' : 'local';
            $var    = trim((string)($pl['provider_variant_id'] ?? ''));
            $allowQuantity = !empty($pl['allow_quantity']);
            if ($dur === '') continue;
            if ($source === 'api' && $var === '') json_error('API plans require a Variant ID.', 422);
            $providerIn = (string)($pl['provider'] ?? PROVIDER_PRIMARY);
            if (!in_array($providerIn, provider_keys(), true)) json_error('Unknown API provider.', 422);
            $provider = $source === 'api' ? $providerIn : PROVIDER_PRIMARY;
            if ($provider === PROVIDER_PANELSTORE) {
                // PANELSTORE identifies a product by "pid"; only a plain identifier is accepted.
                if (!preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $var)) json_error('PANELSTORE Product ID (pid) must be letters, digits, - or _ only.', 422);
                // The documentation does not define multi-key delivery, so quantity stays locked to 1.
                $allowQuantity = false;
            }

            if ($provider === PROVIDER_BANTIBHAIYA) {
                // Provider 4 identifies a product by its exact PID. Optional "PID|Duration Name" overrides the duration name
                // sent to the provider (default = this plan's own duration label).
                if (!preg_match('/^[A-Za-z0-9_.\-]{1,40}(\|[A-Za-z0-9 _.\-]{1,19})?$/', $var)) json_error('Provider 4 Product PID must be letters, digits, . - _ only (optional "PID|Duration Name").', 422);
            }

            if ($provider === PROVIDER_VIPSELLER) {
                // Provider 6 identifies a duration by the provider's numeric variant_id (use LOAD CATALOG to look it up).
                if (!preg_match('/^[0-9]{1,10}$/D', $var) || (int)$var <= 0) json_error('Provider 6 (VIP SELLER HUB) Variant ID must be a number (e.g. 12).', 422);
            }

            $apiVer = null;   // v42: provider_api_version column (Provider 5 only); null = leave the stored value untouched on update
            if ($provider === PROVIDER_LICENCEDASH) {
                // Provider 5 maps a duration to the provider's numeric product_id. The id is stored BARE; the API mode (v2 default | v1 | both)
                // lives in provider_api_version. A legacy "76|v1" typed here is split into the two fields. v1 is device-bound (android_id at purchase).
                $ldv = licencedash_parse_variant($var);
                if ($ldv === null) json_error('Provider 5 Product ID must be a number (e.g. 76). Choose the API version in its own field.', 422);
                $var = (string)$ldv['product_id'];
                $verIn = strtolower(trim((string)($pl['provider_api_version'] ?? '')));
                if ($verIn !== '' && !in_array($verIn, ['v1', 'v2', 'both'], true)) json_error('API version must be v2, v1 or both.', 422);
                if ($verIn !== '') $apiVer = $verIn;
                elseif (strpos(trim((string)($pl['provider_variant_id'] ?? '')), '|') !== false) $apiVer = $ldv['mode'];   // suffix typed by hand / old page
            }

            // Per-duration ON/OFF. Absent from the payload (older cached admin page) = leave the stored value untouched.
            $enabledIn = array_key_exists('enabled', $pl) ? (filter_var($pl['enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : null;

            $existingId = (int)($pl['id'] ?? 0);

            // A disabled provider cannot be newly selected. A plan that already uses it keeps working/editing
            // (nothing is silently switched or deleted).
            if ($source === 'api' && !provider_info($provider)['enabled']) {
                $prev = null;
                if ($existingId > 0) {
                    $q = $pdo->prepare('SELECT source, provider_key FROM product_plans WHERE id = :id AND product_id = :pid');
                    $q->execute([':id' => $existingId, ':pid' => $id]);
                    $prev = $q->fetch() ?: null;
                }
                $unchanged = $prev && $prev['source'] === 'api' && provider_normalize($prev['provider_key'] ?? null) === $provider;
                if (!$unchanged) json_error(provider_info($provider)['label'] . ' is disabled. Enable it in API Settings first.', 422);
            }
            if ($existingId > 0) {
                // Previous price comes from the DATABASE (row locked), never from the browser. Recorded only if it really changed.
                $po = $pdo->prepare('SELECT price, reseller_price, provider_variant_id, provider_key, source, custom_price_enabled, reseller_custom_price_enabled FROM product_plans WHERE id = :id AND product_id = :pid FOR UPDATE');
                $po->execute([':id' => $existingId, ':pid' => $id]);
                $oldRow = $po->fetch();
                $oldPrice = $oldRow ? $oldRow['price'] : false;
                // v59: while a Custom Price is active the plain amount box does NOT change the selling price (use the orange CUSTOM PRICE box).
                if ($oldRow && (int)($oldRow['custom_price_enabled'] ?? 0) === 1) $price = round((float)$oldPrice, 2);
                // v61: same rule for the Reseller price; an unset (NULL) reseller price keeps NULL unless the admin typed one.
                $oldRPrice = ($oldRow && $oldRow['reseller_price'] !== null) ? round((float)$oldRow['reseller_price'], 2) : null;
                if ($rPrice === null || ($oldRow && (int)($oldRow['reseller_custom_price_enabled'] ?? 0) === 1 && $oldRPrice !== null)) $rPrice = $oldRPrice;
                $rTyped = ($rPrice !== null && $rPrice !== $oldRPrice);
                if ($oldRow && array_key_exists('reseller_custom_price_enabled', $pl)) {
                    $customOps[] = [$existingId, filter_var($pl['reseller_custom_price_enabled'], FILTER_VALIDATE_BOOLEAN), isset($pl['reseller_custom_price']) && trim((string)$pl['reseller_custom_price']) !== '' ? trim((string)$pl['reseller_custom_price']) : null, DUAL_RESELLER];
                }
                if ($oldRow && array_key_exists('custom_price_enabled', $pl)) {
                    $customOps[] = [$existingId, filter_var($pl['custom_price_enabled'], FILTER_VALIDATE_BOOLEAN), isset($pl['custom_price']) && trim((string)$pl['custom_price']) !== '' ? trim((string)$pl['custom_price']) : null, DUAL_NORMAL];
                }
                $variantChanged = !$oldRow || (string)$oldRow['source'] !== 'api' || provider_normalize($oldRow['provider_key'] ?? null) !== $provider || (string)$oldRow['provider_variant_id'] !== $var;
                $priceTyped = ($oldPrice !== false && round((float)$oldPrice, 2) !== round($price, 2));   // v42: an admin typed a different selling price
                if ($priceTyped && $price > 0) $reviewOps[] = [$existingId, DUAL_NORMAL, number_format($price, 2, '.', '')];
                if ($rTyped && $rPrice > 0) $reviewOps[] = [$existingId, DUAL_RESELLER, number_format($rPrice, 2, '.', '')];
                if ($oldPrice !== false && round((float)$oldPrice, 2) !== round($price, 2)) {
                    $adm = current_user();
                    $pdo->prepare(
                        'INSERT INTO price_changes (product_id, plan_id, product_name, duration, old_price, new_price, changed_by, changed_by_name, created_at)
                         VALUES (:p, :pl, :pn, :d, :o, :n, :a, :an, NOW())'
                    )->execute([':p' => $id, ':pl' => $existingId, ':pn' => mb_substr($name, 0, 120), ':d' => mb_substr($dur, 0, 60),
                                ':o' => round((float)$oldPrice, 2), ':n' => round($price, 2), ':a' => (int)$adm['id'], ':an' => mb_substr((string)$adm['username'], 0, 60)]);
                    $priceChanges[] = ['plan' => $existingId, 'dur' => $dur, 'old' => round((float)$oldPrice, 2), 'new' => round($price, 2)];
                }
                $pdo->prepare(
                    'UPDATE product_plans SET duration=:d, price=:p, source=:s, provider_variant_id=:v, provider_key=:pk, allow_quantity=:aq, sort_order=:o'
                    . ($enabledIn !== null ? ', enabled=:en' : '')
                    . ($priceTyped ? ', price_manual=1' : '')
                    . ($rPrice !== null ? ', reseller_price=:rp' : '')
                    . ($rTyped ? ', reseller_price_manual=1' : '')
                    . ($source === 'api' && $provider === PROVIDER_LICENCEDASH && $apiVer !== null ? ', provider_api_version=:av' : '')
                    . ($source !== 'api' || $provider !== PROVIDER_LICENCEDASH ? ', provider_api_version=NULL' : '') . '
                     WHERE id=:id AND product_id=:pid'
                )->execute([
                    ':d' => $dur, ':p' => $price, ':s' => $source,
                    ':v' => $source === 'api' ? $var : null,
                    ':pk' => $provider,
                    ':aq' => $allowQuantity ? 1 : 0,
                    ':o' => $order, ':id' => $existingId, ':pid' => $id,
                ] + ($enabledIn !== null ? [':en' => $enabledIn] : [])
                  + ($rPrice !== null ? [':rp' => $rPrice] : [])
                  + ($source === 'api' && $provider === PROVIDER_LICENCEDASH && $apiVer !== null ? [':av' => $apiVer] : []));
                $keepIds[] = $existingId;
            } else {
                $pdo->prepare(
                    'INSERT INTO product_plans (product_id, duration, price, reseller_price, source, provider_variant_id, provider_api_version, provider_key, allow_quantity, sort_order, enabled, created_at)
                     VALUES (:pid, :d, :p, :rp, :s, :v, :av, :pk, :aq, :o, :en, NOW())'
                )->execute([
                    ':pid' => $id, ':d' => $dur, ':p' => $price, ':rp' => $rPrice ?? $price, ':s' => $source,   // v61: new plan without a Reseller price starts at the Normal price (admin edits it independently)
                    ':v' => $source === 'api' ? $var : null,
                    ':av' => ($source === 'api' && $provider === PROVIDER_LICENCEDASH) ? ($apiVer ?? 'v2') : null,   // new Provider 5 plan: V2 unless the admin chose otherwise
                    ':pk' => $provider, ':aq' => $allowQuantity ? 1 : 0, ':o' => $order,
                    ':en' => $enabledIn ?? 1,
                ]);
                $keepIds[] = (int)$pdo->lastInsertId();
            }
            $planId = $existingId > 0 ? $existingId : (int)$pdo->lastInsertId();
            if ($source === 'api' && $provider === PROVIDER_LICENCEDASH && ($existingId <= 0 || !empty($variantChanged))) {
                $verifyQueue[] = ['plan' => $planId, 'var' => $var, 'dur' => $dur, 'price' => $price, 'rprice' => $rPrice ?? $price];
            }
            $variantChanged = null;

            // Local stock can be added directly while creating/editing a duration.
            // The batch is all-or-nothing: if any supplied key is duplicated within
            // the batch or already exists for this plan, the whole save is rejected.
            if ($source === 'local') {
                $rawKeys = $pl['keys'] ?? [];
                if (!is_array($rawKeys)) $rawKeys = [];
                $keys = [];
                foreach ($rawKeys as $rawKey) {
                    $key = trim((string)$rawKey);
                    if ($key !== '') $keys[] = $key;
                }
                if (count($keys) !== count(array_unique($keys, SORT_STRING))) {
                    throw new RuntimeException('Duplicate key found in the submitted batch. No keys were added.');
                }
                if ($keys) {
                    $check = $pdo->prepare('SELECT key_value FROM product_keys WHERE plan_id = :plan AND key_value = :key LIMIT 1');
                    $insKey = $pdo->prepare(
                        'INSERT INTO product_keys (product_id, plan_id, key_value, status, created_at)
                         VALUES (:p, :pl, :k, "available", NOW())'
                    );
                    foreach ($keys as $key) {
                        $check->execute([':plan' => $planId, ':key' => $key]);
                        if ($check->fetchColumn() !== false) {
                            throw new RuntimeException('Key already exists in this duration. No keys were added.');
                        }
                        $insKey->execute([':p' => $id, ':pl' => $planId, ':k' => $key]);
                    }
                    $addedKeys += count($keys);
                    $restockedPlans[] = $planId;
                }
            }
            $order++;
        }

        // Durations removed from the list: deleted when they have no order history. A duration that already has orders is
        // switched OFF instead (orders.plan_id is a RESTRICT foreign key, and purchase history must be preserved).
        $drop = $pdo->prepare('SELECT id FROM product_plans WHERE product_id = :id');
        $drop->execute([':id' => $id]);
        foreach ($drop->fetchAll(PDO::FETCH_COLUMN) as $existingPlanId) {
            $existingPlanId = (int)$existingPlanId;
            if (in_array($existingPlanId, $keepIds, true)) continue;
            $hist = $pdo->prepare('SELECT 1 FROM orders WHERE plan_id = :p LIMIT 1');
            $hist->execute([':p' => $existingPlanId]);
            if ($hist->fetchColumn()) {
                $pdo->prepare('UPDATE product_plans SET enabled = 0 WHERE id = :p')->execute([':p' => $existingPlanId]);
            } else {
                $pdo->prepare('DELETE FROM product_plans WHERE id = :p')->execute([':p' => $existingPlanId]);
            }
        }

        $pdo->commit();
        foreach ($priceChanges as $pc) {
            audit_log('price.changed', 'success', ['entity_type' => 'plan', 'entity_id' => $pc['plan'],
                'reason' => $name . ' / ' . $pc['dur'] . ': ' . number_format($pc['old'], 2, '.', '') . ' → ' . number_format($pc['new'], 2, '.', '')]);
        }
        foreach (array_unique($restockedPlans) as $rp) ops_check_local_stock($pdo, (int)$rp);   // resolves a "stock is zero" alert

        // v59: custom prices (own transaction + history event each; only when the value really changed)
        $customNotes = [];
        $customDone = [];
        foreach ($customOps as [$cpid, $cen, $cval, $ccat]) {
            try {
                $cc = dual_cat_cols($ccat);
                $cur = $pdo->prepare('SELECT `' . $cc['custom_on'] . '` AS custom_price_enabled, `' . $cc['custom'] . '` AS custom_price FROM product_plans WHERE id = :id AND product_id = :p'); $cur->execute([':id' => $cpid, ':p' => $id]);
                $c0 = $cur->fetch();
                if (!$c0) continue;
                $same = ((int)$c0['custom_price_enabled'] === 1) === $cen
                     && (!$cen || ($cval !== null && abs((float)$c0['custom_price'] - (float)$cval) < 0.004));
                if ($same || (!$cen && (int)$c0['custom_price_enabled'] === 0)) continue;
                $customDone[$cpid . ':' . $ccat] = true;
                $r = pp_admin_set_custom($pdo, (int)$cpid, $cen, $cval, (int)current_user()['id'], $ccat);
                if (!$r['ok']) $customNotes[] = 'Custom price not saved: ' . ($r['error'] ?? 'error');
            } catch (Throwable $e) { error_log('[admin/products:custom_price] ' . $e->getMessage()); $customNotes[] = 'Custom price could not be saved.'; }
        }

        // v61: an admin typed a different plain price while a provider-price REVIEW is open for that category: the admin's price is preserved (as a custom price).
        foreach ($reviewOps as [$rpid, $rcat, $rval]) {
            if (isset($customDone[$rpid . ':' . $rcat])) continue;
            try {
                $r = pp_admin_price_during_review($pdo, (int)$rpid, $rcat, $rval, (int)current_user()['id']);
                if ($r !== null && !$r['ok']) $customNotes[] = dual_cat_label($rcat) . ' price kept during review: not saved (' . ($r['error'] ?? 'error') . ').';
            } catch (Throwable $e) { error_log('[admin/products:review_price] ' . $e->getMessage()); }
        }

        // v56: the product is saved. Now (outside the transaction, best effort, never blocks or undoes the save) record the provider price the SERVER verifies for each
        // new / changed Provider 5 Variant ID. Only the provider-price columns are written - the selling price the admin saved is left exactly as saved; the
        // scheduled Price Sync (same rule) is what keeps product_plans.price equal to provider price x the category rate afterwards.
        $priceNotes = [];
        $cache = [];
        foreach (array_slice($verifyQueue, 0, 6) as $vq) {
            try {
                if (!isset($cache[$vq['var']])) $cache[$vq['var']] = PriceSyncService::lookupVariant($pdo, (string)$vq['var'], 1);
                $lv = $cache[$vq['var']];
                $snap = (string)json_encode(['id' => $lv['id'], 'price' => $lv['provider_price'], 'currency' => $lv['currency'], 'rate' => $lv['rate'], 'normal_rate' => $lv['normal_rate'], 'inr' => $lv['inr'], 'normal_inr' => $lv['normal_inr'], 'checked_at' => date('c'), 'source' => 'create_product']);
                // v64: each category stores the calculation of ITS OWN rate and is written ONLY when that calculation exists (a missing rate of one category never
                // leaves a value borrowed from the other). Normal = normal_usd_inr_rate / normal_inr, Reseller = sync_usd_inr_rate / inr.
                $set = ['provider_currency = :pc', 'provider_snapshot = :sn']; $bind = [':pc' => $lv['currency'], ':sn' => $snap, ':id' => $vq['plan'], ':pid' => $id, ':v' => $vq['var']];
                if ($lv['normal_inr'] !== null && $lv['normal_rate'] !== null) { $set[] = 'provider_price = :pp'; $set[] = 'price_rate = :pr'; $set[] = 'inr_cost = :ic'; $bind += [':pp' => $lv['provider_price'], ':pr' => $lv['normal_rate'], ':ic' => $lv['normal_inr']]; }
                if ($lv['inr'] !== null && $lv['rate'] !== null) { $set[] = 'reseller_provider_price = :rpp'; $set[] = 'reseller_price_rate = :rpr'; $set[] = 'reseller_inr_cost = :ric'; $bind += [':rpp' => $lv['provider_price'], ':rpr' => $lv['rate'], ':ric' => $lv['inr']]; }
                $pdo->prepare('UPDATE product_plans SET ' . implode(', ', $set) . ' WHERE id = :id AND product_id = :pid AND source = \'api\' AND provider_variant_id = :v')->execute($bind);
                // Normal price is compared with the NORMAL calculation, Reseller price with the RESELLER calculation (never the other way round).
                if ($lv['normal_inr'] === null) $priceNotes[] = $vq['dur'] . ': Normal User price not verified (' . ($lv['normal_reason'] ?? 'exchange rate missing') . ').';
                elseif (number_format((float)$vq['price'], 2, '.', '') !== $lv['normal_inr']) {
                    $priceNotes[] = $vq['dur'] . ': saved Normal User price ' . number_format((float)$vq['price'], 2, '.', '') . ' differs from provider price x ' . $lv['normal_rate'] . ' = ' . $lv['normal_inr'] . '. Price Sync will set it to ' . $lv['normal_inr'] . '.';
                }
                if ($lv['inr'] === null) $priceNotes[] = $vq['dur'] . ': Reseller price not verified (' . ($lv['reseller_reason'] ?? 'exchange rate missing') . ').';
                elseif (number_format((float)($vq['rprice'] ?? $vq['price']), 2, '.', '') !== $lv['inr']) {
                    $priceNotes[] = $vq['dur'] . ': saved Reseller price ' . number_format((float)($vq['rprice'] ?? $vq['price']), 2, '.', '') . ' differs from provider price x ' . $lv['rate'] . ' = ' . $lv['inr'] . '. Price Sync will set it to ' . $lv['inr'] . '.';
                }
            } catch (Throwable $e) {
                error_log('[admin/products:verify_price] ' . $e->getMessage());
                $priceNotes[] = $vq['dur'] . ': the provider price could not be verified now (' . mb_substr($e->getMessage(), 0, 140) . '). The product is saved; Price Sync verifies it on its next cycle.';
            }
        }
        json_ok(['id' => $id, 'added_keys' => $addedKeys, 'price_notes' => array_merge($priceNotes, $customNotes)]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[admin/products:save] ' . $e->getMessage());
        if ($e instanceof RuntimeException) json_error($e->getMessage(), 422);
        json_error('Could not save product.', 500);
    }
}

if ($action === 'delete') {
    // Archive when there is any history (orders, delivered keys); permanently delete only an unused product.
    $id = input_int('id');
    if ($id <= 0) json_error('Invalid id.', 422);
    $adminId = (int)current_user()['id'];

    $pq = $pdo->prepare('SELECT id, name, image_path, video_path, archived_at FROM products WHERE id = :id LIMIT 1');
    $pq->execute([':id' => $id]);
    $prod = $pq->fetch();
    if (!$prod) json_error('Product not found.', 404);
    if ($prod['archived_at'] !== null) json_error('This product is already archived.', 409);

    try {
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM products WHERE id = :id FOR UPDATE')->execute([':id' => $id]);
        $o = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE product_id = :id');
        $o->execute([':id' => $id]);
        $orders = (int)$o->fetchColumn();
        $k = $pdo->prepare("SELECT COUNT(*) FROM product_keys WHERE product_id = :id AND status <> 'available'");
        $k->execute([':id' => $id]);
        $usedKeys = (int)$k->fetchColumn();

        if ($orders > 0 || $usedKeys > 0) {
            // Soft delete: hidden everywhere, cannot be purchased (also through the API), history stays intact.
            $pdo->prepare('UPDATE products SET archived_at = NOW(), archived_by = :a, enabled = 0 WHERE id = :id')->execute([':a' => $adminId, ':id' => $id]);
            $pdo->commit();
            audit_log('product.archived', 'success', ['entity_type' => 'product', 'entity_id' => $id, 'actor_id' => $adminId,
                'reason' => $prod['name'] . ' archived (orders: ' . $orders . ', delivered keys: ' . $usedKeys . ')']);
            json_ok(['archived' => true, 'deleted' => false,
                'message' => 'This product has order history, so it was archived instead of deleted. It is hidden from the Store and cannot be purchased. You can find it under Audit Logs → Archived Products.']);
        }

        $pdo->prepare('DELETE FROM products WHERE id = :id')->execute([':id' => $id]);   // plans + unused keys cascade
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[admin/products:delete] ' . $e->getMessage());
        audit_log('product.delete', 'failure', ['entity_type' => 'product', 'entity_id' => $id, 'reason' => 'Delete failed: ' . $e->getMessage()]);
        json_error('Could not delete this product. Nothing was changed.', 500);
    }

    // Media files only after the row is gone, only when no other product references the same file, only inside uploads/.
    foreach (['image_path', 'video_path'] as $col) {
        $rel = (string)($prod[$col] ?? '');
        if ($rel === '' || strpos($rel, '..') !== false || !preg_match('#^uploads/(products|product-videos)/[A-Za-z0-9._\-]+$#', $rel)) continue;
        $ref = $pdo->prepare('SELECT 1 FROM products WHERE image_path = :p OR video_path = :p2 LIMIT 1');
        $ref->execute([':p' => $rel, ':p2' => $rel]);
        if ($ref->fetchColumn()) continue;
        $abs = BASE_PATH . '/' . $rel;
        if (is_file($abs)) @unlink($abs);
    }
    audit_log('product.deleted', 'success', ['entity_type' => 'product', 'entity_id' => $id, 'reason' => $prod['name'] . ' permanently deleted (no order history)']);
    json_ok(['archived' => false, 'deleted' => true, 'message' => 'Product deleted permanently.']);
}

if ($action === 'restore') {
    $id = input_int('id');
    if ($id <= 0) json_error('Invalid id.', 422);
    $st = $pdo->prepare('UPDATE products SET archived_at = NULL, archived_by = NULL WHERE id = :id AND archived_at IS NOT NULL');
    $st->execute([':id' => $id]);
    if ($st->rowCount() !== 1) json_error('This product is not archived.', 409);
    audit_log('product.restored', 'success', ['entity_type' => 'product', 'entity_id' => $id, 'reason' => 'Restored from archive (stays hidden until you enable it)']);
    json_ok(['restored' => true]);
}

if ($action === 'toggle_enabled') {
    $id = input_int('id');
    if ($id <= 0) json_error('Invalid id.', 422);
    $stmt = $pdo->prepare('UPDATE products SET enabled = 1 - enabled WHERE id = :id AND archived_at IS NULL');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) {
        $exists = $pdo->prepare('SELECT id, archived_at FROM products WHERE id = :id');
        $exists->execute([':id' => $id]);
        $ex = $exists->fetch();
        if (!$ex) json_error('Product not found.', 404);
        if ($ex['archived_at'] !== null) json_error('Archived products cannot be enabled. Restore it first.', 409);
    }
    $q = $pdo->prepare('SELECT enabled FROM products WHERE id = :id');
    $q->execute([':id' => $id]);
    json_ok(['enabled' => (int)$q->fetchColumn() === 1]);
}

if ($action === 'toggle_maintenance') {
    $id = input_int('id');
    if ($id <= 0) json_error('Invalid id.', 422);
    // v41: a provider-synced product may only leave Maintenance when it is complete (active category, price > 0 on every enabled variant,
    // not removed on the provider). Manual products behave exactly as before.
    $cur = $pdo->prepare('SELECT maintenance FROM products WHERE id = :id');
    $cur->execute([':id' => $id]);
    $curVal = $cur->fetchColumn();
    if ($curVal === false) json_error('Product not found.', 404);
    if ((int)$curVal === 1) {
        $err = rp_provider_go_live_check($pdo, $id);
        if ($err !== null) json_error($err, 422);
    }
    $pdo->prepare('UPDATE products SET maintenance = 1 - maintenance WHERE id = :id')->execute([':id' => $id]);
    $val = (int)$pdo->query('SELECT maintenance FROM products WHERE id = ' . $id)->fetchColumn();
    try {   // keep the sync marker in step with the toggle (provider products only; never touches 'removed')
        $pdo->prepare("UPDATE products SET sync_status = :s WHERE id = :id AND source = 'provider' AND sync_status <> 'removed'")->execute([':s' => $val === 1 ? 'maintenance' : 'live', ':id' => $id]);
    } catch (Throwable $e) { /* sync columns not installed yet */ }
    json_ok(['maintenance' => $val === 1]);
}

json_error('Unknown action.', 400);