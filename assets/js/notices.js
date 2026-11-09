/* User notices: (1) Low Wallet Balance - shown only when the SERVER says this account is API-enabled and below the
   threshold, (2) price-change announcements. The browser never decides eligibility: it only renders what
   api/notices.php returns and reports acknowledgements back to the server. */
(function () {
    'use strict';
    if (!window.KP || typeof window.KP.api !== 'function') return;
    const { api } = window.KP;
    const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = n => '₹' + Number(n || 0).toFixed(2);

    function injectStyles() {
        if (document.getElementById('kpNoticeStyles')) return;
        const st = document.createElement('style');
        st.id = 'kpNoticeStyles';
        st.textContent = `
.kpn-ov{position:fixed;inset:0;z-index:99990;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(3,7,16,.78);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);animation:kpnFade .18s ease}
.kpn-card{width:100%;max-width:380px;max-height:calc(100vh - 36px);overflow:auto;border:1px solid rgba(201,169,97,.38);border-radius:20px;background:linear-gradient(180deg,#14203a,#0b1324);box-shadow:0 24px 70px rgba(0,0,0,.55);padding:26px 22px 20px;text-align:center;color:#eef1f8;font-family:inherit;animation:kpnPop .22s ease}
.kpn-ico{width:68px;height:68px;margin:0 auto 12px;border-radius:50%;display:grid;place-items:center;background:radial-gradient(circle at 30% 25%,rgba(216,183,101,.28),rgba(216,183,101,.06));border:1px solid rgba(216,183,101,.45)}
.kpn-ico svg{width:34px;height:34px}
.kpn-title{margin:0 0 6px;font-size:20px;font-weight:800;letter-spacing:.2px;color:#f1d98f}
.kpn-bal{margin:8px 0 12px;font-size:30px;font-weight:900;color:#ff7a84;letter-spacing:.3px}
.kpn-msg{margin:0 0 18px;font-size:13.5px;line-height:1.55;color:#aab4c8}
.kpn-actions{display:flex;gap:10px}
.kpn-btn{flex:1;min-height:46px;border-radius:12px;font-weight:800;font-size:13px;letter-spacing:.4px;cursor:pointer;border:1px solid rgba(201,169,97,.45);background:#101a30;color:#d8b765;font-family:inherit}
.kpn-btn.primary{background:linear-gradient(135deg,#e7c872,#c9a961);color:#14110a;border-color:transparent}
.kpn-btn:active{transform:scale(.98)}
.kpn-btn:disabled{opacity:.6;cursor:wait}
.kpn-list{display:grid;gap:10px;margin:12px 0 16px;text-align:left}
.kpn-item{padding:12px;border:1px solid #2a3956;border-radius:12px;background:#0c1630}
.kpn-item b{display:block;font-size:14px;color:#eef1f8}
.kpn-item small{display:block;margin-top:2px;color:#8e9ab3;font-size:11.5px}
.kpn-prices{display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap}
.kpn-old{color:#8e9ab3;text-decoration:line-through;font-weight:700}
.kpn-new{font-weight:900;font-size:17px;color:#f1d98f}
.kpn-tag{padding:3px 8px;border-radius:999px;font-size:10px;font-weight:900;letter-spacing:.6px}
.kpn-tag.up{background:rgba(255,92,104,.14);color:#ff7a84}
.kpn-tag.down{background:rgba(52,211,153,.14);color:#34d399}
@keyframes kpnFade{from{opacity:0}to{opacity:1}}
@keyframes kpnPop{from{opacity:0;transform:translateY(10px) scale(.97)}to{opacity:1;transform:none}}
@media(max-width:380px){.kpn-card{padding:22px 16px 16px}.kpn-actions{flex-direction:column}}`;
        document.head.appendChild(st);
    }

    function modal(html) {
        injectStyles();
        const ov = document.createElement('div');
        ov.className = 'kpn-ov';
        ov.setAttribute('role', 'dialog');
        ov.setAttribute('aria-modal', 'true');
        ov.innerHTML = '<div class="kpn-card">' + html + '</div>';
        document.body.appendChild(ov);
        return ov;
    }

    // Resolves when the popup is closed (always after the acknowledgement was sent).
    function showPrice(changes) {
        return new Promise(resolve => {
            const items = changes.map(c => {
                const up = c.direction === 'increased';
                return `<div class="kpn-item"><b>${esc(c.product)}</b><small>${esc(c.duration)} • ${esc(c.at)}</small>
                    <div class="kpn-prices"><span class="kpn-old">${money(c.old_price)}</span><span aria-hidden="true">→</span><span class="kpn-new">${money(c.new_price)}</span>
                    <span class="kpn-tag ${up ? 'up' : 'down'}">${up ? '▲ PRICE INCREASED' : '▼ PRICE DECREASED'}</span></div></div>`;
            }).join('');
            const ov = modal(`<div class="kpn-ico"><svg viewBox="0 0 24 24" fill="none" stroke="#d8b765" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.4"/></svg></div>
                <h3 class="kpn-title">Price Update</h3><p class="kpn-msg" style="margin-bottom:0">The following prices have changed.</p>
                <div class="kpn-list">${items}</div>
                <div class="kpn-actions"><button class="kpn-btn primary" type="button" id="kpnPriceOk">OK</button></div>`);
            const ok = ov.querySelector('#kpnPriceOk');
            ok.addEventListener('click', async () => {
                ok.disabled = true;
                try { await Promise.all(changes.map(c => api('api/notices.php', { method: 'POST', body: { action: 'ack_price', change_id: c.id } }))); } catch (e) { /* shown again next time */ }
                ov.remove();
                resolve();
            });
        });
    }

    function showLow(balance) {
        return new Promise(resolve => {
            const ov = modal(`<div class="kpn-ico"><svg viewBox="0 0 24 24" fill="none" stroke="#ff7a84" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7H5a2 2 0 0 1 0-4h13v4Z"/><path d="M3 5v13a3 3 0 0 0 3 3h14V7"/><circle cx="16.5" cy="14" r="1.3"/></svg></div>
                <h3 class="kpn-title">Low Wallet Balance</h3>
                <div class="kpn-bal">${money(balance)}</div>
                <p class="kpn-msg">Your wallet balance is running low. Please add balance to continue purchasing without interruption.</p>
                <div class="kpn-actions"><button class="kpn-btn" type="button" id="kpnLowOk">OK</button><button class="kpn-btn primary" type="button" id="kpnLowAdd">Add Balance</button></div>`);
            const ack = async () => { try { await api('api/notices.php', { method: 'POST', body: { action: 'ack_low' } }); } catch (e) { /* shown again later */ } };
            ov.querySelector('#kpnLowOk').addEventListener('click', async (e) => { e.target.disabled = true; await ack(); ov.remove(); resolve(); });
            ov.querySelector('#kpnLowAdd').addEventListener('click', async (e) => {
                e.target.disabled = true; await ack();
                window.location.href = (window.KP.baseUrl || '') + '/deposit.php';      // existing deposit page / Paytm flow, unchanged
            });
        });
    }

    async function run() {
        let n;
        try { n = await api('api/notices.php'); } catch (e) { return; }
        if (n && n.price_changes && n.price_changes.length) await showPrice(n.price_changes);
        const onDeposit = /\/deposit\.php$/i.test(window.location.pathname);
        if (n && n.low_balance && n.low_balance.show === true && !onDeposit) await showLow(n.low_balance.balance);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
