# Line-by-line Python MIRROR of PriceSyncService::toMicro / normalizeRate / convertToInr (same integer arithmetic, 64-bit overflow checked).
import re
INT_MAX = 2**63-1
def to_micro(v):
    if v is None or isinstance(v, bool): return None
    s = ('%.6f' % v) if isinstance(v, float) else str(v).strip()
    m = re.fullmatch(r'([0-9]{1,9})(?:\.([0-9]{1,12}))?', s)
    if not m: return None
    frac = (m.group(2) or '').ljust(6,'0')[:6]
    micro = int(m.group(1))*1_000_000 + int(frac)
    return micro if micro <= 100000*1_000_000 else None
def normalize_rate(r):
    r = r.strip()
    m = re.fullmatch(r'([0-9]{1,4})(?:\.([0-9]{1,4}))?', r)
    if not m: return None
    u = int(m.group(1))*10000 + int((m.group(2) or '').ljust(4,'0'))
    if u < 1 or u > 1000*10000: return None
    frac = str(u % 10000).rjust(4,'0').rstrip('0')
    return str(u//10000) + ('.'+frac if frac else '')
def convert(micro, rate):
    r = normalize_rate(rate)
    if micro is None or r is None: return None
    i,_,f = r.partition('.')
    r1e4 = int(i)*10000 + int(f.ljust(4,'0') or 0)
    prod = micro*r1e4 + 50_000_000
    assert prod <= INT_MAX, 'PHP int overflow'
    p = prod // 100_000_000
    return '%d.%02d' % (p//100, p%100)
cases = [('1.00','30','30.00'),('2.50','30','75.00'),('0.50','30','15.00'),('0.0333','30','1.00'),('0.01','30','0.30'),('2','83.5','167.00'),
         ('0.0001','30','0.00'),('0.0167','30','0.50'),('99999.9999','30','3000000.00'),('100000','1000','100000000.00'),('1.005','30','30.15'),('12.3456','30.5','376.54')]
bad=0
for price,rate,exp in cases:
    got = convert(to_micro(price), rate)
    ok = got==exp; bad += (not ok)
    print('PASS' if ok else 'FAIL', f'${price} x {rate} = {got} (expected {exp})')
checks = [(to_micro(2.5)==2500000,'float 2.5'),(to_micro('-1') is None,'neg'),(to_micro('1e3') is None,'exp'),(to_micro('100000.01') is None,'too big'),
          (normalize_rate('30.50')=='30.5',"30.50->30.5"),(normalize_rate('0') is None,'rate 0'),(normalize_rate('1001') is None,'rate 1001'),(normalize_rate('30.12345') is None,'5 decimals'),
          (normalize_rate('30.0000')=='30','30.0000->30 (DB DECIMAL(12,4) string compares equal)')]
for ok,n in checks:
    print('PASS' if ok else 'FAIL', n); bad += (not ok)
# double-conversion guard: converting an already converted price would differ -> the sync only converts when provider_price is NULL (backfill) or on provider/rate events
print('overflow bound: max micro*rate =', 100000*10**6*1000*10**4, '<', INT_MAX)
print('FAILED:', bad)
