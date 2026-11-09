/* v59 admin Monitor page. Displays what api/admin/monitor.php returns and sends admin choices; every decision is made by PHP. */
(function () {
  'use strict';
  if (!window.KP) return;
  const { api, toast } = window.KP;
  const EP = 'api/admin/monitor.php';
  const $ = id => document.getElementById(id);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inr = v => (v === null || v === undefined || v === '') ? '—' : '₹' + Number(v).toFixed(2);
  const usd = v => (v === null || v === undefined || v === '') ? '—' : Number(v).toFixed(4);
  const mmss = s => { s = Math.max(0, Number(s) || 0); return Math.floor(s / 60) + 'm ' + String(s % 60).padStart(2, '0') + 's'; };
  const st = { tab: 'orders', orders: null, prices: null, scheduler: null, review: null, refunds: null, workers: null };
  const tile = (k, v) => '<div class="mon-tile"><b>' + esc(v) + '</b><span>' + esc(k) + '</span></div>';
  const post = (action, data) => api(EP, { method: 'POST', body: Object.assign({ action }, data) });
  const pill = (t, c) => '<span class="pill ' + (c || '') + '">' + esc(t) + '</span>';
  const STATE_CLS = { SUCCESS: 'good', CONFIRMED_REJECTED: 'bad', CONFIRMED_OUT_OF_STOCK: 'warn', UNCERTAIN: 'warn', SUCCESS_WITHOUT_KEY: 'warn', INVALID_RESPONSE: 'warn', INTERNAL_ERROR: 'bad' };

  function renderOrders() {
    const m = st.orders; if (!m) return;
    const c = m.counts || {};
    let h = '<div class="mon-tiles">' + Object.keys(c).map(k => tile(k.replace(/_/g, ' ') + ' (7d)', c[k])).join('') + tile('PENDING REVIEW', m.pending_review) + tile('KEYS PARKED', m.stranded) + '</div>';
    h += '<div class="mon-wrap"><table><thead><tr><th>Order</th><th>Channel</th><th>Product</th><th>Provider state</th><th>HTTP</th><th>Time</th><th>Upstream id</th><th>Order</th><th>Refund</th><th>Error</th><th>Last reconcile</th><th>Created</th></tr></thead><tbody>';
    (m.rows || []).forEach(r => {
      h += '<tr><td>' + esc(r.order_ref) + '</td><td>' + esc(r.channel) + '</td><td>' + esc(r.product_name || '') + ' ' + esc(r.duration || '') + '</td><td>' + pill(r.state, STATE_CLS[r.state]) + '</td><td>' + esc(r.http_code || '—') + '</td><td>' + (r.response_time_ms != null ? esc(r.response_time_ms) + ' ms' : '—') + '</td><td>' + esc(r.upstream_order_id || '—') + '</td><td>' + esc(r.order_status) + '</td><td>' + (r.refund_status ? esc(r.refund_status) + (r.refund_amount ? ' ' + inr(r.refund_amount) : '') : '—') + '</td><td>' + esc(r.error_message || '') + '</td><td>' + esc(r.last_reconcile_at || '—') + '</td><td>' + esc(r.created_at) + '</td></tr>';
    });
    $('tab-orders').innerHTML = h + '</tbody></table></div>' + (m.available ? '' : '<p>Attempt table not available.</p>');
  }

  const REVIEW_STATUS = r => {
    if (r.status === 'pending') return 'Pending Review';
    const res = String(r.resolution || '');
    if (res === 'admin_custom_price') return 'Admin Updated Price';
    if (res.indexOf('auto_applied') === 0) return 'Auto Updated';
    return 'Completed';
  };
  function renderAlerts() {
    const m = st.prices && st.prices.monitor; if (!m) return;
    let h = '<p>Review window: <b>' + esc(m.config.grace_min) + ' min</b> (server clock; keeps running when you are logged out). If you change the price before the deadline your price is kept; otherwise the new provider-derived price is applied automatically. Normal User and Reseller prices are reviewed separately.</p>';
    h += m.alerts.length ? '' : '<p>No price increase is waiting for review.</p>';
    m.alerts.forEach(a => {
      const label = a.price_category === 'reseller' ? 'Reseller' : 'Normal User';
      h += '<div class="card" data-plan="' + a.plan_id + '" data-cat="' + esc(a.price_category) + '"><h3>PRICE REVIEW — ' + esc(label.toUpperCase()) + ' PRICE ' + pill(a.status, 'warn') + '</h3>' +
        '<b>' + esc(a.product) + '</b> — ' + esc(a.duration) + ' <small>(Product ID ' + esc(a.product_id) + ' • Provider ID ' + esc(a.provider_id || '—') + ' • Plan ' + esc(a.plan_id) + ')</small>' +
        '<div>Previous provider price: ' + usd(a.old_provider_price) + ' ' + esc(a.provider_currency || '') + ' → New provider price: ' + usd(a.new_provider_price) + (Number(a.old_provider_price) > 0 ? ' (+' + (((Number(a.new_provider_price) - Number(a.old_provider_price)) / Number(a.old_provider_price)) * 100).toFixed(2) + '%)' : '') + '</div>' +
        '<div>' + esc(label) + ' exchange rate: ' + esc(a.rate) + ' • Previous calculated: ' + inr(a.old_inr) + '</div>' +
        '<div>Current website price: <b>' + inr(a.selling_price) + '</b>' + (Number(a.custom_price_enabled) ? ' (custom ' + inr(a.custom_price) + ')' : '') + ' • Proposed website price: <b>' + inr(a.new_inr) + '</b></div>' +
        '<div>Detected: ' + esc(a.detected_at) + ' • Review deadline: ' + esc(a.deadline) + ' • Remaining: <b class="left" data-s="' + a.seconds_left + '">' + mmss(a.seconds_left) + '</b></div>' +
        '<div class="row"><input class="cp" type="number" min="0" step="0.01" placeholder="Your ' + esc(label) + ' price ₹"><button class="set" type="button">Set &amp; keep my price</button><button class="confirm" type="button">Apply proposed price now</button></div></div>';
    });
    const hist = (m.history || []).filter(r => (r.event_type === 'provider_increase' && r.status !== 'pending') || r.event_type === 'auto_price_update');
    h += '<h3>Review history</h3><div class="mon-wrap"><table><thead><tr><th>Detected</th><th>Category</th><th>Product</th><th>Duration</th><th>Provider old→new</th><th>Price before→after</th><th>Resolved</th><th>Status</th><th>Note</th></tr></thead><tbody>';
    hist.forEach(r => {
      h += '<tr><td>' + esc(r.detected_at) + '</td><td>' + (r.price_category === 'reseller' ? 'Reseller' : 'Normal User') + '</td><td>' + esc(r.product_name || '') + '</td><td>' + esc(r.duration || '') + '</td><td>' + usd(r.old_provider_price) + ' → ' + usd(r.new_provider_price) + '</td><td>' + inr(r.old_selling_price) + ' → ' + inr(r.new_selling_price) + '</td><td>' + esc(r.resolved_at || '—') + '</td><td>' + esc(REVIEW_STATUS(r)) + '</td><td>' + esc(r.note || '') + '</td></tr>';
    });
    $('tab-alerts').innerHTML = h + '</tbody></table></div>';
  }

  function renderDurations() {
    const m = st.prices && st.prices.monitor; if (!m) return; const c = m.counts;
    let h = '<div class="mon-tiles">' + tile('ACTIVE', c.active) + tile('MISSING', c.missing) + tile('ARCHIVED', c.removed) + tile('REAPPEARED', c.reappeared) + tile('LAST COMPLETE CATALOG', c.last_complete_catalog ? new Date(c.last_complete_catalog * 1000).toLocaleString() : 'never') + '</div>';
    h += '<div class="mon-wrap"><table><thead><tr><th>Product</th><th>Duration</th><th>State</th><th>Missing checks</th><th>Missing since</th><th>Removed at</th><th>Service id</th><th></th></tr></thead><tbody>';
    m.durations.forEach(d => {
      const can = d.lifecycle_state === 'removed' || d.lifecycle_state === 'reappeared';
      h += '<tr data-plan="' + d.plan_id + '"><td>' + esc(d.product) + '</td><td>' + esc(d.duration) + '</td><td>' + pill(String(d.lifecycle_state).toUpperCase(), d.lifecycle_state === 'removed' ? 'bad' : 'warn') + '</td><td>' + esc(d.provider_missing_count) + '</td><td>' + esc(d.provider_missing_since || '—') + '</td><td>' + esc(d.provider_removed_at || '—') + '</td><td>' + esc(d.provider_variant_id) + '</td><td>' + (can ? '<button class="restore" type="button">Restore</button>' : '') + '</td></tr>';
    });
    $('tab-durations').innerHTML = h + '</tbody></table></div>';
  }

  function renderHistory() {
    const m = st.prices && st.prices.monitor; if (!m) return;
    let h = '<div class="mon-wrap"><table><thead><tr><th>When</th><th>Event</th><th>Category</th><th>Product</th><th>Duration</th><th>Provider old→new</th><th>Rate</th><th>INR old→new</th><th>Selling old→new</th><th>Custom</th><th>Source</th><th>Status</th><th>Note</th></tr></thead><tbody>';
    m.history.forEach(r => {
      h += '<tr><td>' + esc(r.detected_at) + '</td><td>' + esc(r.event_type) + '</td><td>' + (r.price_category === 'reseller' ? 'Reseller' : 'Normal User') + '</td><td>' + esc(r.product_name || '') + '</td><td>' + esc(r.duration || '') + '</td><td>' + usd(r.old_provider_price) + ' → ' + usd(r.new_provider_price) + '</td><td>' + esc(r.rate || '—') + '</td><td>' + inr(r.old_inr) + ' → ' + inr(r.new_inr) + '</td><td>' + inr(r.old_selling_price) + ' → ' + inr(r.new_selling_price) + '</td><td>' + inr(r.custom_price) + '</td><td>' + esc(r.source) + '</td><td>' + esc(r.status) + (r.resolution ? ' / ' + esc(r.resolution) : '') + '</td><td>' + esc(r.note || '') + '</td></tr>';
    });
    $('tab-history').innerHTML = h + '</tbody></table></div>';
  }

  function renderSettings() {
    const c = st.prices && st.prices.monitor && st.prices.monitor.config; if (!c) return;
    $('tab-settings').innerHTML = '<div class="card"><h3>Price &amp; duration rules</h3>' +
      '<div class="row"><label>RESELLER exchange rate (₹ per $1) — Provider Price Sync rate <input id="sRate" value="' + esc(c.rate_reseller) + '"></label>' +
      '<label>NORMAL USER exchange rate (₹ per $1) <input id="sRateN" value="' + esc(c.rate_normal) + '"></label>' +
      '<label>Price review period (10–30 min, default 15) <input id="sGrace" type="number" min="10" max="30" value="' + esc(c.grace_min) + '"></label>' +
      '<label>Provider price decrease <select id="sDec"><option value="apply"' + (c.decrease_policy === 'apply' ? ' selected' : '') + '>Apply (non-custom prices only)</option><option value="hold"' + (c.decrease_policy === 'hold' ? ' selected' : '') + '>Hold (alert only)</option></select></label></div>' +
      '<div class="row"><label>Remove duration after N consecutive complete catalog checks (default 3) <input id="sChk" type="number" min="1" value="' + esc(c.remove_after_checks) + '"></label>' +
      '<label>…and at least M minutes (default 0) <input id="sMin" type="number" min="0" value="' + esc(c.remove_after_minutes) + '"></label>' +
      '<label><input id="sAuto" type="checkbox"' + (c.auto_restore ? ' checked' : '') + '> Auto-restore a reappeared duration (after validation)</label></div>' +
      '<div class="row"><button id="sSave" type="button">Save settings</button></div></div>';
  }

  function renderSched() {
    const s = st.scheduler; if (!s) return;
    const el = $('monScheduler');
    el.className = 'mon-sched ' + (!s.enabled ? '' : (s.alive ? 'ok' : 'bad'));
    el.textContent = (!s.enabled ? 'Auto price sync is OFF. ' : (s.alive ? 'Scheduler running. ' : 'SCHEDULER NOT RUNNING. ')) + 'Target ' + s.target_interval + ' s, measured ' + (s.actual_interval == null ? 'n/a' : s.actual_interval + ' s') +
      '. Last run ' + (s.last_run ? new Date(s.last_run * 1000).toLocaleString() : 'never') + ', last success ' + (s.last_success ? new Date(s.last_success * 1000).toLocaleString() : 'never') + '. ' + (s.note || '');
  }


  const when = ts => ts ? new Date(ts * 1000).toLocaleString() : 'never';
  function renderWorkers() {
    const w = st.workers; if (!w) return;
    const cls = { ok: 'good', failing: 'bad', stopped: 'bad', never_ran: 'warn' };
    let h = '<div class="mon-wrap"><table><thead><tr><th>Worker</th><th>Status</th><th>Last run</th><th>Last success</th><th>Expected every</th><th>Runs</th><th>Failures</th><th>Skipped (locked)</th><th>Last duration</th><th>Last error</th></tr></thead><tbody>';
    w.workers.forEach(x => {
      h += '<tr><td>' + esc(x.label) + '</td><td>' + pill(x.status.toUpperCase().replace('_', ' '), cls[x.status]) + '</td><td>' + when(x.last_run) + '</td><td>' + when(x.last_success) + '</td><td>' + esc(x.expected_interval) + ' s</td><td>' + x.runs + '</td><td>' + x.failures + (x.fail_streak ? ' (streak ' + x.fail_streak + ')' : '') + '</td><td>' + x.skipped_locked + '</td><td>' + (x.last_duration_ms != null ? x.last_duration_ms + ' ms' : '—') + '</td><td>' + esc(x.last_error || '—') + (x.last_error_at ? ' @ ' + when(x.last_error_at) : '') + '</td></tr>';
    });
    h += '</tbody></table></div>';
    const p = w.price_sync;
    h += '<div class="mon-tiles">' + tile('TARGET INTERVAL', p.target_interval + ' s') + tile('MEASURED INTERVAL', p.actual_interval == null ? 'n/a' : p.actual_interval + ' s') + tile('VARIANTS CHECKED (last cycle)', p.variants_checked) + tile('PRICES CORRECTED', p.prices_corrected) + tile('ALREADY CORRECT', p.already_correct) + tile('CHANGES DETECTED', p.changes_detected) + tile('PENDING PRICE UPDATES', p.pending_price_updates) + tile('LAST CYCLE', p.duration_ms + ' ms') + '</div>';
    h += '<p>' + esc(w.cron_note) + ' Last price status: ' + esc(p.last_status || '—') + '</p>';
    $('tab-workers').innerHTML = h;
  }
  function renderReview() {
    const r = st.review; if (!r) return;
    let h = '<p class="pill warn">' + esc(r.limitation) + '</p><div class="mon-wrap"><table><thead><tr><th>Order</th><th>User</th><th>Product</th><th>Age</th><th>Review state</th><th>Provider state</th><th>HTTP</th><th>Upstream id</th><th>Checks</th><th>Last attempt</th><th>Provider error</th></tr></thead><tbody>';
    (r.rows || []).forEach(o => {
      h += '<tr><td>' + esc(o.order_ref) + '</td><td>' + esc(o.username) + '</td><td>' + esc(o.product) + ' ' + esc(o.duration) + '</td><td>' + esc(o.age_min) + ' min</td><td>' + pill(String(o.review_state || 'not reviewed yet'), (o.review_state === 'window') ? '' : 'warn') + (Number(o.parked) ? ' ' + pill('KEYS PARKED', 'good') : '') + '</td><td>' + esc(o.provider_state || '—') + '</td><td>' + esc(o.http_code || '—') + '</td><td>' + esc(o.upstream_order_id || '—') + '</td><td>' + esc(o.review_checks || 0) + '</td><td>' + esc(o.last_reconcile_at || '—') + '</td><td>' + esc(o.error_message || o.failure_reason || '') + '</td></tr>';
    });
    h += '</tbody></table></div><h3>Request history</h3><div class="mon-wrap"><table><thead><tr><th>When</th><th>Order</th><th>State</th><th>Check</th><th>Detail</th></tr></thead><tbody>';
    (r.history || []).forEach(x => { h += '<tr><td>' + esc(x.created_at) + '</td><td>' + esc(x.order_ref) + '</td><td>' + esc(x.review_state) + '</td><td>' + esc(x.check_no) + '</td><td>' + esc(x.detail || '') + '</td></tr>'; });
    $('tab-review').innerHTML = h + '</tbody></table></div><p>Resolve an order: Admin -> Pending Orders -> Complete order with key / Refund order.</p>';
  }
  function renderRefunds() {
    const r = st.refunds; if (!r) return; const c = r.counts;
    let h = '<div class="mon-tiles">' + tile('AUTO REFUND', r.auto_enabled ? 'ON' : 'OFF') + tile('PROCESSING WINDOW', r.window_seconds + ' s') + tile('REFUNDED (24h)', c.refunded_24h) + tile('ELIGIBLE, WAITING', c.eligible_waiting) + tile('REFUND FAILURES OPEN', c.refund_failed_open) + tile('DUPLICATE REFUNDS FOUND', r.duplicate_refunds) + '</div>';
    h += (r.duplicate_refunds === 0 ? pill('Duplicate protection: no order has more than one refund', 'good') : pill('WARNING: ' + r.duplicate_refunds + ' order reference(s) have more than one refund ledger row', 'bad'));
    h += '<h3>Refund history</h3><div class="mon-wrap"><table><thead><tr><th>Refunded at</th><th>Order</th><th>User</th><th>Amount</th><th>Method</th><th>Reason</th></tr></thead><tbody>';
    (r.recent || []).forEach(x => { h += '<tr><td>' + esc(x.refunded_at || '') + '</td><td>' + esc(x.order_ref) + '</td><td>' + esc(x.username) + '</td><td>' + inr(x.refund_amount) + '</td><td>' + esc(x.refund_method || '') + '</td><td>' + esc(x.failure_code || x.failure_reason || '') + '</td></tr>'; });
    h += '</tbody></table></div><h3>Failed refund attempts</h3><div class="mon-wrap"><table><thead><tr><th>Order</th><th>Reason</th><th>Occurrences</th><th>Last seen</th><th>Status</th></tr></thead><tbody>';
    (r.failed_attempts || []).forEach(x => { h += '<tr><td>' + esc(x.order_ref) + '</td><td>' + esc(x.reason) + '</td><td>' + esc(x.occurrences) + '</td><td>' + esc(x.last_seen) + '</td><td>' + esc(x.status) + '</td></tr>'; });
    $('tab-refunds').innerHTML = h + '</tbody></table></div>';
  }

  async function load() {
    try {
      if (st.tab === 'orders') { st.orders = (await api(EP + '?view=orders')).monitor; renderOrders(); }
      else if (st.tab === 'workers') { st.workers = await api(EP + '?view=workers'); renderWorkers(); }
      else if (st.tab === 'review') { st.review = (await api(EP + '?view=review')).review; renderReview(); }
      else if (st.tab === 'refunds') { st.refunds = (await api(EP + '?view=refunds')).refunds; renderRefunds(); }
      else {
        const r = await api(EP + '?view=prices'); st.prices = r; st.scheduler = r.scheduler;
        renderSched(); ({ alerts: renderAlerts, durations: renderDurations, history: renderHistory, settings: renderSettings })[st.tab]();
      }
    } catch (e) { toast((e && e.message) || 'Could not load.'); }
  }

  document.querySelectorAll('.mon-tabs button').forEach(b => b.addEventListener('click', () => {
    st.tab = b.dataset.tab;
    document.querySelectorAll('.mon-tabs button').forEach(x => x.classList.toggle('on', x === b));
    document.querySelectorAll('main section').forEach(s => { s.hidden = s.id !== 'tab-' + st.tab; });
    load();
  }));
  $('monRefresh').addEventListener('click', load);

  document.addEventListener('click', async ev => {
    const t = ev.target; if (!(t instanceof HTMLElement)) return;
    const row = t.closest('[data-plan]'); const plan = row ? row.dataset.plan : null; const cat = row && row.dataset.cat ? row.dataset.cat : 'normal';
    try {
      if (t.classList.contains('set')) { const v = row.querySelector('.cp').value; await post('set_custom', { plan_id: plan, enabled: 1, price: v, category: cat }); toast('Custom price saved.'); load(); }
      else if (t.classList.contains('confirm')) { if (!confirm('Apply the proposed price now?')) return; await post('confirm_increase', { plan_id: plan, category: cat }); toast('New price applied.'); load(); }
      else if (t.classList.contains('restore')) { if (!confirm('Restore this duration for customers?')) return; await post('restore_duration', { plan_id: plan }); toast('Duration restored.'); load(); }
      else if (t.id === 'sSave') {
        await post('save_settings', { usd_inr_rate: $('sRate').value, normal_usd_inr_rate: $('sRateN').value, grace_minutes: $('sGrace').value, decrease_policy: $('sDec').value, remove_after_checks: $('sChk').value, remove_after_minutes: $('sMin').value, auto_restore: $('sAuto').checked ? 1 : 0 });
        toast('Settings saved.'); load();
      }
    } catch (e) { toast((e && e.message) || 'Failed.'); }
  });
  setInterval(() => document.querySelectorAll('.left').forEach(el => { const s = Math.max(0, Number(el.dataset.s) - 1); el.dataset.s = s; el.textContent = mmss(s); }), 1000);
  setInterval(() => { if (!document.hidden) load(); }, 30000);
  load();
})();
