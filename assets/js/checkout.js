/* =====================================================================
   Checkout modal: quantity, stock check, BUY
   ===================================================================== */
(function () {
    'use strict';
    const { api, toast, copyText } = window.KP;
    const page = document.body.dataset.page;
    if (page !== 'store') return;

    const modal     = document.getElementById('storeCheckout');
    const backdrop  = document.getElementById('storeCheckoutBackdrop');
    const closeBtn  = document.getElementById('checkoutClose');
    const buyBtn    = document.getElementById('checkoutBuy');
    const qtyInput  = document.getElementById('checkoutQuantity');
    const qtyMinus  = document.getElementById('qtyMinus');
    const qtyPlus   = document.getElementById('qtyPlus');
    const balEl     = document.getElementById('checkoutBalance');
    const totalEl   = document.getElementById('checkoutTotal');
    const stockEl   = document.getElementById('checkoutStock');
    const prodName  = document.getElementById('checkoutProductName');
    const planName  = document.getElementById('checkoutPlanName');
    const topupMsg  = document.getElementById('checkoutTopupMsg');
    const balanceBtn= document.getElementById('checkoutBalanceBtn');
    const maintenanceBox = document.getElementById('checkoutMaintenance');
    const checkoutGrid = document.querySelector('.store-checkout-panel .checkout-grid');
    const checkoutTotalBox = document.querySelector('.store-checkout-panel .checkout-total');
    const checkoutNote = document.querySelector('.store-checkout-panel .checkout-note');
    const maintenanceLogo = document.getElementById('maintenanceLogo');
    const deliveryModal = document.getElementById('deliveryModal');
    const deliveredList = document.getElementById('deliveredKeyList');
    const deliveryProduct = document.getElementById('deliveryProduct');
    const deliveryPlan   = document.getElementById('deliveryPlan');
    const copyAllBtn = document.getElementById('copyAllKeys');
    const deliveryClose = document.getElementById('deliveryClose');
    const deliveryBackdrop = document.getElementById('deliveryBackdrop');

    let current = null;

    function money(v) { return '₹' + Number(v || 0).toFixed(2); }

    function refresh() {
        if (!current) return;
        let q = parseInt(qtyInput.value, 10);
        if (!Number.isFinite(q) || q < 1) q = 1;
        const soldOut = current.source === 'local' && Number(current.stock) <= 0;
        const maxQ = current.allowQuantity === false ? 1 : (current.source === 'api' ? 20 : Math.max(1, Number(current.stock) || 0));
        if (!soldOut && q > maxQ) q = maxQ;
        qtyInput.value = String(q);
        qtyInput.max = String(maxQ);
        qtyInput.disabled = soldOut;
        qtyMinus.disabled = soldOut || current.allowQuantity === false || q <= 1;
        qtyPlus.disabled = soldOut || current.allowQuantity === false || q >= maxQ;

        const total = current.price * q;
        totalEl.textContent = money(total);
        const enough = current.balance >= total;
        balEl.textContent = money(current.balance);
        topupMsg.classList.toggle('show', !enough);
        balanceBtn.classList.toggle('topup-danger', !enough);

        if (maintenanceLogo) {
            const globalLogo = document.querySelector('[data-global-logo]');
            const dashLogo = document.getElementById('dashLogo');
            const logoSrc = (globalLogo && globalLogo.src) || (dashLogo && dashLogo.src);
            if (logoSrc) maintenanceLogo.src = logoSrc;
        }

        // Reference checkout behavior: product maintenance replaces the
        // quantity/wallet/total/purchase controls with ONE centered panel.
        // The stock line remains visible when the selected local duration is
        // actually empty, matching the supplied reference screenshot.
        if (current.maintenance) {
            maintenanceBox.classList.add('show');
            maintenanceBox.setAttribute('aria-hidden','false');
            if (checkoutGrid) checkoutGrid.style.display = 'none';
            if (checkoutTotalBox) checkoutTotalBox.style.display = 'none';
            if (checkoutNote) checkoutNote.style.display = 'none';
            buyBtn.style.display = 'none';
            buyBtn.disabled = true;
            if (soldOut) {
                stockEl.textContent = 'OUT OF STOCK';
                stockEl.className = 'checkout-stock bad';
                stockEl.style.display = 'block';
            } else {
                stockEl.textContent = '';
                stockEl.className = 'checkout-stock';
                stockEl.style.display = 'none';
            }
            return;
        } else {
            maintenanceBox.classList.remove('show');
            maintenanceBox.setAttribute('aria-hidden','true');
            if (checkoutGrid) checkoutGrid.style.display = '';
            if (checkoutTotalBox) checkoutTotalBox.style.display = '';
            if (checkoutNote) checkoutNote.style.display = '';
            stockEl.style.display = 'block';
            buyBtn.style.display = '';
        }

        if (soldOut) {
            stockEl.textContent = 'OUT OF STOCK';
            stockEl.className = 'checkout-stock bad';
            buyBtn.disabled = true;
        } else if (!enough) {
            stockEl.textContent = 'INSUFFICIENT WALLET BALANCE';
            stockEl.className = 'checkout-stock bad';
            buyBtn.disabled = true;
        } else {
            stockEl.textContent = '';
            stockEl.className = 'checkout-stock';
            buyBtn.disabled = false;
        }
    }

    function open(info) {
        current = info;
        prodName.textContent = info.productName;
        planName.textContent = info.planLabel;
        qtyInput.value = '1';
        modal.classList.add('open');
        modal.setAttribute('aria-hidden','false');
        document.body.style.overflow = 'hidden';
        refresh();
    }
    function close() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden','true');
        document.body.style.overflow = '';
        document.dispatchEvent(new CustomEvent('kpcheckout:closed'));
    }

    // v53: the store pushes the latest catalog values while checkout is open. Display only: the server re-reads the price when the order is created.
    function sync(next) {
        if (!current || !modal.classList.contains('open')) return;
        if (next === null) { toast('This option is no longer available.', { variant: 'danger' }); close(); return; }
        const priceChanged = Math.abs(Number(next.price) - Number(current.price)) > 0.004;
        current.price = Number(next.price);
        current.maintenance = !!next.maintenance;
        if (next.balance != null) current.balance = Number(next.balance);
        planName.textContent = next.planLabel;
        refresh();
        if (priceChanged) toast('Price updated to ' + money(current.price) + '.', { duration: 2600 });
    }

    qtyInput.addEventListener('input', refresh);
    qtyMinus.addEventListener('click', () => { qtyInput.value = String(Math.max(1, parseInt(qtyInput.value||'1',10) - 1)); refresh(); });
    qtyPlus.addEventListener('click',  () => { if (current && current.allowQuantity === false) { qtyInput.value = '1'; refresh(); return; } qtyInput.value = String(parseInt(qtyInput.value||'1',10) + 1); refresh(); });
    closeBtn.addEventListener('click', close);
    backdrop.addEventListener('click', close);
    balanceBtn.addEventListener('click', () => { window.location.href = window.KP.baseUrl + '/deposit.php'; });

    buyBtn.addEventListener('click', async () => {
        if (!current) return;
        // Device-bound (v1) products only: the key is locked to this Android ID. The server re-checks it before any charge.
        let androidId = '';
        if (current.needsAndroidId) {
            const entered = window.prompt('This key is locked to your device. Enter your Android ID:');
            if (entered === null) return;
            androidId = String(entered).trim();
            if (!androidId) { toast('Android ID is required for this product.', { variant: 'danger' }); return; }
        }
        buyBtn.disabled = true;
        buyBtn.textContent = 'PROCESSING…';
        const endpoint = current.source === 'api' ? 'api/purchase-api.php' : 'api/purchase.php';
        try {
            const r = await api(endpoint, {
                method: 'POST',
                body: Object.assign({ plan_id: current.planId, quantity: parseInt(qtyInput.value||'1',10), expected_price: Number(current.price).toFixed(2) }, androidId ? { android_id: androidId } : {}),
            });

            // The purchase API has already committed the wallet/stock on the
            // server. Reflect that committed state immediately in the current
            // page instead of waiting for a manual browser refresh.
            if (r.balance != null) {
                current.balance = Number(r.balance);
                const balance = '₹' + Number(r.balance).toFixed(2);
                const header = document.getElementById('headerBalance');
                const drawer = document.getElementById('drawerWallet');
                if (header) header.textContent = '💰 ' + balance;
                if (drawer) drawer.textContent = '💰 ' + balance;
                document.dispatchEvent(new CustomEvent('keysPanel:balance-updated', { detail: { balance: Number(r.balance) } }));
            }
            if (window.KPStore && typeof window.KPStore.refresh === 'function') {
                await window.KPStore.refresh();
            }
            close();
            showDelivery(r);
        } catch (err) {
            const code = err && err.payload ? err.payload.code : '';
            if (code === 'PRICE_CHANGED') {
                // Nothing was charged. Show the real price and let the customer confirm again.
                if (err.payload.new_price != null) { current.price = Number(err.payload.new_price); refresh(); }
                toast(err.message, { variant: 'danger', duration: 4200 });
                if (window.KPStore && typeof window.KPStore.refresh === 'function') { try { await window.KPStore.refresh(); } catch (e) {} }
                return;
            }
            if (code === 'ORDER_FAILED' || code === 'ORDER_FAILED_REFUNDED' || code === 'ORDER_FAILED_REFUND_PENDING' || code === 'ORDER_PENDING_VERIFICATION') {
                // Standard customer message. Provider / SQL / PHP details are never sent to the browser.
                // ORDER_FAILED_REFUNDED is sent by the server ONLY after the refund transaction has committed; the wallet balance it
                // returns is the real, post-refund balance.
                if (err.payload.balance != null && !isNaN(Number(err.payload.balance))) {
                    current.balance = Number(err.payload.balance);
                    const balance = '₹' + Number(err.payload.balance).toFixed(2);
                    const header = document.getElementById('headerBalance');
                    const drawer = document.getElementById('drawerWallet');
                    if (header) header.textContent = '💰 ' + balance;
                    if (drawer) drawer.textContent = '💰 ' + balance;
                    document.dispatchEvent(new CustomEvent('keysPanel:balance-updated', { detail: { balance: Number(err.payload.balance) } }));
                }
                close();
                showOrderFailed(err.message, code, err.payload.failure_code === 'OUT_OF_STOCK' ? 'OUT OF STOCK' : '');
                if (window.KPStore && typeof window.KPStore.refresh === 'function') { try { await window.KPStore.refresh(); } catch (e) {} }
            } else {
                toast(err.message || 'Purchase failed.', { variant: 'danger' });
            }
        } finally {
            buyBtn.textContent = '⚡ BUY NOW & DELIVER KEY';
            refresh();
        }
    });

    // "Order Failed" popup: same look as the order-success popup (delivery modal classes), OK returns to the Store.
    function showOrderFailed(message, code, reason) {
        const old = document.getElementById('orderFailedModal');
        if (old) old.remove();
        const wrap = document.createElement('div');
        wrap.className = 'delivery-modal open';
        wrap.id = 'orderFailedModal';
        wrap.setAttribute('role', 'alertdialog');
        wrap.innerHTML = '<div class="delivery-backdrop"></div><section class="delivery-panel" role="dialog" aria-modal="true">'
            + '<div class="delivery-kicker" style="color:#ff7a84">ORDER STATUS</div>'
            + '<h2 class="delivery-title" id="orderFailedTitle">ORDER FAILED</h2>'
            + '<div id="orderFailedReason" style="display:none;margin:-4px 0 10px;text-align:center;font-weight:800;letter-spacing:2px;color:#ffb347;font-size:15px"></div>'
            + '<p class="delivery-sub" id="orderFailedText" style="font-size:13px;color:#aab4c8"></p>'
            + '<button type="button" class="delivery-close" id="orderFailedOk">OK</button></section>';
        document.body.appendChild(wrap);
        wrap.querySelector('#orderFailedText').textContent = message || 'Order Failed. Your order could not be completed. Please try placing a new order.';
        if (reason && code === 'ORDER_FAILED_REFUNDED') {   // confirmed out-of-stock + committed refund: FAILED / OUT OF STOCK
            wrap.querySelector('#orderFailedTitle').textContent = 'FAILED';
            const rEl = wrap.querySelector('#orderFailedReason'); rEl.textContent = reason; rEl.style.display = 'block';
        }
        if (code === 'ORDER_PENDING_VERIFICATION') wrap.querySelector('#orderFailedTitle').textContent = 'ORDER BEING VERIFIED';   // not a confirmed failure: no "refunded" wording
        const done = () => { wrap.remove(); window.scrollTo({ top: 0, behavior: 'smooth' }); };   // back to the Store product list
        wrap.querySelector('#orderFailedOk').addEventListener('click', done);
        wrap.querySelector('.delivery-backdrop').addEventListener('click', done);
    }

    function showDelivery(r) {
        deliveryProduct.textContent = r.product || '—';
        deliveryPlan.textContent = (r.duration || '—') + ' • ' + money(r.total);
        deliveredList.innerHTML = '';
        (r.delivered || []).forEach((key, i) => {
            const row = document.createElement('div');
            row.className = 'key-row';
            const idx = document.createElement('div'); idx.className = 'key-index'; idx.textContent = String(i + 1);
            const val = document.createElement('div'); val.className = 'key-value'; val.textContent = key;
            const cpy = document.createElement('button'); cpy.type = 'button'; cpy.className = 'copy-key'; cpy.textContent = 'COPY';
            cpy.addEventListener('click', async () => {
                try { await copyText(key); cpy.textContent = 'COPIED ✓'; cpy.classList.add('copied'); setTimeout(() => { cpy.textContent = 'COPY'; cpy.classList.remove('copied'); }, 1400); } catch (e) {}
            });
            row.appendChild(idx); row.appendChild(val); row.appendChild(cpy);
            deliveredList.appendChild(row);
        });
        deliveryModal.classList.add('open');
        deliveryModal.setAttribute('aria-hidden','false');
    }

    copyAllBtn.addEventListener('click', async () => {
        const keys = Array.from(deliveredList.querySelectorAll('.key-value')).map(e => e.textContent);
        try { await copyText(keys.join('\n')); copyAllBtn.textContent = 'ALL KEYS COPIED ✓'; copyAllBtn.classList.add('copied'); setTimeout(() => { copyAllBtn.textContent = 'COPY ALL KEYS'; copyAllBtn.classList.remove('copied'); }, 1600); } catch (e) {}
    });
    deliveryClose.addEventListener('click', () => { deliveryModal.classList.remove('open'); deliveryModal.setAttribute('aria-hidden','true'); });
    deliveryBackdrop.addEventListener('click', () => { deliveryModal.classList.remove('open'); deliveryModal.setAttribute('aria-hidden','true'); });

    window.KPCheckout = { open, sync, currentPlanId: () => (current ? current.planId : null) };
})();