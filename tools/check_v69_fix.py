#!/usr/bin/env python3
"""v69 ZapUPI fix checks.  Usage: python3 tools/check_v69_fix.py [path/to/original_v68.zip]

WHAT THIS IS / IS NOT
  * NOT a PHP run (no PHP binary was available when this was written). The authoritative test is
    `php tools/zapupi_selftest.php` on your server, which executes the real PHP functions.
  * A: a Python PORT of zapupi_judge_order_status() driven by the response layout ZapUPI documents. It proves the rules,
       and that v68's top-level parsing could never have accepted that layout.
  * B: source-text checks that the PHP really contains the fix.
  * C: an APPROXIMATE bracket/quote balance lint of the PHP files (hand-written tokenizer, not `php -l`).
  * D: byte-identity of every Paytm file (and everything else not intentionally changed) against the zip you uploaded.
"""
import re, sys, pathlib, zipfile
R = pathlib.Path(__file__).resolve().parent.parent
rd = lambda p: (R / p).read_text(encoding='utf-8')
ok = True
def chk(name, cond, detail=''):
    global ok; ok &= bool(cond); print(('PASS ' if cond else 'FAIL ') + name + ('' if cond else '   <-- ' + str(detail)))

# ------------------------------------------------------------------ A. logic port
def paise(v):
    if isinstance(v, bool): return None
    if isinstance(v, int): return v * 100 if 0 <= v <= 999999999 else None
    if isinstance(v, float):
        if v < 0 or v > 999999999: return None
        v = f'{v:.2f}'
    if not isinstance(v, str): return None
    m = re.fullmatch(r'(\d{1,9})(?:\.(\d{1,2}))?', v.strip())
    return None if not m else int(m[1]) * 100 + int((m[2] or '00').ljust(2, '0'))

def s(a, k):
    v = a.get(k)
    return str(v).strip() if v is not None and not isinstance(v, (dict, list, bool)) else ''

def unwrap(j):                                              # port of zapupi_unwrap_order_status
    env = s(j, 'status').lower()
    if isinstance(j.get('data'), dict) or isinstance(j.get('data'), list):
        d = j['data']
        if isinstance(d, list) and d and isinstance(d[0], dict): d = d[0]
        if isinstance(d, dict): return env, d, 'nested'
    if s(j, 'order_id') != '': return 'success', j, 'flat'
    return env, None, 'none'

ID = r'[A-Za-z0-9_\-]{4,64}'
def judge(local_paise, order_id, stored_txn, j, allow_test=False):          # port of zapupi_judge_order_status
    env_, d, _ = unwrap(j)
    if env_ != 'success': return 'pending'
    if d is None: return 'pending'
    st, ro, txn = s(d, 'status').lower(), s(d, 'order_id'), s(d, 'txn_id')
    env, utr = s(d, 'environment').lower(), s(d, 'utr')
    if ro != '' and ro != order_id: return 'verification_failed'
    if st == 'failed': return 'failed'
    if st != 'success': return 'pending'
    if ro == '': return 'verification_failed'
    if txn == '' or not re.fullmatch(ID, txn): return 'verification_failed'
    if (env == 'test' or txn.upper().startswith('DUMMY')) and not allow_test: return 'test_ignored'
    if env not in ('cashier', 'zappay') and not (allow_test and env == 'test'): return 'verification_failed'
    if stored_txn and stored_txn != txn: return 'verification_failed'
    if utr == '' or not re.fullmatch(ID, utr): return 'verification_failed'
    amt = paise(d.get('amount'))
    if amt is None or amt != local_paise: return 'verification_failed'
    pa = d.get('pay_amount')
    if pa not in (None, ''):
        p = paise(pa)
        if p is None or p < local_paise: return 'verification_failed'
    return 'success'

def v68_judge(local_paise, order_id, stored_txn, j):                          # the OLD behaviour, to prove the root cause
    """v68 read every field from the TOP level of the order-status reply."""
    st = s(j, 'status').lower(); ro = s(j, 'order_id')
    if ro != '' and ro != order_id: return 'verification_failed'
    if st == 'failed': return 'failed'
    if st != 'success': return 'pending'
    if ro == '': return 'verification_failed'        # <- every real payment ended here (reason order_id_missing)
    return 'success'

OID = 'ZP0123456789ABCDEF0123'; TXN = 'ZAPUPI66f10bd3c1f28'
paid = dict(order_id=OID, status='Success', amount='20.00', pay_amount='20.00', txn_id=TXN, utr='443455455550', environment='cashier')
wrap = lambda d, e='success': {'status': e, 'data': d}
J = lambda r, **kw: judge(2000, OID, TXN, r, **kw)

