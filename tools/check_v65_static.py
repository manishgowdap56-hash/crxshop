#!/usr/bin/env python3
"""v65 source-level invariants (read the source text; do NOT execute PHP/JS)."""
import sys
R = lambda p: open(p, encoding='utf-8').read()
adm, ps = R('assets/js/admin.js'), R('includes/price_sync.php')
f = 0
def chk(n, ok):
    global f; print(('PASS ' if ok else 'FAIL ') + n); f += (not ok)
chk('per-input origin tracking (auto/manual/saved)', all(x in adm for x in ['const setOrigin', "'saved'", "'manual'", 'const fillable', 'const setAuto']))
chk('fill: normal -> .dur-price, reseller -> .dur-rprice via explicit category', "cat === 'reseller' ? rPriceEl : (cat === 'normal' ? priceIn : null)" in adm)
chk('fill reads each category own calculation', "const d = cats[cat], inp = catInput(cat)" in adm and 'setAuto(inp, d.calculated)' in adm)
chk('database-backed plan is never auto-overwritten', "d.current_source === 'database'" in adm)
chk('manual / saved value preserved and reported', 'price was preserved' in adm)
chk('mapping change clears auto values of BOTH inputs', "[priceIn, rPriceEl].forEach(el => { if (el && originOf(el) === 'auto')" in adm)
chk('input events mark manual per input', "setOrigin(priceIn," in adm and "setOrigin(rPriceEl," in adm)
chk('no leftover dataset.auto writers besides setOrigin', adm.count('dataset.auto =') == 1)
chk('duplicate search guard + stale checks kept', 'lk.fkey === fkey' in adm and 'my !== lk.fseq' in adm and 'if (lk.pBusy) return;' in adm)
chk('save refuses empty Reseller price for a new Provider 5 duration (no silent copy)', 'It is not copied from the Normal User price.' in adm)
chk('findVariants: candidate valid when only one rate is missing', "elseif ($wp['currency'] !== 'USD'" in ps and "$c['normal_reason'] = $wpN['reason']" in ps)
sys.exit(1 if f else 0)
