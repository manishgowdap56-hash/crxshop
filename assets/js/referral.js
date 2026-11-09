/* =====================================================================
   Referral: stats, link, network
   ===================================================================== */
(function () {
    'use strict';
    const { api, toast, copyText } = window.KP;
    const page = document.body.dataset.page;
    if (page !== 'referral') return;

    const codeEl   = document.getElementById('referralCodeValue');
    const linkEl   = document.getElementById('referralLinkValue');
    const copyBtn  = document.getElementById('referralCopyButton');
    const totalEl  = document.getElementById('referralTotalCount');
    const activeEl = document.getElementById('referralActiveCount');
    const earnedEl = document.getElementById('referralClaimable');
    const titleEl  = document.getElementById('referralNetworkTitle');
    const boxEl    = document.getElementById('referralNetworkBox');

    function money(v) { return '₹' + Number(v || 0).toFixed(2); }

    async function load() {
        try {
            const r = await api('api/referral.php');
            codeEl.textContent = r.code || '—';
            linkEl.textContent = r.link || '—';
            totalEl.textContent = String(r.total || 0);
            activeEl.textContent = String(r.active || 0);
            earnedEl.textContent = money(r.earned || 0);
            titleEl.textContent = 'MY NETWORK (' + (r.total || 0) + ')';
            boxEl.innerHTML = '';
            if (!r.network || !r.network.length) {
                boxEl.className = 'referral-network-empty';
                boxEl.textContent = 'Abhi tak koi bhi aapke link se register nahi hua.';
                return;
            }
            boxEl.className = 'referral-network-list';
            r.network.forEach(n => {
                const row = document.createElement('div');
                row.className = 'referral-network-row';
                row.innerHTML = `
                    <div class="referral-network-user">
                        <strong></strong>
                        <span>${n.active ? 'ACTIVE USER' : 'REGISTERED'}</span>
                    </div>
                    <div class="referral-network-earned">${money(n.earned)}</div>`;
                row.querySelector('strong').textContent = n.username;
                boxEl.appendChild(row);
            });
        } catch (e) {}
    }

    copyBtn.addEventListener('click', async () => {
        try { await copyText(linkEl.textContent); copyBtn.textContent = '✓ Link Copied'; setTimeout(() => copyBtn.textContent = '📋 Copy Link', 1400); } catch (e) {}
    });
    document.getElementById('referralLinkBox').addEventListener('click', async () => {
        try { await copyText(linkEl.textContent); toast('Referral link copied.', { duration: 1600 }); } catch (e) {}
    });

    load();
})();