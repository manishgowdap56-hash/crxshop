#!/usr/bin/env python3
"""v63 STATIC invariants (source scans only - does NOT execute PHP). Run from the site root: python3 tools/check_v63_static.py"""
import re,sys,os
ok=True
def rd(p): return open(p,encoding='utf-8').read()
def chk(n,c):
    global ok
    print(('PASS ' if c else 'FAIL ')+n); ok = ok and c
admin=rd('admin.php'); ps=rd('includes/price_sync.php'); mon=rd('api/admin/monitor.php'); psapi=rd('api/admin/price-sync.php'); psjs=rd('assets/js/admin-price-sync.js')
chk('admin.php links reseller-membership.css', "css/reseller-membership.css" in admin)
chk('admin.php has no hardcoded "x 30" / "= ₹30" / rate input value=30', not re.search(r'× ?30|₹30|id="psRate"[^>]*value="30"',admin))
chk('Price Sync has NO editable rate input', not re.search(r'id="psRate(Reseller|Normal)"(?![^>]*readonly)',admin))
chk('Price Sync JS fills rates from ov.rates', 'ov.rates.reseller' in psjs and 'ov.rates.normal' in psjs)
chk('overview exposes rates from settings()', "'rates' => ['reseller' => $cfg['rate'], 'normal' => $cfg['normal_rate']]" in ps)
chk('history endpoint no longer returns constant RATE', "'rate' => PriceSyncService::RATE" not in psapi)
chk('settings(): reseller = sync_usd_inr_rate', "'rate'              => PriceSyncService::normalizeRate((string)self::raw($pdo, 'sync_usd_inr_rate'" in ps)
chk('settings(): normal = normal_usd_inr_rate', "self::raw($pdo, 'normal_usd_inr_rate'" in ps)
chk('cycle: Normal price uses normal_rate, Reseller uses rate', "DUAL_NORMAL   => self::websitePrice($it, ['rate' => $s['normal_rate']] + $s)" in ps and "DUAL_RESELLER => self::websitePrice($it, ['rate' => $s['rate']] + $s)" in ps)
chk('monitor writes each rate via its own setting', "dual_rate_setting($rc)" in mon and "pp_put_setting($pdo, dual_rate_setting" in mon)
chk('only one definition of sync_usd_inr_rate writer', len([1 for r,_,fs in os.walk('.') for f in fs if f.endswith('.php') and 'tools' not in r and re.search(r"put_setting\(\$pdo, 'sync_usd_inr_rate'|set_setting\('sync_usd_inr_rate'",rd(os.path.join(r,f)))])==0)
lk=rd('api/admin/products.php')
chk('Create Product lookup returns normal + reseller prices', "'reseller_inr_price'" in lk and "'inr_price' => $r['normal_inr']" in lk)
chk('admin-membership.js uses existing membership API only', "api/admin/membership.php" in rd('assets/js/admin-membership.js') and not os.path.exists('api/admin/membership2.php'))
d=rd('includes/dual.php')
names=re.findall(r'^function\s+(\w+)',d,re.M)
chk('dual.php: no duplicate functions', len(names)==len(set(names)))
sys.exit(0 if ok else 1)
