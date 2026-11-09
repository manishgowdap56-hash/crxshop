#!/usr/bin/env python3
"""v64 source-level invariants (reads the source text; does NOT execute PHP/JS)."""
import re, sys
R = lambda p: open(p, encoding='utf-8').read()
dual, ps, pp = R('includes/dual.php'), R('includes/price_sync.php'), R('includes/price_policy.php')
prod, mon, dash, rmjs, adm = R('api/admin/products.php'), R('api/admin/monitor.php'), R('dashboard.php'), R('assets/js/reseller-membership.js'), R('assets/js/admin.js')
fails = 0
def chk(name, ok):
    global fails
    print(('PASS ' if ok else 'FAIL ') + name)
    if not ok: fails += 1

chk('dual_cat_valid throws on anything but normal/reseller', "throw new InvalidArgumentException('Invalid price category.')" in dual and 'dual_cat_try' in dual)
chk('dual_cat_cols is strict', re.search(r'function dual_cat_cols\(string \$cat\): array\s*\{\s*\$cat = dual_cat_valid\(\$cat\);', dual) is not None)
chk('dual_rate returns ?string with no default and no other-category fallback', 'function dual_rate(PDO $pdo, string $cat): ?string' in dual and 'PriceSyncService::RATE' not in dual[dual.index('function dual_rate('):dual.index('Admin: grant')])
chk('settings(): no fallback of normal_rate to sync_usd_inr_rate', not re.search(r"normal_usd_inr_rate.{0,200}sync_usd_inr_rate", ps[ps.index('public static function settings'):ps.index('/* ---- money') if '/* ---- money' in ps else ps.index('public static function toMicro')], re.S))
chk('websitePrice: strict rate when a rate key is passed', "array_key_exists('rate', $cfg)" in ps and "'exchange rate is missing or invalid'" in ps)
chk('websitePrice: INR never multiplied', "$cur === 'INR') return ['inr' => self::convertToInr($micro, '1')" in ps)
chk('pp_config: rates from dual_rate only, no fallback', "dual_rate($pdo, DUAL_RESELLER)" in pp and "dual_rate($pdo, DUAL_NORMAL)" in pp and "pp_setting($pdo, 'normal_usd_inr_rate', $rate)" not in pp)
chk('cycle: per-category validity (one bad rate never blocks the other)', "if ($wp['inr'] === null || $wp['micro'] === null) {      // this category cannot be calculated" in ps)
chk('cycle iterates DUAL_CATS with pp_row_view + explicit category', 'foreach (DUAL_CATS as $cat)' in ps and 'pp_row_view($row, $cat)' in ps)
chk('PriceSyncService::verifyBoth returns both categories', all(x in ps for x in ['function verifyBoth', 'DUAL_NORMAL => self::verifyCategory', 'DUAL_RESELLER => self::verifyCategory']))
chk('applyCategory requires explicit category and never infers it', "$cat = dual_cat_try($catIn);" in ps and "'A price category (normal or reseller) is required.'" in ps)
chk('applyCategory blocks custom / pending / unconfirmed manual', all(x in ps for x in ["'CUSTOM PRICE'", "'PENDING REVIEW'", "'MANUAL PRICE'"]))
chk('applyCategory runs under the existing named lock', "GET_LOCK('" in ps[ps.index('function applyCategory'):] and 'self::LOCK' in ps[ps.index('function applyCategory'):])
chk('products.php: check_prices + apply_price actions', "$action === 'check_prices' || $action === 'apply_price'" in prod and "dual_cat_try((string)input_param('category', ''))" in prod)
chk('products.php: Normal price compared with normal_inr, Reseller with inr', "!== $lv['normal_inr']" in prod and "!== $lv['inr']" in prod and "(string)$vq['price'], 2, '.', '') !== $lv['inr']" not in prod)
chk('monitor.php: category required, never defaulted', "dual_cat_try(input_str('category', ''))" in mon and "input_str('category', DUAL_NORMAL)" not in mon)
chk('admin.js: Check Again calls check_prices and renders both categories', "action: 'check_prices'" in adm and "['normal', 'reseller'].forEach" in adm)
chk('admin.js: each correction sends its own category', "category: cat, plan_id" in adm)
chk('admin.js: duplicate request + stale response guards', 'if (lk.pBusy) return;' in adm and 'my !== lk.seq' in adm)
chk('dashboard.php: card only for Normal Users with Membership enabled', 'if (!$isRes && $mcfg[\'enabled\']):' in dash and '$membership[\'last_purchase\']):' not in dash)
chk('dashboard.php: no Reseller branch inside the card', 'rsActive' not in dash)
chk('membership JS: card removed + immediate reload, no 1500 ms delay', 'removeChild(card)' in rmjs and 'location.reload()' in rmjs and '1500' not in rmjs)
chk('membership JS: success only after the server says is_reseller', 'is_reseller' in rmjs)
sys.exit(1 if fails else 0)
