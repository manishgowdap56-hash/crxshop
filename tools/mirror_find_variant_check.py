#!/usr/bin/env python3
"""Python MIRROR of PriceSyncService::parseDuration / normKey / indexCatalog / findVariants (v57). It proves the matching ALGORITHM on invented catalogs;
it does NOT execute the PHP and says nothing about the real provider catalog. Keep it in step with includes/price_sync.php."""
import re
from decimal import Decimal, ROUND_HALF_UP
DUR=re.compile(r'(?<![A-Za-z0-9.])(\d{1,4}(?:\.\d+)?)\s*(hours?|hrs?|h|days?|d|weeks?|w|months?|mos?)(?![A-Za-z0-9])',re.I)
PARENT=['product_name','product_title','parent_name','parent_product','parent','service_name','group_name','product','service']
DURF=['duration','duration_label','validity','period','plan','plan_name']; UNITF=['duration_unit','unit','period_unit']
def norm(s):
    s=s.lower().replace('+',' plus ').replace('&',' and '); return re.sub(r'\s+',' ',re.sub(r'[^\w]+|_',' ',s)).strip()
def pdur(s):
    m=list(DUR.finditer(s))
    if len(m)!=1: return None
    n=float(m[0].group(1)); u=m[0].group(2).lower()
    if n<=0: return None
    if u.startswith('mo'): key='M%g'%n; unit='month'
    else:
        mult=60 if u[0]=='h' else 1440 if u[0]=='d' else 10080; key='m%d'%round(n*mult); unit={'h':'hour','d':'day','w':'week'}[u[0]]
    return {'key':key,'label':('%g'%n)+' '+unit+('' if n==1 else 's'),'match':m[0].group(0)}
def pick(e,fs):
    for f in fs:
        if f in e and isinstance(e[f],(str,int,float)) and str(e[f])!='': return e[f]
def rec(i,parent_name,e,nested):
    lab=pick(e,['name','title','product_name','plan_name','plan','duration','label']); label=str(lab).strip() if lab is not None else ''
    dur=None
    for f in DURF:
        raw=pick(e,[f])
        if raw is None: continue
        raw=str(raw).strip(); d=pdur(raw)
        if d is None and re.fullmatch(r'\d+(\.\d+)?',raw):
            u=pick(e,UNITF)
            if u is not None: d=pdur(raw+' '+str(u))
        if d: dur=d; break
    fl=pdur(label)
    if dur is None: dur=fl
    ep=pick(e,PARENT); ep=str(ep).strip() if ep is not None else ''
    weak=False; full=norm(label)
    if nested and parent_name: parent=parent_name; full=norm(parent_name+' '+label)
    elif ep and pdur(ep) is None and not re.fullmatch(r'\d+(\.\d+)?',ep) and norm(ep)!=norm(label): parent=ep
    else:
        base=label
        if fl: base=re.sub(r'^[\s\-_|/:()\[\],]+|[\s\-_|/:()\[\],]+$','',DUR.sub(' ',label,1)).strip()
        if base and norm(base): parent=base
        else: parent='';weak=True
    return dict(id=str(i),parent=parent,pk=norm(parent),label=label,full=full,dur=dur['key'] if dur else None,dl=dur['label'] if dur else '',weak=weak,e=e)
def index(lst,cat='',out=None,seen=None):
    out=[] if out is None else out; seen=set() if seen is None else seen
    for n in lst:
        if not isinstance(n,dict): continue
        nm=pick(n,['name','title','product_name','product','plan_name','label']); ns=str(nm).strip() if nm else ''
        done=False
        for k in ['products','items']:
            if isinstance(n.get(k),list) and n[k] and (pick(n,['product_id','id','pid']) is None or pick(n,['price','user_price','cost','rate','amount']) is None):
                index(n[k],ns or cat,out,seen); done=True; break
        if done: continue
        for k in ['variants','plans','packages','durations','tiers','services']:
            if isinstance(n.get(k),list) and n[k]:
                for v in n[k]:
                    vid=pick(v,['variant_id','plan_id','service_id','product_id','id','pid'])
                    if vid is None or str(vid) in seen: continue
                    seen.add(str(vid)); out.append(rec(vid,ns,v,True))
                done=True; break
        if done: continue
        i=pick(n,['product_id','id','pid','service_id'])
        if i is None or str(i) in seen: continue
        seen.add(str(i)); out.append(rec(i,'',n,False))
    return out
def price(e):
    p=pick(e,['price','user_price','cost','rate','amount']); cur=str(e.get('currency') or 'USD').upper()
    try: d=Decimal(str(p))
    except Exception: return None
    if d<=0: return None
    if cur=='INR': return d.quantize(Decimal('0.01'),ROUND_HALF_UP)
    if cur!='USD': return None
    return (d*30).quantize(Decimal('0.01'),ROUND_HALF_UP)