chk('ROOT CAUSE: v68 logic on the documented layout never credits (stuck at verification_failed => PENDING forever)', v68_judge(2000, OID, TXN, wrap(paid)) == 'verification_failed')
chk('ROOT CAUSE: v68 logic would also never mark a Failed payment failed', v68_judge(2000, OID, TXN, wrap({**paid, 'status': 'Failed'})) != 'failed')
chk('FIX: documented layout, data.status Success -> success', J(wrap(paid)) == 'success')
chk('data.status Pending -> pending', J(wrap({**paid, 'status': 'Pending'})) == 'pending')
chk('data.status Failed -> failed', J(wrap({**paid, 'status': 'Failed', 'utr': ''})) == 'failed')
chk('error envelope -> pending', J({'status': 'error', 'message': 'Order not found'}) == 'pending')
chk('success envelope without data -> pending (never credits)', J({'status': 'success'}) == 'pending')
chk('tolerance: flat layout with order_id -> success', J(paid) == 'success')
chk('tolerance: data as one-element list -> success', J({'status': 'success', 'data': [paid]}) == 'success')
chk('status is case-insensitive', J(wrap({**paid, 'status': 'SUCCESS'})) == 'success')
chk('wrong order id -> verification_failed', J(wrap({**paid, 'order_id': 'ZPOTHER'})) == 'verification_failed')
chk('missing order id -> verification_failed', J(wrap({**paid, 'order_id': ''})) == 'verification_failed')
chk('amount mismatch -> verification_failed', J(wrap({**paid, 'amount': '19.00'})) == 'verification_failed')
chk('pay_amount lower -> verification_failed', J(wrap({**paid, 'pay_amount': '10.00'})) == 'verification_failed')
chk('pay_amount slightly higher -> success', J(wrap({**paid, 'pay_amount': '20.37'})) == 'success')
chk('missing utr -> verification_failed', J(wrap({**paid, 'utr': ''})) == 'verification_failed')
chk('txn id differs from stored -> verification_failed', J(wrap({**paid, 'txn_id': 'ZAPUPIsomethingelse'})) == 'verification_failed')
chk('test environment (live site) -> test_ignored', J(wrap({**paid, 'environment': 'test'})) == 'test_ignored')
chk('DUMMY txn (live site) -> test_ignored', judge(2000, OID, '', wrap({**paid, 'txn_id': 'DUMMY12345'})) == 'test_ignored')
chk('unknown environment -> verification_failed', J(wrap({**paid, 'environment': 'mystery'})) == 'verification_failed')
chk('custom amounts 20/50/100/500/1000/20.5 each verify against their own local amount',
    all(judge(paise(a), OID, TXN, wrap({**paid, 'amount': a, 'pay_amount': a})) == 'success' for a in ['20.00', '50.00', '100.00', '500.00', '1000.00', '20.5']))
chk('a ₹20 local order is NOT satisfied by a ₹50 provider answer', judge(2000, OID, TXN, wrap({**paid, 'amount': '50.00', 'pay_amount': '50.00'})) == 'verification_failed')

def from_request(get, qs):                                  # port of zapupi_order_from_request
    from urllib.parse import unquote
    for k in ('order_id', 'order'):
        v = get.get(k)
        if isinstance(v, str):
            m = re.search(r'ZP[A-F0-9]{20}', v)
            if m: return m[0]
    m = re.search(r'ZP[A-F0-9]{20}', unquote(qs)); return m[0] if m else ''
REF = 'ZP' + 'AB12' * 5
chk('return URL ?order_id=… (documented)', from_request({'order_id': REF, 'utr': '1'}, f'order_id={REF}&utr=1') == REF)
chk('return URL v68 ?order=… mangled by second "?"', from_request({'order': f'{REF}?order_id={REF}'}, f'order={REF}?order_id={REF}&utr=1') == REF)
chk('return URL with nothing -> empty (page uses latest own order)', from_request({}, '') == '')
chk('return URL cannot smuggle another id shape (no ZP+20hex -> empty)', from_request({'order_id': "x' OR 1=1 --"}, "order_id=x'%20OR%201=1") == '')

# ------------------------------------------------------------------ B. source checks
z = rd('includes/zapupi.php')
judge_src = z.split('function zapupi_judge_order_status')[1].split('/* ====')[0]
chk('PHP: order-status reply is unwrapped (data envelope)', 'function zapupi_unwrap_order_status' in z and "isset($j['data']) && is_array($j['data'])" in z)
chk('PHP: judge reads payment fields from the unwrapped data ($d), never from the raw top level ($j)',
    "zapupi_s($d, 'status')" in judge_src and "zapupi_s($d, 'order_id')" in judge_src and "zapupi_s($d, 'utr')" in judge_src and not re.search(r"zapupi_s\(\$j, '(order_id|txn_id|utr|environment)'\)", judge_src))
