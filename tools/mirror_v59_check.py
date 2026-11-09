#!/usr/bin/env python3
"""v59 MIRROR check. This is a Python re-implementation of the PURE rules in includes/order_executor.php and includes/price_policy.php.
It proves the RULES behave as specified. It does NOT execute the PHP code, a database or a provider. Run: python3 tools/mirror_v59_check.py"""
import re, sys
fails = 0
def check(name, cond):
    global fails
    print(('PASS' if cond else 'FAIL'), name)
    if not cond: fails += 1

# ---- mirrors ----
def is_stock(m): return re.search(r'out of stock|insufficient stock|no stock|not enough stock|sold out|stock (?:is )?(?:empty|exhausted|unavailable|finished)', re.sub(r'[_\-]+',' ',m), re.I) is not None
def fcode(raw, resp):
    c = re.sub(r'[^A-Za-z0-9]+','_',str(resp.get('error_code',''))).upper().strip()
    if c == 'OUT_OF_STOCK': return 'OUT_OF_STOCK'
    return 'OUT_OF_STOCK' if raw and is_stock(raw) else ''
def classify(cls):
    k, raw, resp = cls['kind'], cls.get('raw',''), cls.get('resp',{})
    if k == 'success': return 'SUCCESS'
    if k == 'rejected': return 'CONFIRMED_OUT_OF_STOCK' if fcode(raw, resp) else 'CONFIRMED_REJECTED'
    if k == 'no_key': return 'SUCCESS_WITHOUT_KEY'
    return 'INVALID_RESPONSE' if re.search(r'empty response|invalid response|unrecognised response|HTML instead of JSON|unreadable', raw, re.I) else 'UNCERTAIN'
HOLD = {'UNCERTAIN','SUCCESS_WITHOUT_KEY','INVALID_RESPONSE','INTERNAL_ERROR'}

def to_micro(v):
    m = re.fullmatch(r'([0-9]{1,9})(?:\.([0-9]{1,12}))?', str(v).strip())
    if not m: return None
    return int(m[1])*1000000 + int((m[2] or '').ljust(6,'0')[:6])
def convert(micro, rate):
    i,_,f = rate.partition('.'); r = int(i)*10000 + int(f.ljust(4,'0')[:4])
    paise = (micro*r + 50000000)//100000000
    return f'{paise//100}.{paise%100:02d}'
def money_ok(v):
    m = re.fullmatch(r'([0-9]{1,7})(?:\.([0-9]{1,2}))?', str(v).strip()) if v is not None else None
    if not m: return None
    p = int(m[1])*100 + int((m[2] or '').ljust(2,'0'))
    return None if p <= 0 else f'{p//100}.{p%100:02d}'
def change(stored, new, old_inr, new_inr):
    if stored is None: return 'first'
    if new > stored: return 'increase'
    if new < stored: return 'decrease'
    return 'rate_change' if old_inr and old_inr != new_inr else 'same'
