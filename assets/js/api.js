/* =====================================================================
   API page: token regenerate + variants list
   ===================================================================== */
(function () {
    'use strict';
    const { api, toast, copyText } = window.KP;
    const page = document.body.dataset.page;
    if (page !== 'api') return;

    const tokenEl = document.getElementById('apiTokenValue');
    const copyBtn = document.getElementById('apiCopyToken');
    const regen   = document.getElementById('apiRegenerate');
    const docsBtn = document.getElementById('apiDocsButton');
    const groups  = document.getElementById('apiVariantGroups');
    const count   = document.getElementById('apiVariantCount');

    function money(v) { return '₹' + Number(v || 0).toFixed(2); }

    copyBtn.addEventListener('click', async () => {
        try { await copyText(tokenEl.textContent); copyBtn.textContent = 'COPIED ✓'; setTimeout(() => copyBtn.textContent = 'COPY', 1400); } catch (e) {}
    });

    regen.addEventListener('click', async () => {
        if (!confirm('Regenerate API key? The old key will stop working immediately.')) return;
        try {
            const r = await api('api/api-token-regenerate.php', { method: 'POST' });
            tokenEl.textContent = r.token;
            toast('New API key generated.', { duration: 2600 });
        } catch (err) { toast(err.message, { variant: 'danger' }); }
    });

    docsBtn.addEventListener('click', () => { window.location.href = window.KP.baseUrl + '/api-docs.php'; });

    let variantsSig = '';
    async function loadVariants() {
        try {
            const r = await api('api/api-variants.php');
            const list = r.groups || [];
            // v55: re-render only when something (price / stock / list) really changed, and keep the cards the user has open
            const sig = JSON.stringify(list);
            if (sig === variantsSig) return;
            variantsSig = sig;
            const openKeys = new Set(Array.from(groups.querySelectorAll('.api-product.open')).map(el => (el.querySelector('.api-product-name') || el).textContent));
            let total = 0;
            groups.innerHTML = '';
            if (!list.length) {
                groups.innerHTML = '<div class="api-empty">No products or variants are currently published by the administrator.</div>';
                count.textContent = '0 VARIANTS';
                return;
            }
            list.forEach(g => {
                total += g.variants.length;
                const card = document.createElement('div');
                card.className = 'api-product';
                const head = document.createElement('button');
                head.type = 'button';
                head.className = 'api-product-head';
                head.innerHTML = `
                    <div class="api-product-main">
                        <div class="api-product-name"></div>
                        <div class="api-product-meta"></div>
                    </div>
                    <span class="api-chevron">›</span>`;
                head.querySelector('.api-product-name').textContent = g.product;
                head.querySelector('.api-product-meta').textContent = g.category + ' • ' + g.variants.length + ' variants';
                const listEl = document.createElement('div');
                listEl.className = 'api-duration-list';
                g.variants.forEach(v => {
                    const row = document.createElement('div');
                    row.className = 'api-duration';
                    row.innerHTML = `
                        <div class="api-variant-id"></div>
                        <div class="api-duration-info">
                            <strong></strong>
                            <span></span>
                        </div>
                        <button type="button" class="api-duration-copy">tap to copy variant_id</button>`;
                    row.querySelector('.api-variant-id').textContent = String(v.variant_id);
                    row.querySelector('strong').textContent = v.duration;
                    row.querySelector('.api-duration-info span').textContent = money(v.price) + (v.in_stock ? '' : ' • OUT OF STOCK');
                    const c = row.querySelector('.api-duration-copy');
                    c.addEventListener('click', async () => {
                        try { await copyText(String(v.variant_id)); c.textContent = 'copied ✓'; setTimeout(() => c.textContent = 'tap to copy variant_id', 1400); } catch (e) {}
                    });
                    listEl.appendChild(row);
                });
                head.addEventListener('click', () => card.classList.toggle('open'));
                card.appendChild(head); card.appendChild(listEl);
                groups.appendChild(card);
                if (openKeys.has((card.querySelector('.api-product-name') || card).textContent)) card.classList.add('open');
            });
            count.textContent = total + ' VARIANTS';
        } catch (e) {}
    }

    loadVariants();
    // v55: a corrected provider price shows up here without a reload (same cadence as the store page)
    setInterval(() => { if (document.visibilityState === 'visible') loadVariants(); }, 5000);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') loadVariants(); });
})();