chk('PHP: verifyZapupiPayment delegates to the pure judge', 'zapupi_judge_order_status($dep, $orderId, $localPaise, $j, zapupi_test_credit_allowed())' in z)
chk('PHP: judge is pure (no db()/curl/set_setting/audit_log inside)', not re.search(r'\bdb\(\)|curl_|set_setting|audit_log|zapupi_http_post|\$pdo', judge_src))
chk('PHP: throttle gap is per caller and webhook stays fastest', "case 'webhook': return 1;" in z and "case 'poll':    return 10;" in z and "case 'page':    return 15;" in z and 'zapupi_status_gap_seconds($source)' in z)
chk('PHP: return URLs carry no own query string', "zapupi_public_url('payment-success.php')" in z and "payment-success.php?order=" not in z)
res = rd('includes/zapupi_result.php')
chk('PHP: result page uses the tolerant extractor + ownership check + no credit call', 'zapupi_order_from_request()' in res and 'user_id = :u AND method' in res and 'zapupi_credit_verified' not in re.sub(r'/\*.*?\*/', '', res, flags=re.S))
chk('PHP: result page fallback is limited to the logged-in user, last 6 h', "WHERE user_id = :u AND method = :m AND created_at > (NOW() - INTERVAL 6 HOUR)" in res)
chk('PHP: credit path unchanged (single caller, row locks, unique keys)', len(re.findall(r'zapupi_credit_verified\(\$pdo', z)) == 1 and z.count('FOR UPDATE') >= 2 and 'uniq_pc_deposit' in z and 'uniq_pc_gateway_txn' in z)
chk('PHP: self-test script present and CLI-only', 'PHP_SAPI !== \'cli\'' in rd('tools/zapupi_selftest.php') and 'zapupi_judge_order_status' in rd('tools/zapupi_selftest.php'))

# ------------------------------------------------------------------ C. approximate PHP lint
def lint(src):
    stack, i, n, line = [], 0, len(src), 1
    pairs = {')': '(', ']': '[', '}': '{'}
    in_php = False
    while i < n:
        c = src[i]
        if not in_php:
            j = src.find('<?php', i)
            if j < 0: break
            line += src.count('\n', i, j); i = j + 5; in_php = True; continue
        if c == '\n': line += 1; i += 1; continue
        if src.startswith('?>', i): in_php = False; i += 2; continue
        if src.startswith('//', i) or c == '#':
            j = src.find('\n', i); j = n if j < 0 else j
            k = src.find('?>', i, j)
            i = k if k >= 0 else j; continue
        if src.startswith('/*', i):
            j = src.find('*/', i + 2)
            if j < 0: return f'unterminated comment at line {line}'
            line += src.count('\n', i, j); i = j + 2; continue
        if c in '\'"':
            q, j = c, i + 1
            while j < n and src[j] != q:
                if src[j] == '\\': j += 1
                if j < n and src[j] == '\n': line += 1
                j += 1
            if j >= n: return f'unterminated string starting line {line}'
            i = j + 1; continue
        if c in '([{': stack.append((c, line))
        elif c in ')]}':
            if not stack or stack[-1][0] != pairs[c]: return f"unbalanced '{c}' at line {line}"
            stack.pop()
        i += 1
    return f"unclosed '{stack[-1][0]}' from line {stack[-1][1]}" if stack else None
for f in ['includes/zapupi.php', 'includes/zapupi_result.php', 'tools/zapupi_selftest.php', 'api/zapupi-webhook.php', 'payment-success.php', 'payment-failed.php', 'payment-timeout.php']:
    r = lint(rd(f)); chk(f'lint (approximate): {f}', r is None, r)

# ------------------------------------------------------------------ D. Paytm / everything else byte-identical to the upload
zpath = sys.argv[1] if len(sys.argv) > 1 else '/mnt/user-data/uploads/mainsite_v68_zapupi.zip'
if pathlib.Path(zpath).exists():
    orig = zipfile.ZipFile(zpath)
    def o(path):
        for n in orig.namelist():
            if n == path or n.endswith('/' + path): return orig.read(n)
    intended = {'includes/zapupi.php', 'includes/zapupi_result.php', 'tools/mirror_v68_check.py'}   # the last one: header comment only (test script, not runtime)
    changed = set(); same = 0
    for name in orig.namelist():
        if name.endswith('/'): continue
        p = R / name
        if not p.exists(): changed.add(name + ' (MISSING)'); continue
        if p.read_bytes() != orig.read(name): changed.add(name)
        else: same += 1
    chk(f'only the intended PHP files differ from the uploaded zip (changed: {sorted(changed)})', changed == intended, sorted(changed ^ intended))
    for f in ['includes/paytm.php', 'api/deposits-status.php', 'api/deposits-create.php', 'api/deposits-upload-proof.php', 'api/admin/settings.php',
              'includes/finance.php', 'config/config.php', 'config/database.php', 'includes/maintenance_lock.php', 'includes/bootstrap.php',
              'api/zapupi-webhook.php', 'cron/zapupi_reconcile.php', 'assets/js/deposit.js']:
        chk(f'byte-identical to upload: {f}', o(f) == (R / f).read_bytes())
else:
    print('SKIP D (original zip not found; pass its path as argv[1])')
sys.exit(0 if ok else 1)
