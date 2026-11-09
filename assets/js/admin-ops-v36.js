/* =====================================================================
   v36 admin additions: daily financial cards + Pending Orders / Refund Controller page.
   The browser only displays what the server returns. Every money decision is made by PHP.
   ===================================================================== */
(function () {
    'use strict';
    if (document.body.dataset.page !== 'admin' || !window.KP) return;
    const { api, toast } = window.KP;
    const $ = id => document.getElementById(id);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = v => '₹' + Number(v || 0).toFixed(2);
    const num = v => Number(v || 0).toLocaleString();
    const set = (id, v) => { const e = $(id); if (e) e.textContent = v; };
    const ago = sec => {
        sec = Math.max(0, Math.floor(sec));
        if (sec < 60) return sec + 's';
        if (sec < 3600) return Math.floor(sec / 60) + 'm';
        if (sec < 86400) return Math.floor(sec / 3600) + 'h ' + Math.floor((sec % 3600) / 60) + 'm';
        return Math.floor(sec / 86400) + 'd ' + Math.floor((sec % 86400) / 3600) + 'h';
    };

    /* ------------------------------------------------------------------ */
    /*  Financial cards (dashboard)                                       */
    /* ------------------------------------------------------------------ */
    function setBadge(n) {
        const b = $('adminPendingBadge');
        if (!b) return;
        b.hidden = !n;
        b.textContent = n > 99 ? '99+' : String(n || 0);
    }

    window.KPAdminFinance = {
        render(r) {
            const mb = $('adminMaintBanner'); if (mb) mb.hidden = !r.maintenance_mode;
            const cb = $('adminControllerBanner'); if (cb) cb.hidden = !(r.controller && r.controller.stale);
            const f = r.finance;
            if (!f) { set('finSub', 'Financial data unavailable (see server log).'); return; }
            const err = f.errors || {};
            const val = (key, fn) => (f[key] == null || err[key]) ? 'N/A' : fn(f[key]);
            set('finSub', f.date + ' • ' + f.timezone + ' • day resets at 12:00 AM');

            set('finDeposits', val('deposits', d => money(d.amount)));
            set('finDepositsNote', f.deposits ? num(f.deposits.count) + ' completed deposit(s)' : 'Completed wallet deposits');
            set('finSales', val('sales', s => money(s.amount)));
            set('finSalesNote', f.sales && f.net_sales != null ? num(f.sales.count) + ' order(s) • net after refunds ' + money(f.net_sales) : 'Completed orders');
            set('finSpending', val('spending', money));
            set('finBuyers', f.unique_buyers == null || err.sales ? 'N/A' : num(f.unique_buyers));
            set('finRefundTotal', val('refunds', x => money(x.amount)));
            set('finRefundCount', val('refunds', x => num(x.count)));
            set('finPending', f.pending_orders == null ? 'N/A' : num(f.pending_orders));
            set('finExpired', f.expired_pending == null ? 'N/A' : num(f.expired_pending));
            set('finExpiredNote', 'Past the ' + Math.round((f.processing_window_seconds || 600) / 60) + ' min window' + (f.needs_review ? ' • ' + num(f.needs_review) + ' need review' : ''));
            setBadge(f.expired_pending || 0);
        }
    };
    document.querySelectorAll('.fin-clickable').forEach(el => {
        const go = () => { const b = document.querySelector('.admin-nav-btn[data-admin-page="' + el.dataset.go + '"]'); if (b) b.click(); };
        el.addEventListener('click', go);
        el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
    });

    /* ------------------------------------------------------------------ */
    /*  Pending Orders page                                               */
    /* ------------------------------------------------------------------ */
    const st = { page: 1, loading: false };
    const RS = { none: 'NOT EVALUATED', review: 'NEEDS REVIEW', blocked: 'BLOCKED', eligible: 'ELIGIBLE', failed: 'REFUND FAILED • RETRYING', processing: 'PROCESSING', refunded: 'REFUNDED' };
    const modalOpen = html => { $('opsModalBody').innerHTML = html; $('opsModal').hidden = false; };
    const modalClose = () => { const m = $('opsModal'); if (m) { m.hidden = true; $('opsModalBody').innerHTML = ''; } };
    const kv = rows => '<dl class="ao-kv">' + rows.map(r => `<dt>${esc(r[0])}</dt><dd>${r[1]}</dd>`).join('') + '</dl>';

    function banners(r) {
        const out = [];
        const c = r.counts, a = r.alerts, k = r.controller;
        if (c.expired > 0) out.push(['warn', `⏰ ${num(c.expired)} expired pending order(s) past the ${Math.round(k.window_seconds / 60)} min window.`]);
        if (a.review > 0 || c.review > 0) out.push(['warn', `🧐 ${num(Math.max(a.review, c.review))} order(s) need admin review (provider outcome not verifiable).`]);
        if (a.refund_failed > 0 || c.refund_failed > 0) out.push(['bad', `❌ ${num(Math.max(a.refund_failed, c.refund_failed))} refund(s) failed. They are retried safely on the next run, or refund manually.`]);
        if (a.api_errors > 0) out.push(['bad', `🔌 ${num(a.api_errors)} open provider-unreachable alert(s). Check API Settings.`]);
        if (k.stale) out.push(['bad', '⚠ The Refund Controller has not run for 15+ minutes while expired orders exist. Check the cron job.']);
        if (k.last_summary && k.last_summary.refunded > 0) out.push(['ok', `✅ Last run refunded ${num(k.last_summary.refunded)} order(s) automatically.`]);
        if (!out.length) out.push(['ok', '✅ No expired or problem orders.']);
        $('poBanners').innerHTML = out.map(b => `<div class="po-banner po-${b[0]}">${esc(b[1])}</div>`).join('');
    }

    const CHIP = { pending: 'pending', processing: 'proc', expired: 'expired', refund_pending: 'failed', refund_failed: 'failed', manual_review: 'review',
                   auto_refunded: 'completed', manual_refunded: 'manual', failed_unrefunded: 'failed', completed: 'completed', other: 'pending' };

    function decisionLabel(d, res) {
        d = String(d || ''); res = String(res || '');
        if (d === 'eligible') return res === 'refunded' ? 'AUTO-REFUNDED' : (res === 'refund_failed' ? 'REFUND FAILED' : (res === 'not_executed' ? 'REFUND ELIGIBLE (AUTO OFF)' : 'REFUND ELIGIBLE'));
        if (d === 'manual') return res === 'refunded' ? 'MANUAL REFUND COMPLETED' : 'MANUAL REFUND ATTEMPT';
        if (d === 'review') return 'MANUAL REVIEW REQUIRED';
        if (d === 'blocked') return 'BLOCKED (NO REFUND)';
        if (d === 'wait') return 'WAIT';
        if (d === 'retry') return 'RE-CHECK';
        if (d === 'recheck') return 'RE-CHECK (ADMIN)';
        if (d === 'completed') return 'COMPLETED BY ADMIN';
        return d.toUpperCase();
    }

    function renderRecent(rows) {
        const box = $('poRecent');
        if (!rows.length) { box.innerHTML = '<div class="admin-order-empty">No controller decisions match.</div>'; return; }
        box.innerHTML = rows.map(x => {
            const good = x.result === 'refunded';
            const bad = x.result === 'refund_failed';
            const trail = (x.prev_status || x.new_status) ? esc((x.prev_status || '—') + ' → ' + (x.new_status || '—')) + ' • ' : '';
            return `<div class="po-recent-row ${good ? 'ok' : (bad ? 'bad' : '')}"><b>${esc(x.created_at)}</b> • #${esc(x.order_ref)} • ${esc(decisionLabel(x.decision, x.result))} • result: ${esc(String(x.result).toUpperCase())}` +
                (good || x.decision === 'manual' ? ' • ' + money(x.amount) : '') + ` • ${esc(x.actor)}<br><span>${trail}${x.api_status ? 'API: ' + esc(x.api_status) + ' • ' : ''}${esc(x.reason || '')}</span></div>`;
        }).join('');
    }

    function card(o) {
        const el = document.createElement('article');
        el.className = 'ao-card';
        const chip = CHIP[o.state] || 'pending';
        const open = o.order_status === 'pending';
        el.innerHTML = `
            <div class="ao-top"><span class="ao-ref"></span><span class="ao-status s-${chip}"></span></div>
            <div class="ao-prod"><span aria-hidden="true">⏳</span><strong class="pn"></strong></div>
            <div class="ao-pills"><span class="ao-pill dur"></span><span class="ao-pill qty"></span><span class="ao-pill amt"></span></div>
            <div class="ops-line u"></div><div class="ops-line pv"></div><div class="ops-line cr"></div><div class="ops-line ex"></div><div class="ops-line as"></div>
            <div class="ops-line el"></div><div class="ops-line rf"></div><div class="ops-line cd"></div><div class="ops-line er"></div>
            <div class="ao-actions"><button type="button" data-a="detail">DETAILS</button>${open ? '<button type="button" data-a="recheck">RE-CHECK</button>' : ''}${open && o.expired ? '<button type="button" class="ban" data-a="refund">REFUND</button>' : ''}</div>`;
        el.querySelector('.ao-ref').textContent = o.order_ref;
        el.querySelector('.ao-status').textContent = String(o.state_label).toUpperCase();
        el.querySelector('.pn').textContent = o.product;
        el.querySelector('.dur').textContent = o.duration;
        el.querySelector('.qty').textContent = '×' + o.quantity;
        el.querySelector('.amt').textContent = money(o.amount);
        el.querySelector('.u').textContent = '👤 ' + o.username + ' (ID ' + o.user_id + ')';
        el.querySelector('.pv').textContent = '🔌 ' + o.provider.label + ' • order status: ' + String(o.order_status).toUpperCase();
        el.querySelector('.cr').textContent = '🕒 Created ' + o.created_at;
        el.querySelector('.ex').textContent = open ? ('⏱ API expiry ' + o.expires_at + (o.expired ? ' • overdue ' + ago(o.overdue_seconds) : ' • still inside the processing window')) : '⏱ Processing window: closed (order is no longer pending)';
        el.querySelector('.as').textContent = '📡 API status: ' + o.api_status + (o.last_checked_at ? ' • last checked ' + o.last_checked_at : ' • never checked') + (o.attempts ? ' • checks ' + o.attempts : '');
        el.querySelector('.el').textContent = '⚖ Refund eligibility: ' + o.eligibility;
        const rf = o.refund || {};
        el.querySelector('.rf').textContent = rf.done
            ? '💸 ' + (rf.method === 'manual' ? 'Manual Refund Completed' : 'Auto Refunded') + (rf.amount != null ? ' ' + money(rf.amount) : '') + ' • ' + (rf.method === 'manual' ? 'manual (admin)' : 'automatic') + ' • ' + (rf.at || '—') + (rf.ledger_id ? ' • wallet ledger #' + rf.ledger_id : '')
            : '💸 Refund: ' + (o.state === 'refund_pending' ? 'REFUND PENDING — verified failure, not refunded yet' + (o.expired ? ' (overdue ' + ago(o.overdue_seconds) + ')' : '')
                : o.state === 'refund_failed' ? 'REFUND FAILED — will retry safely' : o.state === 'manual_review' ? 'MANUAL REVIEW REQUIRED' : o.state === 'completed' ? 'not applicable' : 'not refunded');
        const ld = o.last_decision;
        el.querySelector('.cd').textContent = ld ? '🧠 Controller: ' + decisionLabel(ld.decision, ld.result) + ' • ' + ld.at + (ld.prev || ld.next ? ' • ' + (ld.prev || '—') + ' → ' + (ld.next || '—') : '') : '🧠 Controller: no decision recorded yet';
        const why = (ld && ld.reason) ? ld.reason : (o.failure_note || '');
        const eln = el.querySelector('.er');
        if (why && o.state !== 'completed' && o.state !== 'pending') eln.textContent = (o.state === 'manual_review' || o.state === 'refund_failed' ? '⚠ Reason: ' : '📝 Reason: ') + why; else eln.remove();
        el.querySelector('[data-a="detail"]').addEventListener('click', () => detail(o.id));
        const rc = el.querySelector('[data-a="recheck"]'); if (rc) rc.addEventListener('click', () => recheck(o.id));
        const rb = el.querySelector('[data-a="refund"]'); if (rb) rb.addEventListener('click', () => detail(o.id));
        return el;
    }

    /* Controller decision history (filterable, paginated; rows are never deleted) */
    async function loadDecisions(page) {
        if (page) st.dPage = page;
        const p = new URLSearchParams({ view: 'decisions', order: $('pdOrder').value.trim(), decision: $('pdType').value, from: $('pdFrom').value, to: $('pdTo').value, page: String(st.dPage || 1) });
        try {
            const r = await api('api/admin/pending-orders.php?' + p.toString());
            st.dPage = r.page;
            renderRecent(r.decisions);
            const box = $('poDecPager'); box.innerHTML = '';
            if (r.pages > 1) {
                const mk = (label, pg, dis, on) => { const b = document.createElement('button'); b.type = 'button'; b.textContent = label; if (on) b.classList.add('on'); if (dis) b.disabled = true; else b.addEventListener('click', () => loadDecisions(pg)); box.appendChild(b); };
                mk('‹ PREV', r.page - 1, r.page <= 1); mk(r.page + ' / ' + r.pages + ' (' + num(r.total) + ')', r.page, true, true); mk('NEXT ›', r.page + 1, r.page >= r.pages);
            }
        } catch (e) { $('poRecent').innerHTML = '<div class="admin-order-empty">' + esc(e.message || 'Could not load decisions.') + '</div>'; $('poDecPager').innerHTML = ''; }
    }

    const STATE_CARDS = [['every', 'ALL ORDERS'], ['all', 'ALL PENDING'], ['processing', 'PROCESSING'], ['expired', 'EXPIRED / OVERDUE'], ['confirmed_failed', 'CONFIRMED FAILED'], ['refund_eligible', 'REFUND ELIGIBLE'],
        ['not_refunded', 'NOT YET REFUNDED'], ['refund_failed', 'REFUND FAILED'], ['manual_review', 'MANUAL REVIEW'], ['auto_refunded', 'AUTO REFUNDED'], ['manual_refunded', 'MANUAL REFUND DONE'], ['completed', 'COMPLETED']];
    function stateCards(r) {
        const g = r.group_counts || {};
        $('poStates').innerHTML = STATE_CARDS.map(([k, label]) => {
            const n = k === 'expired' ? r.counts.expired : g[k];
            return `<button type="button" class="ao-stat po-card${r.filter === k ? ' on' : ''}" data-f="${k}"><span>${label}</span><b>${num(n)}</b></button>`;
        }).join('') + '<div class="po-note">Each card is a filter. An order is counted once per card; some cards overlap on purpose (e.g. CONFIRMED FAILED includes AUTO REFUNDED), and ALL ORDERS counts every order exactly once.</div>';
        $('poStates').querySelectorAll('.po-card').forEach(b => b.addEventListener('click', () => { $('poFilter').value = b.dataset.f; load(true); }));
    }

    /* v37: settings come from a settings-only request, so the controls always show real values even when the list cannot load. */
    async function loadConfig() {
        const r = await api('api/admin/pending-orders.php?view=config');
        const k = r.controller;
        if (document.activeElement !== $('poWindow')) $('poWindow').value = k.window_seconds;
        if (document.activeElement !== $('poAuto')) $('poAuto').value = k.auto_enabled ? '1' : '0';
        $('poMeta').textContent = 'Controller last run: ' + (k.last_run || 'never') + ' • window ' + Math.round(k.window_seconds / 60 * 10) / 10 + ' min • automatic refunds ' + (k.auto_enabled ? 'ON' : 'OFF');
        return r;
    }

    function showFailure(e) {
        const code = e && e.payload && e.payload.code ? ' [' + e.payload.code + ']' : '';
        const msg = (e && e.message) || 'Request failed.';
        $('poBanners').innerHTML = `<div class="po-banner po-bad">❌ ${esc(msg)}${esc(code)}</div>`;
        $('poSummary').innerHTML = '';
        $('poList').innerHTML = '<div class="admin-order-empty">Pending orders could not be loaded. Fix the problem above, then press RESET to try again.</div>';
        $('poCount').textContent = 'Could not load pending orders.';
        $('poPager').innerHTML = '';
    }

    async function load(reset) {
        if (st.loading) return;
        if (reset === true) st.page = 1;
        st.loading = true;
        $('poCount').textContent = 'Loading…';
        let schemaBad = null;
        try {
            const cfg = await loadConfig();
            if (cfg.schema && !cfg.schema.ok) schemaBad = cfg.schema.missing;
        } catch (e) { /* the list request below reports the same problem */ }
        const q = new URLSearchParams({ filter: $('poFilter').value || 'all', q: $('poSearch').value.trim(), from: $('poFrom').value, to: $('poTo').value, page: String(st.page) });
        try {
            const r = await api('api/admin/pending-orders.php?' + q.toString());
            st.page = r.page;
            banners(r);
            stateCards(r);
            if (r.recent_error) $('poBanners').insertAdjacentHTML('beforeend', '<div class="po-banner po-warn">⚠ Recent controller decisions could not be read (see server log).</div>');
            $('poSummary').innerHTML = [['PENDING', r.counts.pending], ['EXPIRED', r.counts.expired], ['NEED REVIEW', r.counts.review], ['REFUND FAILED', r.counts.refund_failed]]
                .map(x => `<div class="ao-stat"><span>${x[0]}</span><b>${num(x[1])}</b></div>`).join('');
            const k = r.controller;
            if (document.activeElement !== $('poWindow')) $('poWindow').value = k.window_seconds;
            if (document.activeElement !== $('poAuto')) $('poAuto').value = k.auto_enabled ? '1' : '0';
            $('poMeta').textContent = 'Controller last run: ' + (k.last_run || 'never') + ' • window ' + Math.round(k.window_seconds / 60 * 10) / 10 + ' min • automatic refunds ' + (k.auto_enabled ? 'ON' : 'OFF');
            setBadge(r.counts.expired);
            const filtered = ($('poSearch').value.trim() !== '') || (($('poFilter').value || 'all') !== 'all') || $('poFrom').value !== '' || $('poTo').value !== '';
            $('poCount').textContent = r.total ? `${num(r.total)} order(s) • page ${r.page} of ${r.pages}` : (filtered ? 'No orders match.' : 'No pending orders right now.');
            const list = $('poList'); list.innerHTML = '';
            if (!r.orders.length) list.innerHTML = '<div class="admin-order-empty">' + (filtered ? 'No orders match your search or filter.' : 'No pending orders. Everything has been processed.') + '</div>';
            r.orders.forEach(o => list.appendChild(card(o)));
            pager(r);
            loadDecisions();
        } catch (e) {
            showFailure(e);
            if (schemaBad && schemaBad.length) $('poBanners').insertAdjacentHTML('beforeend', `<div class="po-banner po-warn">Missing: ${esc(schemaBad.join(', '))}</div>`);
            $('poStates').innerHTML = '';
            loadDecisions();
            toast(e.message || 'Request failed.', { variant: 'danger' });
        } finally { st.loading = false; }
    }

    function pager(r) {
        const box = $('poPager'); box.innerHTML = '';
        if (r.pages <= 1) return;
        const mk = (label, p, dis, on) => { const b = document.createElement('button'); b.type = 'button'; b.textContent = label; if (on) b.classList.add('on'); if (dis) b.disabled = true; else b.addEventListener('click', () => { st.page = p; load(); window.scrollTo({ top: 0, behavior: 'smooth' }); }); box.appendChild(b); };
        mk('‹ PREV', r.page - 1, r.page <= 1);
        mk(String(r.page) + ' / ' + r.pages, r.page, true, true);
        mk('NEXT ›', r.page + 1, r.page >= r.pages);
    }

    async function recheck(id) {
        try {
            const r = await api('api/admin/pending-orders.php', { method: 'POST', body: { action: 'recheck', id } });
            toast(r.result === 'refunded' ? 'Refunded automatically: ' + r.message : 'Checked: ' + r.message, { duration: 3200 });
            load();
        } catch (e) { toast(e.message, { variant: 'danger' }); }
    }

    async function detail(id) {
        try {
            const r = await api('api/admin/pending-orders.php?id=' + id);
            const o = r.order, e = r.evidence, d = r.rule;
            let html = `<h3 class="ops-h">ORDER ${esc(o.order_ref)}</h3>` + kv([
                ['User', esc(o.username) + ' (ID ' + o.user_id + ')'], ['Product', esc(o.product) + ' / ' + esc(o.duration) + ' × ' + o.quantity], ['Amount', money(o.amount)],
                ['Order status', esc(o.status)], ['State', esc(o.state_label)], ['Refund eligibility', esc(o.eligibility)], ['Refund', o.refund && o.refund.done ? esc((o.refund.method === 'manual' ? 'Manual Refund Completed' : 'Auto Refunded') + ' ' + money(o.refund.amount) + ' at ' + (o.refund.at || '—') + (o.refund.ledger_id ? ' (wallet ledger #' + o.refund.ledger_id + ')' : '')) : 'none'], ['Provider', esc(o.provider.label)], ['Created', esc(o.created_at)], ['API expiry', esc(o.expires_at) + (o.expired ? ' (expired)' : '')],
                ['Latest API status', esc(o.api_status)], ['Refund status', esc(RS[o.refund_status] || o.refund_status)], ['Provider order ID', esc(o.upstream_id || '—')], ['Failure note', esc(o.failure_note || '—')],
            ]);
            html += '<h3 class="ops-h" style="margin-top:14px">VERIFICATION EVIDENCE</h3>' + kv([
                ['Keys stored', String(e.keys_stored)], ['Wallet charge records', String(e.wallet_charges)], ['Wallet refund records', String(e.wallet_refunds)],
                ['Completion records', String(e.completion_records)], ['Recorded failures', String(e.failure_records)], ['Uncertain records', String(e.uncertain_records)],
                ['Provider status', esc(e.provider_status) + ' — ' + esc(e.provider_detail)], ['Callbacks / webhooks', esc(e.callbacks)],
            ]);
            html += `<div class="ops-box"><b>Rule decision: ${esc(String(d.decision).toUpperCase())} — ${esc(d.api_status)}</b><small>${esc(d.reason)}</small></div>`;
            if (r.log.length) html += '<h3 class="ops-h" style="margin-top:14px">DECISION LOG</h3><div class="ops-log">' + r.log.map(x => `<div class="ops-line"><b>${esc(x.created_at)}</b> ${esc(decisionLabel(x.decision, x.result))} → ${esc(x.result)} • ${esc(x.actor)}<br>${(x.prev_status || x.new_status) ? esc((x.prev_status || '—') + ' → ' + (x.new_status || '—')) + ' • ' : ''}${esc(x.reason || '')}</div>`).join('') + '</div>';
            if (o.status !== 'pending') html += '<div class="ops-box"><b>' + esc(o.state_label) + '</b><small>This order is no longer pending, so no refund or completion action is available. No second refund can be issued.</small></div>';
            if (o.status === 'pending') {
                if (r.can_refund) {
                    html += `<div class="ops-box"><b>Manual refund (credits the wallet once, only if no key was delivered)</b>
                        <input id="poReason" type="text" maxlength="200" placeholder="Reason (required)" class="po-reason"/>
                        <div class="ao-actions"><button type="button" class="ban" id="poDoRefund">REFUND ${money(o.amount)} TO ${esc(o.username).toUpperCase()}</button></div></div>`;
                } else {
                    html += '<div class="ops-warn">' + (o.state === 'processing' ? 'A refund or check is being processed right now. Wait a moment and reload.' : 'Manual refund is not available: the order is inside its window, already has keys / a refund, or was never charged.') + '</div>';
                }
                html += `<div class="ops-box"><b>Provider DID deliver? Paste the key(s) to complete the order (no wallet change).</b>
                    <textarea id="poKeys" placeholder="One key per line"></textarea>
                    <div class="ao-actions"><button type="button" class="unban" id="poDoComplete">COMPLETE ORDER WITH KEY</button></div></div>`;
            }
            modalOpen(html);
            const rb = $('poDoRefund');
            if (rb) rb.addEventListener('click', async () => {
                const reason = $('poReason').value.trim();
                if (reason.length < 3) { toast('Enter a reason.', { variant: 'danger' }); return; }
                if (!confirm('Refund ' + money(o.amount) + ' to ' + o.username + '? This can only happen once.')) return;
                rb.disabled = true;
                try { const x = await api('api/admin/pending-orders.php', { method: 'POST', body: { action: 'manual_refund', id, reason } }); toast(x.message, { duration: 3200 }); modalClose(); load(); }
                catch (err) { toast(err.message, { variant: 'danger' }); rb.disabled = false; }
            });
            const cb = $('poDoComplete');
            if (cb) cb.addEventListener('click', async () => {
                const keys = $('poKeys').value.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
                if (!keys.length) { toast('Paste at least one key.', { variant: 'danger' }); return; }
                if (!confirm('Attach ' + keys.length + ' key(s) and mark the order completed? No refund will be possible afterwards.')) return;
                cb.disabled = true;
                try { await api('api/admin/pending-orders.php', { method: 'POST', body: { action: 'complete_order', id, keys } }); toast('Order completed.', { duration: 2200 }); modalClose(); load(); }
                catch (err) { toast(err.message, { variant: 'danger' }); cb.disabled = false; }
            });
        } catch (err) { toast(err.message, { variant: 'danger' }); }
    }

    $('poRun') && $('poRun').addEventListener('click', async () => {
        $('poRun').disabled = true;
        try { const r = await api('api/admin/pending-orders.php', { method: 'POST', body: { action: 'run_now' } }); toast(r.message, { duration: 3200 }); load(); }
        catch (e) { toast(e.message, { variant: 'danger' }); }
        finally { $('poRun').disabled = false; }
    });
    $('poSave') && $('poSave').addEventListener('click', async () => {
        try { await api('api/admin/pending-orders.php', { method: 'POST', body: { action: 'save_config', window_seconds: Number($('poWindow').value), auto: Number($('poAuto').value) } }); toast('Saved.', { duration: 1600 }); load(); }
        catch (e) { toast(e.message, { variant: 'danger' }); }
    });
    $('poFilter') && $('poFilter').addEventListener('change', () => load(true));
    let t = null;
    $('poSearch') && $('poSearch').addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => load(true), 350); });
    $('poReset') && $('poReset').addEventListener('click', () => { $('poSearch').value = ''; $('poFilter').value = 'all'; $('poFrom').value = ''; $('poTo').value = ''; load(true); });
    ['poFrom', 'poTo'].forEach(id => $(id) && $(id).addEventListener('change', () => load(true)));
    let td = null;
    $('pdOrder') && $('pdOrder').addEventListener('input', () => { clearTimeout(td); td = setTimeout(() => loadDecisions(1), 350); });
    ['pdType', 'pdFrom', 'pdTo'].forEach(id => $(id) && $(id).addEventListener('change', () => loadDecisions(1)));
    $('pdReset') && $('pdReset').addEventListener('click', () => { $('pdOrder').value = ''; $('pdType').value = ''; $('pdFrom').value = ''; $('pdTo').value = ''; loadDecisions(1); });

    window.KPPendingOrders = { load };
    setInterval(() => { const p = $('adminPendingOrdersPage'); if (p && p.classList.contains('active') && !document.hidden && $('opsModal').hidden) load(); }, 30000);
})();
