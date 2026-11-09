/* =====================================================================
   Store (card UI): search + category filter + product cards.
   PRESENTATION ONLY. Data comes from api/products.php (same endpoint,
   same purchase endpoints). BUY RESELLER KEY hands off to the existing
   KPCheckout modal (quantity, wallet check, local/API purchase, delivery).
   ===================================================================== */
(function () {
    'use strict';
    const { api, toast } = window.KP;
    const page = document.body.dataset.page;
    if (page !== 'store') return;

    const listEl = document.getElementById('spList');
    const filtersEl = document.getElementById('spFilters');
    const searchEl = document.getElementById('spSearch');
    const sheet = document.getElementById('spSheet');
    const sheetTitle = document.getElementById('spSheetTitle');
    const sheetPlans = document.getElementById('spPlans');

    const COLLAPSED_ROWS = 3;       // feature rows visible before the chevron (as in the reference)

    let catalog = [];
    let links = { update_file: '', feedback: '' };
    let catalogSig = '';
    let currentUserBalance = 0;
    let reqSeq = 0;
    let loading = false;

    // UI state lives here (not in the DOM) so background refreshes never reset it.
    let query = '';
    let activeFilter = 'all';
    const expanded = new Set();     // per product id: Product A expanded never affects Product B

    const money = v => '₹' + Number(v || 0).toFixed(2);

    function el(tag, cls, text) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }

    /* ----------------------------- data ----------------------------- */
    function sheetOpen() { return sheet.classList.contains('open'); }
    function checkoutOpen() { return document.getElementById('storeCheckout')?.classList.contains('open'); }

    async function loadCatalog(preserveSelection = false, background = false) {
        if (background && loading) return;
        const seq = ++reqSeq;
        loading = true;
        try {
            const r = await api('api/products.php');
            if (seq !== reqSeq) return;
            const next = r.categories || [];
            const nextLinks = r.links || { update_file: '', feedback: '' };
            currentUserBalance = Number(r.balance || 0);
            const sig = JSON.stringify([next, nextLinks]);

            if (background && sig === catalogSig) { updateUserBalanceChrome(currentUserBalance); return; }
            // v53: a product sheet / checkout is open. The old code skipped the refresh here, so a corrected price was only seen after the
            // user closed everything and pressed Checkout again. Now the new catalog is kept and the visible prices are patched in place.
            if (background && (sheetOpen() || checkoutOpen())) {
                catalog = next; links = nextLinks; catalogSig = sig; listDirty = true;
                updateUserBalanceChrome(currentUserBalance);
                patchOpenPrices();
                return;
            }
            if (background && document.activeElement === searchEl) { updateUserBalanceChrome(currentUserBalance); return; }

            catalog = next;
            links = nextLinks;
            catalogSig = sig;
            if (activeFilter !== 'all' && !catalog.some(c => String(c.id) === activeFilter)) activeFilter = 'all';
            render();
            updateUserBalanceChrome(currentUserBalance);
        } catch (err) {
            if (!background) {
                toast(err.message, { variant: 'danger' });
                if (!catalog.length) { listEl.innerHTML = ''; listEl.appendChild(el('div', 'sp-state', 'Could not load the store. Pull to refresh and try again.')); }
            }
        } finally {
            if (seq === reqSeq) loading = false;
        }
    }

    function updateUserBalanceChrome(balance) {
        const value = money(balance);
        const header = document.getElementById('headerBalance');
        const drawer = document.getElementById('drawerWallet');
        if (header) header.textContent = '💰 ' + value;
        if (drawer) drawer.textContent = '💰 ' + value;
        document.dispatchEvent(new CustomEvent('keysPanel:balance-updated', { detail: { balance: Number(balance || 0) } }));
    }

    /* ----------------------------- render ----------------------------- */
    function render() {
        renderFilters();
        renderList();
    }

    function renderFilters() {
        const prevScroll = filtersEl.scrollLeft;
        filtersEl.innerHTML = '';
        const mk = (id, icon, label) => {
            const b = el('button', 'sp-chip' + (activeFilter === id ? ' active' : ''));
            b.type = 'button';
            b.setAttribute('role', 'tab');
            b.setAttribute('aria-selected', activeFilter === id ? 'true' : 'false');
            b.dataset.filter = id;
            b.appendChild(el('span', 'sp-chip-ico', icon));
            b.appendChild(el('span', 'sp-chip-txt', label));
            b.addEventListener('click', () => {
                activeFilter = id;
                filtersEl.querySelectorAll('.sp-chip').forEach(c => {
                    const on = c.dataset.filter === id;
                    c.classList.toggle('active', on);
                    c.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                b.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
                renderList();
            });
            return b;
        };
        filtersEl.appendChild(mk('all', '🔀', 'ALL'));
        catalog.forEach(c => filtersEl.appendChild(mk(String(c.id), '📦', String(c.name).toUpperCase())));
        filtersEl.scrollLeft = prevScroll;
    }

    function visibleProducts() {
        const q = query.trim().toLowerCase();
        const out = [];
        catalog.forEach(c => {
            if (activeFilter !== 'all' && String(c.id) !== activeFilter) return;
            (c.products || []).forEach(p => {
                if (q && !String(p.name).toLowerCase().includes(q)) return;
                out.push({ cat: c, prod: p });
            });
        });
        return out;
    }

    function renderList() {
        VideoCtl.disposeAll();      // stop/release any playing video before its card is replaced
        listEl.innerHTML = '';
        const items = visibleProducts();
        if (!items.length) {
            const hasAny = catalog.some(c => (c.products || []).length);
            listEl.appendChild(el('div', 'sp-state',
                !hasAny ? 'No products available right now.' : 'No products match your search.'));
            return;
        }
        const frag = document.createDocumentFragment();
        items.forEach(({ cat, prod }) => frag.appendChild(buildCard(cat, prod)));
        listEl.appendChild(frag);
        // Measure feature areas once they are in the DOM.
        requestAnimationFrame(() => listEl.querySelectorAll('.sp-feat').forEach(setupFeat));
    }

    /* inline icons (static markup only) */
    const ICONS = {
        bolt: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.2 2 5 13.4h5.4L9.6 22 19 9.8h-5.6z" fill="currentColor"/></svg>',
        download: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11m0 0-4.2-4.2M12 14l4.2-4.2M5 20h14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        play: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4.5v15l13-7.5z" fill="currentColor"/></svg>',
        wrench: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14.7 6.3 3 3-1.4 1.4-3-3zM3 21l8.2-8.2M13 3a5 5 0 0 0-4.6 6.9L3 15.3 8.7 21l5.4-5.4A5 5 0 0 0 21 11l-3.2 3.2-3-3L18 8a5 5 0 0 0-5-5z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/></svg>',
        chevron: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 9 7 7 7-7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    };
    function ico(name, cls) {
        const s = el('span', 'sp-ico' + (cls ? ' ' + cls : ''));
        s.innerHTML = ICONS[name];      // constant strings above, never user data
        return s;
    }


    /* ----------------------------- product video (single active player) -----------------------------
       One Store-level controller guarantees that at most ONE product video is ever playing.
       - Nothing is requested until the user taps the gold Play button (the <video> is created and its src set at that moment).
       - Tapping the video pauses it in place (currentTime kept); Play resumes from there.
       - Starting another product's video fully stops this one (paused, reset to 0, media released).
       - Playback position is never written anywhere (no storage, cookies, URL, server). Leaving the page just pauses. */
    const VIDEO_ICONS = {
        play: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.2v13.6L19.2 12z" fill="currentColor"/></svg>',
        on: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9.5v5h3.6L12 18.5v-13L7.6 9.5z" fill="currentColor"/><path d="M15.2 9a4.2 4.2 0 0 1 0 6M17.6 6.6a7.6 7.6 0 0 1 0 10.8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        off: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9.5v5h3.6L12 18.5v-13L7.6 9.5z" fill="currentColor"/><path d="m15.5 9.5 5 5m0-5-5 5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>',
    };
    const VideoCtl = (function () {
        let active = null;                 // the entry that is playing or paused-in-place
        const all = new Set();

        function setUi(e) {
            e.media.classList.toggle('vid-live', e.state !== 'idle');        // video layer visible
            e.media.classList.toggle('vid-playing', e.state === 'playing');  // gold button hidden only while actively playing
            e.playBtn.setAttribute('aria-label', e.state === 'paused' ? 'Resume video' : 'Play video');
        }
        function release(e) {              // free the network/decoder; video element is rebuilt on the next Play
            if (e.video) {
                try { e.video.pause(); } catch (x) { /* ignore */ }
                e.video.removeAttribute('src');
                try { e.video.load(); } catch (x) { /* ignore */ }
                e.video.remove();
                e.video = null;
            }
        }
        function stop(e) {                 // fully stopped + reset to the beginning (used when another video takes over)
            e.token++;
            e.state = 'idle';
            release(e);
            setUi(e);
            if (active === e) active = null;
        }
        function ensureVideo(e) {
            if (e.video) return e.video;
            const v = document.createElement('video');
            v.className = 'sp-vid';
            v.playsInline = true; v.setAttribute('playsinline', ''); v.setAttribute('webkit-playsinline', '');
            v.controls = false; v.loop = false; v.autoplay = false;
            v.preload = 'auto';            // only ever created after the user asked to play
            // Play-only: no native controls, no download UI, no PiP, no cast/AirPlay, not draggable (casual-user protection only).
            v.disablePictureInPicture = true; v.setAttribute('disablePictureInPicture', '');
            v.disableRemotePlayback = true;   v.setAttribute('disableRemotePlayback', '');
            v.setAttribute('controlsList', 'nodownload noplaybackrate noremoteplayback nofullscreen');
            v.draggable = false; v.setAttribute('draggable', 'false');
            v.addEventListener('dragstart', ev => ev.preventDefault());
            v.muted = e.muted;
            if (e.poster) v.poster = e.poster;      // the product image stays visible until the first video frame
            v.addEventListener('click', ev => { ev.stopPropagation(); if (e.state === 'playing') v.pause(); });   // tap = pause in place
            v.addEventListener('playing', () => { if (e.video !== v) return; e.state = 'playing'; setUi(e); });
            v.addEventListener('pause', () => { if (e.video !== v || v.ended || e.state === 'idle') return; e.state = 'paused'; setUi(e); });   // keeps currentTime
            v.addEventListener('ended', () => { if (e.video !== v) return; e.state = 'idle'; try { v.currentTime = 0; } catch (x) { /* ignore */ } release(e); setUi(e); if (active === e) active = null; });
            v.addEventListener('error', () => {
                if (e.video !== v) return;
                stop(e);
                e.media.classList.add('vid-failed');   // hide the Play button: no broken player
                toast('This video is unavailable right now.', { variant: 'danger' });
            });
            v.src = e.url;
            e.media.insertBefore(v, e.playBtn);
            e.video = v;
            return v;
        }
        function play(e) {
            if (active && active !== e) stop(active);            // never two at once
            active = e;
            const my = ++e.token;
            const v = ensureVideo(e);
            e.state = 'playing'; setUi(e);                       // hide the gold button immediately; 'pause'/'error' events correct it if needed
            const p = v.play();
            if (p && typeof p.catch === 'function') {
                p.catch(() => {
                    if (e.token !== my || e.video !== v) return;
                    e.state = 'paused'; setUi(e);                // e.g. blocked: leave the Play button available
                });
            }
        }
        return {
            register(e) { all.add(e); e.state = 'idle'; e.token = 0; e.video = null; e.muted = false; setUi(e); },
            play,
            toggleMute(e) { e.muted = !e.muted; if (e.video) e.video.muted = e.muted; return e.muted; },
            pauseActive() { if (active && active.video && active.state === 'playing') active.video.pause(); },
            disposeAll() { all.forEach(e => { e.token++; e.state = 'idle'; release(e); }); all.clear(); active = null; },
        };
    })();
    document.addEventListener('visibilitychange', () => { if (document.hidden) VideoCtl.pauseActive(); });
    window.addEventListener('pagehide', () => VideoCtl.pauseActive());

    function attachVideo(media, prod) {
        media.classList.add('has-video');
        const entry = { media, url: prod.video_url, poster: prod.image_url || '', playBtn: null, video: null };
        const play = el('button', 'sp-play'); play.type = 'button';
        play.setAttribute('aria-label', 'Play video');
        play.innerHTML = VIDEO_ICONS.play;
        entry.playBtn = play;
        play.addEventListener('click', ev => { ev.stopPropagation(); VideoCtl.play(entry); });
        const snd = el('button', 'sp-sound'); snd.type = 'button';
        snd.setAttribute('aria-label', 'Sound on'); snd.setAttribute('aria-pressed', 'false');
        snd.innerHTML = VIDEO_ICONS.on;
        snd.addEventListener('click', ev => {
            ev.stopPropagation(); ev.preventDefault();
            const muted = VideoCtl.toggleMute(entry);
            snd.innerHTML = muted ? VIDEO_ICONS.off : VIDEO_ICONS.on;
            snd.setAttribute('aria-label', muted ? 'Sound off' : 'Sound on');
            snd.classList.toggle('muted', muted);
        });
        // Right-click / long-press menu is blocked on the video area only (the rest of the page is untouched).
        media.addEventListener('contextmenu', ev => { ev.preventDefault(); ev.stopPropagation(); });
        media.addEventListener('dragstart', ev => ev.preventDefault());
        media.append(play, snd);
        VideoCtl.register(entry);
    }

    function buildCard(cat, prod) {
        const card = el('article', 'sp-card' + (prod.maintenance ? ' is-maint' : ''));
        card.dataset.productId = String(prod.id);

        /* image frame (16:9, inset, rounded). Image is contained: never stretched, nothing cropped. */
        const media = el('div', 'sp-media');
        if (prod.image_url) {
            const bg = el('img', 'sp-media-bg'); bg.src = prod.image_url; bg.alt = ''; bg.setAttribute('aria-hidden', 'true'); bg.loading = 'lazy';
            const fg = el('img', 'sp-media-img'); fg.src = prod.image_url; fg.alt = prod.name; fg.loading = 'lazy'; fg.decoding = 'async';
            const fail = () => { media.classList.add('no-image'); bg.remove(); fg.remove(); };
            fg.addEventListener('error', fail);
            media.append(bg, fg);
        } else {
            media.classList.add('no-image');
        }
        const ph = el('div', 'sp-media-ph'); ph.appendChild(el('span', null, '🔑'));
        media.appendChild(ph);

        const badge = el('div', 'sp-badge');
        badge.appendChild(el('span', null, '📦'));
        badge.appendChild(el('span', 'sp-badge-txt', String(cat.name).toUpperCase()));
        media.appendChild(badge);

        if (prod.maintenance) {          // maintenance overlay on the image (reference: maintenance screenshot)
            const ov = el('div', 'sp-maint-overlay');
            const box = el('div', 'sp-maint-box');
            const t = el('div', 'sp-maint-title');
            t.append(ico('wrench'), el('span', null, 'UNDER MAINTENANCE'));
            box.append(t, el('div', 'sp-maint-sub', 'TEMPORARILY UNAVAILABLE'));
            ov.appendChild(box);
            media.appendChild(ov);
        }
        if (prod.video_url && !prod.maintenance) attachVideo(media, prod);
        card.appendChild(media);

        /* product name + divider */
        card.appendChild(el('h3', 'sp-name', prod.name));
        const div = el('div', 'sp-divider'); div.appendChild(el('i'));
        card.appendChild(div);

        /* feature rows (reference: bolt rows with accent edge, chevron to expand) */
        const feats = (prod.features || []).filter(f => (f.header && f.header.trim()) || (f.items || []).length);
        if (feats.length) {
            const wrap = el('div', 'sp-feat');
            wrap.dataset.productId = String(prod.id);
            const inner = el('div', 'sp-feat-body');
            feats.forEach(f => {
                const sec = el('div', 'sp-feat-sec');
                if (f.header) sec.appendChild(el('div', 'sp-feat-head', f.header));
                (f.items || []).forEach(t => {
                    const row = el('div', 'sp-row');
                    row.append(ico('bolt', 'sp-row-ico'), el('span', 'sp-row-txt', t));
                    sec.appendChild(row);
                });
                inner.appendChild(sec);
            });
            wrap.appendChild(inner);
            const more = el('button', 'sp-more');
            more.type = 'button';
            more.hidden = true;
            more.setAttribute('aria-expanded', 'false');
            more.addEventListener('click', () => toggleFeat(wrap, prod.id));
            wrap.appendChild(more);
            card.appendChild(wrap);
        }

        /* global update / video-feedback buttons (configured once in Admin > Settings) */
        const hasUpdate = !!links.update_file, hasFeedback = !!links.feedback;
        if (hasUpdate || hasFeedback) {
            const row = el('div', 'sp-links' + (hasUpdate && hasFeedback ? '' : ' single'));
            const mkLink = (href, kind, icon, l1, l2) => {
                const a = el('a', 'sp-link ' + kind);
                a.href = href; a.target = '_blank'; a.rel = 'noopener noreferrer';
                a.appendChild(ico(icon));
                const t = el('span', 'sp-link-txt');
                t.append(el('span', null, l1), el('span', null, l2));
                a.appendChild(t);
                return a;
            };
            if (hasUpdate) row.appendChild(mkLink(links.update_file, 'update', 'download', 'CHECK', 'UPDATE FILE'));
            if (hasFeedback) row.appendChild(mkLink(links.feedback, 'video', 'play', 'CHECK', 'VIDEO/FEEDBACK'));
            card.appendChild(row);
        }

        /* purchase entry only; quantity / wallet / API logic stays in the existing checkout */
        if (prod.maintenance) {
            const m = el('button', 'sp-maint-btn');
            m.type = 'button';
            m.disabled = true;
            m.setAttribute('aria-disabled', 'true');
            m.append(ico('wrench'), el('span', null, 'UNDER MAINTENANCE'));
            card.appendChild(m);
        } else {
            const buy = el('button', 'sp-buy');
            buy.type = 'button';
            buy.append(el('span', 'sp-buy-key', '🔑'), el('span', null, 'BUY RESELLER KEY'));
            buy.addEventListener('click', () => onBuy(prod.id));
            card.appendChild(buy);
        }
        return card;
    }

    /* ------------------- SHOW MORE / SHOW LESS ------------------- */
    // Collapsed height = everything down to the bottom of the 3rd feature row (as in the reference).
    function collapsedHeight(inner) {
        const rows = inner.querySelectorAll('.sp-row');
        const r = rows[COLLAPSED_ROWS - 1];
        return r ? r.offsetTop + r.offsetHeight + 2 : inner.scrollHeight;
    }

    function setupFeat(wrap) {
        const inner = wrap.querySelector('.sp-feat-body');
        const more = wrap.querySelector('.sp-more');
        if (!inner || !more) return;
        const id = Number(wrap.dataset.productId);
        const rowCount = inner.querySelectorAll('.sp-row').length;
        if (rowCount <= COLLAPSED_ROWS) {         // short content: show everything, no toggle
            inner.style.maxHeight = 'none';
            wrap.classList.remove('collapsed');
            more.hidden = true;
            return;
        }
        more.hidden = false;
        if (expanded.has(id)) {
            inner.style.transition = 'none';
            inner.style.maxHeight = 'none';
            wrap.classList.remove('collapsed');
            void inner.offsetHeight;
            inner.style.transition = '';
        } else {
            inner.style.maxHeight = collapsedHeight(inner) + 'px';
            wrap.classList.add('collapsed');
        }
        paintMore(wrap, expanded.has(id));
    }

    function paintMore(wrap, isOpen) {
        const more = wrap.querySelector('.sp-more');
        more.textContent = '';
        more.classList.toggle('open', isOpen);
        more.append(ico('chevron'), el('span', 'sp-more-txt', isOpen ? 'SHOW LESS' : 'SHOW MORE'));
        more.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    function toggleFeat(wrap, id) {
        const inner = wrap.querySelector('.sp-feat-body');
        const opening = !expanded.has(id);
        if (opening) {
            expanded.add(id);
            wrap.classList.remove('collapsed');
            inner.style.maxHeight = inner.scrollHeight + 'px';
            const done = e => {
                if (e.target !== inner || e.propertyName !== 'max-height') return;
                inner.removeEventListener('transitionend', done);
                if (expanded.has(id)) inner.style.maxHeight = 'none';
            };
            inner.addEventListener('transitionend', done);
        } else {
            expanded.delete(id);
            inner.style.maxHeight = inner.scrollHeight + 'px';
            void inner.offsetHeight;
            wrap.classList.add('collapsed');
            inner.style.maxHeight = collapsedHeight(inner) + 'px';
        }
        paintMore(wrap, opening);
    }

    let listDirty = false;      // v53: catalog changed while the sheet / checkout was open; the list is re-rendered when they close
    let sheetProdId = null;

    function findPlan(planId) {
        for (const c of catalog) for (const p of (c.products || [])) {
            const pl = (p.plans || []).find(x => x.id === planId);
            if (pl) return { prod: p, plan: pl };
        }
        return null;
    }

    function flushDirtyList() { if (listDirty && !sheetOpen() && !checkoutOpen()) { listDirty = false; render(); } }

    // Updates the prices the user is looking at (duration selector + open checkout) from the freshly fetched catalog.
    function patchOpenPrices() {
        if (sheetOpen()) {
            sheetPlans.querySelectorAll('.sp-plan[data-plan-id]').forEach(btn => {
                const f = findPlan(Number(btn.dataset.planId));
                const priceEl = btn.querySelector('.sp-plan-price');
                if (!f) { btn.disabled = true; btn.classList.add('sp-plan-gone'); if (priceEl) priceEl.textContent = 'UNAVAILABLE'; return; }
                const txt = money(f.plan.price);
                if (priceEl && priceEl.textContent !== txt) {
                    priceEl.textContent = txt;
                    btn.classList.add('sp-plan-updated'); setTimeout(() => btn.classList.remove('sp-plan-updated'), 1600);
                }
            });
        }
        if (checkoutOpen() && window.KPCheckout && typeof window.KPCheckout.sync === 'function') {
            const id = window.KPCheckout.currentPlanId();
            if (id != null) {
                const f = findPlan(id);
                window.KPCheckout.sync(f ? { price: Number(f.plan.price || 0), planLabel: planLabel(f.plan), maintenance: !!f.prod.maintenance, balance: currentUserBalance } : null);
            }
        }
    }
    document.addEventListener('kpcheckout:closed', flushDirtyList);

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => listEl.querySelectorAll('.sp-feat').forEach(setupFeat), 150);
    });

    /* ------------------- purchase entry (existing checkout) ------------------- */
    function findProduct(id) {
        for (const c of catalog) {
            const p = (c.products || []).find(x => x.id === id);
            if (p) return p;
        }
        return null;
    }

    function planLabel(pl) {
        const stockLabel = pl.source === 'api' ? '' : (pl.stock === 0 ? ' • OUT OF STOCK' : '');
        return money(pl.price) + ' — ' + pl.duration + stockLabel;
    }

    function startCheckout(prod, pl) {
        if (!window.KPCheckout) return;
        window.KPCheckout.open({
            planId: pl.id,
            productName: prod.name,
            planLabel: planLabel(pl),
            price: Number(pl.price || 0),
            duration: pl.duration,
            source: pl.source,
            stock: Number(pl.stock == null ? -1 : pl.stock),
            allowQuantity: pl.allow_quantity !== false,
            needsAndroidId: pl.needs_android_id === true,
            maintenance: !!prod.maintenance,
            balance: currentUserBalance,
        });
    }

    function onBuy(productId) {
        const prod = findProduct(productId);         // fresh state, in case it changed since render
        if (!prod) return;
        if (prod.maintenance) { renderList(); return; }
        const plans = prod.plans || [];
        if (!plans.length) { toast('No plans are available for this product yet.', { variant: 'danger' }); return; }
        if (plans.length === 1) { startCheckout(prod, plans[0]); return; }
        openSheet(prod);
    }

    function openSheet(prod) {
        sheetTitle.textContent = prod.name;
        sheetPlans.innerHTML = '';
        prod.plans.forEach(pl => {
            const b = el('button', 'sp-plan');
            b.type = 'button';
            b.dataset.planId = String(pl.id);
            const left = el('span', 'sp-plan-dur', pl.duration);
            const right = el('span', 'sp-plan-right');
            right.appendChild(el('b', 'sp-plan-price', money(pl.price)));
            if (pl.source !== 'api' && pl.stock === 0) right.appendChild(el('small', 'sp-plan-out', 'OUT OF STOCK'));
            b.append(left, right);
            b.addEventListener('click', () => {
                const f = findPlan(pl.id);                       // v53: the plan as it is NOW, not as it was when the sheet was opened
                closeSheet();
                if (!f) { toast('This option is no longer available.', { variant: 'danger' }); return; }
                startCheckout(f.prod, f.plan);
            });
            sheetPlans.appendChild(b);
        });
        sheetProdId = prod.id;
        sheet.classList.add('open');
        sheet.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    function closeSheet() {
        sheet.classList.remove('open');
        sheet.setAttribute('aria-hidden', 'true');
        if (!checkoutOpen()) document.body.style.overflow = '';
        flushDirtyList();
    }
    document.getElementById('spSheetClose').addEventListener('click', closeSheet);
    document.getElementById('spSheetBackdrop').addEventListener('click', closeSheet);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && sheetOpen()) closeSheet(); });

    /* ------------------- search ------------------- */
    searchEl.addEventListener('input', () => { query = searchEl.value; renderList(); });

    /* ------------------- boot + polling ------------------- */
    window.KPStore = { refresh: () => loadCatalog(true), reload: () => loadCatalog(false), getBalance: () => currentUserBalance };
    loadCatalog();
    // Keeps stock / maintenance / admin edits current without a manual reload (same cadence as before).
    setInterval(() => {
        if (document.visibilityState === 'visible') loadCatalog(true, true);      // v53: also while the sheet / checkout is open
    }, 5000);
    // coming back to the tab: refresh at once instead of waiting for the next tick
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') loadCatalog(true, true); });
})();
