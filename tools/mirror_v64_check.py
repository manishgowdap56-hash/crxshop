#!/usr/bin/env python3
"""Python MIRROR of the v64 PHP rules (websitePrice strict rate, verifyCategory, independent categories). It tests the RULES, it does not execute PHP."""
import re, sys
def to_micro(v):
    m = re.fullmatch(r'(\d{1,9})(?:\.(\d{1,12}))?', str(v).strip())
    if not m: return None
    mi = int(m[1]) * 10**6 + int((m[2] or '').ljust(6, '0')[:6]); return mi if mi <= 100000 * 10**6 else None
def norm_rate(r):
    m = re.fullmatch(r'(\d{1,4})(?:\.(\d{1,4}))?', str(r or '').strip())
    if not m: return None
    u = int(m[1]) * 10000 + int((m[2] or '').ljust(4, '0')); return (str(u // 10000) + ('.' + str(u % 10000).zfill(4).rstrip('0') if u % 10000 else '')) if 1 <= u <= 10**7 else None
def conv(micro, rate):
    r = norm_rate(rate); i, _, f = r.partition('.'); r4 = int(i) * 10000 + int(f.ljust(4, '0') or 0)
    p = (micro * r4 + 50_000_000) // 100_000_000; return '%d.%02d' % (p // 100, p % 100)
def website_price(item, cfg):
    rate = (norm_rate(cfg['rate']) if cfg['rate'] is not None else None) if 'rate' in cfg else '30'
    cur = (item.get('currency') or cfg.get('provider_currency', 'USD')).upper(); micro = to_micro(item['price']) if item.get('price') is not None else None
    if not micro: return dict(inr=None, reason='provider price invalid')
    if cur == 'INR': return dict(inr=conv(micro, '1'), rate='1')
    if cur != 'USD': return dict(inr=None, reason='unsupported currency')
    if rate is None: return dict(inr=None, reason='exchange rate is missing or invalid')
    return dict(inr=conv(micro, rate), rate=rate)
def settings(db):  # NO cross-category fallback
    return dict(rate=norm_rate(db.get('sync_usd_inr_rate', '')), normal_rate=norm_rate(db.get('normal_usd_inr_rate', '')))
def paise(v):
    m = re.fullmatch(r'(-?)(\d{1,10})(?:\.(\d{1,6}))?', str(v).strip()); fr = (m[3] or '').ljust(4, '0'); p = int(m[2]) * 100 + int(fr[:2]) + (1 if int(fr[2]) >= 5 else 0); return -p if m[1] else p
def verify(cat, item, db, plan):
    s = settings(db); wp = website_price(item, dict(rate=s['normal_rate'] if cat == 'normal' else s['rate']))
    if wp['inr'] is None: return 'INVALID RATE' if 'rate' in wp.get('reason', '') else 'INVALID PROVIDER PRICE', None
    if plan.get('pending'): return 'PENDING REVIEW', wp['inr']
    if plan.get('custom'): return 'CUSTOM PRICE', wp['inr']
    if paise(plan['price']) == paise(wp['inr']): return 'CORRECT', wp['inr']
    return ('MANUAL PRICE' if plan.get('manual') else 'PRICE MISMATCH'), wp['inr']
fails = 0
def chk(n, ok):
    global fails; print(('PASS ' if ok else 'FAIL ') + n); fails += (not ok)
usd = lambda p: dict(price=p, currency='USD')
for i, (n, r, p, en, er) in enumerate([(30, 40, '2', '60.00', '80.00'), (35, 40, '2', '70.00', '80.00'), (35, 50, '2', '70.00', '100.00'), (65, 35, '5', '325.00', '175.00')], 1):
    s = settings({'normal_usd_inr_rate': str(n), 'sync_usd_inr_rate': str(r)})
    chk(f'TEST {i}: N={n} R={r} ${p} -> {en} / {er}', website_price(usd(p), dict(rate=s['normal_rate']))['inr'] == en and website_price(usd(p), dict(rate=s['rate']))['inr'] == er)
db = {'normal_usd_inr_rate': '65', 'sync_usd_inr_rate': '35'}; plan = dict(price='350.00')
chk('TEST 4 Check Again: Normal mismatch detected', verify('normal', usd('5'), db, plan) == ('PRICE MISMATCH', '325.00'))
chk('TEST 4 Check Again: Reseller mismatch detected independently', verify('reseller', usd('5'), db, plan) == ('PRICE MISMATCH', '175.00'))
chk('TEST 5/6: correcting Normal leaves Reseller verification unchanged (separate rows)', verify('reseller', usd('5'), db, dict(price='350.00'))[0] == 'PRICE MISMATCH' and verify('normal', usd('5'), db, dict(price='325.00'))[0] == 'CORRECT')
chk('TEST 7: change only Normal rate -> Reseller calc unchanged', website_price(usd('5'), dict(rate=settings({**db, 'normal_usd_inr_rate': '70'})['rate']))['inr'] == '175.00')
chk('TEST 8: change only Reseller rate -> Normal calc unchanged', website_price(usd('5'), dict(rate=settings({**db, 'sync_usd_inr_rate': '50'})['normal_rate']))['inr'] == '325.00')
chk('TEST 9: repeated sync, no double conversion (calc always from original provider price)', all(website_price(usd('5'), dict(rate='65'))['inr'] == '325.00' for _ in range(5)))
chk('TEST 11: custom price protects only its own category', verify('normal', usd('5'), db, dict(price='350.00', custom=True))[0] == 'CUSTOM PRICE' and verify('reseller', usd('5'), db, dict(price='350.00'))[0] == 'PRICE MISMATCH')
chk('TEST 12: manual flag reported per category', verify('reseller', usd('5'), db, dict(price='350.00', manual=True))[0] == 'MANUAL PRICE' and verify('normal', usd('5'), db, dict(price='350.00'))[0] == 'PRICE MISMATCH')
chk('pending review reported per category', verify('normal', usd('5'), db, dict(price='350.00', pending=True))[0] == 'PENDING REVIEW')
# no cross-category fallback
miss_n = {'sync_usd_inr_rate': '35'}; miss_r = {'normal_usd_inr_rate': '65'}
chk('Normal rate missing -> Normal INVALID RATE (NOT the reseller 35)', verify('normal', usd('5'), miss_n, dict(price='350'))[0] == 'INVALID RATE')
chk('Normal rate missing -> Reseller still verifies', verify('reseller', usd('5'), miss_n, dict(price='350'))[1] == '175.00')
chk('Reseller rate missing -> Reseller INVALID RATE, Normal still verifies', verify('reseller', usd('5'), miss_r, dict(price='350'))[0] == 'INVALID RATE' and verify('normal', usd('5'), miss_r, dict(price='350'))[1] == '325.00')
chk('invalid rate strings rejected (0, 1001, abc, 5 decimals, empty)', all(norm_rate(x) is None for x in ['0', '1001', 'abc', '1.23456', '']))
chk('TEST 13: malformed / zero / negative provider price rejected', all(website_price(dict(price=p, currency='USD'), dict(rate='65'))['inr'] is None for p in ['abc', '0', '-3', '1e3', '']))
chk('unsupported currency rejected', website_price(dict(price='5', currency='EUR'), dict(rate='65'))['inr'] is None)
chk('INR provider price is never multiplied, even with no rate configured', website_price(dict(price='175', currency='INR'), dict(rate=None))['inr'] == '175.00')
chk('paise comparison has no float error (0.1+0.2 style)', paise('325.00') == paise('325') and paise('0.30') == 30)
sys.exit(1 if fails else 0)
