# Python MIRROR (not the PHP itself) of the v55 PriceSyncService decision logic:
#   toMicro / convertToInr (integer arithmetic, rate 30), collectPrices (id matching), and the per-variant decision
#   (unusable price -> keep, equal -> already correct, else -> correct).
# It proves the ALGORITHM on synthetic catalogs. It does NOT prove that the PHP runs: run tools/test_price_sync.php on the server for that.
import re
def to_micro(v):
    if v is None or isinstance(v, bool) or isinstance(v, (list, dict)): return None
    s = ('%.6f' % v) if isinstance(v, float) else str(v).strip()
    m = re.fullmatch(r'([0-9]{1,9})(?:\.([0-9]{1,12}))?', s)
    if not m: return None
    micro = int(m.group(1))*1000000 + int((m.group(2) or '').ljust(6,'0')[:6])
    return micro if micro <= 100000*1000000 else None
def to_inr(micro, rate=30):
    paise = (micro*rate*10000 + 50000000)//100000000
    return '%d.%02d' % (paise//100, paise%100)
def micro_ok(p):
    m = to_micro(p); return m if (m is not None and m > 0) else None
def pick(a, fields):
    for f in fields:
        if f in a and isinstance(a[f], (str,int,float)) and not isinstance(a[f], bool) and str(a[f]) != '': return a[f]
def valid_id(v):
    if v is None or not isinstance(v,(str,int)) or isinstance(v,bool): return None
    v = str(v).strip()
    return str(int(v)) if re.fullmatch(r'[0-9]{1,10}', v) and int(v) > 0 else None
def price_of(n):
    p = pick(n, ['price','user_price','cost','rate','amount'])
    if p is None: return None
    try: float(p)
    except: return None
    m = to_micro(p); return None if m is None else '%d.%04d' % divmod((m+50)//100, 10000)
def cur_of(n):
    c = pick(n, ['currency','price_currency','currency_code']); c = (str(c).strip().upper() if c else '')
    return c if re.fullmatch(r'[A-Z]{3}', c) else ''
def is_list(v): return isinstance(v, list) and len(v) > 0
VAR = ['variants','plans','packages','durations','tiers','services']; CON = ['products','items']
def collect(nodes, inherit, out, depth=0):
    if depth > 5: return
    for n in nodes:
        if not isinstance(n, dict): continue
        cur = cur_of(n) or inherit
        done = False
        for k in CON:
            if is_list(n.get(k)) and (valid_id(pick(n,['product_id','id','pid'])) is None or price_of(n) is None):
                collect(n[k], cur, out, depth+1); done = True; break
        if done: continue
        for k in VAR:
            if not is_list(n.get(k)): continue
            for v in n[k]:
                if not isinstance(v, dict): continue
                vid = valid_id(pick(v, ['variant_id','plan_id','service_id','product_id','id','pid']))
                if vid is None: continue
                rec(out, vid, v, cur_of(v) or cur)
            done = True; break
        if done: continue
        i = valid_id(pick(n, ['product_id','id','pid','service_id']))
        if i is not None: rec(out, i, n, cur)
def rec(out, i, n, cur):
    sig = (price_of(n) or '') + '|' + cur
    if i in out:
        if out[i]['sig'] != sig: out[i]['conflict'] = True
        return
    out[i] = {'price': price_of(n), 'currency': cur, 'sig': sig, 'conflict': False}
def website_price(it):
    cur = it['currency'] or 'USD'; m = micro_ok(it['price'])
    if m is None: return None
    if cur == 'INR': return to_inr(m, 1)
    if cur != 'USD': return None
    return to_inr(m, 30)
def decide(stored, it):
    if it is None: return 'mapping_error'
    if it['conflict']: return 'invalid'
    w = website_price(it)
    if w is None: return 'invalid'
    return 'already' if '%.2f' % float(stored) == w else ('correct->' + w)
fail = 0
def t(name, got, exp):
    global fail
    ok = got == exp; fail += (not ok); print(('PASS ' if ok else 'FAIL ') + name + ('' if ok else '  got=%r exp=%r' % (got, exp)))
for usd, inr in [('1.00','30.00'),('2.00','60.00'),('3.50','105.00'),('5.00','150.00'),('0.99','29.70'),('12.345','370.35')]:
    t('$%s -> INR %s' % (usd, inr), to_inr(to_micro(usd)), inr)
cat = {'data': {'products': [
    {'id': 10, 'name': 'A 1 DAY', 'price': '1.00', 'currency': 'USD'},
    {'product_id': 11, 'name': 'A 3 DAY', 'price': 3.5},
    {'id': 5, 'name': 'Parent', 'variants': [{'variant_id': 21, 'duration': '1 DAY', 'price': '2.00'}, {'variant_id': 22, 'duration': '7 DAY', 'price': '5.00'}]},
    {'name': 'Cat', 'products': [{'id': 30, 'name': 'X', 'price': '0'}, {'id': 31, 'name': 'Y', 'price': 'abc'}, {'id': 32, 'name': 'Z'}]},
    {'id': 40, 'name': 'D', 'price': '1.00'}, {'id': 40, 'name': 'D', 'price': '2.00'},
    {'id': 41, 'name': 'E', 'price': '1.00'}, {'id': 41, 'name': 'E', 'price': '1.00'},
    {'id': 50, 'name': 'F', 'price': '100', 'currency': 'INR'}, {'id': 51, 'name': 'G', 'price': '1', 'currency': 'EUR'},
]}}
m = {}; collect(cat['data']['products'], '', m)
t('flat id 10', m['10']['price'], '1.0000'); t('flat product_id 11', m['11']['price'], '3.5000')
t('nested variants 21/22', (m['21']['price'], m['22']['price']), ('2.0000','5.0000')); t('parent id 5 not recorded', '5' in m, False)
t('container children found', sorted(k for k in m if k in ('30','31','32')), ['30','31','32'])
t('duplicate with different price = conflict', m['40']['conflict'], True); t('identical duplicate harmless', m['41']['conflict'], False)
t('10: wrong price 29.99 -> corrected to 30.00', decide('29.99', m['10']), 'correct->30.00')
t('10: admin custom price 99 -> corrected', decide('99.00', m['10']), 'correct->30.00')
t('10: already 30.00', decide('30.00', m['10']), 'already')
t('11: 3.5 -> 105.00', decide('1.00', m['11']), 'correct->105.00')
t('INR price is not converted again', decide('1.00', m['50']), 'correct->100.00')
t('zero price keeps stored', decide('50', m['30']), 'invalid'); t('garbage price keeps stored', decide('50', m['31']), 'invalid')
t('missing price keeps stored', decide('50', m['32']), 'invalid'); t('conflict keeps stored', decide('50', m['40']), 'invalid')
t('unsupported currency keeps stored', decide('50', m['51']), 'invalid'); t('not in catalog = mapping error', decide('50', m.get('999')), 'mapping_error')
print('FAILED:', fail); raise SystemExit(1 if fail else 0)
