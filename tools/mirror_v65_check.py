#!/usr/bin/env python3
"""Python MIRROR of the v65 admin.js fill rules (does NOT execute the JS)."""
import sys
class Inp:
    def __init__(s, v='', o=''): s.v, s.o = v, o
    def fillable(s): return s.v.strip() == '' or float(s.v or 0) == 0 or s.o in ('auto', 'stale')
def fill(inputs, cats):  # cats: {'normal': calc|None, 'reseller': calc|None}; explicit keys only
    for cat in ('normal', 'reseller'):
        c, i = cats.get(cat), inputs[cat]
        if c is not None and i.fillable(): i.v, i.o = '%.2f' % float(c), 'auto'
def reset(inputs):
    for i in inputs.values():
        if i.o == 'auto': i.v, i.o = '', ''
f = 0
def chk(n, ok):
    global f; print(('PASS ' if ok else 'FAIL ') + n); f += (not ok)
c = {'normal': '325.00', 'reseller': '175.00'}
I = {'normal': Inp(), 'reseller': Inp()}; fill(I, c)
chk('TEST 1/2: both inputs filled independently (325 / 175)', (I['normal'].v, I['reseller'].v) == ('325.00', '175.00'))
chk('auto origin is not manual', I['normal'].o == 'auto' and I['reseller'].o == 'auto')
reset(I); chk('TEST 3: mapping change clears BOTH auto values', I['normal'].v == '' and I['reseller'].v == '')
fill(I, {'normal': '400.00', 'reseller': '200.00'}); chk('TEST 3: new mapping fills new independent prices', (I['normal'].v, I['reseller'].v) == ('400.00', '200.00'))
I = {'normal': Inp('400', 'manual'), 'reseller': Inp('250', 'manual')}; fill(I, c)
chk('TEST 4: manual prices preserved', (I['normal'].v, I['reseller'].v) == ('400', '250'))
I = {'normal': Inp('400', 'manual'), 'reseller': Inp()}; fill(I, c)
chk('manual Normal kept while empty Reseller still filled (independent)', (I['normal'].v, I['reseller'].v) == ('400', '175.00'))
I = {'normal': Inp('350', 'saved'), 'reseller': Inp('350', 'saved')}; fill(I, c)
chk('TEST 7: saved prices never silently overwritten', (I['normal'].v, I['reseller'].v) == ('350', '350'))
I = {'normal': Inp(), 'reseller': Inp()}; fill(I, {'normal': None, 'reseller': '175.00'})
chk('TEST 8: Normal rate invalid -> Normal empty, Reseller filled', (I['normal'].v, I['reseller'].v) == ('', '175.00'))
I = {'normal': Inp(), 'reseller': Inp()}; fill(I, {'normal': '325.00', 'reseller': None})
chk('TEST 9: Reseller rate invalid -> Reseller empty, Normal filled', (I['normal'].v, I['reseller'].v) == ('325.00', ''))
I = {'normal': Inp(), 'reseller': Inp()}; fill(I, {'normal': '325.00', 'reseller': '175.00'})
chk('never cross-assigned', I['normal'].v != I['reseller'].v)
sys.exit(1 if f else 0)
