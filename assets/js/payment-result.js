/* ZapUPI result page: while the payment is still PENDING, ask the SERVER (api/deposits-status.php) for the verdict.
   The browser never decides or credits anything; it only reloads when the server reports a final state. */
(function () {
    'use strict';
    const box = document.getElementById('zpResult');
    if (!box || box.dataset.state !== 'PENDING') return;
    const id = box.dataset.deposit;
    const line = document.getElementById('zpStatusLine');
    const btn = document.getElementById('zpCheck');
    let busy = false, tries = 0;

    async function check() {
        if (busy) return;
        busy = true;
        try {
            const r = await window.KP.api('api/deposits-status.php?deposit_id=' + encodeURIComponent(id));
            if (r.status === 'SUCCESS' || r.status === 'FAILURE' || r.status === 'EXPIRED') { window.location.reload(); return; }
            if (line) line.textContent = 'Still waiting for confirmation…';
        } catch (e) {
            if (line) line.textContent = 'Could not check right now. Retrying…';
        } finally { busy = false; }
    }
    if (btn) btn.addEventListener('click', check);
    const t = setInterval(() => { if (++tries > 60) { clearInterval(t); if (line) line.textContent = 'Still pending. You can close this page; your wallet is credited automatically once confirmed.'; return; } check(); }, 5000);
})();
