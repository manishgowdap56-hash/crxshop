#!/usr/bin/env python3
"""v68 ZapUPI source-text invariants. NOT a PHP run: it only reads files (no PHP binary was available when this was written)."""
import re, sys, pathlib, subprocess, zipfile, hashlib, io
R = pathlib.Path(__file__).resolve().parent.parent
rd = lambda p: (R / p).read_text(encoding='utf-8')
ok = True
def chk(name, cond):
    global ok; ok &= bool(cond); print(('PASS ' if cond else 'FAIL ') + name)

z = rd('includes/zapupi.php')
chk('TLS verified (PEER=true, HOST=2), no verify disabled', 'CURLOPT_SSL_VERIFYPEER => true' in z and 'CURLOPT_SSL_VERIFYHOST => 2' in z and not re.search(r'VERIFY(PEER|HOST)\s*=>\s*(false|0)', z))
chk('cURL timeouts + no redirects + https-only', all(k in z for k in ['CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT', 'CURLOPT_FOLLOWLOCATION => false', 'CURLPROTO_HTTPS']))
chk('credit: row lock FOR UPDATE on deposits and users', z.count('FOR UPDATE') >= 2)
chk('credit: balance added in SQL (decimal), not PHP float', 'balance = balance + :a' in z)
chk('credit: UNIQUE credit record insert + 23000 handled', 'INSERT INTO payment_credits' in z and "=== '23000'" in z and 'uniq_pc_deposit' in z and 'uniq_pc_gateway_txn' in z)
chk('credit: guarded UPDATE (status pending, rowCount==1)', 'WHERE id = :id AND status = "pending"' in z and 'rowCount() !== 1' in z)
chk('credit function is called only from zapupi_settle', len(re.findall(r'zapupi_credit_verified\(\$pdo', z)) == 1)
chk('webhook never credits directly', 'zapupi_credit_verified' not in z.split('function zapupi_handle_webhook')[1].split('function zapupi_reconcile_pending')[0])
chk('no float casts on money in credit/verify code', '(float)' not in z.split('function verifyZapupiPayment')[1].split('function zapupi_credit_failed_alert')[0].replace('(float)$amountStr', ''))
chk('verify requires status success + order id + txn + utr + env + amount', all(k in z for k in ["'order_id_missing'", "'txn_id_missing'", "'utr_missing'", "'environment_invalid'", "'amount_mismatch'"]))
chk('test env / DUMMY blocked', "strncasecmp($txnId, 'DUMMY', 5)" in z and "'test_ignored'" in z and "APP_ENV !== 'production'" in z)
chk('payment_url must be https on zapupi.com', 'zapupi_https_zapupi_url($payUrl)' in z)
chk('API key never put in logs/safe json', 'zap_key' in z and "'zap_key'" not in z.split('function zapupi_safe_json')[1].split('function zapupi_is_test_payment')[0])
chk('order id is crypto random', "random_bytes(10)" in z)
chk('network failure never = success', "'provider_unreachable'" in z)
chk('manual-credit impossible for zapupi in admin API', "ZapUPI deposits are confirmed automatically" in rd('api/admin/deposits.php'))
hook = rd('api/zapupi-webhook.php')
chk('webhook: POST only, session closed, no CSRF dependence, returns json', "request_method() !== 'POST'" in hook and 'session_write_close' in hook and 'csrf_verify' not in hook)
adm = rd('api/admin/zapupi.php')
chk('admin api: require_admin + CSRF on POST', 'require_admin()' in adm and 'csrf_verify()' in adm)
chk('admin api never returns the key value', "'api_key'" not in adm.split('function zp_admin_state')[1].split("if ($method === 'GET')")[0] and 'zapupi_mask_key' in adm)
chk('admin api never reads/writes paytm_* settings', not re.search(r"['\"]paytm_", adm))
chk('API key not present in any frontend asset or page', not any('zapupi_api_key' in (R / f).read_text(encoding='utf-8', errors='ignore') for f in ['deposit.php','admin.php','assets/js/deposit.js','assets/js/admin.js','assets/js/payment-result.js','includes/zapupi_result.php']))
chk('config.php untouched by v68 (no hardcoded key)', 'zapupi' not in rd('config/config.php').lower())
res = re.sub(r'/\*.*?\*/', '', rd('includes/zapupi_result.php'), flags=re.S)   # ignore comments
chk('result pages: login + ownership + gateway check + server verify, no credit call', 'require_user()' in res and 'user_id = :u AND method' in res and 'zapupi_settle' in res and 'zapupi_credit_verified' not in res)
chk('deposit.js: same-tab redirect, no window.open for payment_url', 'window.location.assign(url)' in rd('assets/js/deposit.js') and 'window.open(url' not in rd('assets/js/deposit.js'))

# Paytm untouched: compare against the ORIGINAL upload
orig = zipfile.ZipFile('/mnt/user-data/uploads/mainsite_v67.zip')
names = {n.split('/', 1)[-1] if n.startswith('mainsite') else n: n for n in orig.namelist()}
def original(path):
    for n in orig.namelist():
        if n == path or n.endswith('/' + path): return orig.read(n)
    return None
for f in ['includes/paytm.php', 'config/config.php', 'api/deposits-upload-proof.php', 'api/admin/settings.php', 'includes/finance.php']:
    o = original(f); chk(f'unchanged vs v67: {f}', o is not None and o == (R / f).read_bytes())
o = original('api/deposits-status.php').decode(); n = rd('api/deposits-status.php')
chk('deposits-status.php: whole Paytm body preserved (only a zapupi branch inserted)', o.split("if ($dep['status'] !== 'pending' ||")[1] == n.split("if ($dep['status'] !== 'pending' ||")[1] and o.split("if ($dep['status'] !== 'pending' ||")[0] in n)
o = original('api/deposits-create.php').decode(); n = rd('api/deposits-create.php')
chk('deposits-create.php: Paytm path preserved (zapupi branch inserted before it)', o.split('// ---- Amount: validated')[1] == n.split('// ---- Amount: validated')[1])
sys.exit(0 if ok else 1)
