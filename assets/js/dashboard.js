/* =====================================================================
   Dashboard: refresh wallet + stats
   ===================================================================== */
(function () {
    'use strict';
    const { api } = window.KP;
    const page = document.body.dataset.page;
    if (page !== 'home') return;

    async function refresh() {
        try {
            const r = await api('api/me.php');
            const u = r.user, s = r.stats;
            const hb = document.getElementById('headerBalance');
            const wb = document.getElementById('walletBalance');
            const ot = document.getElementById('ordersToday');
            const os = document.getElementById('ordersMonth');
            const ts = document.getElementById('totalSpent');
            if (hb) hb.textContent = '💰 ₹' + u.balance.toFixed(2);
            if (wb) wb.textContent = '₹' + u.balance.toFixed(2);
            if (ot) ot.textContent = String(s.orders_today);
            if (os) os.textContent = String(s.total_orders);
            if (ts) ts.textContent = '₹' + s.total_spent.toFixed(2);
        } catch (e) {}
    }

    refresh();
    setInterval(refresh, 30000);
})();