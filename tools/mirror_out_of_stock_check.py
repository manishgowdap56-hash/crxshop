#!/usr/bin/env python3
"""v58 MIRROR check (Python re-implementation of the PHP rules, SQLite for the wallet). Proves the RULES, not the PHP.
Run: python3 tools/mirror_out_of_stock_check.py"""
import re, sqlite3, sys

class Uncertain(Exception): pass

def is_stock(msg):
    m = re.sub(r'[_\-]+', ' ', msg)
    return re.search(r'out of stock|insufficient stock|no stock|not enough stock|sold out|stock (?:is )?(?:empty|exhausted|unavailable|finished)', m, re.I) is not None

def failure_code(raw, resp):
    c = re.sub(r'[^A-Za-z0-9]+', '_', str(resp.get('error_code', '')).strip()).upper() if resp.get('error_code') is not None else ''
    if c == 'OUT_OF_STOCK': return 'OUT_OF_STOCK'
    if raw and is_stock(raw): return 'OUT_OF_STOCK'
    return ''

def err_msg(j, http):
    e = j.get('error')
    if isinstance(e, dict) and str(e.get('message', '')).strip():
        code = str(e.get('code', '')).strip(); m = str(e['message']).strip()
        return m + (f' [{code}]' if code and code.lower() not in m.lower() else '')
    if isinstance(e, str) and e.strip(): return e.strip()
    if str(j.get('message', '')).strip(): return str(j['message']).strip()
    return f'Provider rejected the request (HTTP {http}).'

def generate(j, http, unparsed=False):   # mirrors licencedash_generate() after licencedash_request()
    if http >= 500 and j.get('ok') is not False: raise Uncertain('server error')
    ok = j.get('ok') is True and 200 <= http < 300
    keys = []
    if ok:
        d = j.get('data') if isinstance(j.get('data'), dict) else {}
        keys = [str(k).strip() for k in (d.get('keys') or []) if str(k).strip()]
    if ok and keys: return {'ok': True, 'keys': keys}
    okf = j.get('ok'); is4 = 400 <= http < 500 and http != 408
    if ok: raise Uncertain('accepted but no key')
    if not (okf is False or (is4 and okf is not True)): raise Uncertain('unrecognised response')
    out = {'ok': False, 'error': err_msg(j, http)}
    e = j.get('error')
    if isinstance(e, dict) and e.get('code') is not None: out['error_code'] = str(e['code'])[:64]
    return out

def classify(mock):                       # mirrors provider_call_classified()
    try: r = mock()
    except Uncertain as e: return 'uncertain', str(e), {}
    except RuntimeError as e: return 'uncertain', str(e), {}      # timeout etc.
    if r['ok'] is False: return 'rejected', r['error'], r
    return 'success', '', r

def wallet():
    db = sqlite3.connect(':memory:')
    db.executescript("""create table u(id int, bal real); create table o(id int, ref text, st text, fc text, fr text);
    create table l(uid int, type text, ref text, amt real, desc text);""")
    db.execute('insert into u values(1,100)'); return db

def charge(db, oid, amt=10.0):
    db.execute('insert into o values(?,?,?,?,?)', (oid, f'R{oid}', 'pending', None, None))
    db.execute('update u set bal=bal-?', (amt,)); db.execute("insert into l values(1,'purchase',?,?,?)", (f'R{oid}', -amt, 'p'))

def refund_once(db, oid, code=''):       # mirrors order_refund_once(): status gate + ledger dup check + deducted amount
    st = db.execute('select st from o where id=?', (oid,)).fetchone()[0]
    if st != 'pending': return 'already_settled'
    ref = f'R{oid}'
    if db.execute("select 1 from l where type='refund' and ref=?", (ref,)).fetchone(): return 'already_settled'
    row = db.execute("select amt from l where type='purchase' and ref=?", (ref,)).fetchone()
    if not row: return 'no_charge'
    amt = abs(row[0]); db.execute('update u set bal=bal+?', (amt,))
    db.execute("update o set st='failed', fc=? where id=? and st='pending'", (code or None, oid))
    db.execute("insert into l values(1,'refund',?,?,?)", (ref, amt, 'Refund: order failed - OUT OF STOCK' if code == 'OUT_OF_STOCK' else 'Refund: order could not be completed'))
    return 'refunded'

