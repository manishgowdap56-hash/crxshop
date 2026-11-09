/* =====================================================================
   v37 admin: Referral Commission Management.
   The browser only DISPLAYS what api/admin/referral-commission.php returns and SENDS the admin's chosen percentage;
   the server validates it, stores it, and is the only place a commission is ever calculated.
   ===================================================================== */
(function () {
    'use strict';
    if (document.body.dataset.page !== 'admin' || !window.KP) return;
    const { api, toast } = window.KP;
    const EP = 'api/admin/referral-commission.php';
    const $ = id => document.getElementById(id);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = v => '₹' + Number(v || 0).toFixed(2);
    const num = v => Number(v || 0).toLocaleString();
    const pct = v => { let s = Number(v || 0).toFixed(2).replace(/0+$/, '').replace(/\.$/, ''); return (s === '' ? '0' : s) + '%'; };
    const st = { uid: 0, uname: '', hPage: 1, uPage: 1, range: { min: 0.1, max: 25 }, busy: {} };
    const STATUS_CLASS = { credited: 'completed', pending: 'pending', failed: 'failed', reversed: 'refunded' };

    const errText = e => ((e && e.message) || 'Request failed.') + (e && e.payload && e.payload.code ? ' [' + e.payload.code + ']' : '');
    function banner(kind, text) {
        const box = $('rcBanners'); if (!box) return;
        const div = document.createElement('div');
        div.className = 'po-banner po-' + kind; div.textContent = text;
        box.appendChild(div);
    }

    /* ------------------------------ settings ------------------------------ */
    function renderSettings(s) {
        st.range = { min: s.min, max: s.max };
        $('rcActive').value = s.label;
        $('rcRange').textContent = '(' + pct(s.min) + ' – ' + pct(s.max) + ')';
        $('rcMeta').textContent = 'Applies to FUTURE eligible purchases only. Past commissions keep the percentage that was applied when they were credited.' +
            (s.updated_at ? ' • Last saved: ' + s.updated_at : '') + (s.stored ? '' : ' • Using the built-in default until you save a value.');
        const sel = $('rcSelect');
        const known = Array.from(sel.options).some(o => o.value !== '' && o.value !== 'custom' && Number(o.value) === Number(s.percent));
        if (known) { sel.value = String(Number(s.percent)); $('rcCustomWrap').hidden = true; }
        else { sel.value = 'custom'; $('rcCustomWrap').hidden = false; $('rcCustom').value = String(s.percent); }
    }

    async function loadSettings() {
        const r = await api(EP + '?view=settings');
        renderSettings(r.settings);
    }

    async function save() {
        const sel = $('rcSelect').value;
        const raw = sel === 'custom' ? $('rcCustom').value.trim() : sel;
        if (raw === '') { toast('Choose a percentage first.', { variant: 'danger' }); return; }
        const v = Number(raw);
        if (!isFinite(v) || v < st.range.min || v > st.range.max) { toast('Percentage must be between ' + pct(st.range.min) + ' and ' + pct(st.range.max) + '.', { variant: 'danger' }); return; }
        if (!confirm('Set the active referral commission to ' + pct(v) + '?\n\nIt applies to future eligible purchases only. Existing commissions are not changed.')) return;
        const btn = $('rcSave'); btn.disabled = true;
        try {
            const r = await api(EP, { method: 'POST', body: { action: 'save_percent', percent: raw } });
            renderSettings(r.settings);
            toast(r.message, { duration: 3600 });
        } catch (e) { toast(errText(e), { variant: 'danger' }); }
        finally { btn.disabled = false; }
    }

    /* ------------------------------ summary ------------------------------ */
    function renderSummary(s) {
        const c = (label, value, note) => `<div class="ao-stat"><span>${esc(label)}</span><b>${esc(value)}</b>${note ? `<small>${esc(note)}</small>` : ''}</div>`;
        $('rcSummary').innerHTML = [
            c('REGISTERED REFERRALS', num(s.registered_referrals), num(s.referrals_who_bought) + ' have purchased'),
            c('ELIGIBLE PURCHASES', num(s.eligible_purchases), 'completed orders by referred users'),
            c('PURCHASE VALUE', money(s.purchase_value), 'orders only, not deposits'),
            c('COMMISSION EARNED', money(s.commission_earned), 'excludes failed'),
            c('COMMISSION CREDITED', money(s.commission_credited), 'paid to wallets'),
            c('PENDING', money(s.commission_pending), num(s.pending_count) + ' record(s)'),
            c('FAILED', money(s.commission_failed), num(s.failed_count) + ' record(s)'),
            c('REVERSED', money(s.commission_reversed), num(s.reversed_count) + ' record(s)'),
            c('NET CREDITED', money(s.net_credited), 'credited minus reversed'),
            c('USERS PAID', num(s.users_paid), 'received commission'),
            c('COMMISSION TRANSACTIONS', num(s.transactions), 'wallet credits'),
            c('REFERRED USERS\' DEPOSITS', money(s.referred_deposits.amount), num(s.referred_deposits.count) + ' approved • never commissioned'),
        ].join('');
        if (!s.ledger_matches_events) {
            banner('warn', '⚠ The commission ledger (' + money(s.commission_credited) + ') differs from the legacy per-event total (' + money(s.legacy_events_total) + '). Some historical events had no order reference; totals on this page follow the ledger.');
        }
    }

    async function loadSummary() {
        const r = await api(EP + '?view=summary');
        renderSummary(r.summary);
        renderSettings(r.settings);
    }

    /* ------------------------------ pager ------------------------------ */
    function pager(box, r, go) {
        box.innerHTML = '';
        if (r.pages <= 1) return;
        const mk = (label, p, dis, on) => {
            const b = document.createElement('button'); b.type = 'button'; b.textContent = label;
            if (on) b.classList.add('on'); if (dis) b.disabled = true; else b.addEventListener('click', () => go(p));
            box.appendChild(b);
        };
        mk('‹ PREV', r.page - 1, r.page <= 1);
        mk(r.page + ' / ' + r.pages, r.page, true, true);
        mk('NEXT ›', r.page + 1, r.page >= r.pages);
    }

    /* ------------------------------ user-wise ------------------------------ */
    function userCard(u) {
        const el = document.createElement('article');
        el.className = 'ao-card';
        el.innerHTML = `
            <div class="ao-top"><span class="ao-ref"></span><span class="ao-status s-completed">${esc(num(u.referred_users))} REFERRED</span></div>
            <div class="ao-pills"><span class="ao-pill qty">${esc(num(u.purchases))} purchase(s)</span><span class="ao-pill amt">${esc(money(u.purchase_value))}</span></div>
            <div class="ops-line e"></div><div class="ops-line p"></div><div class="ops-line l"></div>
            <div class="ao-actions"><button type="button" data-a="hist">VIEW COMMISSION HISTORY</button></div>`;
        el.querySelector('.ao-ref').textContent = u.username + ' (ID ' + u.user_id + ')';
        el.querySelector('.e').textContent = 'Earned ' + money(u.earned) + ' • Credited ' + money(u.credited);
        el.querySelector('.p').textContent = 'Pending ' + money(u.pending) + ' • Failed ' + money(u.failed) + ' • Reversed ' + money(u.reversed);
        el.querySelector('.l').textContent = 'Latest commission: ' + (u.last_at || '—');
        el.querySelector('[data-a="hist"]').addEventListener('click', () => {
            st.uid = u.user_id; st.uname = u.username; st.hPage = 1;
            loadHistory().then(() => { const t = $('rcScope'); if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
        });
        return el;
    }

    async function loadUsers(page) {
        if (page) st.uPage = page;
        $('rcUsersCount').textContent = 'Loading…';
        try {
            const r = await api(EP + '?' + new URLSearchParams({ view: 'users', q: $('rcUserSearch').value.trim(), page: String(st.uPage) }));
            st.uPage = r.page;
            $('rcUsersCount').textContent = r.total ? `${num(r.total)} referring user(s) • page ${r.page} of ${r.pages}` : 'No referring users yet.';
            const list = $('rcUsers'); list.innerHTML = '';
            if (!r.users.length) list.innerHTML = '<div class="admin-order-empty">' + ($('rcUserSearch').value.trim() ? 'No users match your search.' : 'Nobody has referred a user yet.') + '</div>';
            r.users.forEach(u => list.appendChild(userCard(u)));
            pager($('rcUsersPager'), r, p => loadUsers(p));
        } catch (e) {
            $('rcUsersCount').textContent = 'Could not load user earnings.';
            $('rcUsers').innerHTML = '<div class="admin-order-empty">' + esc(errText(e)) + '</div>';
            $('rcUsersPager').innerHTML = '';
        }
    }

    /* ------------------------------ history ------------------------------ */
    function filterParams() {
        const p = new URLSearchParams();
        const add = (k, v) => { v = String(v == null ? '' : v).trim(); if (v !== '') p.set(k, v); };
        add('q', $('rcSearch').value); add('from', $('rcFrom').value); add('to', $('rcTo').value);
        add('status', $('rcStatus').value); add('percent', $('rcPercent').value);
        add('referrer', $('rcReferrer').value); add('referred', $('rcReferred').value);
        if (st.uid > 0) p.set('uid', String(st.uid));
        return p;
    }

    function histCard(c) {
        const el = document.createElement('article');
        el.className = 'ao-card';
        el.innerHTML = `
            <div class="ao-top"><span class="ao-ref"></span><span class="ao-status s-${STATUS_CLASS[c.status] || 'pending'}">${esc(String(c.status).toUpperCase())}</span></div>
            <div class="ao-pills"><span class="ao-pill amt rc-comm"></span><span class="ao-pill qty rc-pct"></span><span class="ao-pill dur rc-ch"></span></div>
            <div class="ops-line a"></div><div class="ops-line b"></div><div class="ops-line o"></div><div class="ops-line t"></div>
            <div class="ao-actions"><button type="button" data-a="detail">DETAILS</button></div>`;
        el.querySelector('.ao-ref').textContent = 'Commission #' + c.id;
        el.querySelector('.rc-comm').textContent = money(c.commission);
        el.querySelector('.rc-pct').textContent = pct(c.percent) + ' applied';
        el.querySelector('.rc-ch').textContent = String(c.channel).toUpperCase();
        el.querySelector('.a').textContent = '🤝 Referrer: ' + (c.referrer || '(deleted user)') + ' (ID ' + c.referrer_id + ')';
        el.querySelector('.b').textContent = '👤 Referred: ' + (c.referred || '(deleted user)') + ' (ID ' + c.referred_id + ')';
        el.querySelector('.o').textContent = '🧾 Order ' + (c.order_ref || ('#' + c.order_id)) + ' • eligible amount ' + money(c.eligible_amount) + (c.wallet_ledger_id ? ' • wallet ledger #' + c.wallet_ledger_id : '');
        el.querySelector('.t').textContent = '🕒 ' + c.created_at + (c.status === 'reversed' ? ' • reversed ' + money(c.reversed_amount) : '');
        el.querySelector('[data-a="detail"]').addEventListener('click', () => detail(c.id));
        return el;
    }

    async function loadHistory(page) {
        if (page) st.hPage = page;
        $('rcCount').textContent = 'Loading…';
        const scope = $('rcScope');
        if (st.uid > 0) {
            scope.hidden = false;
            scope.innerHTML = '';
            scope.append('Showing the commission history of ' + st.uname + ' (ID ' + st.uid + '). ');
            const b = document.createElement('button'); b.type = 'button'; b.className = 'ao-clear'; b.textContent = 'SHOW ALL USERS';
            b.addEventListener('click', () => { st.uid = 0; st.uname = ''; st.hPage = 1; loadHistory(); });
            scope.appendChild(b);
        } else { scope.hidden = true; scope.innerHTML = ''; }
        const p = filterParams(); p.set('view', 'history'); p.set('page', String(st.hPage));
        try {
            const r = await api(EP + '?' + p.toString());
            st.hPage = r.page;
            const filtered = Array.from(filterParams().keys()).length > 0;
            $('rcCount').textContent = r.total
                ? `${num(r.total)} commission(s) • page ${r.page} of ${r.pages} • purchase value ${money(r.filtered.purchase_value)} • credited ${money(r.filtered.commission_credited)}`
                : (filtered ? 'No commissions match these filters.' : 'No commissions yet.');
            const list = $('rcList'); list.innerHTML = '';
            if (!r.rows.length) list.innerHTML = '<div class="admin-order-empty">' + (filtered ? 'No commissions match your filters.' : 'No referral commissions have been recorded yet.') + '</div>';
            r.rows.forEach(c => list.appendChild(histCard(c)));
            pager($('rcPager'), r, p2 => loadHistory(p2));
        } catch (e) {
            $('rcCount').textContent = 'Could not load commission history.';
            $('rcList').innerHTML = '<div class="admin-order-empty">' + esc(errText(e)) + '</div>';
            $('rcPager').innerHTML = '';
        }
    }

    async function detail(id) {
        try {
            const r = await api(EP + '?view=detail&id=' + id);
            const c = r.commission, w = r.wallet_entry, o = r.order;
            const kv = rows => '<dl class="ao-kv">' + rows.map(x => `<dt>${esc(x[0])}</dt><dd>${esc(x[1])}</dd>`).join('') + '</dl>';
            let html = `<h3 class="ops-h">COMMISSION #${esc(c.id)}</h3>` + kv([
                ['Status', String(c.status).toUpperCase()], ['Referring user', (c.referrer || '(deleted)') + ' (ID ' + c.referrer_id + ')'], ['Referred user', (c.referred || '(deleted)') + ' (ID ' + c.referred_id + ')'],
                ['Order', (c.order_ref || '—') + ' (ID ' + c.order_id + ')'], ['Eligible purchase amount', money(c.eligible_amount)], ['Percentage applied', pct(c.percent)],
                ['Commission amount', money(c.commission)], ['Channel', String(c.channel).toUpperCase()], ['Created', c.created_at],
                ['Reversed amount', c.status === 'reversed' ? money(c.reversed_amount) + ' on ' + (c.reversed_at || '—') : '—'], ['Note', c.note || '—'],
            ]);
            html += '<h3 class="ops-h" style="margin-top:14px">WALLET LEDGER ENTRY</h3>' + (w ? kv([
                ['Ledger ID', '#' + w.id], ['Amount', money(w.amount)], ['Balance after', money(w.balance_after)], ['Reference', w.reference_id || '—'], ['Description', w.description || '—'], ['Time', w.created_at],
            ]) : '<div class="ops-warn">No wallet entry is linked to this commission' + (c.status === 'failed' ? ' (the credit failed and nothing was paid).' : '.') + '</div>');
            if (o) html += '<h3 class="ops-h" style="margin-top:14px">ORDER</h3>' + kv([['Order', o.order_ref], ['Status', o.status], ['Total', money(o.total_price)], ['Source', o.source], ['Placed', o.created_at]]);
            $('opsModalBody').innerHTML = html; $('opsModal').hidden = false;
        } catch (e) { toast(errText(e), { variant: 'danger' }); }
    }

    /* ------------------------------ page load ------------------------------ */
    async function load() {
        const box = $('rcBanners'); if (box) box.innerHTML = '';
        const jobs = [
            loadSummary().catch(e => { $('rcSummary').innerHTML = ''; banner('bad', '❌ ' + errText(e)); return loadSettings().catch(() => {}); }),
            loadUsers(1),
            loadHistory(1),
        ];
        await Promise.all(jobs);
    }

    $('rcSave') && $('rcSave').addEventListener('click', save);
    $('rcSelect') && $('rcSelect').addEventListener('change', () => { $('rcCustomWrap').hidden = $('rcSelect').value !== 'custom'; });
    let tu = null, th = null;
    $('rcUserSearch') && $('rcUserSearch').addEventListener('input', () => { clearTimeout(tu); tu = setTimeout(() => loadUsers(1), 350); });
    $('rcSearch') && $('rcSearch').addEventListener('input', () => { clearTimeout(th); th = setTimeout(() => loadHistory(1), 350); });
    ['rcFrom', 'rcTo', 'rcStatus'].forEach(id => $(id) && $(id).addEventListener('change', () => loadHistory(1)));
    $('rcApply') && $('rcApply').addEventListener('click', () => loadHistory(1));
    ['rcPercent', 'rcReferrer', 'rcReferred'].forEach(id => $(id) && $(id).addEventListener('keydown', e => { if (e.key === 'Enter') loadHistory(1); }));
    $('rcReset') && $('rcReset').addEventListener('click', () => {
        ['rcSearch', 'rcFrom', 'rcTo', 'rcPercent', 'rcReferrer', 'rcReferred'].forEach(id => { if ($(id)) $(id).value = ''; });
        $('rcStatus').value = ''; st.uid = 0; st.uname = ''; loadHistory(1);
    });
    $('rcExport') && $('rcExport').addEventListener('click', () => {
        const p = filterParams(); p.set('export', '1');
        window.location.href = (window.KP.baseUrl || '') + '/' + EP + '?' + p.toString();
    });

    window.KPReferralAdmin = { load };
})();
