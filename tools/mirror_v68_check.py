#!/usr/bin/env python3
"""SUPERSEDED by tools/check_v69_fix.py + tools/zapupi_selftest.php. WARNING: this old mirror modelled order-status as a FLAT reply, which is
not the layout ZapUPI documents (envelope + nested "data"); that is why it passed while real payments stayed PENDING. Kept only for its paise/URL/SQLite checks.

v68 LOGIC MIRROR (Python re-implementation of the PHP rules + SQLite for the unique-key behaviour).
This is NOT the PHP code running. It checks that the RULES are coherent; it cannot prove the PHP matches. Run real tests on a server."""
import re, sqlite3, threading, sys
from urllib.parse import urlparse
ok = True
def chk(n, c):
    global ok; ok &= bool(c); print(('PASS ' if c else 'FAIL ') + n)

def paise(v):                       # mirror of zapupi_paise
    if isinstance(v, bool): return None
    if isinstance(v, int): return v*100 if 0 <= v <= 999999999 else None
    if isinstance(v, float):
        if v < 0 or v > 999999999: return None
        v = f'{v:.2f}'
    if not isinstance(v, str): return None
    m = re.fullmatch(r'(\d{1,9})(?:\.(\d{1,2}))?', v.strip())
    if not m: return None
    return int(m[1])*100 + int((m[2] or '00').ljust(2, '0'))
chk('paise: 100 / "100.00" / "100.5" / 99.99', [paise(100), paise('100.00'), paise('100.5'), paise(99.99)] == [10000, 10000, 10050, 9999])
chk('paise: rejects 0.001, -5, abc, 1e3, "", None, [], True', all(paise(x) is None for x in ['0.001', '-5', 'abc', '1e3', '', None, [], True]))
chk('paise: 0.1+0.2 style float safe (30 paise)', paise(0.1+0.2) == 30)

def url_ok(u):                      # mirror of zapupi_https_zapupi_url
    if not u or len(u) > 2048 or re.search(r'[\x00-\x20\x7f]', u): return False
    p = urlparse(u)
    if p.scheme.lower() != 'https' or p.username or p.password: return False
    if p.port not in (None, 443): return False
    h = (p.hostname or '').lower()
    return h == 'zapupi.com' or h.endswith('.zapupi.com')
good = ['https://pay.zapupi.com/instent-pay-X', 'https://zapupi.com/a']
bad = ['http://pay.zapupi.com/x', 'https://evil.com/x', 'https://pay.zapupi.com.evil.com/x', 'https://evilzapupi.com/x', 'https://user:p@pay.zapupi.com/x',
       'https://pay.zapupi.com:8443/x', 'javascript:alert(1)', '//pay.zapupi.com/x', 'https://pay.zapupi.com/x y', '']
chk('url: zapupi https accepted', all(url_ok(u) for u in good))
chk('url: http, lookalike hosts, creds, odd port, js:, protocol-relative, spaces rejected', not any(url_ok(u) for u in bad))

def decide(local_paise, resp, stored_txn='', allow_test=False):      # mirror of verifyZapupiPayment decision order
    st = str(resp.get('status', '')).lower()
    if resp.get('order_id', '') not in ('', 'L1'): return 'verification_failed'
    if st == 'failed': return 'failed'
    if st != 'success': return 'pending'
    if not resp.get('order_id'): return 'verification_failed'
    txn = resp.get('txn_id', '')
    if not re.fullmatch(r'[A-Za-z0-9_\-]{4,64}', txn or ''): return 'verification_failed'
    env = str(resp.get('environment', '')).lower()
    if (env == 'test' or txn.upper().startswith('DUMMY')) and not allow_test: return 'test_ignored'
    if env not in ('cashier', 'zappay'): return 'verification_failed'
    if stored_txn and stored_txn != txn: return 'verification_failed'
    if not re.fullmatch(r'[A-Za-z0-9_\-]{4,64}', resp.get('utr', '') or ''): return 'verification_failed'
    if paise(resp.get('amount')) != local_paise: return 'verification_failed'
    if resp.get('pay_amount') not in (None, ''):
        pa = paise(resp['pay_amount'])
        if pa is None or pa < local_paise: return 'verification_failed'
    return 'success'