def find(lst,name,dur):
    q=norm(name); idx=index(lst)
    prod=[r for r in idx if not r['weak'] and (r['pk']==q or r['full']==q)]
    if not prod: return 'none',[]
    qd=pdur(dur.strip()) if dur.strip() else None
    if not dur.strip(): return 'duration_missing',[]
    if qd is None: return 'duration_unreadable',[]
    c=[r for r in prod if r['dur']==qd['key']]
    if not c: return 'none',[]
    out=[(r['id'],price(r['e'])) for r in c]
    if len(c)>1: return 'ambiguous',out
    return ('match' if out[0][1] is not None else 'unpriced'),out
fails=0
def t(n,ok):
    global fails; print(('PASS ' if ok else 'FAIL ')+n); fails+= (not ok)
flat=[{'id':70,'name':'BRMOD 1 Day','price':'0.70'},{'id':71,'name':'BRMOD 3 Days','price':'1.50'},{'id':73,'name':'BRMOD 7 Days','price':'3.00'},{'id':80,'name':'BRPC SILENT 1 Day','price':'0.20'},{'id':81,'name':'BRPC SILENT 10 Days','price':'2'},{'id':82,'name':'BRPC SILENT 30 Days','price':'6'},{'id':90,'name':'BRPC 1 Day','price':'9'}]
r=find(flat,'brmod','7 Days'); t('flat name: BRMOD 7 Days -> id 73, $3.00 -> 90.00',r==('match',[('73',Decimal('90.00'))]))
r=find(flat,'  BRPC   Silent ','1 day'); t('BRPC SILENT 1 Day -> id 80 (not 10/30 day), $0.20 -> 6.00',r==('match',[('80',Decimal('6.00'))]))
t('BRPC (shorter name) never matches BRPC SILENT',find(flat,'BRPC','1 Day')==('match',[('90',Decimal('270.00'))]) and find(flat,'BRPC','10 Days')[0]=='none')
t('changing duration 1 Day -> 3 Days changes id',find(flat,'BRMOD','3 Days')[1][0][0]=='71')
t('24 hours equals 1 day',find(flat,'BRMOD','24 Hours')[1][0][0]=='70')
t('unknown name -> none (no guess)',find(flat,'BRMO','7 Days')[0]=='none')
t('duration the product lacks -> none',find(flat,'BRMOD','30 Days')[0]=='none')
t('blank / unreadable duration',find(flat,'BRMOD','')[0]=='duration_missing' and find(flat,'BRMOD','forever')[0]=='duration_unreadable')
nested=[{'name':'Lk team Root+Pc','variants':[{'id':201,'name':'1 Day','price':'0.70'},{'id':202,'name':'5 Days','price':'1.50'}]},{'name':'Lk team Root Pc','variants':[{'id':301,'name':'1 Day','price':'9'}]}]
r=find(nested,'LK TEAM ROOT+PC','1 Day'); t('nested variants, + kept significant: id 201, $0.70 -> 21.00',r==('match',[('201',Decimal('21.00'))]))
t('"Root+Pc" and "Root Pc" are different products',find(nested,'lk team root pc','1 Day')[1][0][0]=='301')
fld=[{'id':5,'name':'BRMOD','duration':'7 Days','price':'3'},{'id':6,'name':'7 Days','product_name':'OTHER','price':'1'},{'id':7,'name':'BRMOD','duration':'7','duration_unit':'days','price':'4'}]
r=find(fld,'BRMOD','7 Days'); t('two variants with the same name+duration -> ambiguous, admin must choose',r[0]=='ambiguous' and len(r[1])==2)
t('explicit product-name field with duration-only name',find(fld,'OTHER','7 Days')[1][0][0]=='6')
weak=[{'id':9,'name':'7 Days','price':'1'}]
t('duration-only entry without product-name field is never auto-matched',find(weak,'7 Days','7 Days')[0]=='none')
t('different currency refused (EUR)',find([{'id':1,'name':'X 1 Day','price':'2','currency':'EUR'}],'X','1 Day')[0]=='unpriced')
t('explicit INR not multiplied again',find([{'id':1,'name':'X 1 Day','price':'45','currency':'INR'}],'X','1 Day')[1][0][1]==Decimal('45.00'))
t('USD 1.50 -> 45.00 / 3.00 -> 90.00 / 0.20 -> 6.00 / 0.70 -> 21.00',[price({'price':p}) for p in ('1.50','3.00','0.20','0.70')]==[Decimal(x) for x in ('45.00','90.00','6.00','21.00')])
print('ALL PASSED' if not fails else 'FAILED %d'%fails); raise SystemExit(1 if fails else 0)