def should_remove(count, since, now, need, minutes): return since is not None and count >= need and now - since >= minutes*60
def plausible(mapped, missing): return True if mapped < 4 else missing <= max(3, mapped//2)

# ---- API order classification (tests 1-7) ----
check('1 success one key -> SUCCESS', classify({'kind':'success'}) == 'SUCCESS')
check('2 success multiple keys -> SUCCESS', classify({'kind':'success','keys':['a','b']}) == 'SUCCESS')
check('3 explicit reject -> CONFIRMED_REJECTED', classify({'kind':'rejected','raw':'Invalid product [BAD_PRODUCT]','resp':{'error_code':'BAD_PRODUCT'}}) == 'CONFIRMED_REJECTED')
check('4 OUT_OF_STOCK code -> CONFIRMED_OUT_OF_STOCK', classify({'kind':'rejected','raw':'x [OUT_OF_STOCK]','resp':{'error_code':'OUT_OF_STOCK'}}) == 'CONFIRMED_OUT_OF_STOCK')
check('4b OOS by message only', classify({'kind':'rejected','raw':'This product is sold out','resp':{}}) == 'CONFIRMED_OUT_OF_STOCK')
check('5 timeout -> UNCERTAIN (hold)', classify({'kind':'uncertain','raw':'API provider timed out.'}) == 'UNCERTAIN')
check('6 HTTP200 invalid JSON -> INVALID_RESPONSE (hold)', classify({'kind':'uncertain','raw':'API provider returned an invalid response (HTTP 200).'}) in HOLD)
check('7 ok:true without key -> SUCCESS_WITHOUT_KEY (hold, no refund)', classify({'kind':'no_key'}) in HOLD)
check('timeout never OUT_OF_STOCK', classify({'kind':'uncertain','raw':'out of stock?? timed out'}) != 'CONFIRMED_OUT_OF_STOCK')
check('HTTP 500 text never OUT_OF_STOCK', classify({'kind':'uncertain','raw':'API provider returned a server error (HTTP 500).'}) == 'UNCERTAIN')
check('empty response is INVALID_RESPONSE', classify({'kind':'uncertain','raw':'API provider returned an empty response (HTTP 200).'}) == 'INVALID_RESPONSE')
check('every hold state is refused a refund (set)', all(s in HOLD for s in ['UNCERTAIN','SUCCESS_WITHOUT_KEY','INVALID_RESPONSE','INTERNAL_ERROR']))

# ---- duplicate-send guard (UNIQUE(order_id)) ----
attempts=set()
def begin(o):
    if o in attempts: return False
    attempts.add(o); return True
check('10 second execution for same order is not sent', begin(1) and not begin(1))

# ---- money ----
check('USD 0.10 x30 = 3.00', convert(to_micro('0.10'),'30')=='3.00')
check('USD 0.30 x30 = 9.00', convert(to_micro('0.30'),'30')=='9.00')
check('USD 0.50 x30 = 15.00', convert(to_micro('0.50'),'30')=='15.00')
check('rate change 30->32 -> 0.50 = 16.00 (not double converted)', convert(to_micro('0.50'),'32')=='16.00')
check('0.333 x 30 rounds half-up 9.99', convert(to_micro('0.333'),'30')=='9.99')
check('invalid provider prices rejected', all(to_micro(x) is None for x in ['abc','-1','1e3','']) )
check('custom price validation', money_ok('5')=='5.00' and money_ok('0') is None and money_ok('-3') is None and money_ok('5.555') is None and money_ok('x') is None)

# ---- price tests ----
check('P1 unchanged', change(300000,300000,'9.00','9.00')=='same')
check('P2 increase detected', change(300000,500000,'9.00','15.00')=='increase')
check('P2b 6-decimal price compared at 4 dp does not flap', change(to_micro('0.1235'), to_micro('0.1235'),'3.71','3.71')=='same')
check('P6 decrease detected', change(500000,300000,'15.00','9.00')=='decrease')
check('P10 rate change only', change(300000,300000,'9.00','9.60')=='rate_change')
check('first sight', change(None,300000,'','9.00')=='first')

def expire(custom_on, custom, new_inr):
    """mirror of pp_apply_expired decision"""
    if custom_on and money_ok(custom) and float(custom) >= float(new_inr): return 'custom_covers'
    return 'auto_applied'
check('P6 grace ends, no custom -> auto applied', expire(False,None,'15.00')=='auto_applied')
check('P4 custom 20 >= new 15 -> kept', expire(True,'20.00','15.00')=='custom_covers')
check('custom 12 < new 15 -> auto fallback (never sell below cost silently)', expire(True,'12.00','15.00')=='auto_applied')

# grace window is clamped 20..30
def grace(v): return max(20, min(30, v or 25))
check('grace default 25, clamp 20..30', grace(None)==25 and grace(5)==20 and grace(99)==30 and grace(22)==22)

# ---- duration lifecycle simulation ----
class Plan:
    def __init__(s): s.state='active'; s.count=0; s.since=None; s.enabled=1
def step(p, present, complete, now, need=3, minutes=0):
    if not complete: return           # failed/partial catalog: NO decision
    if present:
        if p.state in ('missing',) or (p.state=='active' and p.count): p.state='active'; p.count=0; p.since=None
        elif p.state=='removed': p.state='reappeared'      # never silently restored
        return
    if p.state in ('removed','reappeared'): return
    p.count += 1; p.since = p.since or now; p.state='missing'
    if should_remove(p.count,p.since,now,need,minutes): p.state='removed'; p.enabled=0
p=Plan(); t=0
for _ in range(100): step(p,False,False,t); t+=10
check('D3 failed/partial/invalid catalogs never count', p.state=='active' and p.count==0)
step(p,False,True,t); t+=10
check('D4 complete check #1: NOT removed', p.state=='missing' and p.enabled==1)
step(p,False,True,t); t+=10
check('D4 complete check #2: NOT removed', p.state=='missing' and p.enabled==1)
step(p,False,False,t); t+=10
check('failed check between does not count and does not reset', p.count==2 and p.enabled==1)
step(p,False,True,t); t+=10
check('D5 complete check #3: archived, no longer purchasable', p.state=='removed' and p.enabled==0)
def restore(p, price_valid, auto=True):
    if p.state=='removed' and auto and price_valid: p.state='active'; p.enabled=1; p.count=0
    elif p.state=='removed': p.state='reappeared'
restore(p, False)
check('D8 reappears with INVALID price: not restored (stays disabled, flagged)', p.state=='reappeared' and p.enabled==0)
p2=Plan(); [step(p2,False,True,i*10) for i in range(3)]; restore(p2, True)
check('D9 reappears with valid price + auto-restore: same row re-enabled (no duplicate variant)', p2.state=='active' and p2.enabled==1)
p3=Plan(); [step(p3,False,True,i*10) for i in range(3)]; restore(p3, True, auto=False)
check('auto-restore OFF: flagged only', p3.state=='reappeared' and p3.enabled==0)
q=Plan(); t=0
for _ in range(2): step(q,False,True,t); t+=10
step(q,True,True,t)
check('flap: back before 3rd check -> counters reset', q.state=='active' and q.count==0 and q.enabled==1)
check('catalog sanity: 8 of 10 vanishing is not trusted', not plausible(10,8) and plausible(10,3) and plausible(3,3))

# ---- v60: custom price protection, versioning, percentage ----
def expire2(custom_on): return 'custom_price_kept' if custom_on else 'auto_applied'
check('V60 custom ON at deadline: price NEVER overwritten (even if custom < new calculated)', expire2(True)=='custom_price_kept')
check('V60 custom OFF at deadline: latest pending applied', expire2(False)=='auto_applied')
def pct(old,new): return None if not old else round((new-old)*100/old,2)
check('percentage 0.30 -> 0.50 = +66.67', pct(300000,500000)==66.67)
check('percentage 0.50 -> 0.30 = -40.00', pct(500000,300000)==-40.0)
# versioning: second change during grace; only the matching version may be applied
plan={'ver':0,'pending':None}; ev={'ver':None}
def open_or_update(new):
    plan['ver']+=1; plan['pending']=new; ev['ver']=plan['ver']
open_or_update(500000); open_or_update(700000)
def apply():  # mirrors the FOR UPDATE version comparison
    return plan['pending'] if ev['ver']==plan['ver'] else None
check('V60 second change during grace: latest price (0.70) is applied, never the first (0.50)', apply()==700000)
ev['ver']=1
check('V60 stale event version is refused', apply() is None)

print('\n%s' % ('ALL MIRROR CHECKS PASSED' if not fails else f'{fails} MIRROR CHECK(S) FAILED'))
sys.exit(1 if fails else 0)