def settle(db, oid, kind, raw, resp):    # mirrors order_settle_failure()
    if kind == 'uncertain':
        db.execute("update o set fr=? where id=? and st='pending'", ('UNCERTAIN: ' + raw, oid)); return 'reconcile'
    return refund_once(db, oid, failure_code(raw, resp) if kind == 'rejected' else '')

fails = 0
def t(name, ok):
    global fails; print(('[PASS] ' if ok else '[FAIL] ') + name); fails += (not ok)

def run(mock, oid):
    db = wallet(); charge(db, oid)
    kind, raw, resp = classify(mock)
    if kind == 'success': db.execute("update o set st='completed' where id=?", (oid,)); res = 'success'
    else: res = settle(db, oid, kind, raw, resp)
    o = db.execute('select st,fc from o where id=?', (oid,)).fetchone()
    return db, kind, res, o, db.execute('select bal from u').fetchone()[0], db.execute("select count(*) from l where type='refund'").fetchone()[0]

OOS = lambda: generate({'ok': False, 'error': {'code': 'OUT_OF_STOCK', 'message': 'Selected variation is out of stock'}}, 409)
db, kind, res, o, bal, nref = run(OOS, 1)
t('A confirmed out of stock -> rejected, refunded, FAILED, OUT_OF_STOCK, wallet restored, 1 refund row', kind == 'rejected' and res == 'refunded' and o == ('failed', 'OUT_OF_STOCK') and bal == 100 and nref == 1)
desc = db.execute("select desc from l where type='refund'").fetchone()[0]
t('A ledger refund description records OUT OF STOCK', 'OUT OF STOCK' in desc)
db, kind, res, o, bal, nref = run(lambda: generate({'ok': False, 'error': {'code': 'OUT_OF_STOCK', 'message': 'Product unavailable'}}, 409), 2)
t('B code-only (message lacks stock wording) -> OUT_OF_STOCK', o == ('failed', 'OUT_OF_STOCK') and bal == 100)
db, kind, res, o, bal, nref = run(lambda: generate({'ok': False, 'error': {'code': 'INVALID_PRODUCT', 'message': 'Unknown product'}}, 422), 3)
t('C other explicit refusal -> refunded but NOT labelled OUT OF STOCK', res == 'refunded' and o == ('failed', None) and bal == 100)
db, kind, res, o, bal, nref = run(lambda: generate({'ok': True, 'data': {'keys': ['AAAA-BBBB']}}, 200), 4)
t('D success -> completed, no refund, wallet stays charged', res == 'success' and o[0] == 'completed' and bal == 90 and nref == 0)
t('D refund_once on completed order refused', refund_once(db, 4, 'OUT_OF_STOCK') == 'already_settled' and db.execute("select count(*) from l where type='refund'").fetchone()[0] == 0)
def boom(): raise RuntimeError('API provider timed out.')
for label, mock in [('timeout', boom), ('HTTP 200 unknown body', lambda: generate({'foo': 1}, 200)),
                    ('ok:true no key', lambda: generate({'ok': True, 'data': {'keys': []}}, 200)),
                    ('HTTP 502 no ok:false', lambda: generate({'message': 'Bad gateway'}, 502)),
                    ('HTTP 503 mentioning stock, no ok:false', lambda: generate({'message': 'out of stock?'}, 503)),
                    ('HTTP 302 redirect body', lambda: generate({}, 302))]:
    db, kind, res, o, bal, nref = run(mock, 5)
    t(f'E [{label}] -> uncertain: pending, NOT refunded, no failure code, wallet held', kind == 'uncertain' and res == 'reconcile' and o == ('pending', None) and bal == 90 and nref == 0)
db, *_ = run(OOS, 6); before = db.execute('select bal from u').fetchone()[0]
for _ in range(10): settle(db, 6, 'rejected', 'out of stock', {'error_code': 'OUT_OF_STOCK'}); refund_once(db, 6, 'OUT_OF_STOCK')
t('F ten repeated settle/callback/cron calls -> still exactly one refund, balance unchanged', db.execute("select count(*) from l where type='refund'").fetchone()[0] == 1 and db.execute('select bal from u').fetchone()[0] == before)
t('detect: "OUT_OF_STOCK" underscore code text', is_stock('Product unavailable [OUT_OF_STOCK]'))
t('detect: timeout text is NOT stock', not is_stock('API provider timed out.') and not is_stock('Provider rejected the request (HTTP 409).'))
print('ALL PASSED' if fails == 0 else f'{fails} FAILED'); sys.exit(1 if fails else 0)
