/* v62 "BECOME A RESELLER" (wallet). The browser only sends the click; PHP checks role, price, balance and does the deduction + activation in one transaction.
 * v64: once the server CONFIRMS the purchase, the whole card is removed at once and the dashboard reloads immediately (no delay), so the new account type
 * comes from the database and the card is not rendered again (dashboard.php renders it for Normal Users only). Nothing is shown as successful before the
 * backend answer. */
(function () {
  'use strict';
  if (!window.KP) return;
  const { api, toast } = window.KP;
  // the toast of the purchase made just before the reload (the card itself is gone by then)
  try { if (sessionStorage.getItem('kpMembershipOk') === '1') { sessionStorage.removeItem('kpMembershipOk'); toast('Membership purchased. You are now a Reseller!', { duration: 3500 }); } } catch (e) { /* storage unavailable: no toast */ }
  const card = document.getElementById('resellerCard');
  const buy = document.getElementById('rsBuy');
  if (!card || !buy) return;
  const msg = document.getElementById('rsMsg'), need = document.getElementById('rsNeed'), needAmt = document.getElementById('rsNeedAmt'), funds = document.getElementById('rsFunds');
  const inr = v => '₹' + Number(v).toFixed(2);
  let busy = false, done = false;
  const finish = (flag) => {                                          // backend confirmed Reseller: remove the complete card NOW, then load the real state
    done = true;
    if (card.parentNode) card.parentNode.removeChild(card);
    if (flag) { try { sessionStorage.setItem('kpMembershipOk', '1'); } catch (e) { /* ignore */ } }
    location.reload();
  };
  buy.addEventListener('click', async () => {
    if (busy || done) return; busy = true; buy.disabled = true;       // a second click is ignored; the server also locks the user row
    try {
      const r = await api('api/membership.php', { method: 'POST', body: { action: 'buy' } });
      if (!r || !r.membership || !r.membership.is_reseller) throw new Error('The purchase could not be confirmed. Refresh the page to see your account.');
      finish(true);
      return;
    } catch (e) {
      const d = (e && (e.data || e.payload || e.extra)) || {};
      if (e && e.status === 409) { finish(false); return; }           // already a Reseller (another tab / request): not charged again; the card goes away
      if (e && (e.status === 402 || d.insufficient)) {
        need.hidden = false; buy.hidden = true; funds.hidden = false;
        if (needAmt && d.needed != null) needAmt.textContent = inr(d.needed);
      } else {
        msg.hidden = false; msg.className = 'rs-msg warn'; msg.textContent = (e && e.message) || 'Could not complete the purchase.';
        buy.disabled = false;
      }
    }
    busy = false;
  });
})();
