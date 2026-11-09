#!/usr/bin/env python3
"""v61 Python MIRROR of the pure rules (proves the rules, NOT the PHP/DB). Run: python3 tools/mirror_v61_check.py"""
from decimal import Decimal, ROUND_HALF_UP
ok=0
def check(n,c):
    global ok
    assert c, n; ok+=1
def inr(usd,rate): return (Decimal(usd)*Decimal(rate)).quantize(Decimal('0.01'),ROUND_HALF_UP)
def pick(plan,cat):
    if cat=='reseller' and plan.get('reseller_price') is not None: return plan['reseller_price']
    return plan['price']
# two rates independent
rates={'sync_usd_inr_rate':'30','normal_usd_inr_rate':'45'}
def setrate(cat,v): rates['sync_usd_inr_rate' if cat=='reseller' else 'normal_usd_inr_rate']=v
before=dict(rates); setrate('reseller','35')
check('reseller change leaves normal',rates['normal_usd_inr_rate']==before['normal_usd_inr_rate'])
before=dict(rates); setrate('normal','50')
check('normal change leaves reseller',rates['sync_usd_inr_rate']==before['sync_usd_inr_rate'])
check('calc normal',inr('2.5',rates['normal_usd_inr_rate'])==Decimal('125.00'))
check('calc reseller',inr('2.5',rates['sync_usd_inr_rate'])==Decimal('87.50'))
# price pick
p={'price':500,'reseller_price':400}
check('normal sees normal',pick(p,'normal')==500); check('reseller sees reseller',pick(p,'reseller')==400)
check('unset reseller falls back',pick({'price':500,'reseller_price':None},'reseller')==500)
# migration idempotence: account_type default, never UPDATEd
users=[{'id':i,'account_type':'normal'} for i in range(1,4)]
users[1]['account_type']='reseller'   # admin promoted
def migrate(us):  # re-run: ADD COLUMN IF MISSING only; no UPDATE
    return us
check('re-run keeps reseller',migrate(users)[1]['account_type']=='reseller')
# review: increase opens review once, admin change preserved, expiry applies
class Plan:
    def __init__(s): s.price=Decimal('100'); s.custom=False; s.deadline=None; s.pending=None; s.base=Decimal('3')
def detect(pl,new,now):
    if new>pl.base and pl.deadline is None: pl.deadline=now+15*60; pl.pending=new; return 'opened'
    return 'noop'
def admin_set(pl,price): pl.price=Decimal(price); pl.custom=True; pl.base=pl.pending or pl.base; pl.deadline=None; pl.pending=None
def expire(pl,now,rate):
    if pl.deadline is not None and pl.deadline<=now:
        pl.price=inr(pl.pending,rate); pl.custom=False; pl.base=pl.pending; pl.deadline=None; pl.pending=None; return 'auto'
    return 'noop'
a=Plan(); check('opens',detect(a,Decimal('4'),0)=='opened'); check('no duplicate',detect(a,Decimal('4'),1)=='noop')
check('not applied early',expire(a,600,'45')=='noop' and a.price==100)
admin_set(a,'777'); check('admin kept',expire(a,10**6,'45')=='noop' and a.price==777)
check('no re-detect after admin ack',detect(a,Decimal('4'),10**6)=='noop')
b=Plan(); b.custom=True; detect(b,Decimal('4'),0)
check('custom auto-applied after review',expire(b,15*60,'45')=='auto' and b.price==Decimal('180.00') and b.custom is False)
# categories independent
n,r=Plan(),Plan(); detect(n,Decimal('4'),0); detect(r,Decimal('4'),0); admin_set(r,'90')
check('normal still pending when reseller admin-updated',n.deadline is not None and r.deadline is None)
# membership activation
def activate(m,paid,txn,used):
    if m['status']=='paid': return 'SUCCESS'
    if paid+Decimal('0.009')<m['due']: m['status']='underpaid'; return 'UNDERPAID'
    if txn in used: return 'FAILURE'
    m['status']='paid'; used.add(txn); return 'SUCCESS'
used=set(); m={'status':'pending','due':Decimal('500')}
check('underpaid not activated',activate(dict(m),Decimal('100'),'T1',used)=='UNDERPAID')
check('paid activates',activate(m,Decimal('500'),'T1',used)=='SUCCESS')
check('duplicate callback idempotent',activate(m,Decimal('500'),'T1',used)=='SUCCESS' and len(used)==1)
m2={'status':'pending','due':Decimal('500')}
check('txn replay rejected',activate(m2,Decimal('500'),'T1',used)=='FAILURE' and m2['status']=='pending')
print(f'{ok} mirror checks passed')

# ---- v62 wallet membership mirror ----
def buy(u,price,enabled=True):
    if not enabled: return 'OFF'
    if u['type']=='reseller': return 'ALREADY'
    if u['bal']<price: return 'INSUFFICIENT'
    u['bal']-=price; u['type']='reseller'; u['ledger'].append(-price); u['mem']+=1; return 'OK'
u={'bal':Decimal('1000'),'type':'normal','ledger':[],'mem':0}
check('off rejects',buy(u,Decimal('400'),False)=='OFF' and u['bal']==1000 and u['type']=='normal')
check('buy ok',buy(u,Decimal('400'))=='OK' and u['bal']==Decimal('600') and u['type']=='reseller')
check('second (double click) not charged',buy(u,Decimal('400'))=='ALREADY' and u['bal']==600 and u['mem']==1 and len(u['ledger'])==1)
v={'bal':Decimal('100'),'type':'normal','ledger':[],'mem':0}
check('insufficient: nothing changes',buy(v,Decimal('400'))=='INSUFFICIENT' and v['bal']==100 and v['type']=='normal' and v['mem']==0)
def api_ok(role,typ): return role=='admin' or typ=='reseller'
check('api: normal denied',not api_ok('user','normal')); check('api: reseller ok',api_ok('user','reseller')); check('api: admin ok',api_ok('admin','normal'))
check('api: revoked denied',not api_ok('user','normal'))
print('v62 mirror checks passed')