base = dict(status='Success', order_id='L1', txn_id='ZAPUPI123', utr='4434554', amount='100.00', pay_amount='100.00', environment='cashier')
chk('verify: good payment -> success', decide(10000, base) == 'success')
chk('verify: pay_amount slightly higher (provider fee/paise) -> success', decide(10000, {**base, 'pay_amount': '100.37'}) == 'success')
for name, mod, exp in [('pending status', {'status': 'Pending'}, 'pending'), ('failed status', {'status': 'Failed'}, 'failed'), ('empty status', {'status': ''}, 'pending'),
                       ('wrong order id', {'order_id': 'L2'}, 'verification_failed'), ('missing order id', {'order_id': ''}, 'verification_failed'),
                       ('amount mismatch', {'amount': '99.00'}, 'verification_failed'), ('pay_amount lower', {'pay_amount': '50.00'}, 'verification_failed'),
                       ('missing txn', {'txn_id': ''}, 'verification_failed'), ('missing utr (bare API envelope)', {'utr': ''}, 'verification_failed'),
                       ('test env', {'environment': 'test'}, 'test_ignored'), ('DUMMY txn', {'txn_id': 'DUMMY123'}, 'test_ignored'),
                       ('DUMMY txn but env cashier', {'txn_id': 'dummy999', 'environment': 'cashier'}, 'test_ignored'),
                       ('unknown env', {'environment': 'sandbox'}, 'verification_failed'), ('missing env', {'environment': ''}, 'verification_failed')]:
    chk(f'verify: {name} -> {exp}', decide(10000, {**base, **mod}) == exp)
chk('verify: txn differs from stored create-time txn -> verification_failed', decide(10000, base, stored_txn='ZUA1') == 'verification_failed')

# ---- DB-level exactly-once behaviour (SQLite mirror of the MySQL unique keys + guarded UPDATE) ----
def run(n_threads):
    db = sqlite3.connect(':memory:', check_same_thread=False, isolation_level=None); db.execute('PRAGMA busy_timeout=5000')
    db.executescript("""CREATE TABLE users(id INTEGER PRIMARY KEY, balance INTEGER);
        CREATE TABLE deposits(id INTEGER PRIMARY KEY, user_id INT, amount INT, status TEXT, credit_status TEXT);
        CREATE TABLE payment_credits(id INTEGER PRIMARY KEY, deposit_id INT UNIQUE, gateway TEXT, txn TEXT, UNIQUE(gateway, txn));
        CREATE TABLE ledger(id INTEGER PRIMARY KEY, user_id INT, amount INT);
        INSERT INTO users VALUES(1, 0); INSERT INTO deposits VALUES(1, 1, 10000, 'pending', 'pending');""")
    lock = threading.Lock(); results = []
    def credit():
        with lock:                          # SQLite is one writer; stands in for SELECT ... FOR UPDATE row lock
            db.execute('BEGIN')
            try:
                d = db.execute('SELECT status FROM deposits WHERE id=1').fetchone()
                if d[0] != 'pending': db.execute('ROLLBACK'); results.append('already'); return
                db.execute('UPDATE users SET balance = balance + 10000 WHERE id=1')
                db.execute('INSERT INTO ledger(user_id, amount) VALUES(1, 10000)')
                db.execute("INSERT INTO payment_credits(deposit_id, gateway, txn) VALUES(1,'zapupi','T1')")
                cur = db.execute("UPDATE deposits SET status='approved', credit_status='success' WHERE id=1 AND status='pending'")
                if cur.rowcount != 1: raise RuntimeError('state changed')
                db.execute('COMMIT'); results.append('credited')
            except sqlite3.IntegrityError: db.execute('ROLLBACK'); results.append('blocked_by_unique')
    ts = [threading.Thread(target=credit) for _ in range(n_threads)]
    [t.start() for t in ts]; [t.join() for t in ts]
    return db, results
db, res = run(25)
chk('25 concurrent success webhooks -> exactly 1 credit', res.count('credited') == 1 and db.execute('SELECT balance FROM users').fetchone()[0] == 10000 and db.execute('SELECT COUNT(*) FROM ledger').fetchone()[0] == 1)
db, _ = run(1)
# even if the status guard were bypassed, the UNIQUE keys alone stop a second credit:
try:
    db.execute("INSERT INTO payment_credits(deposit_id, gateway, txn) VALUES(1,'zapupi','T2')"); blocked = False
except sqlite3.IntegrityError: blocked = True
chk('UNIQUE(deposit_id) blocks 2nd credit record even if PHP checks were skipped', blocked)
db.execute("INSERT INTO deposits VALUES(2,1,10000,'pending','pending')")
try:
    db.execute("INSERT INTO payment_credits(deposit_id, gateway, txn) VALUES(2,'zapupi','T1')"); blocked = False
except sqlite3.IntegrityError: blocked = True
chk('UNIQUE(gateway, txn): one provider transaction cannot fund two orders', blocked)
sys.exit(0 if ok else 1)
