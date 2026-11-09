/* =====================================================================
   My Keys: date picker + history list
   ===================================================================== */
(function () {
    'use strict';
    const { api, copyText } = window.KP;
    const page = document.body.dataset.page;
    if (page !== 'mykeys') return;

    const dateEl = document.getElementById('historyDate');
    const listEl = document.getElementById('myKeysList');
    const emptyEl = document.getElementById('myKeysEmpty');
    const apiCount = document.getElementById('apiBuyCount');
    const siteCount = document.getElementById('siteBuyCount');
    const dateLabel = document.getElementById('historyDateLabel');
    const totalLabel = document.getElementById('historyTotalLabel');

    function todayLocal() {
        const d = new Date();
        const y = d.getFullYear();
        const m = String(d.getMonth()+1).padStart(2,'0');
        const day = String(d.getDate()).padStart(2,'0');
        return `${y}-${m}-${day}`;
    }

    function money(v) { return '₹' + Number(v || 0).toFixed(2); }

    // v58: FAILED order card. "OUT OF STOCK" sits directly underneath FAILED and only appears for a provider-confirmed out-of-stock.
    function failedCard(it) {
        const card = document.createElement('article');
        card.className = 'key-history-card failed';
        card.innerHTML = `
            <div class="key-history-title"><span class="movie">🎬</span><span></span></div>
            <div class="history-failed"><strong class="history-failed-status">FAILED</strong><span class="history-failed-reason"></span></div>
            <div class="key-history-meta">
                <span class="history-pill"></span>
                <span class="history-pill"></span>
                <span class="history-pill refund"></span>
            </div>
            <div class="key-history-purchased"><span class="cart">🛒</span>Purchased: </div>`;
        card.querySelector('.key-history-title span:last-child').textContent = it.product || '—';
        const rs = card.querySelector('.history-failed-reason');
        if (it.reason) rs.textContent = it.reason; else rs.remove();
        const pills = card.querySelectorAll('.history-pill');
        pills[0].textContent = it.duration || '—';
        pills[1].textContent = money(it.price);
        if (it.refunded != null) pills[2].textContent = 'REFUNDED ' + money(it.refunded); else pills[2].remove();
        card.querySelector('.key-history-purchased').append(document.createTextNode(it.purchased || ''));
        return card;
    }

    async function load() {
        const date = dateEl.value || todayLocal();
        listEl.innerHTML = '';
        try {
            const r = await api('api/mykeys.php?date=' + encodeURIComponent(date));
            apiCount.textContent = String(r.api_count || 0);
            siteCount.textContent = String(r.site_count || 0);
            totalLabel.textContent = (r.total || 0) + ' KEYS';
            dateLabel.textContent = 'PURCHASE HISTORY · ' + date;
            if (!r.items || !r.items.length) {
                emptyEl.hidden = false;
                return;
            }
            emptyEl.hidden = true;
            r.items.forEach(it => {
                if (it.kind === 'failed') { listEl.appendChild(failedCard(it)); return; }
                const card = document.createElement('article');
                card.className = 'key-history-card';
                const srcCls = it.source === 'api' ? 'source' : 'source site';
                const srcText = it.source === 'api' ? '🔌 Via API' : '🌐 Site Purchase';
                card.innerHTML = `
                    <div class="key-history-title"><span class="movie">🎬</span><span></span></div>
                    <div class="key-history-key"></div>
                    <div class="key-history-meta">
                        <span class="history-pill"></span>
                        <span class="history-pill"></span>
                        <span class="history-pill ${srcCls}">${srcText}</span>
                        <button type="button" class="history-copy" aria-label="Copy key">📋</button>
                    </div>
                    <div class="key-history-purchased"><span class="cart">🛒</span>Purchased: ${it.purchased}</div>`;
                card.querySelector('.key-history-title span:last-child').textContent = it.product || '—';
                card.querySelector('.key-history-key').textContent = it.key || '';
                const pills = card.querySelectorAll('.history-pill');
                pills[0].textContent = it.duration || '—';
                pills[1].textContent = money(it.price);
                const cpy = card.querySelector('.history-copy');
                cpy.addEventListener('click', async () => {
                    try { await copyText(it.key); cpy.textContent = '✓'; cpy.classList.add('copied'); setTimeout(() => { cpy.textContent = '📋'; cpy.classList.remove('copied'); }, 1400); } catch (e) {}
                });
                listEl.appendChild(card);
            });
        } catch (e) {
            emptyEl.hidden = false;
        }
    }

    if (!dateEl.value) dateEl.value = todayLocal();
    dateEl.addEventListener('change', load);
    load();
})();