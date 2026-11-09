/* =====================================================================
   v55 admin: PRICE SYNC page. The ONLY provider-sync function left: automatic provider price check + correction.
   The browser only DISPLAYS what api/admin/price-sync.php returns and SENDS the admin's choices; every price decision is made by PHP
   (cron worker), so everything keeps running when this page is closed.
   ===================================================================== */
(function () {
    'use strict';
    if (document.body.dataset.page !== 'admin' || !window.KP) return;
    const { api, toast } = window.KP;
    const EP = 'api/admin/price-sync.php';
    const $ = id => document.getElementById(id);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const errText = e => (e && e.message) || 'Request failed.';
    const n = x => Number(x || 0);
    const when = ts => ts ? new Date(ts * 1000).toLocaleString() : '—';
    const whenSql = s => s ? String(s).replace('T', ' ') : '—';
    const inr = v => (v === null || v === undefined || v === '') ? '—' : '₹' + Number(v).toFixed(2);
    const secs = v => (v === null || v === undefined) ? '—' : v + ' s';
    const st = { tab: 'history', page: 1, ov: null, timer: null };
    const tile = (k, v, sub, cls) => '<div class="ps-tile' + (cls ? ' ' + cls : '') + '"><b>' + esc(v) + '</b><span>' + esc(k) + '</span>' + (sub ? '<i>' + esc(sub) + '</i>' : '') + '</div>';
    const RESULT = { success: ['CORRECTED', 's-completed', 'ps-res-success'], already: ['ALREADY CORRECT', 's-pending', 'ps-res-already'], failed: ['FAILED', 's-failed', 'ps-res-failed'], pending: ['PENDING', 's-pending', 'ps-res-pending'] };

    function render(ov) {
        st.ov = ov;
        // v63: rates come from the backend settings (edited only in Admin -> Monitor -> Settings); nothing is hard-coded here
        if (ov.rates) {
          const fr = v => (v == null || v === '') ? 'NOT SET' : String(Number(v));   // v64: a missing rate is shown as NOT SET (never as 0 or the other category's value)
          const R = fr(ov.rates.reseller), N = fr(ov.rates.normal);
          if ($('psSubRates')) $('psSubRates').textContent = 'RESELLER $1 = ' + (R === 'NOT SET' ? R : '₹' + R) + ' • NORMAL USER $1 = ' + (N === 'NOT SET' ? N : '₹' + N);
          if ($('psRuleRates')) $('psRuleRates').innerHTML = 'Reseller website price = <b>provider USD price × ' + esc(R) + '</b>. Normal User website price = <b>provider USD price × ' + esc(N) + '</b>.';
          if ($('psRateReseller')) $('psRateReseller').value = R === 'NOT SET' ? R : '1 USD = ₹' + R;
          if ($('psRateNormal')) $('psRateNormal').value = N === 'NOT SET' ? N : '1 USD = ₹' + N;
        }
        const cy = ov.cycle || null, c = (cy && cy.counts) || {};
        if (document.activeElement !== $('psEnabled')) $('psEnabled').value = ov.enabled ? '1' : '0';
        if (document.activeElement !== $('psInterval')) $('psInterval').value = ov.target_interval;
        let cls = 'ok', txt = 'RUNNING ON THE SERVER';
        if (!ov.enabled) { cls = ''; txt = 'AUTO PRICE SYNC IS OFF'; }
        else if (!ov.scheduler_alive) { cls = 'bad'; txt = 'SCHEDULER NOT RUNNING'; }
        else if (cy && cy.status === 'failed') { cls = 'bad'; txt = 'LAST CHECK FAILED — STORED PRICES KEPT'; }
        else if (cy && cy.status === 'partial') { cls = 'warn'; txt = 'RUNNING — SOME ITEMS NEED ATTENTION'; }
        $('psDot').className = 'ps2-dot ' + cls;
        $('psStateText').textContent = txt;
        $('psStateSub').textContent = ov.effective_note + (ov.last_status ? ' • LAST: ' + ov.last_status : '');
        const next = ov.next_check_at ? when(ov.next_check_at) : (ov.enabled ? 'NOT SCHEDULED' : 'OFF');
        $('psTiles').innerHTML = [
            tile('TARGET INTERVAL', ov.target_interval + ' s', 'configured'),
            tile('ACTUAL INTERVAL', ov.actual_interval === null ? '—' : ov.actual_interval + ' s', ov.actual_interval === null ? 'not measured yet' : 'measured over ' + ov.measured_samples + ' cycles' + (ov.cycle_seconds !== null ? ' • ' + ov.cycle_seconds + ' s per cycle' : ''), ov.actual_interval !== null && ov.actual_interval > ov.target_interval * 1.5 ? 'hot' : ''),
            tile('LAST SUCCESSFUL CHECK', ov.last_success ? when(ov.last_success) : 'NEVER'),
            tile('NEXT CHECK', next),
            tile('MAPPED VARIANTS', n(ov.mapped_total), 'provider variants on the site'),
            tile('CHECKED (LAST CYCLE)', cy ? n(c.checked) + ' / ' + n(c.mapped) : '—', 'valid provider price retrieved'),
            tile('PRICES UPDATED', cy ? n(c.updated) : '—', 'last cycle • ' + n(ov.corrected_24h) + ' in 24 h', n(c.updated) ? 'hot' : ''),
            tile('ALREADY CORRECT', cy ? n(c.already_correct) : '—', 'last cycle'),
            tile('INVALID / MISSING PRICES', cy ? n(c.invalid_price) : '—', 'stored price kept', n(c.invalid_price) ? 'bad' : ''),
            tile('FAILED REQUESTS', cy ? n(c.failed_requests) : '—', 'provider calls that failed', n(c.failed_requests) ? 'bad' : ''),
            tile('MAPPING ERRORS', cy ? n(c.mapping_errors) : '—', cy ? n(c.not_in_catalog) + ' not in catalog' : '', n(c.mapping_errors) ? 'bad' : ''),
            tile('FAILED UPDATES', cy ? n(c.failed_updates) : '—', n(ov.failed_open) + ' open failed card(s)', n(c.failed_updates) || n(ov.failed_open) ? 'bad' : ''),
        ].join('');
        $('psProbCount').textContent = (ov.problems || []).length ? '(' + ov.problems.length + ')' : '';
        $('psErrCount').textContent = ov.errors_24h ? '(' + ov.errors_24h + ')' : '';
        const badge = $('adminSyncBadge'); if (badge) { const k = n(ov.failed_open); badge.hidden = !k; badge.textContent = String(k); }
    }

    async function loadStatus() {
        try { const r = await api(EP + '?view=status'); render(r.overview); if (r.provider && (!r.provider.enabled || !r.provider.configured)) $('psBanners').innerHTML = '<div class="ops-warn">The price provider is switched OFF or not configured (Admin → API Settings). Prices cannot be checked until it is.</div>'; else $('psBanners').innerHTML = ''; }
        catch (e) { $('psStateText').textContent = 'COULD NOT LOAD'; $('psStateSub').textContent = errText(e); }
    }

    /* ------------------------------- tabs ------------------------------- */
    function showTab(t) {
        st.tab = t; if (t !== 'history') st.page = 1;
        document.querySelectorAll('[data-ps-tab]').forEach(b => b.classList.toggle('active', b.dataset.psTab === t));
        if (t === 'history') loadHistory(); else if (t === 'problems') loadProblems(); else loadErrors();
    }

    const kv = rows => '<dl class="ao-kv">' + rows.map(r => '<dt>' + r[0] + '</dt><dd>' + r[1] + '</dd>').join('') + '</dl>';

    function card(x) {
        const R = RESULT[x.status] || [String(x.status).toUpperCase(), '', ''];
        const rows = [
            ['PRODUCT ID / PROVIDER VARIANT ID', esc(x.product_id) + ' / ' + esc(x.provider_variant_id || '—')],
            ['DURATION', esc(x.duration || '—')],
            ['PREVIOUS WEBSITE PRICE', inr(x.old_selling_price)],
            ['PROVIDER ' + esc(x.provider_currency || 'USD') + ' PRICE', (x.provider_currency === 'INR' ? '₹' : '$') + (x.new_provider_price == null ? '—' : String(Number(x.new_provider_price)))],
            ['EXCHANGE RATE USED', x.provider_currency === 'INR' ? 'none (provider price is INR)' : '1 USD = ₹' + String(Number(x.rate))],
            ['CORRECT INR PRICE', inr(x.new_inr_cost)],
            ['FINAL UPDATED WEBSITE PRICE', x.status === 'success' || x.status === 'already' ? inr(x.new_selling_price) : '— (not saved)'],
            ['DETECTED', esc(whenSql(x.created_at))],
            ['PRICE UPDATED', x.applied_at ? esc(whenSql(x.applied_at)) : '—'],
            ['RESULT', '<b class="' + R[2] + '">' + R[0] + '</b>' + (x.attempts > 1 ? ' • ' + n(x.attempts) + ' attempts' : '')],
        ];
        if (x.error) rows.push(['ERROR', esc(x.error)]);
        return '<article class="ao-card pc-card pr-' + esc(x.direction) + '">'
            + '<div class="ao-top"><span class="ao-ref">#' + esc(x.id) + '</span><span class="ao-status ' + R[1] + '">' + R[0] + '</span></div>'
            + '<div class="ao-prod"><strong>' + esc(x.product_name || '—') + '</strong></div>' + kv(rows) + '</article>';
    }

    const FILTERS = [['', 'ALL'], ['success', 'CORRECTED'], ['already', 'ALREADY CORRECT'], ['failed', 'FAILED']];
    st.filter = '';
    async function loadHistory() {
        const body = $('psBody');
        try {
            const r = await api(EP + '?view=history&page=' + st.page + (st.filter ? '&status=' + st.filter : ''));
            const pages = Math.max(1, Math.ceil(r.total / r.per_page));
            body.innerHTML = '<div class="ps-filter">' + FILTERS.map(f => '<button type="button" class="ps-tab' + (st.filter === f[0] ? ' active' : '') + '" data-psf="' + f[0] + '">' + f[1] + '</button>').join('') + '</div>'
                + (r.rows.length ? '<div class="ao-list">' + r.rows.map(card).join('') + '</div>' : '<div class="ps-empty">No price changes recorded yet. A card is written only when a website price really had to be corrected (or a correction failed) — never for a price that was already right.</div>')
                + '<div class="ps-pager"><button class="ao-clear" id="psPrev" type="button"' + (st.page <= 1 ? ' disabled' : '') + '>PREV</button><span class="po-meta">PAGE ' + st.page + ' / ' + pages + ' • ' + n(r.total) + ' ENTRIES</span><button class="ao-clear" id="psNext" type="button"' + (st.page >= pages ? ' disabled' : '') + '>NEXT</button></div>';
            body.querySelectorAll('[data-psf]').forEach(b => b.addEventListener('click', () => { st.filter = b.dataset.psf; st.page = 1; loadHistory(); }));
            const pv = $('psPrev'), nx = $('psNext');
            if (pv) pv.addEventListener('click', () => { st.page = Math.max(1, st.page - 1); loadHistory(); });
            if (nx) nx.addEventListener('click', () => { st.page += 1; loadHistory(); });
        } catch (e) { body.innerHTML = '<div class="ps-empty">' + esc(errText(e)) + '</div>'; }
    }

    function loadProblems() {
        const body = $('psBody'), list = (st.ov && st.ov.problems) || [];
        body.innerHTML = list.length
            ? '<div class="ao-list">' + list.map(p => '<article class="ao-card pc-card"><div class="ao-top"><span class="ao-ref">VARIANT ' + esc(p.provider_id || '—') + '</span><span class="ao-status s-failed">NOT CHECKED</span></div>'
                + '<div class="ao-prod"><strong>' + esc(p.product || '—') + '</strong></div>' + kv([['DURATION', esc(p.duration || '—')], ['PLAN ID', esc(p.plan_id)], ['REASON', esc(p.reason)]]) + '</article>').join('') + '</div>'
            : '<div class="ps-empty">No problems in the last cycle: every mapped variant had a usable provider price.</div>';
    }

    async function loadErrors() {
        const body = $('psBody');
        try {
            const r = await api(EP + '?view=errors');
            body.innerHTML = r.errors.length ? r.errors.map(e => '<div class="ps-log"><b>' + esc(e.created_at) + '</b> • ' + esc(e.stage) + (e.attempt ? ' (attempt ' + esc(e.attempt) + ')' : '') + '<pre>' + esc(e.message) + '</pre></div>').join('') : '<div class="ps-empty">No errors recorded.</div>';
        } catch (e) { body.innerHTML = '<div class="ps-empty">' + esc(errText(e)) + '</div>'; }
    }

    /* ------------------------------- actions ------------------------------- */
    $('psCheckNow').addEventListener('click', async () => {
        const b = $('psCheckNow'); b.disabled = true; const old = b.textContent; b.textContent = 'CHECKING…';
        try {
            const r = await api(EP, { method: 'POST', body: { action: 'check_now' } });
            toast(r.message, r.status === 'failed' ? { variant: 'danger' } : { duration: 5200 });
            render(r.overview); showTab(st.tab);
        } catch (e) { toast(errText(e), { variant: 'danger' }); loadStatus(); }
        finally { b.disabled = false; b.textContent = old; }
    });
    $('psSave').addEventListener('click', async () => {
        try {
            const r = await api(EP, { method: 'POST', body: { action: 'save_settings', enabled: Number($('psEnabled').value), interval_seconds: Number($('psInterval').value) } });
            render(r.overview); toast('Settings saved.', { variant: 'success' });
        } catch (e) { toast(errText(e), { variant: 'danger' }); }
    });
    document.querySelectorAll('[data-ps-tab]').forEach(b => b.addEventListener('click', () => showTab(b.dataset.psTab)));
    document.querySelectorAll('.admin-nav-btn[data-admin-page="price-sync"]').forEach(btn => btn.addEventListener('click', () => { loadStatus().then(() => showTab(st.tab)); }));
    // live refresh while the page is open (read-only database call; the work itself runs on the server)
    setInterval(() => { const pg = $('adminPriceSyncPage'); if (!document.hidden && pg && pg.classList.contains('active')) { loadStatus().then(() => { if (st.tab === 'history' && st.page === 1) loadHistory(); }); } }, 5000);
    window.KPPriceSync = { load: () => loadStatus().then(() => showTab(st.tab)) };
})();
