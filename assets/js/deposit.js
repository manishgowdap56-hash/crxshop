/* =====================================================================
   Deposit: create Paytm order, show server-issued QR, poll server-side verification
   ===================================================================== */
(function () {
    'use strict';
    const { api, toast } = window.KP;
    const page = document.body.dataset.page;
    if (page !== 'deposit') return;

    const formView = document.getElementById('depositFormView');
    const payView  = document.getElementById('depositPaymentView');
    const amountEl = document.getElementById('depositAmount');
    const msgEl    = document.getElementById('depositFormMessage');
    const createBtn= document.getElementById('depositCreateOrder');
    const listEl   = document.getElementById('depositHistoryList');
    const emptyEl  = document.getElementById('depositHistoryEmpty');
    const balEl    = document.getElementById('balancePageAmount');
    const payAmt   = document.getElementById('depositPayableAmount');
    const payId    = document.getElementById('depositOrderId');
    const qrBox    = document.getElementById('depositQrFrame');
    const countdownEl = document.getElementById('depositCountdown');
    const statusEl = document.getElementById('depositPaymentStatus');
    const cancelBtn= document.getElementById('depositCancelPayment');
    const downloadQr = document.getElementById('depositDownloadQr');
    const qrView   = document.getElementById('depositQrView');
    const procView = document.getElementById('depositProcessingView');
    const succView = document.getElementById('depositSuccessView');
    const succAmt  = document.getElementById('depositSuccessAmount');
    const succBal  = document.getElementById('depositSuccessBalance');

    let pollTimer = null;
    let countdownTimer = null;
    let currentOrder = null;

    /* ---- v68: payment method (Paytm | ZapUPI). Only methods the admin enabled are rendered by the server. ---- */
    const gwData = document.getElementById('depositGatewayData');
    const gwBox  = document.getElementById('depositGateways');
    const gwInstr = document.getElementById('depositGatewayInstructions');
    const hintEl = document.querySelector('.deposit-hint');
    let gateway = gwData ? (gwData.dataset.default || 'paytm') : 'paytm';
    function gwInfo(id) { return gwData ? gwData.querySelector('[data-gateway="' + id + '"]') : null; }
    function applyGateway(id) {
        const info = gwInfo(id);
        if (!info) return;
        gateway = id;
        amountEl.min = info.dataset.min; amountEl.max = info.dataset.max;
        amountEl.placeholder = 'Minimum ₹' + info.dataset.min;
        if (hintEl) hintEl.textContent = 'Minimum ₹' + info.dataset.min + ' — maximum ₹' + Number(info.dataset.max).toLocaleString('en-IN') + '.';
        if (gwInstr) { const t = info.dataset.instructions || ''; gwInstr.textContent = t; gwInstr.hidden = !t; }   // textContent: never HTML
        if (gwBox) gwBox.querySelectorAll('.deposit-gw').forEach(b => { const on = b.dataset.gateway === id; b.classList.toggle('active', on); b.setAttribute('aria-checked', on ? 'true' : 'false'); });
    }
    if (gwBox) gwBox.addEventListener('click', ev => { const b = ev.target.closest('.deposit-gw'); if (b) applyGateway(b.dataset.gateway); });
    applyGateway(gateway);
    // Retry link from the ZapUPI failed page: deposit.php?gateway=zapupi&amount=100.00 (a NEW order is created on submit).
    (function () {
        const q = new URLSearchParams(window.location.search);
        const g = q.get('gateway'); if (g && gwInfo(g)) applyGateway(g);
        const a = q.get('amount'); if (a && /^\d{1,9}(\.\d{1,2})?$/.test(a)) amountEl.value = a;
    })();

    function money(v) { return '₹' + Number(v || 0).toFixed(2); }

    /* Every place the wallet balance is displayed. Values ALWAYS come from the server. */
    function setBalance(balance) {
        const n = Number(balance);
        if (!Number.isFinite(n)) return;
        const txt = money(n);
        [['balancePageAmount', txt], ['walletBalance', txt], ['checkoutBalance', txt],
         ['profileHeroBalance', txt], ['headerBalance', '💰 ' + txt], ['drawerWallet', '💰 ' + txt]]
            .forEach(([id, v]) => { const el = document.getElementById(id); if (el) el.textContent = v; });
    }

    /* Re-read the authoritative wallet from the DB (same idea as index.php refreshWalletAfterDeposit). */
    async function refreshWallet() {
        for (let i = 0; i < 3; i++) {
            try {
                const me = await api('api/me.php');
                if (me && me.user && Number.isFinite(Number(me.user.balance))) {
                    setBalance(me.user.balance);
                    return Number(me.user.balance);
                }
            } catch (e) {}
            await new Promise(r => setTimeout(r, 350));
        }
        return null;
    }

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    // DB status -> UI state. Only a DB status of "approved" is ever shown as Success.
    function uiStatus(d) {
        const raw = String(d.status || 'pending').toLowerCase();
        if (raw === 'approved') return ['success', 'SUCCESS'];
        if (raw === 'expired')  return ['expired', 'EXPIRED'];
        if (raw === 'rejected') return ['failed', 'FAILED'];
        return ['pending', 'PENDING'];
    }

    let pendingIds = [];

    function renderHistory(list, balance) {
        setBalance(balance);
        listEl.innerHTML = '';
        pendingIds = list.filter(d => String(d.status).toLowerCase() === 'pending').map(d => d.id);
        if (!list.length) { emptyEl.hidden = false; scheduleBackground(); return; }
        emptyEl.hidden = true;
        list.slice(0, 12).forEach(d => {
            const [st, label] = uiStatus(d);
            const card = document.createElement('div');
            card.className = 'deposit-history-item ' + st;
            card.innerHTML = `
                <div class="deposit-history-row">
                    <span class="deposit-history-amount">${money(d.amount)}</span>
                    <span class="deposit-history-status ${st}">${label}</span>
                </div>
                <div class="deposit-history-meta">${esc(d.order_ref)} • ${esc(d.created_at)}${d.method === 'zapupi' ? ' • ZapUPI' : (d.method === 'paytm_qr' ? ' • Paytm' : '')}</div>`;
            listEl.appendChild(card);
        });
        scheduleBackground();
    }

    async function loadHistory() {
        try {
            const r = await api('api/deposits-list.php');
            renderHistory(r.deposits || [], r.balance);
        } catch (e) {}
    }

    /* Payments still PENDING in the DB (e.g. user pressed Cancel or reloaded after paying) keep being
       verified server-side, so they complete live without a refresh. Nothing is credited client-side. */
    let bgTimer = null;
    function scheduleBackground() {
        if (bgTimer || !pendingIds.length) return;
        bgTimer = setInterval(async () => {
            if (currentOrder || !pendingIds.length) {
                if (!pendingIds.length) { clearInterval(bgTimer); bgTimer = null; }
                return;
            }
            let changed = false, credited = false;
            for (const id of pendingIds.slice()) {
                try {
                    const r = await api('api/deposits-status.php?deposit_id=' + encodeURIComponent(id));
                    if (r.status !== 'PENDING') { changed = true; if (r.status === 'SUCCESS') credited = true; }
                } catch (e) {}
            }
            if (changed) {
                await loadHistory();
                if (credited) {
                    await refreshWallet();
                    toast('Payment verified. Wallet credited automatically.', { duration: 3000 });
                }
            }
        }, 5000);
    }

    let redirecting = false;
    async function createDeposit() {
        if (redirecting) return;
        const amount = parseFloat(amountEl.value || '0');
        // Display-only check: limits come from the server-rendered min/max attributes.
        // The server re-validates the amount and is the only authority.
        const min = parseFloat(amountEl.min || '0'), max = parseFloat(amountEl.max || '0');
        if (!Number.isFinite(amount) || amount < min) { msgEl.textContent = 'Minimum ₹' + min + '.'; return; }
        if (max > 0 && amount > max) { msgEl.textContent = 'Maximum ₹' + max.toLocaleString('en-IN') + '.'; return; }
        msgEl.textContent = 'Creating order…';
        createBtn.disabled = true;
        try {
            if (gateway === 'zapupi') {
                // The server creates the local order + ZapUPI order and returns a payment_url it has already validated.
                const r = await api('api/deposits-create.php', { method: 'POST', body: { amount, gateway: 'zapupi' } });
                const url = r && r.deposit ? String(r.deposit.payment_url || '') : '';
                if (!/^https:\/\//i.test(url)) throw new Error('Payment page unavailable. Please try again.');
                msgEl.textContent = 'Redirecting to the secure payment page…';
                redirecting = true;
                window.location.assign(url);            // SAME TAB, never a popup
                return;
            }
            const r = await api('api/deposits-create.php', { method: 'POST', body: { amount } });
            msgEl.textContent = '';
            showPaymentView(r.deposit);
            loadHistory();
        } catch (err) {
            msgEl.textContent = err.message || 'Failed to create order.';
        } finally {
            if (!redirecting) createBtn.disabled = false;
        }
    }

    function showSub(which) {   // 'qr' | 'processing' | 'success'
        qrView.hidden   = which !== 'qr';
        procView.hidden = which !== 'processing';
        succView.hidden = which !== 'success';
    }

    function showPaymentView(d) {
        currentOrder = d;
        settledId = 0;
        formView.hidden = true;
        payView.hidden = false;
        showSub('qr');
        payAmt.textContent = money(d.amount);
        payId.textContent = d.order_ref;

        // Real UPI QR issued by the server for this order (never generated in the browser).
        qrBox.innerHTML = '';
        const img = document.createElement('img');
        img.alt = 'UPI payment QR';
        img.style.cssText = 'width:100%;height:100%;display:block';
        img.referrerPolicy = 'no-referrer';
        img.src = d.qr_image;
        qrBox.appendChild(img);

        statusEl.className = 'payment-status waiting';
        statusEl.innerHTML = '<span class="status-dot"></span><span>Waiting for payment…</span>';

        // Countdown uses the server's remaining seconds (no client/server clock mismatch).
        if (countdownTimer) clearInterval(countdownTimer);
        const deadline = Date.now() + Math.max(1, Number(d.expires_in || 180)) * 1000;
        function tick() {
            const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            const mm = String(Math.floor(remaining / 60));
            const ss = String(remaining % 60).padStart(2, '0');
            countdownEl.textContent = mm + ':' + ss;
            if (remaining <= 0) { clearInterval(countdownTimer); clearInterval(pollTimer); finalizeAtDeadline(); }
        }
        tick();
        countdownTimer = setInterval(tick, 500);

        // Verification is done by the server asking Paytm. Poll every 5s, and do not
        // query immediately after creating the order (same as the source).
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(pollStatus, 5000);
    }

    let polling = false;
    let settledId = 0;      // guards the success sequence so it runs exactly once per order

    /* Reference sequence (index.php checkPaytmDepositStatus), started ONLY after the server
       answered SUCCESS, i.e. Paytm confirmed and the DB credit is committed:
       history -> PROCESSING view -> wallet re-read from DB -> 1.5s -> SUCCESS capsule -> 4.2s -> close. */
    async function runSuccessSequence(order, r) {
        if (settledId === order.id) return;
        settledId = order.id;
        clearInterval(pollTimer); clearInterval(countdownTimer);
        await loadHistory();
        succAmt.textContent = money(order.amount);
        { const so = document.getElementById('depositSuccessOrder'); if (so) so.textContent = order.order_ref || '—'; }   // v67: display only
        showSub('processing');
        const bal = await refreshWallet();
        if (bal === null && typeof r.balance === 'number') setBalance(r.balance);   // server-provided value
        succBal.textContent = money(bal !== null ? bal : r.balance);
        setTimeout(() => {
            if (!currentOrder || currentOrder.id !== order.id) return;
            showSub('success');
        }, 1500);
        setTimeout(async () => {
            await refreshWallet();
            await loadHistory();
            if (currentOrder && currentOrder.id === order.id) backToForm();
            toast('Payment successful. Wallet credited automatically.', { duration: 3000 });
        }, 4200);
    }

    function showFailed(text) {
        statusEl.className = 'payment-status failed';
        statusEl.innerHTML = '<span class="status-dot"></span><span></span>';
        statusEl.lastChild.textContent = text;
    }

    /* Returns the server's verdict; the UI only reacts to that verdict. */
    async function checkOnce() {
        const order = currentOrder;
        if (!order) return null;
        const r = await api('api/deposits-status.php?deposit_id=' + encodeURIComponent(order.id));
        if (!currentOrder || currentOrder.id !== order.id) return null;
        if (r.status === 'SUCCESS') {
            await runSuccessSequence(order, r);
        } else if (r.status === 'EXPIRED') {
            clearInterval(pollTimer); clearInterval(countdownTimer);
            showFailed('Payment failed — time expired. Create a new deposit');
            setTimeout(backToForm, 2500);
            loadHistory();
        } else if (r.status === 'FAILURE') {
            clearInterval(pollTimer); clearInterval(countdownTimer);
            showFailed('Payment failed');
            setTimeout(backToForm, 2500);
            loadHistory();
        }
        // PENDING: stay on the waiting screen. Success is never assumed.
        return r.status;
    }

    async function pollStatus() {
        if (!currentOrder || polling) return;
        polling = true;
        try { await checkOnce(); } catch (e) { /* temporary error: keep polling, nothing is credited */ }
        finally { polling = false; }
    }

    /* Timer reached 0:00. Do NOT declare failure locally: ask the server, and give it a short
       grace period to answer (a payment made in the last seconds must still be verified). */
    async function finalizeAtDeadline() {
        const order = currentOrder;
        if (!order) return;
        statusEl.className = 'payment-status processing';
        statusEl.innerHTML = '<span class="status-dot"></span><span>Verifying final payment status…</span>';
        for (let i = 0; i < 6 && currentOrder && currentOrder.id === order.id && settledId !== order.id; i++) {
            try {
                const st = await checkOnce();
                if (st && st !== 'PENDING') return;
            } catch (e) {}
            await new Promise(r => setTimeout(r, 2000));
        }
        if (currentOrder && currentOrder.id === order.id && settledId !== order.id) {
            showFailed('Payment failed — time expired. Create a new deposit');
            setTimeout(backToForm, 2500);
            loadHistory();
        }
    }

    function backToForm() {
        if (pollTimer) clearInterval(pollTimer);
        if (countdownTimer) clearInterval(countdownTimer);
        currentOrder = null;
        polling = false;
        formView.hidden = false;
        payView.hidden = true;
        showSub('qr');
        amountEl.value = '';
        loadHistory();
    }

    createBtn.addEventListener('click', createDeposit);
    cancelBtn.addEventListener('click', backToForm);
    downloadQr.addEventListener('click', async () => {
        const img = qrBox.querySelector('img');
        if (!img || !img.src) return;
        const name = (currentOrder ? currentOrder.order_ref : 'payment') + '-qr.png';
        try {
            const blob = await (await fetch(img.src)).blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = name;
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 400);
        } catch (e) {
            window.open(img.src, '_blank', 'noopener');   // cross-origin fallback
        }
    });

    loadHistory();
})();