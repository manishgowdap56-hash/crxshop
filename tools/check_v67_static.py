#!/usr/bin/env python3
"""v67 source-text invariants for Master Frontend Control. NOT a PHP/browser run - it only reads the files."""
import re, sys, pathlib
R = pathlib.Path(__file__).resolve().parent.parent
rd = lambda p: (R / p).read_text(encoding='utf-8')
ok = True
def chk(name, cond):
    global ok; ok &= bool(cond); print(('PASS ' if cond else 'FAIL ') + name)

for f in ['includes/header.php','admin.php','admin-monitor.php','login.php','register.php','welcome.php','maintenance.php']:
    s = rd(f); chk(f'{f}: theme_head injected once, inside <head>, before </head>', s.count('theme_head(') == 1 and s.index('theme_head(') < s.index('</head>'))
chk('admin.php: admin side only (safe=1 disables)', "empty($_GET['safe']) ? 'admin' : ''" in rd('admin.php'))
chk('user pages never request the admin theme', all("theme_head('admin')" not in rd(f) for f in ['includes/header.php','register.php','welcome.php','maintenance.php']))
t = rd('includes/theme.php')
chk('theme.php: reset deletes ONLY the theme_config row', len(re.findall(r'DELETE FROM', t)) == 1 and "DELETE FROM settings WHERE setting_key = :k" in t)
chk('theme.php: no other SQL than the settings row', not re.search(r'(UPDATE|INSERT|TRUNCATE|DROP|ALTER)\s', t))
chk('theme.php: theme_head swallows all errors (graceful fallback)', 'catch (Throwable $e)' in t and "return '';" in t)
chk('theme.php: only #RRGGBB accepted', "#[0-9a-fA-F]{6}$/D" in t)
chk('theme.php: 23 colours', len(re.findall(r"^\s+'[a-z0-9_]+'\s+=> \['[^']+',\s*'[A-Za-z ]+',\s*'--", t, re.M)) == 23)
chk('theme.php: 6 font options', all(f"'{k}'" in t for k in ['original','dashboard','poppins','montserrat','outfit','playfair']))
chk("theme.php: 'all' writes both sides, side scopes write one", "($scope === 'all' ? THEME_SIDES : [$scope])" in t)
chk('theme.php: monospace variable never overridden', "--font-mono" not in t)
a = rd('api/admin/theme.php')
chk('api: admin only + CSRF on POST + no-store', 'require_admin()' in a and 'csrf_verify()' in a and 'no-store' in a)
chk('bootstrap includes theme.php', "includes/theme.php" in rd('includes/bootstrap.php') or "'/theme.php'" in rd('includes/bootstrap.php'))
chk('admin nav link present', 'admin-theme.php' in rd('admin.php'))
d = rd('deposit.php')
for i in ['depositQrFrame','depositOrderId','depositPayableAmount','depositCountdown','depositPaymentStatus','depositDownloadQr','depositCancelPayment','depositSuccessAmount','depositSuccessBalance','depositCreateOrder']:
    chk(f'deposit.php keeps id {i}', f'id="{i}"' in d)
chk('success screen uses the global logo (panel_logo_url + data-global-logo)', 'class="pay-logo-frame"><img data-global-logo src="<?= e(panel_logo_url()) ?>"' in d)
c = rd('assets/css/deposit-premium.css')
chk('QR never filtered/blended', 'filter:none!important' in c and 'mix-blend-mode:normal!important' in c and 'backdrop-filter' not in c.split('.payment-qr-frame')[1].split('}')[0])
chk('QR sits on pure white quiet zone', re.search(r'\.payment-qr-frame\{[^}]*background:#fff', c) is not None)
js = rd('assets/js/deposit.js')
chk('deposit.js: only the display-only order-id line added', js.count('v67: display only') == 1)
sys.exit(0 if ok else 1)
