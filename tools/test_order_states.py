#!/usr/bin/env python3
"""Evaluates the REAL state expression from includes/refund_controller.php (RC_STATE_CASE_SQL) against synthetic order rows in SQLite.
Only the two MySQL time placeholders are swapped for SQLite equivalents; the CASE text itself is used unchanged.
This checks the classification LOGIC (one state per order, 'refunded' only with recorded wallet evidence). It does not test MySQL syntax."""
import re, sqlite3, sys, pathlib
src = (pathlib.Path(__file__).resolve().parent.parent / 'includes' / 'refund_controller.php').read_text(encoding='utf8')
m = re.search(r"const RC_STATE_CASE_SQL = <<<'SQL'\n(.*?)\nSQL;", src, re.S)
assert m, 'RC_STATE_CASE_SQL not found'
case = m.group(1).replace('{EXPIRED}', "o.created_at < datetime('now','-600 seconds')").replace(
    '{STALE_CLAIM}', "(o.last_checked_at IS NULL OR o.last_checked_at < datetime('now','-5 minutes'))")
db = sqlite3.connect(':memory:')
db.execute("CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT, refund_status TEXT, refund_method TEXT, refund_ledger_id INTEGER, created_at TEXT, last_checked_at TEXT)")
OLD, NEW = "datetime('now','-3 hours')", "datetime('now','-1 minutes')"
FRESH, STALE = "datetime('now','-30 seconds')", "datetime('now','-20 minutes')"
rows = [  # name, status, refund_status, method, ledger_id, created, last_checked, expected
 ('delivered',            'completed','NULL','NULL','NULL',OLD,'NULL','completed'),
 ('new pending',          'pending','NULL','NULL','NULL',NEW,'NULL','pending'),
 ('expired, unchecked',   'pending','NULL','NULL','NULL',OLD,'NULL','expired'),
 ('being checked now',    'pending',"'processing'",'NULL','NULL',OLD,FRESH,'processing'),
 ('stale claim',          'pending',"'processing'",'NULL','NULL',OLD,STALE,'expired'),
 ('verified, not refunded','pending',"'eligible'",'NULL','NULL',OLD,'NULL','refund_pending'),
 ('refund attempt failed','pending',"'failed'",'NULL','NULL',OLD,'NULL','refund_failed'),
 ('ambiguous provider',   'pending',"'review'",'NULL','NULL',OLD,'NULL','manual_review'),
 ('blocked (has key)',    'pending',"'blocked'",'NULL','NULL',OLD,'NULL','manual_review'),
 ('auto refunded',        'failed',"'refunded'","'auto'",'7',OLD,'NULL','auto_refunded'),
 ('manual refunded',      'failed',"'refunded'","'manual'",'8',OLD,'NULL','manual_refunded'),
 ('refunded by purchase flow (no method)','failed','NULL','NULL','9',OLD,'NULL','auto_refunded'),
 ('failed, no refund',    'failed','NULL','NULL','NULL',OLD,'NULL','failed_unrefunded'),
 ('claimed "refunded" but order still pending w/ ledger row','pending',"'refunded'",'NULL','5',OLD,'NULL','manual_review'),
 ('pending + refund_status=refunded but NO ledger (must not show refunded)','pending',"'refunded'","'auto'",'NULL',OLD,'NULL','expired'),
]
for i,(n,st,rs,me,lid,cr,lc,exp) in enumerate(rows,1):
    db.execute(f"INSERT INTO orders VALUES ({i},'{st}',{rs},{me},{lid},{cr},{lc})")
fail = 0
for i,(n,*_,exp) in enumerate(rows,1):
    got = db.execute(f"SELECT {case} FROM orders o WHERE o.id={i}").fetchone()[0]
    ok = got == exp; fail += (not ok)
    print(('PASS' if ok else 'FAIL'), f'{n:75s} -> {got}' + ('' if ok else f'  (expected {exp})'))
tot = sum(c for _,c in db.execute(f"SELECT {case} AS st, COUNT(*) FROM orders o GROUP BY st").fetchall())
print(('PASS' if tot == len(rows) else 'FAIL'), f'every order counted exactly once: {tot}/{len(rows)}'); fail += (tot != len(rows))
sys.exit(1 if fail else 0)
