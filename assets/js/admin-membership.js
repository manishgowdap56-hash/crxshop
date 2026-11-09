/* Admin -> Users -> Reseller Membership settings + history + bulk promote.
 * Uses the EXISTING api/admin/membership.php (GET = settings + history, POST action=save). PHP makes every decision; this only displays and sends.
 * Styles: assets/css/reseller-membership.css (linked from admin.php). */
(function () {
  'use strict';
  if (!window.KP) return;
  const { api, toast } = window.KP;
  const EP = 'api/admin/membership.php';
  const card = document.getElementById('adminMembershipCard');
  if (!card) return;
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const pm = h => h.payment_method === 'wallet' ? 'Website Wallet' : (h.source === 'admin' ? 'Admin' : 'Paytm (legacy)');
  let saving = false;

  const sw = (id, label, on, hint) =>
    '<div class="amc-field"><span class="amc-label">' + label + '</span>' +
    '<label class="amc-switch"><input type="checkbox" role="switch" id="' + id + '"' + (on ? ' checked' : '') + '>' +
    '<span class="amc-switch-text">' + hint + '</span><b class="amc-onoff ' + (on ? 'on' : 'off') + '" data-for="' + id + '">' + (on ? 'ON' : 'OFF') + '</b></label></div>';

  function render(r) {
    const c = r.config;
    card.innerHTML = '<div class="amc">' +
      '<div class="amc-head"><div class="amc-title">⭐ RESELLER MEMBERSHIP</div><div class="amc-state ' + (c.enabled ? 'on' : 'off') + '" id="rmState">' + (c.enabled ? 'ENABLED' : 'DISABLED') + '</div></div>' +
      '<div class="amc-stats"><div class="amc-stat"><span>RESELLERS</span><b>' + esc(r.counts.resellers) + '</b></div><div class="amc-stat"><span>NORMAL USERS</span><b>' + esc(r.counts.normal) + '</b></div></div>' +
      '<div class="amc-grid">' +
      sw('rmEnabled', 'MEMBERSHIP', c.enabled, 'Normal Users can buy') +
      sw('rmButton', 'PURCHASE BUTTON', c.button, 'Show the purchase button') +
      '<div class="amc-field"><label for="rmPrice">MEMBERSHIP PRICE (INR)</label><div class="amc-input-wrap"><span>₹ INR</span><input id="rmPrice" type="number" inputmode="decimal" min="1" step="0.01" placeholder="e.g. 400" value="' + (c.price !== '0.00' ? esc(c.price) : '') + '"></div><small>Any amount. Deducted from the user\'s website wallet.</small></div>' +
      '<div class="amc-field full"><label for="rmDesc">DESCRIPTION (shown to users)</label><textarea id="rmDesc" rows="3" maxlength="1000" placeholder="What does a Reseller get?">' + esc(c.description) + '</textarea></div>' +
      '</div><div class="amc-actions"><button type="button" id="rmSave" class="amc-save">SAVE MEMBERSHIP SETTINGS</button></div>' +
      '<details class="amc-hist"><summary>Membership history (' + r.history.length + ')</summary><div class="amc-table-wrap"><table><thead><tr><th>When</th><th>User</th><th>Method</th><th>Status</th><th>Price</th><th>Deducted</th><th>Wallet ref</th><th>Activated</th></tr></thead><tbody>' +
      (r.history.length ? r.history.map(h => '<tr><td>' + esc(h.created_at) + '</td><td class="amc-user">' + esc(h.username) + ' #' + esc(h.user_id) + '</td><td>' + esc(pm(h)) + '</td><td>' + esc(h.payment_status) + '</td><td>' + esc(h.price_due) + '</td><td>' + esc(h.amount_deducted || '') + '</td><td>' + esc(h.wallet_ref || h.payment_ref || '') + '</td><td>' + esc(h.activated_at || '') + '</td></tr>').join('')
        : '<tr><td colspan="8">No membership records yet.</td></tr>') +
      '</tbody></table></div></details></div>';
  }

  async function load() {
    try { render(await api(EP)); }
    catch (e) { card.innerHTML = '<div class="amc"><div class="amc-title">⭐ RESELLER MEMBERSHIP</div><p>Could not load membership settings.</p></div>'; toast(e.message || 'Could not load membership.', { variant: 'danger' }); }
  }

  // ON/OFF text follows the real switch immediately (the database changes only when SAVE succeeds; the card re-renders from the server's answer)
  card.addEventListener('change', ev => {
    const t = ev.target; if (!t || t.type !== 'checkbox') return;
    const b = card.querySelector('.amc-onoff[data-for="' + t.id + '"]');
    if (b) { b.textContent = t.checked ? 'ON' : 'OFF'; b.className = 'amc-onoff ' + (t.checked ? 'on' : 'off'); }
  });

  card.addEventListener('click', async ev => {
    if (ev.target.id !== 'rmSave') return;
    if (saving) return;                                   // no duplicate requests
    saving = true;
    const btn = ev.target; btn.disabled = true; const old = btn.textContent; btn.textContent = 'SAVING…';
    try {
      const r = await api(EP, { method: 'POST', body: { action: 'save', enabled: document.getElementById('rmEnabled').checked ? 1 : 0, button: document.getElementById('rmButton').checked ? 1 : 0,
        price: document.getElementById('rmPrice').value, description: document.getElementById('rmDesc').value } });
      toast('Membership settings saved.', { duration: 2200 });
      await load();                                       // re-render from the stored settings so the UI shows what the database really holds
    } catch (e) { toast(e.message || 'Could not save.', { variant: 'danger' }); btn.disabled = false; btn.textContent = old; }
    saving = false;
  });

  let bulkBusy = false;
  async function bulk(type) {
    if (bulkBusy) return;
    const ids = prompt('User IDs, comma separated (e.g. 12,15,40):');
    if (!ids) return;
    bulkBusy = true;
    try {
      const r = await api('api/admin/users-update.php', { method: 'POST', body: { action: 'bulk_set_type', type, user_ids: ids } });
      toast('Changed ' + r.changed + ' user(s)' + (r.failed && r.failed.length ? '; failed: ' + r.failed.join(',') : '') + '.', { duration: 3500 });
      load();
    } catch (e) { toast(e.message, { variant: 'danger' }); }
    bulkBusy = false;
  }
  const g = document.getElementById('adminBulkReseller'), n = document.getElementById('adminBulkNormal');
  if (g) g.addEventListener('click', () => bulk('reseller'));
  if (n) n.addEventListener('click', () => { if (confirm('Revoke Reseller access for these users?')) bulk('normal'); });
  load();
})();
