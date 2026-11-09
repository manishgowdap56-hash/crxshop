/* =====================================================================
   Admin panel: navigation + page loaders
   ===================================================================== */
(function () {
    'use strict';
    const { api, toast, copyText } = window.KP;
    const page = document.body.dataset.page;
    if (page !== 'admin') return;

    /* ------------------ Drawer ------------------ */
    const menu = document.getElementById('adminMenu') || document.getElementById('dashMenu');
    const drawer = document.getElementById('adminDrawer');
    const overlay = document.getElementById('adminDrawerOverlay');
    const closeDrawer = () => {
        if (!drawer) return;
        drawer.classList.remove('open');
        drawer.setAttribute('aria-hidden','true');
        if (menu) menu.setAttribute('aria-expanded','false');
        if (overlay) { overlay.classList.remove('open'); overlay.setAttribute('aria-hidden','true'); }
        document.body.classList.remove('admin-drawer-open');
    };
    const openDrawer = () => {
        if (!drawer) return;
        drawer.classList.add('open');
        drawer.setAttribute('aria-hidden','false');
        if (menu) menu.setAttribute('aria-expanded','true');
        if (overlay) { overlay.classList.add('open'); overlay.setAttribute('aria-hidden','false'); }
        document.body.classList.add('admin-drawer-open');
    };
    if (menu) {
        const activateMenu = (e) => { e.preventDefault(); e.stopPropagation(); openDrawer(); };
        menu.addEventListener('click', activateMenu);
        menu.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') activateMenu(e);
        });
        menu.addEventListener('pointerup', (e) => {
            if (e.pointerType !== 'mouse') activateMenu(e);
        }, {passive:false});
    }
    document.getElementById('adminDrawerClose') && document.getElementById('adminDrawerClose').addEventListener('click', closeDrawer);
    overlay && overlay.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDrawer(); });

    /* ------------------ Page router ------------------ */
    const pages = {
        dashboard: document.getElementById('adminDashboardPage'),
        users: document.getElementById('adminUsersPage'),
        products: document.getElementById('adminProductsPage'),
        'website-orders': document.getElementById('adminWebsiteOrdersPage'),
        'all-orders': document.getElementById('adminAllOrdersPage'),
        'api-orders': document.getElementById('adminApiOrdersPage'),
        deposits: document.getElementById('adminDepositsPage'),
        'api-settings': document.getElementById('adminApiSettingsPage'),
        alerts: document.getElementById('adminAlertsPage'),
        'pending-orders': document.getElementById('adminPendingOrdersPage'),
        'referral-commission': document.getElementById('adminReferralPage'),
        'price-sync': document.getElementById('adminPriceSyncPage'),
        audit: document.getElementById('adminAuditPage'),
        settings: document.getElementById('adminJoinSettingsPage'),
    };

    let currentAdminPage = 'dashboard';

    function showPage(name) {
        const validName = pages[name] ? name : 'dashboard';
        currentAdminPage = validName;
        const root = document.getElementById('adminScreen');
        const dashboardContent = document.querySelector('#adminDashboardPage .admin-content');

        // #adminScreen is the application root/header container. Each actual
        // admin page is a sibling section so switching pages never leaves the
        // dashboard flowing underneath Users/Products/Orders/Settings.
        if (root) {
            root.classList.remove('admin-page-dashboard','admin-page-users','admin-page-products','admin-page-website-orders','admin-page-all-orders','admin-page-api-orders','admin-page-deposits','admin-page-api-settings','admin-page-alerts','admin-page-pending-orders','admin-page-referral-commission','admin-page-price-sync','admin-page-audit','admin-page-settings');
            root.classList.add('active', 'admin-page-' + validName);
            root.setAttribute('aria-hidden', 'false');
        }

        Object.entries(pages).forEach(([k, el]) => {
            if (!el) return;
            el.classList.toggle('active', k === validName);
            el.setAttribute('aria-hidden', k === validName ? 'false' : 'true');
        });
        if (dashboardContent) {
            dashboardContent.style.display = validName === 'dashboard' ? '' : 'none';
        }
        document.querySelectorAll('.admin-nav-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.adminPage === validName);
        });
        closeDrawer();
        window.scrollTo(0, 0);
        if (validName === 'dashboard') loadDashboard();
        if (validName === 'users') loadUsers();
        if (validName === 'products') loadProducts();
        if (validName === 'website-orders') loadWebsiteOrders();
        if (validName === 'all-orders') aoInit();
        if (validName === 'api-orders') loadApiOrders();
        if (validName === 'deposits') { loadDeposits(); loadPaytmSettings(); loadZapupiSettings(); }
        if (validName === 'api-settings') loadApiSettings();
        if (validName === 'alerts') loadAlerts(true);
        if (validName === 'pending-orders' && window.KPPendingOrders) window.KPPendingOrders.load(true);
        if (validName === 'referral-commission' && window.KPReferralAdmin) window.KPReferralAdmin.load();
        if (validName === 'audit') auShowTab(auState.tab);
        if (validName === 'settings') loadSettings();
    }

    document.querySelectorAll('.admin-nav-btn').forEach(btn => {
        btn.addEventListener('click', () => showPage(btn.dataset.adminPage));
    });

    /* ------------------ Money helper ------------------ */
    const money = v => '₹' + Number(v || 0).toFixed(2);

    /* ------------------ Dashboard ------------------ */
    const timeEl = document.getElementById('adminCurrentTime');
    if (timeEl) timeEl.textContent = new Date().toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });

    async function loadDashboard() {
        try {
            const r = await api('api/admin/dashboard.php');
            setText('adminWebsiteOrders', r.website_orders);
            setText('adminApiOrders', r.api_orders);
            setText('adminTotalUsers', r.total_users);
            setText('adminActiveUsers', r.active_users);
            setText('adminRevenue', money(r.revenue));
            setText('adminProductKeys', r.product_keys);
            setText('adminNormalUsers', r.normal_users);
            setText('adminApiUsers', r.api_users);
            setText('adminTotalKeys', r.total_keys);
            setText('adminWalletTotal', money(r.wallet_total));
            setText('adminProductCount', r.product_count);
            setText('adminApiShare', r.api_share + '%');
            setText('adminWebsiteShare', r.website_share + '%');
            document.getElementById('adminApiShareBar').style.width = r.api_share + '%';
            document.getElementById('adminWebsiteShareBar').style.width = r.website_share + '%';
            document.getElementById('adminStoreStatus').textContent = r.maintenance_mode ? 'MAINTENANCE' : 'READY';
            document.getElementById('adminApiStatus').textContent = r.maintenance_mode ? 'MAINTENANCE' : 'READY';
            if (window.KPAdminFinance) window.KPAdminFinance.render(r);   // v36: financial cards + banners
        } catch (e) { toast(e.message || 'Admin request failed.', {variant:'danger'}); }
    }

    function setText(id, v) { const el = document.getElementById(id); if (el) el.textContent = String(v); }

    /* ------------------ Users ------------------ */
    let selectedUsername = '';
    async function loadUsers() {
        const q = document.getElementById('adminUserSearch').value.trim();
        try {
            const typeSel = document.getElementById('adminUserType');
            const tp = typeSel ? typeSel.value : '';
            const qs = [q ? 'q=' + encodeURIComponent(q) : '', tp ? 'type=' + encodeURIComponent(tp) : ''].filter(Boolean).join('&');
            const r = await api('api/admin/users-list.php' + (qs ? '?' + qs : ''));
            const users = r.users || [];
            document.getElementById('adminUsersCount').textContent = users.length + ' USERS';
            const list = document.getElementById('adminUserList');
            list.innerHTML = '';
            if (!users.length) {
                list.innerHTML = '<div class="admin-user-empty">No matching user found.</div>';
                return;
            }
            users.forEach(u => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'admin-user-row' + (selectedUsername === u.username ? ' selected' : '');
                btn.innerHTML = `
                    <div class="admin-user-avatar"></div>
                    <div class="admin-user-main">
                        <strong></strong>
                        <div class="admin-user-meta">
                            <span class="admin-user-tag ${u.role === 'admin' ? 'gold' : ''}">${u.role.toUpperCase()}</span>
                            <span class="admin-user-tag ${u.account_type === 'reseller' ? 'gold' : ''}">${u.account_type === 'reseller' ? 'RESELLER' : 'NORMAL USER'}</span>
                            <span class="admin-user-tag ${u.banned ? 'red' : 'gold'}">${u.banned ? 'BANNED' : 'ACTIVE'}</span>
                            <span class="admin-user-tag">${u.stats.total_orders} ORDERS</span>
                        </div>
                    </div>
                    <div class="admin-user-spend">${money(u.balance)}</div>`;
                btn.querySelector('.admin-user-avatar').textContent = u.username.slice(0, 2).toUpperCase();
                btn.querySelector('strong').textContent = u.username;
                btn.addEventListener('click', () => { selectedUsername = u.username; loadUsers(); renderUserDetail(u); });
                list.appendChild(btn);
            });
        } catch (e) { toast(e.message || 'Admin request failed.', {variant:'danger'}); }
    }

    function renderUserDetail(u) {
        const box = document.getElementById('adminUserDetail');
        box.className = 'admin-user-detail';
        box.innerHTML = `
            <div class="admin-detail-head">
                <div class="admin-detail-avatar"></div>
                <div style="min-width:0">
                    <strong></strong>
                    <span>${u.referral_code || '—'}</span>
                </div>
                <div class="admin-detail-status ${u.banned ? 'banned' : ''}">${u.banned ? 'BANNED' : (u.role === 'admin' ? 'ADMIN' : 'ACTIVE')}</div>
            </div>
            <div class="admin-detail-grid">
                <div class="admin-detail-stat"><span>BALANCE</span><strong>${money(u.balance)}</strong></div>
                <div class="admin-detail-stat"><span>TOTAL SPEND</span><strong>${money(u.stats.total_spent)}</strong></div>
                <div class="admin-detail-stat"><span>TOTAL ORDERS</span><strong>${u.stats.total_orders}</strong></div>
                <div class="admin-detail-stat"><span>TOTAL KEYS</span><strong>${u.stats.total_keys}</strong></div>
                <div class="admin-detail-stat"><span>WEBSITE</span><strong>${u.stats.site_orders}</strong></div>
                <div class="admin-detail-stat"><span>API</span><strong>${u.stats.api_orders}</strong></div>
            </div>
            <div class="admin-detail-info">
                <strong>ACCOUNT DETAILS</strong><br>
                ID: ${u.id}<br>
                Created: ${u.created_at}<br>
                Last login: ${u.last_login_at || '—'}<br>
                Last active: ${u.last_active_at || '—'}<br>
                Account type: <b>${u.account_type === 'reseller' ? 'RESELLER' : 'NORMAL USER'}</b>${u.reseller_since ? ' (since ' + u.reseller_since + ', ' + (u.reseller_source === 'paid' ? 'paid membership' : 'granted by admin') + ')' : ''}
                ${(u.membership_history || []).length ? '<br><strong>RESELLER MEMBERSHIP HISTORY</strong>' + u.membership_history.map(m => '<br>' + [m.created_at, m.payment_method === 'wallet' ? 'WEBSITE WALLET' : (m.source === 'admin' ? 'ADMIN' : 'PAYTM (LEGACY)'), String(m.payment_status).toUpperCase(), m.source !== 'admin' ? '₹' + Number(m.amount_deducted != null ? m.amount_deducted : (m.price_paid != null ? m.price_paid : m.price_due)).toFixed(2) : '', m.wallet_ref || '', m.activated_at ? 'activated ' + m.activated_at : '', m.revoked_at ? 'revoked ' + m.revoked_at : ''].filter(Boolean).join(' • ')).join('') : ''}
            </div>
            <div class="admin-detail-actions">
                <button class="admin-user-action" data-action="add_balance">+ ADD BALANCE</button>
                <button class="admin-user-action" data-action="remove_balance">− REMOVE BALANCE</button>
                <button class="admin-user-action" data-action="reset_password">🔑 RESET PASSWORD</button>
                <button class="admin-user-action ${u.account_type === 'reseller' ? 'danger' : 'success'}" data-action="${u.account_type === 'reseller' ? 'revoke_reseller' : 'grant_reseller'}">${u.account_type === 'reseller' ? 'REVOKE RESELLER' : 'GRANT RESELLER'}</button>
                <button class="admin-user-action ${u.role === 'admin' ? 'danger' : 'success'}" data-action="toggle_role">${u.role === 'admin' ? 'REMOVE ADMIN' : 'MAKE ADMIN'}</button>
                <button class="admin-user-action ${u.banned ? 'success' : 'danger'}" data-action="toggle_ban">${u.banned ? 'UNBAN USER' : 'BAN USER'}</button>
                <button class="admin-user-action ${u.api_enabled ? 'danger' : 'success'}" data-action="toggle_api_enabled">${u.api_enabled ? 'API USER: ON (TURN OFF)' : 'MARK AS API USER'}</button>
            </div>
            <div class="admin-balance-editor" hidden id="adminBalanceEditor">
                <div class="admin-balance-editor-title" id="adminBalanceEditorTitle">ADD BALANCE</div>
                <div class="admin-balance-editor-row">
                    <span>₹</span>
                    <input id="adminBalanceAmount" type="number" min="0.01" step="0.01" placeholder="Enter amount">
                    <button type="button" id="adminBalanceSave" class="admin-balance-save">SAVE</button>
                    <button type="button" id="adminBalanceCancel" class="admin-balance-cancel">CANCEL</button>
                </div>
            </div>
            <div class="admin-reset-password-box" hidden id="adminResetBox">
                <div class="admin-reset-password-title">🔐 NEW PASSWORD</div>
                <div class="admin-reset-password-row">
                    <code id="adminGeneratedPassword"></code>
                    <button type="button" class="admin-reset-copy" id="adminCopyReset">📋 COPY</button>
                </div>
            </div>`;
        box.querySelector('.admin-detail-avatar').textContent = u.username.slice(0, 2).toUpperCase();
        box.querySelector('.admin-detail-head strong').textContent = u.username;

        // Actions
        box.querySelectorAll('[data-action]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const action = btn.dataset.action;
                if (action === 'add_balance' || action === 'remove_balance') {
                    const ed = box.querySelector('#adminBalanceEditor');
                    ed.hidden = false;
                    box.querySelector('#adminBalanceEditorTitle').textContent = action === 'add_balance' ? 'ADD BALANCE' : 'REMOVE BALANCE';
                    box.querySelector('#adminBalanceCancel').onclick = () => { ed.hidden = true; };
                    box.querySelector('#adminBalanceSave').onclick = async () => {
                        const amount = parseFloat(box.querySelector('#adminBalanceAmount').value || '0');
                        if (!amount || amount <= 0) { toast('Enter a valid amount.', { variant: 'danger' }); return; }
                        try {
                            await api('api/admin/users-update.php', { method: 'POST', body: {
                                user_id: u.id, action, amount,
                            }});
                            toast('Balance updated.', { duration: 2200 });
                            loadUsers(); renderUserDetail(Object.assign({}, u, { balance: u.balance + (action === 'add_balance' ? amount : -amount) }));
                        } catch (err) { toast(err.message, { variant: 'danger' }); }
                    };
                } else if (action === 'reset_password') {
                    if (!confirm('Reset password for ' + u.username + '?')) return;
                    try {
                        const r = await api('api/admin/users-reset-password.php', { method: 'POST', body: { user_id: u.id } });
                        const rb = box.querySelector('#adminResetBox');
                        rb.hidden = false;
                        box.querySelector('#adminGeneratedPassword').textContent = r.generated_password;
                        box.querySelector('#adminCopyReset').onclick = async () => {
                            try { await copyText(r.generated_password); } catch (e) { toast(e.message || 'Admin request failed.', {variant:'danger'}); }
                        };
                        toast('Password reset.', { duration: 2200 });
                    } catch (err) { toast(err.message, { variant: 'danger' }); }
                } else if (action === 'grant_reseller' || action === 'revoke_reseller') {
                    try {
                        if (action === 'revoke_reseller' && !confirm('Revoke Reseller access? The user returns to Normal User pricing immediately.')) return;
                        await api('api/admin/users-update.php', { method: 'POST', body: { user_id: u.id, action } });
                        selectedUsername = null; loadUsers();
                        toast(action === 'grant_reseller' ? 'Reseller access granted.' : 'Reseller access revoked.', { duration: 2200 });
                    } catch (err) { toast(err.message, { variant: 'danger' }); }
                } else if (action === 'toggle_ban' || action === 'toggle_role' || action === 'toggle_api_enabled') {
                    try {
                        await api('api/admin/users-update.php', { method: 'POST', body: { user_id: u.id, action } });
                        loadUsers();
                        toast('Updated.', { duration: 1800 });
                    } catch (err) { toast(err.message, { variant: 'danger' }); }
                }
            });
        });
    }

    document.getElementById('adminUserSearch') && document.getElementById('adminUserSearch').addEventListener("input", loadUsers);
    document.getElementById("adminUserType") && document.getElementById("adminUserType").addEventListener("change", loadUsers);

    /* ------------------ Products ------------------ */
    let categoryCache = [];
    let productCache = [];
    async function loadProducts() {
        try {
            const [cats, prods] = await Promise.all([
                api('api/admin/categories.php'),
                api('api/admin/products.php'),
            ]);
            categoryCache = cats.categories || [];
            renderCategoryOptions();
            renderCategoryCards();
            productCache = prods.products || [];
            applyProductSearch();
            renderRestockOptions(prods.products || []);
        } catch (e) { toast(e.message || 'Admin request failed.', {variant:'danger'}); }
    }


    /* ---------- Admin product search (client-side over the already-loaded list, case-insensitive, by product name) ---------- */
    function applyProductSearch() {
        const input = document.getElementById('adminProductSearch');
        const q = (input ? input.value : '').trim().toLowerCase();
        const clr = document.getElementById('adminProductSearchClear');
        if (clr) clr.hidden = q === '';
        renderProductCards(q ? productCache.filter(p => String(p.name).toLowerCase().includes(q)) : productCache);
    }
    document.getElementById('adminProductSearch')?.addEventListener('input', applyProductSearch);
    document.getElementById('adminProductSearchClear')?.addEventListener('click', () => {
        const i = document.getElementById('adminProductSearch'); if (i) { i.value = ''; i.focus(); }
        applyProductSearch();
    });

    function renderCategoryOptions() {
        const sel = document.getElementById('adminProductCategory');
        sel.innerHTML = '<option value="">SELECT CATEGORY</option>';
        categoryCache.forEach(c => {
            sel.appendChild(new Option(c.name, String(c.id)));
        });
    }

    function renderCategoryCards() {
        const box = document.getElementById('adminCategoryCards');
        document.getElementById('adminCategoryCountLabel').textContent = categoryCache.length + ' CATEGORIES';
        box.innerHTML = '';
        if (!categoryCache.length) {
            box.innerHTML = '<div class="admin-category-empty">No categories yet.</div>';
            return;
        }
        categoryCache.forEach(c => {
            const card = document.createElement('article');
            card.className = 'admin-category-card';
            card.innerHTML = `
                <div class="admin-category-info">
                    <strong></strong>
                    <small>${c.product_count} PRODUCTS</small>
                </div>
                <label class="admin-category-pos">POS <input type="number" step="1" inputmode="numeric" value="${Number(c.sort_order) || 0}" data-cat-pos aria-label="Category position"></label>
                <div class="admin-category-actions">
                    <button type="button" data-cat-edit>EDIT</button>
                    <button type="button" class="danger" data-cat-del>DELETE</button>
                </div>`;
            card.querySelector('strong').textContent = c.name;
            card.querySelector('[data-cat-pos]').addEventListener('change', async e => {
                const v = String(e.target.value).trim();
                if (!/^-?\d{1,6}$/.test(v)) { toast('Position must be a whole number.', { variant: 'danger' }); e.target.value = String(Number(c.sort_order) || 0); return; }
                try {
                    await api('api/admin/categories.php', { method: 'POST', body: { action: 'update', id: c.id, sort_order: parseInt(v, 10) } });
                    toast('Category position saved.', { duration: 1500 });
                    loadProducts();
                } catch (err) { toast(err.message, { variant: 'danger' }); e.target.value = String(Number(c.sort_order) || 0); }
            });
            card.querySelector('[data-cat-edit]').addEventListener('click', async () => {
                const name = prompt('Edit category name:', c.name);
                if (!name || !name.trim() || name === c.name) return;
                try {
                    await api('api/admin/categories.php', { method: 'POST', body: { action: 'update', id: c.id, name: name.trim() } });
                    loadProducts();
                } catch (err) { toast(err.message, { variant: 'danger' }); }
            });
            card.querySelector('[data-cat-del]').addEventListener('click', async () => {
                if (!confirm('Delete category ' + c.name + '?')) return;
                try {
                    await api('api/admin/categories.php', { method: 'POST', body: { action: 'delete', id: c.id } });
                    loadProducts();
                } catch (err) { toast(err.message, { variant: 'danger' }); }
            });
            box.appendChild(card);
        });
    }

    function renderProductCards(products) {
        const box = document.getElementById('adminProductCards');
        const searching = (document.getElementById('adminProductSearch')?.value || '').trim() !== '';
        document.getElementById('adminProductCountLabel').textContent = searching ? (products.length + ' OF ' + productCache.length + ' PRODUCTS') : (products.length + ' PRODUCTS');
        box.innerHTML = '';
        if (!products.length) {
            box.innerHTML = '<div class="admin-product-empty">' + (searching ? 'No products match your search.' : 'No products yet.') + '</div>';
            return;
        }
        products.forEach(p => {
            const total = p.plans.reduce((n, pl) => n + (pl.source === 'api' ? 99 : 0), 0) || p.total_stock;
            const card = document.createElement('article');
            card.className = 'admin-product-card' + (p.maintenance ? ' maintenance' : '');
            let durationsHtml = p.plans.map(pl => {
                const stockLabel = pl.source === 'api' ? 'API stock' : 'Local stock';
                return `<div class="admin-product-duration${pl.enabled === false ? ' plan-off' : ''}">
                    <div class="admin-duration-top"><strong>${pl.duration}${pl.enabled === false ? '<em class="admin-plan-off-badge">OFF</em>' : ''}</strong><span title="Normal User price / Reseller price">${money(pl.price)} <small>/ R ${money(pl.reseller_price != null ? pl.reseller_price : pl.price)}</small></span></div>
                    <div class="admin-duration-meta">${stockLabel}${pl.source === 'api' ? ' • ' + providerLabel(pl.provider) + (p.provider_service_id ? ' • Service ID: ' + esc2(p.provider_service_id) : '') + ' • Variant ID: ' + esc2(pl.provider_variant_id || '—') + (pl.provider_api_version ? ' • API: ' + esc2(String(pl.provider_api_version).toUpperCase()) : '') + (pl.provider_price != null ? ' • Provider: ' + esc2(pl.provider_currency || '') + ' ' + esc2(pl.provider_price) : '') : ''}</div>
                </div>`;
            }).join('');
            card.innerHTML = `
                <div class="admin-product-card-head">
                    <div>
                        <h3></h3>
                        <div class="category"><b class="admin-order-badge">#${Number(p.sort_order) || 0}</b> ${p.category_name || '—'}${p.video_url ? ' • 🎬 VIDEO' : ''}</div>
                    </div>
                    <div class="admin-stock-total">${p.total_stock} STOCK</div>
                </div>
                ${durationsHtml}
                <div class="admin-card-actions">
                    <button data-act="edit">EDIT</button>
                    <button data-act="restock">KEY ADD</button>
                    <button class="enable ${p.enabled ? 'on' : ''}" data-act="enabled">${p.enabled ? 'DISABLE PRODUCT' : 'ENABLE PRODUCT'}</button>
                    <button class="maint ${p.maintenance ? 'on' : ''}" data-act="maint">${p.maintenance ? 'DISABLE MAINTENANCE' : 'MAINTENANCE MODE'}</button>
                    <button class="maint on" data-act="delete" style="border-color:rgba(255,92,104,.55);color:#ff7a84">🗑 DELETE PRODUCT</button>
                </div>`;
            card.querySelector('h3').textContent = p.name;
            card.querySelector('h3').style.cursor = 'pointer';
            card.querySelector('h3').addEventListener('click', () => loadProductIntoBuilder(p));

            card.querySelector('[data-act="edit"]').addEventListener('click', () => loadProductIntoBuilder(p));
            card.querySelector('[data-act="restock"]').addEventListener('click', () => {
                showPage('products');
                const sel = document.getElementById('adminRestockProduct');
                sel.value = String(p.id);
                sel.dispatchEvent(new Event('change'));
                document.querySelector('.admin-key-restock').scrollIntoView({ behavior: 'smooth' });
            });
            card.querySelector('[data-act="enabled"]').addEventListener('click', async () => {
                try {
                    const r = await api('api/admin/products.php', { method: 'POST', body: { action: 'toggle_enabled', id: p.id } });
                    toast(r.enabled ? 'Product visible to users.' : 'Product hidden from users.', { duration: 1800 });
                    loadProducts();
                } catch (err) { toast(err.message, { variant: 'danger' }); }
            });
            card.querySelector('[data-act="delete"]').addEventListener('click', async () => {
                if (!confirm('Delete "' + p.name + '"?\n\nIf this product has orders or delivered keys it is ARCHIVED instead (hidden from the Store, history kept, restorable). Only an unused product is permanently deleted.')) return;
                try {
                    const r = await api('api/admin/products.php', { method: 'POST', body: { action: 'delete', id: p.id } });
                    toast(r.message || (r.archived ? 'Product archived.' : 'Product deleted.'), { duration: 4200 });
                    loadProducts();
                } catch (err) { toast(err.message, { variant: 'danger' }); }
            });
            card.querySelector('[data-act="maint"]').addEventListener('click', async () => {
                try { await api('api/admin/products.php', { method: 'POST', body: { action: 'toggle_maintenance', id: p.id } }); loadProducts(); } catch (err) { toast(err.message, { variant: 'danger' }); }
            });
            box.appendChild(card);
        });
    }

    function loadProductIntoBuilder(p) {
        showPage('products');
        document.getElementById('adminProductName').value = p.name;
        document.getElementById('adminProductCategory').value = String(p.category_id);
        const builder = document.getElementById('adminDurationBuilder');
        builder.innerHTML = '';
        (p.plans || []).forEach(pl => addDurationRow(pl));
        renderFeatureBuilder(p.features || []);
        productImageState = { file: null, clear: false };
        const imgInput = document.getElementById('adminProductImageFile'); if (imgInput) imgInput.value = '';
        setProductImagePreview(p.image_url || '');
        const ordEl = document.getElementById('adminProductOrder'); if (ordEl) ordEl.value = String(Number(p.sort_order) || 0);
        productVideoState = { file: null, clear: false };
        const vIn = document.getElementById('adminProductVideoFile'); if (vIn) vIn.value = '';
        setProductVideoPreview(p.video_url || '');
        document.getElementById('adminProductName').scrollIntoView({ behavior: 'smooth' });
        currentEditingProductId = p.id;
    }
    let currentEditingProductId = 0;

    /* ---------- Store UI: product image + feature sections ---------- */
    let productImageState = { file: null, clear: false };
    function setProductImagePreview(url) {
        const img = document.getElementById('adminProductImagePreview');
        const empty = document.getElementById('adminProductImageEmpty');
        const pick = document.getElementById('adminProductImagePick');
        if (!img) return;
        if (url) { img.src = url; img.hidden = false; if (empty) empty.hidden = true; }
        else { img.removeAttribute('src'); img.hidden = true; if (empty) empty.hidden = false; }
        if (pick) pick.textContent = url ? 'CHANGE IMAGE' : 'UPLOAD IMAGE';
    }
    // Same browser-side pipeline as the logo/display uploads (canvas -> WebP quality ladder, JPEG fallback).
    // Unlike the banner it is NOT cropped: the whole image is kept (only downscaled) and the Store card fits it.
    async function prepareProductImageUpload(file) {
        const MAX_W = 1280, MAX_H = 1280;
        const SAFE_BYTES = Math.floor(1.45 * 1024 * 1024), TARGET_BYTES = 700 * 1024;
        if (!/^image\/(png|jpeg|webp)$/i.test(file.type)) throw new Error('Please choose a PNG, JPG or WEBP image.');
        const dataUrl = await new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(r.result); r.onerror = () => rej(new Error('Could not read the selected image.')); r.readAsDataURL(file); });
        const img = await new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => rej(new Error('Could not decode the selected image.')); i.src = dataUrl; });
        const iw = img.naturalWidth || img.width, ih = img.naturalHeight || img.height;
        const scale = Math.min(1, MAX_W / iw, MAX_H / ih);
        const w = Math.max(1, Math.round(iw * scale)), h = Math.max(1, Math.round(ih * scale));
        const canvas = document.createElement('canvas'); canvas.width = w; canvas.height = h;
        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('Your browser cannot prepare this image for upload.');
        ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, 0, 0, w, h);
        const toBlob = (c, t, q) => new Promise(r => c.toBlob(r, t, q));
        let blob = null;
        for (const q of [0.88, 0.82, 0.76, 0.70, 0.62, 0.54, 0.46]) {
            blob = await toBlob(canvas, 'image/webp', q);
            if (blob && blob.type === 'image/webp' && blob.size <= TARGET_BYTES) break;
        }
        if (!blob || blob.type !== 'image/webp' || blob.size > SAFE_BYTES) {
            const jc = document.createElement('canvas'); jc.width = w; jc.height = h;
            const jx = jc.getContext('2d'); jx.fillStyle = '#ffffff'; jx.fillRect(0, 0, w, h); jx.drawImage(canvas, 0, 0);
            for (const q of [0.86, 0.78, 0.70, 0.62, 0.54]) { blob = await toBlob(jc, 'image/jpeg', q); if (blob && blob.size <= TARGET_BYTES) break; }
        }
        if (!blob || blob.size > SAFE_BYTES) throw new Error('This image is unusually large or complex. Please use a simpler image.');
        return new File([blob], 'product.' + (blob.type === 'image/webp' ? 'webp' : 'jpg'), { type: blob.type, lastModified: Date.now() });
    }
    document.getElementById('adminProductImagePick')?.addEventListener('click', () => document.getElementById('adminProductImageFile').click());
    document.getElementById('adminProductImageFile')?.addEventListener('change', e => {
        const f = e.target.files && e.target.files[0];
        if (!f) return;
        if (!/^image\/(png|jpeg|webp)$/i.test(f.type)) { document.getElementById('adminProductMessage').textContent = 'Use a PNG, JPG or WEBP image.'; e.target.value = ''; return; }
        productImageState = { file: f, clear: false };
        setProductImagePreview(URL.createObjectURL(f));
        document.getElementById('adminProductMessage').textContent = 'Image ready. Press SAVE PRODUCT to apply.';
    });
    document.getElementById('adminProductImageClear')?.addEventListener('click', () => {
        productImageState = { file: null, clear: true };
        const i = document.getElementById('adminProductImageFile'); if (i) i.value = '';
        setProductImagePreview('');
        document.getElementById('adminProductMessage').textContent = 'Image will be removed when you press SAVE PRODUCT.';
    });


    /* ---------- Product video (optional): pick, validate playability in the browser, upload after the product is saved ---------- */
    let productVideoState = { file: null, clear: false };
    function setProductVideoPreview(url) {
        const v = document.getElementById('adminProductVideoPreview');
        const empty = document.getElementById('adminProductVideoEmpty');
        const pick = document.getElementById('adminProductVideoPick');
        if (!v) return;
        v.pause();
        if (url) { v.src = url; v.hidden = false; if (empty) empty.hidden = true; }
        else { v.removeAttribute('src'); v.load(); v.hidden = true; if (empty) empty.hidden = false; }
        if (pick) pick.textContent = url ? 'REPLACE VIDEO' : 'UPLOAD VIDEO';
    }
    // The browser is the only reliable judge of codec support, so a file it cannot decode is rejected here with a clear message.
    function checkVideoPlayable(file) {
        return new Promise(resolve => {
            const url = URL.createObjectURL(file);
            const t = document.createElement('video');
            let done = false;
            const finish = ok => { if (done) return; done = true; clearTimeout(timer); t.removeAttribute('src'); t.load(); URL.revokeObjectURL(url); resolve(ok); };
            const timer = setTimeout(() => finish(false), 15000);
            t.preload = 'metadata'; t.muted = true;
            t.onloadedmetadata = () => finish(t.videoWidth > 0 && t.videoHeight > 0);
            t.onerror = () => finish(false);
            t.src = url;
        });
    }
    document.getElementById('adminProductVideoPick')?.addEventListener('click', () => document.getElementById('adminProductVideoFile').click());
    document.getElementById('adminProductVideoFile')?.addEventListener('change', async e => {
        const f = e.target.files && e.target.files[0];
        const msg = document.getElementById('adminProductMessage');
        if (!f) return;
        if (!/^video\/(mp4|webm)$/i.test(f.type) && !/\.(mp4|m4v|webm)$/i.test(f.name)) { msg.textContent = 'Unsupported video format. Use MP4 (H.264 + AAC) or WebM.'; e.target.value = ''; return; }
        msg.textContent = 'Checking that this browser can play the video…';
        if (!(await checkVideoPlayable(f))) {
            msg.textContent = 'This video cannot be played by the browser (unsupported codec/format). Re-export it as MP4 with H.264 video and AAC audio.';
            e.target.value = ''; return;
        }
        productVideoState = { file: f, clear: false };
        setProductVideoPreview(URL.createObjectURL(f));
        msg.textContent = 'Video ready (' + (f.size / 1048576).toFixed(1) + ' MB). Press SAVE PRODUCT to upload.';
    });
    document.getElementById('adminProductVideoClear')?.addEventListener('click', () => {
        productVideoState = { file: null, clear: true };
        const i = document.getElementById('adminProductVideoFile'); if (i) i.value = '';
        setProductVideoPreview('');
        document.getElementById('adminProductMessage').textContent = 'Video will be removed when you press SAVE PRODUCT.';
    });
    // XMLHttpRequest (not fetch) so large uploads can show progress.
    function uploadProductVideo(id, file) {
        return new Promise((resolve, reject) => {
            const bar = document.getElementById('adminProductVideoProgress');
            const fd = new FormData();
            fd.append('id', String(id));
            if (file) { fd.append('action', 'upload'); fd.append('video', file, file.name); } else { fd.append('action', 'clear'); }
            const x = new XMLHttpRequest();
            x.open('POST', window.KP.baseUrl + '/api/admin/product-video.php');
            x.withCredentials = true;
            x.setRequestHeader('X-CSRF-Token', window.KP.csrf);
            if (file && bar) { bar.hidden = false; bar.firstElementChild.style.width = '0%'; }
            x.upload.onprogress = ev => { if (bar && ev.lengthComputable) bar.firstElementChild.style.width = Math.round(ev.loaded / ev.total * 100) + '%'; };
            x.onerror = () => { if (bar) bar.hidden = true; reject(new Error('Video upload failed (network error or connection reset by the server upload limit).')); };
            x.onload = () => {
                if (bar) bar.hidden = true;
                let j = null;
                try { j = JSON.parse(x.responseText); } catch (e) { /* not JSON */ }
                if (!j) return reject(new Error(x.status === 413 ? 'Server upload limit exceeded (HTTP 413). Raise the hosting upload limit or use a smaller video.' : 'Video upload failed (HTTP ' + x.status + ').'));
                if (x.status < 200 || x.status >= 300 || !j.ok) return reject(new Error(j.error || ('Video upload failed (HTTP ' + x.status + ').')));
                resolve(j);
            };
            x.send(fd);
        });
    }

    function addFeatureCard(sec) {
        const box = document.getElementById('adminFeatureBuilder');
        if (!box) return;
        const card = document.createElement('div');
        card.className = 'admin-feature-card';
        card.innerHTML = `
            <div class="admin-feature-card-head"><span>FEATURE SECTION</span><button type="button" class="admin-small-btn danger feat-remove">REMOVE</button></div>
            <input class="feat-header" type="text" maxlength="80" placeholder="Section header">
            <textarea class="feat-items" rows="4" placeholder="One feature per line"></textarea>`;
        card.querySelector('.feat-header').value = (sec && sec.header) || '';
        card.querySelector('.feat-items').value = ((sec && sec.items) || []).join('\n');
        card.querySelector('.feat-remove').addEventListener('click', () => card.remove());
        box.appendChild(card);
    }
    function renderFeatureBuilder(list) {
        const box = document.getElementById('adminFeatureBuilder');
        if (!box) return;
        box.innerHTML = '';
        (list || []).forEach(addFeatureCard);
    }
    function collectFeatures() {
        return Array.from(document.querySelectorAll('#adminFeatureBuilder .admin-feature-card')).map(c => ({
            header: c.querySelector('.feat-header').value.trim(),
            items: c.querySelector('.feat-items').value.split(/\r?\n/).map(x => x.trim()).filter(Boolean),
        })).filter(f => f.header || f.items.length);
    }
    document.getElementById('adminAddFeature')?.addEventListener('click', () => addFeatureCard(null));
    function esc2(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

    function providerLabel(key) {
        const p = (window.KPAdminProviders || []).find(x => x.key === (key || 'primary'));
        return p ? String(p.label).replace(/[<>&"]/g, '') : (key === 'vipsellerhub' ? 'VIP SELLER HUB API' : key === 'licencedashboard' ? 'LICENCE DASHBOARD API' : key === 'bantibhaiya' ? 'BANTIBHAIYA API' : key === 'panelstore' ? 'PANELSTORE API' : key === 'keypanel' ? 'KEYPANEL API' : 'DRIPSTORE API');
    }

    // Provider choices for a duration. Disabled providers are not selectable for new configuration;
    // a plan that already uses one keeps showing it (nothing is silently switched).
    function providerOptions(selected) {
        const list = window.KPAdminProviders || [
            { key: 'primary', label: window.KPAdminApiProviderName || 'DRIPSTORE API', enabled: true },
            { key: 'keypanel', label: 'KEYPANEL API', enabled: true },
            { key: 'panelstore', label: 'PANELSTORE API', enabled: true },
            { key: 'bantibhaiya', label: 'BANTIBHAIYA API', enabled: true },
            { key: 'licencedashboard', label: 'LICENCE DASHBOARD API', enabled: true },
            { key: 'vipsellerhub', label: 'VIP SELLER HUB API', enabled: true },
        ];
        return list.map(p => {
            const dis = !p.enabled && p.key !== selected;
            const lbl = String(p.label).replace(/[<>&"]/g, '') + (p.enabled ? '' : ' (disabled)');
            return `<option value="${p.key}" ${p.key === selected ? 'selected' : ''} ${dis ? 'disabled' : ''}>${lbl}</option>`;
        }).join('');
    }

    // v57: provider product names for the suggestion list (loaded once, on first focus of a PROVIDER PRODUCT NAME field)
    let providerNamesLoaded = false;
    async function loadProviderNames() {
        if (providerNamesLoaded) return;
        providerNamesLoaded = true;
        try {
            const r = await api('api/admin/products.php', { method: 'POST', body: { action: 'provider_products', provider: 'licencedashboard' } });
            const dl = document.getElementById('adminProviderProducts');
            if (dl) { dl.innerHTML = ''; (r.names || []).forEach(n => { const o = document.createElement('option'); o.value = n; dl.appendChild(o); }); }
        } catch (e) { providerNamesLoaded = false; /* suggestions are optional; typing a name still works */ }
    }
    // the local product name above the plans also feeds the lookup of every plan whose own provider-name field is empty
    (function () {
        const el = document.getElementById('adminProductName');
        if (el) el.addEventListener('input', () => document.querySelectorAll('#adminDurationBuilder .admin-duration-card').forEach(r => { if (r._lookup && r._lookup.nameChanged) r._lookup.nameChanged(); }));
    })();

    function addDurationRow(pl) {
        const builder = document.getElementById('adminDurationBuilder');
        if (!document.getElementById('adminDurationPresets')) {
            const dl = document.createElement('datalist'); dl.id = 'adminDurationPresets';
            ['1 Hour', '2 Hours', '3 Hours', '6 Hours', '12 Hours', '1 Day', '3 Days', '5 Days', '7 Days', '10 Days', '15 Days', '30 Days'].forEach(v => { const o = document.createElement('option'); o.value = v; dl.appendChild(o); });
            document.body.appendChild(dl);
            const dp = document.createElement('datalist'); dp.id = 'adminProviderProducts'; document.body.appendChild(dp);
        }
        const row = document.createElement('div');
        row.className = 'admin-duration-card';
        const source = pl.source || 'local';
        const allowQuantity = !(pl.allow_quantity === false || Number(pl.allow_quantity) === 0);
        const planEnabled = !(pl.enabled === false || Number(pl.enabled) === 0);   // new / legacy durations default to ON
        row.innerHTML = `
            <div class="admin-duration-head">
                <strong>DURATION PLAN</strong>
                <button type="button" class="admin-duration-remove">REMOVE</button>
            </div>
            <div class="admin-api-provider-card" style="display:${source==='api'?'block':'none'}">
                <span>API PROVIDER</span>
                <select class="dur-provider">${providerOptions(pl.provider || 'primary')}</select>
            </div>
            <div class="admin-duration-fields">
                <input class="dur-name" type="text" list="adminDurationPresets" placeholder="Duration e.g. 30 Days" value="${pl.duration || ''}">
                <label class="dur-price-label"><small>NORMAL USER PRICE ₹</small><input class="dur-price" type="number" min="0" step="0.01" placeholder="Normal User price" value="${pl.price || ''}"></label>
                <label class="dur-price-label"><small>RESELLER PRICE ₹</small><input class="dur-rprice" type="number" min="0" step="0.01" placeholder="Reseller price" value="${pl.reseller_price != null ? pl.reseller_price : (pl.price || '')}"></label>
            </div>
            <div class="admin-custom-price" style="display:${source==='api'?'block':'none'}" data-lifecycle="${esc2(pl.lifecycle_state || 'active')}">
                <div class="acp-head">
                    <span class="acp-title">CUSTOM PRICE</span>
                    <label class="acp-switch"><input type="checkbox" class="dur-custom-on" ${pl.custom_price_enabled ? 'checked' : ''}> <span>Use custom selling price</span></label>
                </div>
                <div class="acp-grid">
                    <div><small>Provider price</small><b>${pl.provider_price != null ? esc2(pl.provider_currency || '') + ' ' + esc2(pl.provider_price) : '—'}</b></div>
                    <div><small>Exchange rate</small><b>${pl.price_rate ? '₹' + esc2(pl.price_rate) : '—'}</b></div>
                    <div><small>Calculated price</small><b>${pl.inr_cost ? '₹' + esc2(pl.inr_cost) : '—'}</b></div>
                    <div><small>Selling price now</small><b>₹${esc2(Number(pl.price || 0).toFixed(2))}</b></div>
                </div>
                <input class="dur-custom-price" type="number" min="0" step="0.01" placeholder="NORMAL USER custom price (₹)" value="${pl.custom_price || ''}">
                <div class="acp-head" style="margin-top:10px">
                    <span class="acp-title">RESELLER CUSTOM PRICE</span>
                    <label class="acp-switch"><input type="checkbox" class="dur-rcustom-on" ${pl.reseller_custom_price_enabled ? 'checked' : ''}> <span>Use custom Reseller price</span></label>
                </div>
                <div class="acp-grid">
                    <div><small>Reseller exchange rate</small><b>${pl.reseller_price_rate ? '₹' + esc2(pl.reseller_price_rate) : '—'}</b></div>
                    <div><small>Reseller calculated</small><b>${pl.reseller_inr_cost ? '₹' + esc2(pl.reseller_inr_cost) : '—'}</b></div>
                    <div><small>Reseller price now</small><b>₹${esc2(Number(pl.reseller_price != null ? pl.reseller_price : pl.price || 0).toFixed(2))}</b></div>
                </div>
                <input class="dur-rcustom-price" type="number" min="0" step="0.01" placeholder="RESELLER custom price (₹)" value="${pl.reseller_custom_price || ''}">
                <small class="acp-note">${pl.reseller_price_increase ? 'RESELLER PRICE REVIEW PENDING: provider now ' + esc2(pl.reseller_price_increase.new_provider_price) + ' (₹' + esc2(pl.reseller_price_increase.new_inr) + '). Automatic update at ' + esc2(pl.reseller_price_increase.deadline) + '. Change the Reseller price before then to keep it.' : ''}</small>
                <small class="acp-note">${pl.price_increase ? 'PRICE INCREASE PENDING: provider now ' + esc2(pl.price_increase.new_provider_price) + ' (₹' + esc2(pl.price_increase.new_inr) + '). Automatic update at ' + esc2(pl.price_increase.deadline) + '. Set a custom price to keep control.' : 'The custom price never changes the provider cost. It is kept when the provider catalog refreshes.'}${pl.lifecycle_state && pl.lifecycle_state !== 'active' ? ' • DURATION STATE: ' + esc2(String(pl.lifecycle_state).toUpperCase()) : ''}</small>
            </div>
            <div class="admin-field" style="margin-top:10px">
                <label>STOCK SOURCE</label>
                <select class="dur-source">
                    <option value="local" ${source==='local'?'selected':''}>LOCAL STOCK</option>
                    <option value="api" ${source==='api'?'selected':''}>API STOCK</option>
                </select>
            </div>
            <div class="admin-field dur-api-wrap" style="margin-top:8px;display:${source==='api'?'block':'none'}">
                <div class="dur-pname-wrap" style="display:none;margin-bottom:8px">
                    <label>PROVIDER PRODUCT NAME (finds the ID from name + duration)</label>
                    <input class="dur-pname" type="text" list="adminProviderProducts" autocomplete="off" placeholder="Leave empty to use the product name above">
                </div>
                <label class="dur-variant-label">DURATION ID / VARIANT ID</label>
                <input class="dur-variant" type="text" placeholder="e.g. 157" value="${esc2(pl.provider_variant_id || '')}">
                <div class="admin-input-actions dur-lookup" style="display:none">
                    <button type="button" class="admin-small-btn gold dur-lookup-btn">CHECK PROVIDER PRICE</button>
                    <button type="button" class="admin-small-btn dur-lookup-apply" style="display:none"></button>
                    <button type="button" class="admin-small-btn dur-lookup-useid" style="display:none"></button>
                </div>
                <div class="dur-lookup-choices" style="display:none;flex-direction:column;gap:6px;margin-top:6px"></div>
                <small class="dur-lookup-msg" role="status" aria-live="polite" style="display:block;margin-top:6px;min-height:12px;color:#8e9ab0"></small>
                <div class="dur-verify" style="display:none"></div>
            </div>
            <div class="admin-field dur-ver-wrap" style="margin-top:8px;display:none">
                <label>API VERSION (separate from the ID)</label>
                <select class="dur-apiver">
                    <option value="v2" ${(!pl.provider_api_version || pl.provider_api_version === 'v2') ? 'selected' : ''}>V2 (direct key)</option>
                    <option value="v1" ${pl.provider_api_version === 'v1' ? 'selected' : ''}>V1 (device-bound, needs Android ID)</option>
                    <option value="both" ${pl.provider_api_version === 'both' ? 'selected' : ''}>BOTH (V2 unless V1 is requested)</option>
                </select>
                <small class="dur-sync-info">${pl.provider_price != null ? 'Provider price: ' + esc2(pl.provider_currency || '') + ' ' + esc2(pl.provider_price) + (pl.price_rate && pl.provider_currency === 'USD' ? ' × ' + esc2(pl.price_rate) + ' = ₹' + esc2((Math.round(parseFloat(pl.provider_price) * parseFloat(pl.price_rate) * 100) / 100).toFixed(2)) + ' (initial)' : '') + (pl.price_manual ? ' • selling price edited by admin' : '') : ''}</small>
            </div>
            <div class="admin-field dur-local-wrap" style="margin-top:8px;display:${source==='local'?'block':'none'}">
                <label>ADD KEYS TO THIS DURATION</label>
                <textarea class="dur-keys" rows="6" placeholder="Paste one key per line"></textarea>
                <div class="admin-key-count">0 keys in this duration</div>
            </div>
            <div class="admin-field admin-quantity-control" style="margin-top:8px">
                <label>QUANTITY CONTROL</label>
                <button type="button" class="admin-quantity-toggle ${allowQuantity?'on':''}">${allowQuantity?'ON':'OFF'}</button>
                <small>${allowQuantity ? 'User can increase quantity (API maximum is 20).' : 'Quantity is shown but locked to 1 purchase.'}</small>
            </div>
            <div class="admin-field admin-duration-status-control" style="margin-top:8px">
                <label>DURATION STATUS</label>
                <button type="button" class="admin-api-toggle dur-enabled-toggle ${planEnabled?'on':''}">${planEnabled?'ON':'OFF'}</button>
                <small>${planEnabled ? 'ON: shown in the Store and can be purchased.' : 'OFF: hidden from the Store and cannot be purchased. Other durations of this product are not affected.'}</small>
            </div>
            <input type="hidden" class="dur-existing-id" value="${pl.id || ''}">`;
        row.querySelector('.admin-duration-remove').addEventListener('click', () => row.remove());
        // v56/v57: provider lookups inside this duration (Provider 5 only). The SERVER searches the catalog, picks the variant and converts the price
        // (same rule as Price Sync); this code only displays results and fills fields. Each Duration Plan has its own independent state.
        const priceIn = row.querySelector('.dur-price');
        const rPriceEl = row.querySelector('.dur-rprice');
        // v65: where each category's price came from, tracked SEPARATELY per input: 'auto' (filled from a provider calculation), 'manual' (typed by the admin),
        // 'saved' (value loaded from the database) or '' (empty / stale-cleared). A programmatic fill is never treated as a manual edit.
        const originOf = el => (el && el.dataset.origin) || '';
        const setOrigin = (el, o) => { if (el) { el.dataset.origin = o; el.dataset.auto = o === 'auto' ? '1' : '0'; } };
        const setAuto = (el, v) => { el.value = String(Number(v).toFixed(2)); setOrigin(el, 'auto'); };
        const fillable = el => !!el && (el.value.trim() === '' || Number(el.value) === 0 || originOf(el) === 'auto' || originOf(el) === 'stale');
        { const saved = !!row.querySelector('.dur-existing-id').value; [priceIn, rPriceEl].forEach(el => { if (el) setOrigin(el, el.value.trim() === '' ? '' : (saved ? 'saved' : 'manual')); }); }
        const variantIn = row.querySelector('.dur-variant');
        const nameIn = row.querySelector('.dur-pname');
        const durIn = row.querySelector('.dur-name');
        const lkMsg = row.querySelector('.dur-lookup-msg');
        const lkBox = row.querySelector('.dur-lookup');
        const lkBtn = row.querySelector('.dur-lookup-btn');
        const lkApply = row.querySelector('.dur-lookup-apply');
        const lkUseId = row.querySelector('.dur-lookup-useid');
        const lkChoices = row.querySelector('.dur-lookup-choices');
        const lk = { seq: 0, fseq: 0, pBusy: false, fBusy: false, ok: false, timer: null, ftimer: null, autoId: null, pending: null, verify: null };
        Object.defineProperty(lk, 'busy', { get() { return lk.pBusy || lk.fBusy; } });
        row._lookup = lk;
        const lkSetMsg = (text, kind) => { lkMsg.textContent = text || ''; lkMsg.style.color = kind === 'error' ? '#ff717b' : (kind === 'warn' ? '#d8b765' : (kind === 'ok' ? '#6fdb9c' : '#8e9ab0')); };
        const lkTime = ts => ts ? ' [provider data read ' + new Date(Number(ts) * 1000).toLocaleTimeString() + ']' : '';
        const lkActive = () => {
            const pv = row.querySelector('.dur-provider');
            return row.querySelector('.dur-source').value === 'api' && pv && pv.value === 'licencedashboard';
        };
        const lkProviderName = () => nameIn.value.trim() || ((document.getElementById('adminProductName') || {}).value || '').trim();
        const lkClearChoices = () => { lkChoices.innerHTML = ''; lkChoices.style.display = 'none'; lkUseId.style.display = 'none'; lkUseId.textContent = ''; lk.pending = null; };
        const lkReset = () => {   // whatever was fetched for the old input must not be used any more
            lk.seq++; lk.fseq++; lk.pBusy = false; lk.fBusy = false; lk.ok = false;
            lkApply.style.display = 'none'; lkApply.textContent = ''; delete lkApply.dataset.value;
            lkClearChoices();
            if (typeof vClear === 'function') vClear();   // v64: both verification results belonged to the old input
            lkBtn.disabled = false; lkBtn.textContent = 'CHECK PROVIDER PRICE';
            [priceIn, rPriceEl].forEach(el => { if (el && originOf(el) === 'auto') { el.value = ''; setOrigin(el, ''); } });   // v65: BOTH auto-filled prices belonged to the old mapping (manual / saved values stay)
            lkSetMsg('', '');
        };
        // v64: CHECK AGAIN verifies BOTH price categories in one request and shows two INDEPENDENT results (Normal User / Reseller). Each result has its own
        // correction button that sends its own explicit category; the browser never supplies a price to store (the server recalculates it).
        const vBox = row.querySelector('.dur-verify');
        const vClear = () => { vBox.innerHTML = ''; vBox.style.display = 'none'; lk.verify = null; };
        const V_COLORS = { 'CORRECT': '#6fdb9c', 'PRICE MISMATCH': '#d8b765', 'MANUAL PRICE': '#d8b765', 'CUSTOM PRICE': '#7fb2ff', 'PENDING REVIEW': '#7fb2ff', 'INVALID RATE': '#ff717b', 'INVALID PROVIDER PRICE': '#ff717b', 'UNVERIFIED': '#8e9ab0' };
        const money = v => (v == null || v === '') ? '—' : '₹' + Number(v).toFixed(2);
        const catInput = cat => cat === 'reseller' ? rPriceEl : (cat === 'normal' ? priceIn : null);   // explicit category only
        const vRender = () => {
            const v = lk.verify; if (!v) { vClear(); return; }
            vBox.innerHTML = ''; vBox.style.display = 'block';
            ['normal', 'reseller'].forEach(cat => {
                const d = v.cats[cat]; if (!d) return;
                const col = V_COLORS[d.status] || '#8e9ab0';
                const card = document.createElement('div');
                card.className = 'dur-verify-card'; card.dataset.category = cat;
                card.style.cssText = 'margin-top:8px;padding:10px;border:1px solid ' + col + '55;border-radius:10px;background:rgba(255,255,255,.03)';
                const head = document.createElement('div');
                head.style.cssText = 'display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap';
                const t = document.createElement('strong'); t.style.fontSize = '12px'; t.textContent = (cat === 'reseller' ? 'RESELLER' : 'NORMAL USER') + ' PRICE VERIFICATION';
                const b = document.createElement('span'); b.style.cssText = 'font-size:11px;font-weight:700;color:' + col; b.textContent = d.status;
                head.append(t, b);
                const grid = document.createElement('div'); grid.className = 'acp-grid'; grid.style.marginTop = '8px';
                const rateTxt = d.provider_currency === 'INR' ? 'none (INR)' : (d.exchange_rate ? '₹' + Number(d.exchange_rate) : 'NOT SET');
                [['Provider price', d.provider_currency + ' ' + d.provider_price], [cat === 'reseller' ? 'Reseller exchange rate' : 'Exchange rate', rateTxt], [cat === 'reseller' ? 'Reseller calculated price' : 'Calculated price', money(d.calculated)],
                 [cat === 'reseller' ? 'Current Reseller selling price' : 'Current selling price', money(d.current)], ['Difference', d.difference == null ? '—' : (Number(d.difference) > 0 ? '+' : '') + Number(d.difference).toFixed(2)]]
                    .forEach(([k, val]) => { const c = document.createElement('div'); const s = document.createElement('small'); s.textContent = k; const bb = document.createElement('b'); bb.textContent = val; c.append(s, bb); grid.appendChild(c); });
                const msg = document.createElement('small'); msg.style.cssText = 'display:block;margin-top:6px;color:' + col; msg.textContent = d.message || '';
                card.append(head, grid, msg);
                const typed = catInput(cat);
                if (d.current_source === 'database' && typed && typed.value.trim() !== '' && Number(typed.value).toFixed(2) !== Number(d.current).toFixed(2)) {
                    const n = document.createElement('small'); n.style.cssText = 'display:block;margin-top:4px;color:#8e9ab0';
                    n.textContent = 'Unsaved value in the form: ₹' + Number(typed.value).toFixed(2) + ' (saved price: ₹' + Number(d.current).toFixed(2) + ').';
                    card.appendChild(n);
                }
                if (d.can_apply) {
                    const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'admin-small-btn gold dur-verify-apply'; btn.style.marginTop = '8px';
                    btn.dataset.category = cat; btn.disabled = !!v.busy[cat];
                    btn.textContent = (v.busy[cat] ? 'APPLYING…' : 'USE ' + (cat === 'reseller' ? 'RESELLER' : 'NORMAL') + ' ₹' + Number(d.calculated).toFixed(2));
                    btn.addEventListener('click', () => vApply(cat));
                    card.appendChild(btn);
                }
                vBox.appendChild(card);
            });
        };
        const vApply = async (cat) => {
            const v = lk.verify; if (!v || !v.cats[cat] || !v.cats[cat].can_apply || v.busy[cat]) return;
            const d = v.cats[cat], my = lk.seq, id = v.variant, input = catInput(cat);
            if (!input) return;
            if (d.apply_mode === 'form') {            // unsaved duration: only THIS category's input is filled; nothing is stored until the product is saved
                setAuto(input, d.calculated);
                lkSetMsg((cat === 'reseller' ? 'Reseller' : 'Normal User') + ' price field set to ₹' + input.value + '. Save the product to store it. The other category was not changed.', 'ok');
                lkRun(); return;
            }
            let confirmManual = 0;
            if (d.needs_confirm) {
                if (!window.confirm('The current ' + (cat === 'reseller' ? 'Reseller' : 'Normal User') + ' price was typed by an admin. Replace it with the calculated ₹' + Number(d.calculated).toFixed(2) + '?')) return;
                confirmManual = 1;
            }
            v.busy[cat] = true; vRender();
            try {
                const r = await api('api/admin/products.php', { method: 'POST', body: { action: 'apply_price', provider: 'licencedashboard', category: cat, plan_id: Number(row.querySelector('.dur-existing-id').value) || 0, variant_id: id, confirm_manual: confirmManual } });
                if (my !== lk.seq || variantIn.value.trim() !== id) return;      // the field changed meanwhile: do not touch the screen
                input.value = String(Number(r.price).toFixed(2)); setOrigin(input, 'saved');   // only THIS category's input; it now equals the saved price
                lkSetMsg((cat === 'reseller' ? 'Reseller' : 'Normal User') + ' price set to ₹' + input.value + ' (saved). The other category was not changed.', 'ok');
                lkRun();                                                          // re-verify both from the server
            } catch (err) {
                if (my !== lk.seq || !lk.verify) return;
                lk.verify.busy[cat] = false; vRender();
                lkSetMsg((cat === 'reseller' ? 'Reseller' : 'Normal User') + ' price NOT changed: ' + (err.message || 'update failed'), 'error');
            }
        };
        const lkRun = async (prefix) => {   // verify the price of the ID that is in the field (manual ID, or CHECK AGAIN): BOTH categories
            if (!lkActive()) return;
            if (lk.pBusy) return;           // no duplicate Check Again request while one is running
            const id = variantIn.value.trim();
            if (!id) { lkSetMsg('Enter the Provider Variant ID, or a provider product name + duration.', 'error'); return; }
            if (!/^\d{1,10}$/.test(id)) { lkSetMsg('Provider 5 Variant ID must be digits only (e.g. 76).', 'error'); return; }
            const my = ++lk.seq, editing = (typeof currentEditingProductId !== 'undefined') ? currentEditingProductId : null;
            lk.pBusy = true; lk.ok = false; vClear();
            lkApply.style.display = 'none';
            lkBtn.disabled = true; lkBtn.textContent = 'CHECKING…';
            lkSetMsg('Checking the provider price for ID ' + id + '…', '');
            try {
                const r = await api('api/admin/products.php', { method: 'POST', body: {
                    action: 'check_prices', provider: 'licencedashboard', variant_id: id, duration: durIn.value.trim(),
                    plan_id: Number(row.querySelector('.dur-existing-id').value) || 0, product_id: Number(editing) || 0,
                    entered_price: priceIn.value.trim(), entered_reseller_price: (row.querySelector('.dur-rprice') || {}).value || '',
                }});
                if (my !== lk.seq || !row.isConnected || variantIn.value.trim() !== id || (typeof currentEditingProductId !== 'undefined' && currentEditingProductId !== editing)) return;   // stale answer
                lk.pBusy = false; lk.ok = true;
                lkBtn.disabled = false; lkBtn.textContent = 'CHECK AGAIN';
                const cats = r.categories || {};
                // v65: each category's input is filled from ITS OWN calculation (categories.normal -> .dur-price, categories.reseller -> .dur-rprice).
                // Eligible = empty / 0 / previously auto-filled / stale. A manual or saved value is never overwritten; it is compared and reported instead.
                const notes = [];
                ['normal', 'reseller'].forEach(cat => {
                    const d = cats[cat], inp = catInput(cat), lbl = cat === 'reseller' ? 'Reseller' : 'Normal User';
                    if (!d || !inp) return;
                    if (d.calculated == null) { notes.push(lbl + ': ' + (d.status === 'INVALID RATE' ? 'INVALID RATE, not filled' : 'not calculated')); return; }
                    if (d.current_source === 'database') { if (d.status !== 'CORRECT') notes.push(lbl + ' saved price kept (' + d.status + ')'); return; }
                    if (fillable(inp)) {
                        setAuto(inp, d.calculated);
                        d.current = d.calculated; d.difference = '0.00'; d.status = 'CORRECT'; d.can_apply = false; d.current_source = 'form';
                        d.message = lbl + ' price auto-filled with ₹' + Number(d.calculated).toFixed(2) + ' (not saved yet).';
                        notes.push(lbl + ' price auto-filled');
                    } else if (d.status === 'PRICE MISMATCH') {
                        d.message += ' Your ' + (originOf(inp) === 'saved' ? 'saved' : 'manual') + ' price was preserved.';
                        notes.push(lbl + ' ' + (originOf(inp) === 'saved' ? 'saved' : 'manual') + ' price preserved (mismatch)');
                    }
                });
                lk.verify = { variant: id, cats, busy: { normal: false, reseller: false } };
                const p = r.provider || {};
                lkSetMsg((prefix || 'Verified: ') + (p.name ? '“' + p.name + '” • ' : '') + p.currency + ' ' + p.provider_price + (notes.length ? ' — ' + notes.join('; ') : '') + lkTime(r.fetched_at) + (r.duration_warning ? ' ⚠ ' + r.duration_warning : ''), (r.duration_warning || notes.some(n => /INVALID|mismatch|not calculated/.test(n))) ? 'warn' : 'ok');
                vRender();
            } catch (err) {
                if (my !== lk.seq) return;
                lk.pBusy = false; lk.ok = false;
                lkBtn.disabled = false; lkBtn.textContent = 'RETRY';
                lkSetMsg('Price NOT verified: ' + (err.message || 'lookup failed') + ' You can retry, or enter the price manually.', 'error');
            }
        };
        const lkFill = (c, fetchedAt) => {   // a catalog variant was chosen (automatically or by the admin): ID, then BOTH categories are verified by the server
            variantIn.value = String(c.id); lk.autoId = String(c.id); lk.ok = true;
            lkClearChoices();
            lk.seq++; lk.pBusy = false;      // any check still running for an earlier ID is now outdated
            lkRun('ID ' + c.id + ' detected: ');
        };
        const lkFind = async (force) => {   // product name + duration -> ID + price
            if (!lkActive()) return;
            const pname = lkProviderName(), dur = durIn.value.trim();
            if (!pname || !dur) return;      // nothing to search with yet
            const fkey = pname + '\u0000' + dur;
            if (lk.fBusy && lk.fkey === fkey && !force) return;   // the same search is already running
            const my = ++lk.fseq; lk.fkey = fkey;
            lk.fBusy = true; lkClearChoices();
            lkSetMsg('Searching the provider catalog for “' + pname + '” / ' + dur + '…', '');
            try {
                const r = await api('api/admin/products.php', { method: 'POST', body: { action: 'find_variant', provider: 'licencedashboard', product_name: pname, duration: dur, fresh: force ? 1 : 0 } });
                if (my !== lk.fseq || pname !== lkProviderName() || dur !== durIn.value.trim()) return;   // stale: name / duration changed meanwhile
                lk.fBusy = false;
                const cur = variantIn.value.trim();
                const manual = cur !== '' && cur !== lk.autoId;
                if (r.status === 'match') {
                    const c = r.candidates[0];
                    if (!manual || cur === String(c.id)) lkFill(c, r.fetched_at);
                    else {   // the admin's own ID is never overwritten automatically
                        lk.pending = { c, at: r.fetched_at };
                        lkUseId.textContent = 'USE ID ' + c.id; lkUseId.style.display = '';
                        lkSetMsg('Provider catalog: ID ' + c.id + ' = “' + c.product + '” ' + c.duration + ' (' + (c.currency === 'INR' ? 'INR ' : c.currency + ' ') + c.provider_price + ' → Normal ' + (c.normal_inr != null ? '₹' + c.normal_inr : 'rate missing') + ' / Reseller ' + (c.inr != null ? '₹' + c.inr : 'rate missing') + '). The ID in the field (' + cur + ') was NOT changed.', 'warn');
                    }
                } else if (r.status === 'ambiguous') {
                    lkSetMsg(r.message, 'warn');
                    lkChoices.style.display = 'flex';
                    r.candidates.forEach(c => {
                        const b = document.createElement('button'); b.type = 'button'; b.className = 'admin-small-btn';
                        b.style.height = 'auto'; b.style.padding = '8px 10px'; b.style.textAlign = 'left'; b.style.whiteSpace = 'normal';
                        b.textContent = 'ID ' + c.id + ' • ' + (c.label || c.product) + ' • ' + (c.problem ? 'price unusable (' + c.problem + ')' : (c.currency === 'INR' ? 'INR ' : c.currency + ' ') + c.provider_price + ' → N ' + (c.normal_inr != null ? '₹' + c.normal_inr : '—') + ' / R ' + (c.inr != null ? '₹' + c.inr : '—'));
                        if (c.problem) b.disabled = true;
                        b.addEventListener('click', () => { if (c.problem) return; lkFill(c, r.fetched_at); });
                        lkChoices.appendChild(b);
                    });
                } else {
                    let m = r.message || 'No match.';
                    if (r.status === 'none' && r.available_durations && r.available_durations.length) m += ' Available: ' + r.available_durations.join(', ') + '.';
                    if (r.status === 'none' && r.suggestions && r.suggestions.length) m += ' Similar names: ' + r.suggestions.join(', ') + '.';
                    lkSetMsg(m + ' Enter the Variant ID manually if needed.', r.status === 'duration_missing' ? '' : 'error');
                }
            } catch (err) {
                if (my !== lk.fseq) return;
                lk.fBusy = false;
                lkSetMsg('ID not detected: ' + (err.message || 'catalog search failed') + ' Press FIND ID to retry, or enter the ID manually.', 'error');
                lkUseId.textContent = 'FIND ID'; lkUseId.style.display = ''; lk.pending = { retry: true };
            }
        };
        const lkSchedule = () => { clearTimeout(lk.ftimer); lk.ftimer = setTimeout(() => lkFind(false), 900); };
        const lkInvalidate = () => {   // product name or duration changed: the previous match no longer applies
            clearTimeout(lk.timer); clearTimeout(lk.ftimer);
            if (lk.autoId !== null && variantIn.value.trim() === lk.autoId) variantIn.value = '';
            lk.autoId = null;
            lkReset();
        };
        const lookupUi = {
            refresh: (schedule) => {
                const on = lkActive();
                lkBox.style.display = on ? 'flex' : 'none';
                row.querySelector('.dur-pname-wrap').style.display = on ? 'block' : 'none';
                if (!on) { lkInvalidate(); return; }
                if (schedule) { lkInvalidate(); lkSchedule(); }
            },
            run: lkRun,
        };
        lk.nameChanged = () => { if (nameIn.value.trim() === '' && lkActive()) { lkInvalidate(); lkSchedule(); } };   // product name above changed
        lkBtn.addEventListener('click', () => { clearTimeout(lk.timer); lkRun(); });
        lkApply.addEventListener('click', () => {
            if (!lkApply.dataset.value) return;
            setAuto(priceIn, lkApply.dataset.value);
            lkApply.style.display = 'none';
            lkSetMsg('Price set to ₹' + priceIn.value + ' (provider price × rate).', 'ok');
        });
        lkUseId.addEventListener('click', () => {
            if (lk.pending && lk.pending.retry) { lkFind(true); return; }
            if (lk.pending && lk.pending.c) { const p = lk.pending; lkFill(p.c, p.at); }
        });
        priceIn.addEventListener('input', () => setOrigin(priceIn, priceIn.value.trim() === '' ? '' : 'manual'));
        if (rPriceEl) rPriceEl.addEventListener('input', () => setOrigin(rPriceEl, rPriceEl.value.trim() === '' ? '' : 'manual'));
        variantIn.addEventListener('input', () => {   // the admin typed an ID: it is manual from now on
            clearTimeout(lk.timer); clearTimeout(lk.ftimer);
            lk.autoId = null;
            lkReset();
            if (lkActive() && /^\d{1,10}$/.test(variantIn.value.trim())) lk.timer = setTimeout(lkRun, 800);   // verify its price once it has settled
        });
        const onNameOrDuration = () => { if (!lkActive()) return; lkInvalidate(); lkSchedule(); };
        nameIn.addEventListener('input', onNameOrDuration);
        durIn.addEventListener('input', onNameOrDuration);
        nameIn.addEventListener('focus', () => { if (lkActive()) loadProviderNames(); });

        const sourceSel = row.querySelector('.dur-source');
        const sync = () => {
            const apiMode = sourceSel.value === 'api';
            row.querySelector('.dur-api-wrap').style.display = apiMode ? 'block' : 'none';
            row.querySelector('.dur-local-wrap').style.display = apiMode ? 'none' : 'block';
            row.querySelector('.admin-api-provider-card').style.display = apiMode ? 'block' : 'none';
            { const cp = row.querySelector('.admin-custom-price'); if (cp) cp.style.display = apiMode ? 'block' : 'none'; }
            const pv = row.querySelector('.dur-provider');
            row.querySelector('.dur-ver-wrap').style.display = (apiMode && pv && pv.value === 'licencedashboard') ? 'block' : 'none';
            lookupUi.refresh(true);
        };
        sourceSel.addEventListener('change', sync);
        const toggle = row.querySelector('.admin-quantity-toggle');
        // Provider-specific identifier wording + quantity lock (PANELSTORE: product id "pid", 1 key per purchase).
        const provSel = row.querySelector('.dur-provider');
        const syncProvider = (fromUser) => {
            const ps = provSel && provSel.value === 'panelstore';
            const p4 = provSel && provSel.value === 'bantibhaiya';
            const p5 = provSel && provSel.value === 'licencedashboard';
            const p6 = provSel && provSel.value === 'vipsellerhub';
            row.querySelector('.dur-variant-label').textContent = p5 ? 'PROVIDER PRODUCT / SERVICE ID (digits only)' : (p6 ? 'PROVIDER VARIANT ID (digits only)' : ((ps || p4) ? 'PROVIDER PRODUCT ID (PID)' : 'DURATION ID / VARIANT ID'));
            row.querySelector('.dur-variant').placeholder = p5 ? 'e.g. 76' : (p6 ? 'e.g. 12' : (p4 ? 'Exact product PID' : (ps ? 'e.g. 1' : 'e.g. 157')));
            row.querySelector('.dur-ver-wrap').style.display = (p5 && sourceSel.value === 'api') ? 'block' : 'none';
            lookupUi.refresh(!!fromUser);
            if (ps) {
                toggle.classList.remove('on'); toggle.textContent = 'OFF';
                toggle.nextElementSibling.textContent = 'PANELSTORE delivers 1 key per purchase, so quantity is locked to 1.';
                toggle.disabled = true;
            } else {
                toggle.disabled = false;
                if (fromUser) toggle.nextElementSibling.textContent = toggle.classList.contains('on') ? 'User can increase quantity (API maximum is 20).' : 'Quantity is shown but locked to 1 purchase.';
            }
        };
        if (provSel) { provSel.addEventListener('change', () => syncProvider(true)); syncProvider(false); }
        lookupUi.refresh(false);
        toggle.addEventListener('click', () => {
            const on = !toggle.classList.contains('on');
            toggle.classList.toggle('on', on);
            toggle.textContent = on ? 'ON' : 'OFF';
            toggle.nextElementSibling.textContent = on ? 'User can increase quantity (API maximum is 20).' : 'Quantity is shown but locked to 1 purchase.';
        });
        const statusBtn = row.querySelector('.dur-enabled-toggle');
        statusBtn.addEventListener('click', () => {
            const on = !statusBtn.classList.contains('on');
            statusBtn.classList.toggle('on', on);
            statusBtn.textContent = on ? 'ON' : 'OFF';
            statusBtn.nextElementSibling.textContent = on ? 'ON: shown in the Store and can be purchased.' : 'OFF: hidden from the Store and cannot be purchased. Other durations of this product are not affected.';
        });
        const keysBox = row.querySelector('.dur-keys');
        if (keysBox) keysBox.addEventListener('input', () => {
            const n = keysBox.value.split(/\r?\n/).map(x=>x.trim()).filter(Boolean).length;
            row.querySelector('.admin-key-count').textContent = n + ' key(s) in this duration';
        });
        builder.appendChild(row);
    }

    document.getElementById('adminAddDuration').addEventListener('click', () => addDurationRow({}));

    document.getElementById('adminSaveCategory').addEventListener('click', async () => {
        const input = document.getElementById('adminNewCategoryName');
        const name = input.value.trim();
        if (!name) return;
        try {
            const posEl = document.getElementById('adminNewCategoryPosition');
            const body = { action: 'create', name };
            if (posEl && posEl.value.trim() !== '') body.sort_order = posEl.value.trim();
            await api('api/admin/categories.php', { method: 'POST', body });
            if (posEl) posEl.value = '';
            input.value = '';
            loadProducts();
            toast('Category created.', { duration: 1800 });
        } catch (err) { toast(err.message, { variant: 'danger' }); }
    });

    document.getElementById('adminSaveProduct').addEventListener('click', async () => {
        const name = document.getElementById('adminProductName').value.trim();
        const categoryId = parseInt(document.getElementById('adminProductCategory').value, 10);
        if (!name || !categoryId) { document.getElementById('adminProductMessage').textContent = 'Name and category are required.'; return; }
        const plans = Array.from(document.querySelectorAll('#adminDurationBuilder .admin-duration-card')).map(row => {
            const source = row.querySelector('.dur-source').value;
            const keys = source === 'local'
                ? row.querySelector('.dur-keys').value.split(/\r?\n/).map(x => x.trim()).filter(Boolean)
                : [];
            return {
                id: parseInt(row.querySelector('.dur-existing-id').value, 10) || 0,
                duration: row.querySelector('.dur-name').value.trim(),
                price: parseFloat(row.querySelector('.dur-price').value || '0'),
                reseller_price: row.querySelector('.dur-rprice') && row.querySelector('.dur-rprice').value !== '' ? parseFloat(row.querySelector('.dur-rprice').value) : '',
                source,
                provider_variant_id: row.querySelector('.dur-variant').value.trim(),
                provider_api_version: (row.querySelector('.dur-provider')?.value === 'licencedashboard' && source === 'api') ? (row.querySelector('.dur-apiver')?.value || '') : '',
                provider: row.querySelector('.dur-provider')?.value || 'primary',
                allow_quantity: row.querySelector('.admin-quantity-toggle')?.classList.contains('on') ? 1 : 0,
                enabled: row.querySelector('.dur-enabled-toggle')?.classList.contains('on') ? 1 : 0,
                ...(source === 'api' && row.querySelector('.dur-custom-on') ? { custom_price_enabled: row.querySelector('.dur-custom-on').checked ? 1 : 0, custom_price: row.querySelector('.dur-custom-price')?.value || '', reseller_custom_price_enabled: row.querySelector('.dur-rcustom-on')?.checked ? 1 : 0, reseller_custom_price: row.querySelector('.dur-rcustom-price')?.value || '' } : {}),
                keys,
            };
        }).filter(p => p.duration);
        if (!plans.length) { document.getElementById('adminProductMessage').textContent = 'Add at least one duration.'; return; }
        // v65: a NEW Provider 5 duration needs BOTH prices. An empty Reseller price would silently become the Normal price (legacy fallback) and could hide an
        // invalid / missing Reseller calculation, so it is refused here.
        const noR = plans.find(p => p.source === 'api' && p.provider === 'licencedashboard' && !p.id && p.price > 0 && p.reseller_price === '');
        if (noR) { document.getElementById('adminProductMessage').textContent = 'Enter the Reseller price for "' + noR.duration + '" (or press CHECK AGAIN to calculate it). It is not copied from the Normal User price.'; return; }
        if (Array.from(document.querySelectorAll('#adminDurationBuilder .admin-duration-card')).some(rw => rw._lookup && rw._lookup.busy)) {
            document.getElementById('adminProductMessage').textContent = 'A provider price check is still running. Wait for it to finish, then save.'; return;
        }
        try {
            const r = await api('api/admin/products.php', { method: 'POST', body: {
                action: 'save', id: currentEditingProductId, category_id: categoryId, name, plans, features: collectFeatures(),
                sort_order: (document.getElementById('adminProductOrder')?.value || '').trim(),
            }});
            let imageNote = '';
            // Product is saved first; the image is a separate step so the existing save/stock logic is unchanged.
            if (productImageState.file || productImageState.clear) {
                try {
                    const fd = new FormData();
                    fd.append('id', String(r.id));
                    if (productImageState.file) {
                        fd.append('action', 'upload');
                        fd.append('image', await prepareProductImageUpload(productImageState.file));
                    } else {
                        fd.append('action', 'clear');
                    }
                    const ir = await fetch(window.KP.baseUrl + '/api/admin/product-image.php', {
                        method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.KP.csrf }, body: fd,
                    });
                    const raw = await ir.text();
                    let ij = null;
                    try { ij = JSON.parse(raw); } catch (e) { throw new Error('Server returned an invalid upload response. Check uploads/products permissions and PHP upload limits.'); }
                    if (!ir.ok || !ij.ok) throw new Error(ij.error || ('Image upload failed (' + ir.status + ').'));
                } catch (imgErr) {
                    imageNote = ' Product saved, but the image failed: ' + (imgErr.message || 'upload error');
                }
            }
            let videoNote = '';
            if (productVideoState.file || productVideoState.clear) {
                try { await uploadProductVideo(r.id, productVideoState.file); }
                catch (vErr) { videoNote = ' Product saved, but the video failed: ' + (vErr.message || 'upload error'); }
            }
            document.getElementById('adminProductMessage').textContent = 'Saved. ' + Number(r.added_keys || 0) + ' key(s) added.' + imageNote + videoNote;
            if (Array.isArray(r.price_notes) && r.price_notes.length) { document.getElementById('adminProductMessage').textContent += ' ' + r.price_notes.join(' '); toast(r.price_notes[0], { duration: 6000 }); }
            currentEditingProductId = 0;
            productImageState = { file: null, clear: false };
            const imgIn = document.getElementById('adminProductImageFile'); if (imgIn) imgIn.value = '';
            setProductImagePreview('');
            productVideoState = { file: null, clear: false };
            const vIn2 = document.getElementById('adminProductVideoFile'); if (vIn2) vIn2.value = '';
            setProductVideoPreview('');
            const ordIn = document.getElementById('adminProductOrder'); if (ordIn) ordIn.value = '';
            renderFeatureBuilder([]);
            document.getElementById('adminProductName').value = '';
            document.getElementById('adminDurationBuilder').innerHTML = '';
            loadProducts();
            toast('Product saved.', { duration: 2000 });
        } catch (err) { document.getElementById('adminProductMessage').textContent = err.message; }
    });

    document.getElementById('adminEditSelectedProduct').addEventListener('click', () => {
        const name = document.getElementById('adminProductName').value.trim();
        if (!name) return;
        // handled by clicking EDIT on the card
    });


    function renderRestockOptions(products) {
        const sel = document.getElementById('adminRestockProduct');
        sel.innerHTML = '<option value="">SELECT PRODUCT</option>';
        products.forEach(p => {
            sel.appendChild(new Option(p.name, String(p.id)));
        });
        const syncRestockMode = () => {
            const prod = products.find(p => String(p.id) === sel.value);
            const durSel = document.getElementById('adminRestockDuration');
            const selected = prod?.plans?.find(pl => String(pl.id) === String(durSel.value));
            const apiMode = selected && selected.source === 'api';
            const wrap = document.getElementById('adminRestockKeysWrap');
            const btn = document.getElementById('adminRestockBtn');
            if (wrap) wrap.style.display = apiMode ? 'none' : '';
            if (btn) {
                btn.style.display = apiMode ? 'none' : '';
                btn.textContent = apiMode ? 'API STOCK' : 'ADD KEYS TO STOCK';
            }
        };
        sel.onchange = () => {
            const prod = products.find(p => String(p.id) === sel.value);
            const durSel = document.getElementById('adminRestockDuration');
            durSel.innerHTML = '<option value="">SELECT DURATION</option>';
            if (!prod) { durSel.disabled = true; syncRestockMode(); return; }
            prod.plans.forEach(pl => {
                durSel.appendChild(new Option(pl.duration + ' — ' + (pl.source === 'api' ? 'API' : 'LOCAL'), String(pl.id)));
            });
            durSel.disabled = false;
            syncRestockMode();
        };
        document.getElementById('adminRestockDuration').onchange = syncRestockMode;
    }

    document.getElementById('adminRestockBtn').addEventListener('click', async () => {
        const planId = parseInt(document.getElementById('adminRestockDuration').value, 10);
        const keys = document.getElementById('adminRestockKeys').value;
        if (!planId || !keys.trim()) { document.getElementById('adminRestockMessage').textContent = 'Select plan and paste keys.'; return; }
        try {
            const r = await api('api/admin/restock.php', { method: 'POST', body: { plan_id: planId, keys } });
            document.getElementById('adminRestockMessage').textContent = r.added + ' key(s) added successfully.';
            document.getElementById('adminRestockKeys').value = '';
            loadProducts();
        } catch (err) { document.getElementById('adminRestockMessage').textContent = err.message; }
    });

    /* ------------------ Orders ------------------ */
    async function loadWebsiteOrders() {
        try {
            const r = await api('api/admin/orders-website.php');
            const s = r.summary;
            setText('adminSiteOrderCount', s.total_orders);
            setText('adminSiteKeysSold', s.keys_sold);
            setText('adminSiteSalesValue', money(s.sales_value));
            setText('adminSiteAvailableStock', s.available_stock);

            // Inventory
            const inv = document.getElementById('adminLocalInventoryTable');
            inv.innerHTML = '';
            if (!r.inventory.length) inv.innerHTML = '<div class="admin-order-empty">No local inventory.</div>';
            else inv.innerHTML = '<div class="admin-orders-table-wrap"><table class="admin-orders-table"><thead><tr><th>PRODUCT</th><th>DURATION</th><th>PRICE</th><th>AVAILABLE</th><th>DELIVERED</th></tr></thead><tbody>' +
                r.inventory.map(x => `<tr><td><strong>${x.product}</strong><span class="muted">${x.category}</span></td><td>${x.duration}</td><td class="gold-text">${money(x.price)}</td><td class="green-text">${x.available}</td><td>${x.delivered}</td></tr>`).join('') +
                '</tbody></table></div>';

            // Filter
            const filter = document.getElementById('adminWebsiteOrderFilter');
            const currentFilter = filter.value;
            const uniqProducts = [...new Set(r.orders.map(o => o.product))];
            filter.innerHTML = '<option value="all">ALL PRODUCTS</option>' + uniqProducts.map(p => `<option value="${encodeURIComponent(p)}">${p}</option>`).join('');
            if (uniqProducts.some(p => encodeURIComponent(p) === currentFilter)) filter.value = currentFilter;
            const filtered = filter.value === 'all' ? r.orders : r.orders.filter(o => encodeURIComponent(o.product) === filter.value);

            const hist = document.getElementById('adminWebsiteOrderHistory');
            hist.innerHTML = '';
            if (!filtered.length) hist.innerHTML = '<div class="admin-order-empty">No orders found.</div>';
            else hist.innerHTML = '<div class="admin-orders-table-wrap"><table class="admin-orders-table"><thead><tr><th>DATE</th><th>USER</th><th>PRODUCT</th><th>DURATION</th><th>QTY</th><th>UNIT</th><th>TOTAL</th><th>ORDER ID</th></tr></thead><tbody>' +
                filtered.map(o => `<tr><td>${o.created_at}</td><td><strong>${o.username}</strong></td><td>${o.product}</td><td>${o.duration}</td><td>${o.quantity}</td><td class="gold-text">${money(o.unit_price)}</td><td class="gold-text">${money(o.total_price)}</td><td><span class="admin-order-pill">${o.order_ref}</span></td></tr>`).join('') +
                '</tbody></table></div>';
        } catch (e) { toast(e.message || 'Admin request failed.', {variant:'danger'}); }
    }

    async function loadApiOrders() {
        try {
            const r = await api('api/admin/orders-api.php');
            setText('adminApiOrderPageCount', r.summary.total_orders);
            setText('adminApiKeysSold', r.summary.keys_sold);
            setText('adminApiSalesValue', money(r.summary.sales_value));
            setText('adminApiUsersCount', r.summary.api_users);
            const hist = document.getElementById('adminApiOrderHistory');
            hist.innerHTML = '';
            if (!r.orders.length) hist.innerHTML = '<div class="admin-order-empty">No API orders yet.</div>';
            else hist.innerHTML = '<div class="admin-orders-table-wrap"><table class="admin-orders-table"><thead><tr><th>DATE</th><th>USER</th><th>PRODUCT</th><th>DURATION</th><th>VARIANT</th><th>QTY</th><th>TOTAL</th><th>STATUS</th><th>UPSTREAM ORDER</th></tr></thead><tbody>' +
                r.orders.map(o => `<tr><td>${o.created_at}</td><td><strong>${o.username}</strong></td><td>${o.product}</td><td>${o.duration}</td><td class="gold-text">${o.variant_id || '—'}</td><td>${o.quantity}</td><td class="gold-text">${money(o.total_price)}</td><td>${o.status}</td><td><span class="admin-order-pill api">${o.upstream_order_id || '—'}</span></td></tr>`).join('') +
                '</tbody></table></div>';
        } catch (e) { toast(e.message || 'Admin request failed.', {variant:'danger'}); }
    }

    /* ------------------ Deposits (monitor: see Operations block below) ------------------ */
    function escHtml(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }


    /* ------------------ ALL ORDERS (website + API, read-only list; ban/unban via explicit endpoint) ------------------ */
    const ao = { page: 1, source: 'all', inited: false, seq: 0, timer: null };
    const aoEl = id => document.getElementById(id);
    const aoStatusLabel = s => ({ completed: 'COMPLETED', pending: 'PENDING', failed: 'FAILED', refunded: 'REFUNDED' }[s] || String(s).toUpperCase());
    function aoQuery(page) {
        const q = new URLSearchParams();
        q.set('page', String(page || ao.page));
        q.set('per_page', '25');
        q.set('source', ao.source);
        q.set('status', aoEl('aoStatus').value);
        q.set('provider', aoEl('aoProvider').value);
        q.set('date', aoEl('aoDate').value);
        q.set('sort', aoEl('aoSort').value);
        if (aoEl('aoDate').value === 'custom') { q.set('from', aoEl('aoFrom').value); q.set('to', aoEl('aoTo').value); }
        const k = aoEl('aoKey').value.trim(), u = aoEl('aoUser').value.trim(), o = aoEl('aoOrder').value.trim();
        if (k) q.set('key', k); if (u) q.set('username', u); if (o) q.set('order', o);
        return q.toString();
    }
    function aoInit() {
        if (!ao.inited) {
            ao.inited = true;
            const reload = () => { ao.page = 1; aoLoad(); };
            const debounced = () => { clearTimeout(ao.timer); ao.timer = setTimeout(reload, 350); };
            ['aoKey', 'aoUser', 'aoOrder'].forEach(id => aoEl(id).addEventListener('input', debounced));
            ['aoStatus', 'aoProvider', 'aoSort', 'aoFrom', 'aoTo'].forEach(id => aoEl(id).addEventListener('change', reload));
            aoEl('aoDate').addEventListener('change', () => { aoEl('aoCustom').hidden = aoEl('aoDate').value !== 'custom'; reload(); });
            aoEl('aoSource').addEventListener('click', e => {
                const b = e.target.closest('button[data-v]'); if (!b) return;
                ao.source = b.dataset.v;
                aoEl('aoSource').querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
                reload();
            });
            aoEl('aoReset').addEventListener('click', () => {
                ['aoKey', 'aoUser', 'aoOrder', 'aoFrom', 'aoTo'].forEach(id => { aoEl(id).value = ''; });
                aoEl('aoStatus').value = 'all'; aoEl('aoProvider').value = 'all'; aoEl('aoDate').value = 'all'; aoEl('aoSort').value = 'newest';
                aoEl('aoCustom').hidden = true; ao.source = 'all';
                aoEl('aoSource').querySelectorAll('button').forEach(x => x.classList.toggle('on', x.dataset.v === 'all'));
                reload();
            });
            aoEl('aoModalClose').addEventListener('click', aoCloseModal);
            aoEl('aoModal').addEventListener('click', e => { if (e.target === aoEl('aoModal')) aoCloseModal(); });
        }
        aoLoad();
    }
    function aoCloseModal() { aoEl('aoModal').hidden = true; aoEl('aoModalBody').innerHTML = ''; }
    async function aoLoad() {
        const my = ++ao.seq;
        aoEl('aoCount').textContent = 'Loading…';
        try {
            const r = await api('api/admin/all-orders.php?' + aoQuery(ao.page));
            if (my !== ao.seq) return;
            aoRenderSummary(r.summary);
            const sel = aoEl('aoProvider');
            if (sel.options.length <= 1) {
                sel.innerHTML = '<option value="all">ALL PROVIDERS</option>' + (r.providers || []).map(p => `<option value="${escHtml(p.key)}">PROVIDER ${p.number}</option>`).join('') + '<option value="local">LOCAL STOCK</option>';
            }
            ao.page = r.pagination.page;
            aoRenderList(r.orders);
            aoRenderPager(r.pagination);
        } catch (err) {
            if (my !== ao.seq) return;
            aoEl('aoCount').textContent = '';
            aoEl('aoList').innerHTML = '<div class="admin-order-empty">' + escHtml(err.message || 'Could not load orders.') + '</div>';
            aoEl('aoPager').innerHTML = '';
        }
    }
    function aoRenderSummary(s) {
        const cells = [['TOTAL ORDERS', s.total], ['WEBSITE', s.website], ['API', s.api], ['COMPLETED', s.completed], ['PENDING', s.pending], ['FAILED', s.failed], ['REFUNDED', s.refunded]];
        aoEl('aoSummary').innerHTML = cells.map(c => `<div class="ao-stat"><span>${c[0]}</span><b>${Number(c[1] || 0).toLocaleString()}</b></div>`).join('');
    }
    function aoRenderPager(p) {
        aoEl('aoCount').textContent = p.total ? `Showing ${p.from.toLocaleString()}-${p.to.toLocaleString()} of ${p.total.toLocaleString()} orders` : 'No orders match.';
        const box = aoEl('aoPager'); box.innerHTML = '';
        if (p.pages <= 1) return;
        const mk = (label, page, on, dis) => { const b = document.createElement('button'); b.type = 'button'; b.textContent = label; if (on) b.classList.add('on'); if (dis) b.disabled = true; else b.addEventListener('click', () => { ao.page = page; aoLoad(); window.scrollTo({ top: aoEl('aoCount').offsetTop - 80, behavior: 'smooth' }); }); box.appendChild(b); };
        mk('‹ PREV', p.page - 1, false, p.page <= 1);
        const nums = new Set([1, p.pages, p.page - 1, p.page, p.page + 1]);
        let last = 0;
        [...nums].filter(n => n >= 1 && n <= p.pages).sort((a, b) => a - b).forEach(n => { if (last && n - last > 1) { const d = document.createElement('span'); d.textContent = '…'; box.appendChild(d); } mk(String(n), n, n === p.page, false); last = n; });
        mk('NEXT ›', p.page + 1, false, p.page >= p.pages);
    }
    function aoSourceBadge(o) {
        const web = o.source === 'website';
        const prov = o.provider.key === 'local' ? 'LOCAL STOCK' : ('PROVIDER ' + o.provider.number);
        return `<span class="ao-badge ${web ? 'web' : 'api'}">${web ? 'WEBSITE • STORE' : 'API'}</span><span class="ao-badge prov">${escHtml(prov)}</span>`;
    }
    function aoRenderList(orders) {
        const box = aoEl('aoList'); box.innerHTML = '';
        if (!orders.length) { box.innerHTML = '<div class="admin-order-empty">No orders found.</div>'; return; }
        orders.forEach(o => {
            const card = document.createElement('article');
            card.className = 'ao-card';
            const banned = o.account_status === 'banned';
            card.innerHTML = `
                <div class="ao-top"><span class="ao-ref"></span><span class="ao-status s-${escHtml(o.status)}">${aoStatusLabel(o.status)}${o.failure_label ? '<small class="ao-status-reason">' + escHtml(o.failure_label) + '</small>' : ''}</span></div>
                <div class="ao-prod"><span aria-hidden="true">🎬</span><strong></strong></div>
                <div class="ao-keys"></div>
                <div class="ao-pills">${aoSourceBadge(o)}<span class="ao-pill dur"></span><span class="ao-pill qty"></span><span class="ao-pill amt"></span></div>
                <div class="ao-user"><span>👤 <b class="un"></b> <i>ID ${o.user_id}</i></span><span class="ao-acct ${banned ? 'banned' : 'active'}">ACCOUNT: ${banned ? 'BANNED' : 'ACTIVE'}</span></div>
                <div class="ao-date">🛒 ${escHtml(o.created_at)}</div>
                <div class="ao-actions">
                    <button type="button" data-a="detail">VIEW DETAILS</button>
                    <button type="button" data-a="user">VIEW USER</button>
                    <button type="button" class="${banned ? 'unban' : 'ban'}" data-a="ban">${banned ? 'UNBAN USER' : 'BAN USER'}</button>
                </div>`;
            card.querySelector('.ao-ref').textContent = '#' + o.order_ref;
            card.querySelector('.ao-prod strong').textContent = o.product || '—';
            card.querySelector('.dur').textContent = o.duration || '—';
            card.querySelector('.qty').textContent = '×' + o.quantity;
            card.querySelector('.amt').textContent = money(o.total_price);
            card.querySelector('.un').textContent = o.username;
            const kb = card.querySelector('.ao-keys');
            if (!o.keys.length) { const n = document.createElement('div'); n.className = 'ao-nokey'; n.textContent = o.status === 'completed' ? 'No key stored for this order' : 'No key (order ' + o.status + ')'; kb.appendChild(n); }
            o.keys.forEach(k => kb.appendChild(aoKeyRow(k)));
            card.querySelector('[data-a="detail"]').addEventListener('click', () => aoOpenDetail(o.id));
            card.querySelector('[data-a="user"]').addEventListener('click', () => aoOpenUser(o.user_id));
            card.querySelector('[data-a="ban"]').addEventListener('click', () => aoConfirmBan(o.user_id, o.username, banned));
            box.appendChild(card);
        });
    }
    function aoKeyRow(k) {
        const row = document.createElement('div'); row.className = 'ao-key';
        const t = document.createElement('span'); t.className = 'ao-key-txt'; t.textContent = k;
        const b = document.createElement('button'); b.type = 'button'; b.className = 'ao-copy'; b.setAttribute('aria-label', 'Copy key'); b.textContent = '📋';
        b.addEventListener('click', async () => {
            try {
                if (window.KP && typeof window.KP.copyText === 'function') await window.KP.copyText(k); else await navigator.clipboard.writeText(k);
                b.textContent = '✓'; b.classList.add('copied'); setTimeout(() => { b.textContent = '📋'; b.classList.remove('copied'); }, 1400);
            } catch (e) { toast('Could not copy the key.', { variant: 'danger' }); }
        });
        row.append(t, b);
        return row;
    }
    function aoModal(html) { aoEl('aoModalBody').innerHTML = html; aoEl('aoModal').hidden = false; }
    function aoKV(rows) { return '<dl class="ao-kv">' + rows.map(r => `<dt>${r[0]}</dt><dd>${r[1]}</dd>`).join('') + '</dl>'; }
    async function aoOpenDetail(id) {
        aoModal('<div class="ao-loading">Loading…</div>');
        try {
            const { order: o } = await api('api/admin/all-orders.php?view=order&id=' + encodeURIComponent(id));
            const rows = [
                ['ORDER', escHtml('#' + o.order_ref)],
                ['SOURCE', o.source === 'website' ? 'WEBSITE ORDER' : 'API ORDER'],
                ['FULFILMENT', o.plan_source === 'api' ? 'Provider ' + o.provider.number + ' • ' + escHtml(o.provider.label) : 'Local stock'],
                ['USER', escHtml(o.username) + ' (ID ' + o.user_id + ')'],
                ['ACCOUNT', o.account_status === 'banned' ? 'BANNED' : 'ACTIVE'],
                ['PRODUCT', escHtml(o.product)],
                ['PLAN / DURATION', escHtml(o.duration)],
                ['QUANTITY', String(o.quantity)],
                ['UNIT PRICE', money(o.unit_price)],
                ['TOTAL', money(o.total_price)],
                ['STATUS', aoStatusLabel(o.status) + (o.failure_label ? '<br><b class="ao-status-reason">' + escHtml(o.failure_label) + '</b>' : '')],
                ['DATE / TIME', escHtml(o.created_at)],
            ];
            if (o.failure_reason) rows.push(['FAILURE REASON', escHtml(o.failure_reason)]);
            if (o.refund) rows.push(['REFUND', money(o.refund.amount) + ' • ' + escHtml(o.refund.at)]);
            if (o.upstream_order_id) rows.splice(3, 0, ['PROVIDER ORDER REF', escHtml(o.upstream_order_id)]);
            aoModal('<h3>ORDER DETAILS</h3>' + aoKV(rows) + '<div class="ao-detail-keys"><b>KEYS (' + o.keys.length + ')</b><div id="aoDetailKeys"></div></div>');
            const kb = aoEl('aoDetailKeys');
            if (!o.keys.length) kb.innerHTML = '<div class="ao-nokey">No key stored for this order.</div>'; else o.keys.forEach(k => kb.appendChild(aoKeyRow(k)));
        } catch (err) { aoModal('<div class="admin-order-empty">' + escHtml(err.message) + '</div>'); }
    }
    async function aoOpenUser(id) {
        aoModal('<div class="ao-loading">Loading…</div>');
        try {
            const { user: u } = await api('api/admin/all-orders.php?view=user&user_id=' + encodeURIComponent(id));
            const t = u.totals;
            aoModal('<h3>USER DETAILS</h3>' + aoKV([
                ['USERNAME', escHtml(u.username)], ['USER ID', String(u.id)], ['EMAIL', u.email ? escHtml(u.email) : '—'],
                ['ROLE', escHtml(u.role).toUpperCase()], ['ACCOUNT', u.account_status === 'banned' ? 'BANNED' : 'ACTIVE'],
                ['WALLET BALANCE', money(u.balance)], ['REGISTERED', escHtml(u.created_at || '—')],
                ['LAST LOGIN', escHtml(u.last_login_at || '—')], ['LAST ACTIVE', escHtml(u.last_active_at || '—')],
                ['TOTAL ORDERS', String(t.orders)], ['SUCCESSFUL', String(t.successful) + ' (website ' + t.website + ' • API ' + t.api + ')'],
                ['FAILED / REFUNDED / PENDING', t.failed + ' / ' + t.refunded + ' / ' + t.pending],
                ['KEYS (SUCCESSFUL)', String(t.keys)], ['TOTAL SPENT (SUCCESSFUL)', money(t.spent)],
            ]) + '<div class="ao-modal-actions"><button type="button" id="aoUserOrders">VIEW THEIR ORDERS</button><button type="button" id="aoUserBan" class="' + (u.account_status === 'banned' ? 'unban' : 'ban') + '">' + (u.account_status === 'banned' ? 'UNBAN USER' : 'BAN USER') + '</button></div>');
            aoEl('aoUserOrders').addEventListener('click', () => { aoCloseModal(); aoEl('aoUser').value = u.username; ao.page = 1; aoLoad(); });
            aoEl('aoUserBan').addEventListener('click', () => aoConfirmBan(u.id, u.username, u.account_status === 'banned'));
        } catch (err) { aoModal('<div class="admin-order-empty">' + escHtml(err.message) + '</div>'); }
    }
    function aoConfirmBan(userId, username, isBanned) {
        const verb = isBanned ? 'UNBAN' : 'BAN';
        aoModal(`<h3>${verb} USER?</h3>` + aoKV([['USERNAME', escHtml(username)], ['USER ID', String(userId)], ['CURRENT STATUS', isBanned ? 'BANNED' : 'ACTIVE']]) +
            `<p class="ao-warn">Are you sure you want to ${verb} this user? ${isBanned ? 'Their access is restored.' : 'They are signed out and blocked on their next request.'} Orders and keys are never deleted.</p>` +
            `<div class="ao-modal-actions"><button type="button" id="aoCancel">CANCEL</button><button type="button" id="aoConfirm" class="${isBanned ? 'unban' : 'ban'}">CONFIRM ${verb}</button></div>`);
        aoEl('aoCancel').addEventListener('click', aoCloseModal);
        aoEl('aoConfirm').addEventListener('click', async () => {
            const btn = aoEl('aoConfirm'); btn.disabled = true;
            try {
                await api('api/admin/user-ban.php', { method: 'POST', body: { user_id: userId, banned: !isBanned } });
                toast(isBanned ? 'User unbanned.' : 'User banned.', { duration: 1800 });
                aoCloseModal(); aoLoad();
            } catch (err) { btn.disabled = false; toast(err.message, { variant: 'danger' }); }
        });
    }

    /* ------------------ Paytm deposit settings ------------------ */
    async function loadPaytmSettings() {
        const card = document.getElementById('adminPaytmCard');
        if (!card) return;
        try {
            const r = await api('api/admin/settings.php');
            const s = r.settings;
            document.getElementById('adminPaytmMid').value = s.paytm_mid || '';
            document.getElementById('adminPaytmUpi').value = s.paytm_upi_id || '';
            document.getElementById('adminDepositMin').value = s.deposit_min_amount || '10';
            document.getElementById('adminDepositMax').value = s.deposit_max_amount || '100000';
            const on = !!s.paytm_enabled;
            const t = document.getElementById('adminPaytmToggle');
            t.classList.toggle('on', on); t.textContent = on ? 'ON' : 'OFF';
            document.getElementById('adminPaytmStatus').textContent = s.paytm_configured ? (on ? 'ACTIVE' : 'CONFIGURED • OFF') : 'NOT CONFIGURED';
        } catch (e) { toast(e.message || 'Admin request failed.', { variant: 'danger' }); }
    }
    function paytmMsg(text, isError) {
        const m = document.getElementById('adminPaytmMessage');
        if (!m) return;
        m.textContent = text; m.classList.toggle('error', !!isError);
    }
    document.getElementById('adminPaytmSave')?.addEventListener('click', async () => {
        const mid = document.getElementById('adminPaytmMid').value.trim();
        const upi = document.getElementById('adminPaytmUpi').value.trim();
        if (!mid || !upi) { paytmMsg('Enter both Paytm MID and UPI ID.', true); return; }
        try {
            await api('api/admin/settings.php', { method: 'POST', body: { action: 'save_paytm', mid, upi_id: upi } });
            paytmMsg('Paytm MID + UPI ID saved.'); loadPaytmSettings();
        } catch (err) { paytmMsg(err.message || 'Could not save Paytm settings.', true); }
    });
    document.getElementById('adminPaytmToggle')?.addEventListener('click', async () => {
        const btn = document.getElementById('adminPaytmToggle');
        const on = !btn.classList.contains('on');
        try {
            await api('api/admin/settings.php', { method: 'POST', body: { action: 'toggle_paytm', enabled: on } });
            paytmMsg('Paytm deposits turned ' + (on ? 'ON' : 'OFF') + '.'); loadPaytmSettings();
        } catch (err) { paytmMsg(err.message || 'Could not update Paytm setting.', true); }
    });
    document.getElementById('adminDepositLimitsSave')?.addEventListener('click', async () => {
        const min = document.getElementById('adminDepositMin').value;
        const max = document.getElementById('adminDepositMax').value;
        try {
            await api('api/admin/settings.php', { method: 'POST', body: { action: 'save_deposit_limits', min, max } });
            paytmMsg('Deposit limits saved.'); loadPaytmSettings();
        } catch (err) { paytmMsg(err.message || 'Could not save deposit limits.', true); }
    });

    /* ------------------ v68: ZapUPI gateway settings (own endpoint: api/admin/zapupi.php; never touches Paytm) ------------------ */
    function zapMsg(text, isError) {
        const m = document.getElementById('adminZapMessage'); if (!m) return;
        m.textContent = text; m.classList.toggle('error', !!isError);
    }
    function zapRender(z) {
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
        set('adminZapEndpoint', z.endpoint || ''); set('adminZapName', z.name || ''); set('adminZapMin', z.min_amount || ''); set('adminZapMax', z.max_amount || '');
        set('adminZapInstr', z.instructions || ''); set('adminZapMode', z.mode || 'production'); set('adminZapWebhook', z.webhook_url || '');
        set('adminZapKey', '');   // the key is write-only: only a mask is ever shown
        const note = document.getElementById('adminZapKeyNote');
        if (note) note.textContent = z.key_masked ? '(saved: ' + z.key_masked + ')' : '(not set)';
        const t = document.getElementById('adminZapToggle');
        if (t) { t.classList.toggle('on', !!z.enabled); t.textContent = z.enabled ? 'ON' : 'OFF'; }
        setText('adminZapStatus', z.status_label || '—');
        const la = z.last_api;
        const last = document.getElementById('adminZapLast');
        if (last) last.textContent = la ? ('Last API response: ' + la.at + ' • ' + la.action + ' • ' + (la.ok ? 'OK' : 'ERROR') + (la.http ? ' (HTTP ' + la.http + ')' : '') + ' • ' + (la.message || '')) : 'Last API response: —';
        if (z.webhook_url && !z.webhook_https) zapMsg('Webhook URL is not https. Set APP_URL to your https address before going live.', true);
    }
    async function loadZapupiSettings() {
        if (!document.getElementById('adminZapupiCard')) return;
        try { const r = await api('api/admin/zapupi.php'); zapRender(r.zapupi); }
        catch (e) { toast(e.message || 'Admin request failed.', { variant: 'danger' }); }
    }
    document.getElementById('adminZapToggle')?.addEventListener('click', async () => {
        const btn = document.getElementById('adminZapToggle');
        const on = !btn.classList.contains('on');
        try { const r = await api('api/admin/zapupi.php', { method: 'POST', body: { action: 'toggle', enabled: on } }); zapRender(r.zapupi); zapMsg('ZapUPI turned ' + (on ? 'ON' : 'OFF') + '. Paytm is unchanged.'); }
        catch (err) { zapMsg(err.message || 'Could not update ZapUPI.', true); }
    });
    document.getElementById('adminZapSave')?.addEventListener('click', async () => {
        const v = id => (document.getElementById(id)?.value || '');
        try {
            const r = await api('api/admin/zapupi.php', { method: 'POST', body: { action: 'save', api_key: v('adminZapKey').trim(), endpoint: v('adminZapEndpoint').trim(), name: v('adminZapName').trim(),
                min_amount: v('adminZapMin'), max_amount: v('adminZapMax'), instructions: v('adminZapInstr'), mode: v('adminZapMode') } });
            zapRender(r.zapupi); zapMsg('ZapUPI settings saved. Paytm settings were not changed.');
        } catch (err) { zapMsg(err.message || 'Could not save ZapUPI settings.', true); }
    });
    document.getElementById('adminZapTest')?.addEventListener('click', async () => {
        zapMsg('Testing connection…');
        try { const r = await api('api/admin/zapupi.php', { method: 'POST', body: { action: 'test' } }); zapRender(r.zapupi); zapMsg('Reachable (HTTP ' + r.http + '). Provider said: ' + (r.provider_message || r.provider_status || '—')); }
        catch (err) { zapMsg(err.message || 'Connection test failed.', true); loadZapupiSettings(); }
    });

    /* ------------------ API providers (registry used by the product form) ------------------ */
    window.KPAdminProviders = null;   // [{key,label,enabled,configured}] from the server
    async function loadProviders() {
        try {
            const r = await api('api/admin/settings.php');
            window.KPAdminProviders = r.settings.providers || null;
        } catch (e) {}
    }
    loadProviders();

    /* ------------------ API Settings ------------------ */
    async function loadApiSettings() {
        try {
            const r = await api('api/admin/settings.php');
            const s = r.settings;
            window.KPAdminApiProviderName = s.api_service_name || 'DRIPSTORE API';
            document.getElementById('adminApiServiceName').value = s.api_service_name || '';
            document.getElementById('adminApiEndpoint').value = s.api_endpoint || 'https://dripclientstore.shop/api/v1';
            document.getElementById('adminApiMasterToken').value = '';
            document.getElementById('adminApiSettingsStatus').textContent = s.api_master_token_set ? 'CONFIGURED' : 'NOT CONFIGURED';
            const t = document.getElementById('adminApiToggle');
            if (t) { const on = s.api_provider_enabled !== false; t.classList.toggle('on', on); t.textContent = on ? 'ON' : 'OFF'; }
            // Provider 2
            window.KPAdminProviders = s.providers || window.KPAdminProviders;
            const n2 = document.getElementById('adminApi2ServiceName');
            if (n2) {
                n2.value = s.api2_service_name || '';
                document.getElementById('adminApi2Endpoint').value = s.api2_endpoint || 'https://keypanel.shop/reseller_gateway.php';
                document.getElementById('adminApi2MasterToken').value = '';
                document.getElementById('adminApi2SettingsStatus').textContent = s.api2_master_token_set ? 'CONFIGURED' : 'NOT CONFIGURED';
                const t2 = document.getElementById('adminApi2Toggle');
                t2.classList.toggle('on', !!s.api2_provider_enabled); t2.textContent = s.api2_provider_enabled ? 'ON' : 'OFF';
            }
            // Provider 3 (PANELSTORE)
            const n3 = document.getElementById('adminApi3ServiceName');
            if (n3) {
                n3.value = s.api3_service_name || '';
                document.getElementById('adminApi3Endpoint').value = s.api3_endpoint || 'https://panelstore.shop/api/v1/index.php';
                document.getElementById('adminApi3MasterToken').value = '';
                document.getElementById('adminApi3SettingsStatus').textContent = s.api3_master_token_set ? 'CONFIGURED' : 'NOT CONFIGURED';
                const t3 = document.getElementById('adminApi3Toggle');
                t3.classList.toggle('on', !!s.api3_provider_enabled); t3.textContent = s.api3_provider_enabled ? 'ON' : 'OFF';
            }
            // Provider 4 (BANTIBHAIYA)
            const n4 = document.getElementById('adminApi4ServiceName');
            if (n4) {
                n4.value = s.api4_service_name || '';
                document.getElementById('adminApi4Endpoint').value = s.api4_endpoint || 'https://bantibhaiya.to/api/reseller_v1.php';
                document.getElementById('adminApi4ApiKey').value = '';
                document.getElementById('adminApi4MasterToken').value = '';
                document.getElementById('adminApi4SettingsStatus').textContent = (s.api4_api_key_set && s.api4_master_token_set) ? 'CONFIGURED' : 'NOT CONFIGURED';
                const t4 = document.getElementById('adminApi4Toggle');
                t4.classList.toggle('on', !!s.api4_provider_enabled); t4.textContent = s.api4_provider_enabled ? 'ON' : 'OFF';
            }
            // Provider 5 (LICENCE DASHBOARD)
            const n5 = document.getElementById('adminApi5ServiceName');
            if (n5) {
                n5.value = s.api5_service_name || '';
                document.getElementById('adminApi5Endpoint').value = s.api5_endpoint || 'https://licencedashboard.shop/api/v1';
                document.getElementById('adminApi5MasterToken').value = '';
                document.getElementById('adminApi5SettingsStatus').textContent = s.api5_master_token_set ? 'CONFIGURED' : 'NOT CONFIGURED';
                const t5 = document.getElementById('adminApi5Toggle');
                t5.classList.toggle('on', !!s.api5_provider_enabled); t5.textContent = s.api5_provider_enabled ? 'ON' : 'OFF';
            }
            // Provider 6 (VIP SELLER HUB)
            const n6 = document.getElementById('adminApi6ServiceName');
            if (n6) {
                n6.value = s.api6_service_name || '';
                document.getElementById('adminApi6Endpoint').value = s.api6_endpoint || 'https://whitexmodz.store/api/v1';
                document.getElementById('adminApi6MasterToken').value = '';
                document.getElementById('adminApi6SettingsStatus').textContent = s.api6_master_token_set ? 'CONFIGURED' : 'NOT CONFIGURED';
                const t6 = document.getElementById('adminApi6Toggle');
                t6.classList.toggle('on', !!s.api6_provider_enabled); t6.textContent = s.api6_provider_enabled ? 'ON' : 'OFF';
            }
        } catch (e) { toast(e.message || 'Admin request failed.', {variant:'danger'}); }
    }

    document.getElementById('adminApiToggle')?.addEventListener('click', async () => {
        const btn = document.getElementById('adminApiToggle');
        const on = !btn.classList.contains('on');
        try {
            await api('api/admin/settings.php', { method: 'POST', body: { action: 'toggle_api_provider', enabled: on } });
            btn.classList.toggle('on', on); btn.textContent = on ? 'ON' : 'OFF';
            document.getElementById('adminApiSettingsMessage').textContent = on ? 'API provider enabled.' : 'API provider disabled.';
        } catch (err) { document.getElementById('adminApiSettingsMessage').textContent = err.message; }
    });

    document.getElementById('adminApiSave')?.addEventListener('click', async () => {
        const endpoint = document.getElementById('adminApiEndpoint').value.trim();
        const serviceName = document.getElementById('adminApiServiceName').value.trim();
        const masterToken = document.getElementById('adminApiMasterToken').value;
        const enabled = document.getElementById('adminApiToggle')?.classList.contains('on') !== false;
        try {
            await api('api/admin/settings.php', { method: 'POST', body: { action: 'save_api_settings', endpoint, service_name: serviceName, master_token: masterToken, enabled } });
            document.getElementById('adminApiSettingsMessage').textContent = masterToken ? 'API settings and new token saved.' : 'API settings saved. Existing token was kept.';
            document.getElementById('adminApiMasterToken').value = '';
            window.KPAdminApiProviderName = serviceName || 'DRIPSTORE API';
            loadApiSettings();
        } catch (err) { document.getElementById('adminApiSettingsMessage').textContent = err.message; }
    });

    document.getElementById('adminApiRemoveToken')?.addEventListener('click', async () => {
        if (!confirm('Remove the current API token?')) return;
        try { await api('api/admin/settings.php', { method: 'POST', body: { action: 'remove_api_token' } }); loadApiSettings(); }
        catch (err) { toast(err.message, { variant: 'danger' }); }
    });

    async function runApiBalance(action, label) {
        const msg = document.getElementById('adminApiSettingsMessage');
        msg.textContent = label + '…';
        try {
            const r = await api('api/admin/settings.php', { method: 'POST', body: { action } });
            msg.textContent = label === 'Checking balance' ? 'Balance response: ' + JSON.stringify(r.response) : 'Connection successful.';
        } catch (err) { msg.textContent = 'Failed: ' + (err.message || 'Unknown error'); }
    }
    document.getElementById('adminApiTest')?.addEventListener('click', () => runApiBalance('test_api', 'Testing API connection'));
    document.getElementById('adminApiBalance')?.addEventListener('click', () => runApiBalance('check_api_balance', 'Checking balance'));

    /* ------------------ Provider 2 (KEYPANEL) settings ------------------ */
    const api2Msg = t => { const m = document.getElementById('adminApi2SettingsMessage'); if (m) m.textContent = t; };
    document.getElementById('adminApi2Toggle')?.addEventListener('click', async () => {
        const btn = document.getElementById('adminApi2Toggle');
        const on = !btn.classList.contains('on');
        try {
            await api('api/admin/settings.php', { method: 'POST', body: { action: 'toggle_api2_provider', enabled: on } });
            btn.classList.toggle('on', on); btn.textContent = on ? 'ON' : 'OFF';
            api2Msg(on ? 'Provider 2 enabled.' : 'Provider 2 disabled. Key and product assignments are kept.');
            loadProviders();
        } catch (err) { api2Msg(err.message); }
    });
    document.getElementById('adminApi2Save')?.addEventListener('click', async () => {
        const body = {
            action: 'save_api2_settings',
            endpoint: document.getElementById('adminApi2Endpoint').value.trim(),
            service_name: document.getElementById('adminApi2ServiceName').value.trim(),
            master_token: document.getElementById('adminApi2MasterToken').value,
            enabled: document.getElementById('adminApi2Toggle').classList.contains('on'),
        };
        try {
            await api('api/admin/settings.php', { method: 'POST', body });
            api2Msg(body.master_token ? 'Settings and new key saved.' : 'Settings saved. Existing key was kept.');
            document.getElementById('adminApi2MasterToken').value = '';
            loadApiSettings();
        } catch (err) { api2Msg(err.message); loadApiSettings(); }
    });
    document.getElementById('adminApi2RemoveToken')?.addEventListener('click', async () => {
        if (!confirm('Remove the stored key? Provider 2 will be switched OFF.')) return;
        try { await api('api/admin/settings.php', { method: 'POST', body: { action: 'remove_api2_token' } }); api2Msg('Key removed. Provider 2 is OFF.'); loadApiSettings(); }
        catch (err) { api2Msg(err.message); }
    });
    document.getElementById('adminApi2Test')?.addEventListener('click', async () => {
        api2Msg('Checking configuration…');
        try { const r = await api('api/admin/settings.php', { method: 'POST', body: { action: 'test_api2' } }); api2Msg(r.message); }
        catch (err) { api2Msg('Failed: ' + (err.message || 'Unknown error')); }
    });

    /* ------------------ Provider 3 (PANELSTORE) settings ------------------ */
    const api3Msg = t => { const m = document.getElementById('adminApi3SettingsMessage'); if (m) m.textContent = t; };
    document.getElementById('adminApi3Toggle')?.addEventListener('click', async () => {
        const btn = document.getElementById('adminApi3Toggle');
        const on = !btn.classList.contains('on');
        try {
            await api('api/admin/settings.php', { method: 'POST', body: { action: 'toggle_api3_provider', enabled: on } });
            btn.classList.toggle('on', on); btn.textContent = on ? 'ON' : 'OFF';
            api3Msg(on ? 'Provider enabled.' : 'Provider disabled. Key and product assignments are kept.');
            loadProviders();
        } catch (err) { api3Msg(err.message); }
    });
    document.getElementById('adminApi3Save')?.addEventListener('click', async () => {
        const body = {
            action: 'save_api3_settings',
            endpoint: document.getElementById('adminApi3Endpoint').value.trim(),
            service_name: document.getElementById('adminApi3ServiceName').value.trim(),
            master_token: document.getElementById('adminApi3MasterToken').value,
            enabled: document.getElementById('adminApi3Toggle').classList.contains('on'),
        };
        try {
            await api('api/admin/settings.php', { method: 'POST', body });
            api3Msg(body.master_token ? 'Settings and new key saved.' : 'Settings saved. Existing key was kept.');
            document.getElementById('adminApi3MasterToken').value = '';
            loadApiSettings();
        } catch (err) { api3Msg(err.message); loadApiSettings(); }
    });
    document.getElementById('adminApi3RemoveToken')?.addEventListener('click', async () => {
        if (!confirm('Remove the stored key? This provider will be switched OFF.')) return;
        try { await api('api/admin/settings.php', { method: 'POST', body: { action: 'remove_api3_token' } }); api3Msg('Key removed. Provider is OFF.'); loadApiSettings(); }
        catch (err) { api3Msg(err.message); }
    });
    async function runApi3Check(label, showBalance) {
        api3Msg(label + '…');
        try {
            const r = await api('api/admin/settings.php', { method: 'POST', body: { action: 'test_api3' } });
            const bal = Number(r.balance);
            api3Msg(showBalance ? 'Balance: ₹' + (isFinite(bal) ? bal.toFixed(2) : String(r.balance)) : 'SUCCESS — connection OK (balance ₹' + (isFinite(bal) ? bal.toFixed(2) : String(r.balance)) + ').');
        } catch (err) { api3Msg(String(err.message || 'FAILED: Unknown error')); }
    }
    document.getElementById('adminApi3Test')?.addEventListener('click', () => runApi3Check('Testing connection', false));
    document.getElementById('adminApi3Balance')?.addEventListener('click', () => runApi3Check('Checking balance', true));

    /* ------------------ Provider 4 (BANTIBHAIYA) settings ------------------ */
    const api4Msg = t => { const m = document.getElementById('adminApi4SettingsMessage'); if (m) m.textContent = t; };
    document.getElementById('adminApi4Toggle')?.addEventListener('click', async () => {
        const btn = document.getElementById('adminApi4Toggle');
        const on = !btn.classList.contains('on');
        try {
            await api('api/admin/settings.php', { method: 'POST', body: { action: 'toggle_api4_provider', enabled: on } });
            btn.classList.toggle('on', on); btn.textContent = on ? 'ON' : 'OFF';
            api4Msg(on ? 'Provider enabled.' : 'Provider disabled. Keys and product assignments are kept.');
            loadProviders();
        } catch (err) { api4Msg(err.message); }
    });
    document.getElementById('adminApi4Save')?.addEventListener('click', async () => {
        const body = {
            action: 'save_api4_settings',
            endpoint: document.getElementById('adminApi4Endpoint').value.trim(),
            service_name: document.getElementById('adminApi4ServiceName').value.trim(),
            api_key: document.getElementById('adminApi4ApiKey').value,
            master_token: document.getElementById('adminApi4MasterToken').value,
            enabled: document.getElementById('adminApi4Toggle').classList.contains('on'),
        };
        try {
            await api('api/admin/settings.php', { method: 'POST', body });
            api4Msg((body.api_key || body.master_token) ? 'Settings and new key(s) saved.' : 'Settings saved. Existing keys were kept.');
            document.getElementById('adminApi4ApiKey').value = '';
            document.getElementById('adminApi4MasterToken').value = '';
            loadApiSettings();
        } catch (err) { api4Msg(err.message); loadApiSettings(); }
    });
    document.getElementById('adminApi4RemoveToken')?.addEventListener('click', async () => {
        if (!confirm('Remove both stored keys? This provider will be switched OFF.')) return;
        try { await api('api/admin/settings.php', { method: 'POST', body: { action: 'remove_api4_token' } }); api4Msg('Keys removed. Provider is OFF.'); loadApiSettings(); }
        catch (err) { api4Msg(err.message); }
    });
    document.getElementById('adminApi4Test')?.addEventListener('click', async () => {
        api4Msg('Checking configuration…');
        try { const r = await api('api/admin/settings.php', { method: 'POST', body: { action: 'test_api4' } }); api4Msg(r.message || 'Configuration is complete.'); }
        catch (err) { api4Msg(String(err.message || 'FAILED: Unknown error')); }
    });

    /* ------------------ Operations: Deposit monitor, Alerts, Audit / Price log, Archived products ------------------ */
    // Everything below only renders what the server returns. All money / order actions are decided server-side.
    const opsEl = id => document.getElementById(id);
    const opsVal = id => { const e = opsEl(id); return e ? String(e.value || '').trim() : ''; };
    function opsModalOpen(html) { opsEl('opsModalBody').innerHTML = html; opsEl('opsModal').hidden = false; }
    function opsModalClose() { const m = opsEl('opsModal'); if (m) { m.hidden = true; opsEl('opsModalBody').innerHTML = ''; } }
    opsEl('opsModalClose') && opsEl('opsModalClose').addEventListener('click', opsModalClose);
    opsEl('opsModal') && opsEl('opsModal').addEventListener('click', e => { if (e.target.id === 'opsModal') opsModalClose(); });
    const opsKV = rows => '<dl class="ao-kv">' + rows.map(r => `<dt>${escHtml(r[0])}</dt><dd>${r[1]}</dd>`).join('') + '</dl>';
    const opsSt = { deposit: 1, alert: 1, audit: 1, prices: 1 };
    function opsPager(boxId, countId, p, noun, onPage) {
        const cnt = opsEl(countId); const box = opsEl(boxId);
        if (cnt) cnt.textContent = p.total ? `${Number(p.total).toLocaleString()} ${noun} • page ${p.page} of ${p.pages}` : `No ${noun} match.`;
        if (!box) return; box.innerHTML = '';
        if (p.pages <= 1) return;
        const mk = (label, page, on, dis) => { const b = document.createElement('button'); b.type = 'button'; b.textContent = label; if (on) b.classList.add('on'); if (dis) b.disabled = true; else b.addEventListener('click', () => { onPage(page); window.scrollTo({ top: 0, behavior: 'smooth' }); }); box.appendChild(b); };
        mk('‹ PREV', p.page - 1, false, p.page <= 1);
        const nums = new Set([1, p.pages, p.page - 1, p.page, p.page + 1]); let last = 0;
        [...nums].filter(n => n >= 1 && n <= p.pages).sort((a, b) => a - b).forEach(n => { if (last && n - last > 1) { const d = document.createElement('span'); d.textContent = '…'; box.appendChild(d); } mk(String(n), n, n === p.page, false); last = n; });
        mk('NEXT ›', p.page + 1, false, p.page >= p.pages);
    }
    const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

    /* ---- Deposits ---- */
    const depState = { page: 1 };
    const depStatusClass = s => ({ approved: 'completed', pending: 'pending', rejected: 'failed', expired: 'refunded' }[s] || 'pending');
    async function loadDeposits(resetPage) {
        if (resetPage === true) depState.page = 1;
        const q = new URLSearchParams({ filter: opsVal('adminDepositFilter') || 'all', gateway: opsVal('adminDepositGateway') || 'all', q: opsVal('adminDepositSearch'), from: opsVal('adminDepositFrom'), to: opsVal('adminDepositTo'), page: String(depState.page) });
        try {
            const r = await api('api/admin/deposits.php?' + q.toString());
            const s = r.summary;
            setText('adminDepPending', s.pending); setText('adminDepApproved', s.approved_today);
            setText('adminDepTotal', money(s.total_credited)); setText('adminDepRejected', s.rejected);
            depState.page = r.page;
            const list = opsEl('adminDepositList'); list.innerHTML = '';
            opsPager('adminDepositPager', 'adminDepositCount', r, 'deposits', pg => { depState.page = pg; loadDeposits(); });
            if (s.credit_missing > 0) opsEl('adminDepositCount').textContent += ` • ⚠ ${s.credit_missing} paid deposit(s) need review`;
            if (!r.deposits.length) { list.innerHTML = '<div class="admin-order-empty">No deposits found.</div>'; return; }
            r.deposits.forEach(d => {
                const card = document.createElement('article'); card.className = 'ao-card';
                card.innerHTML = `
                    <div class="ao-top"><span class="ao-ref"></span><span class="ao-status s-${depStatusClass(d.status)}">${escHtml(String(d.status).toUpperCase())}</span></div>
                    <div class="ao-prod"><span aria-hidden="true">💰</span><strong>${money(d.amount)}</strong></div>
                    <div class="ao-pills"><span class="ao-pill dur">${escHtml(d.method)}</span><span class="ao-pill qty">GW: ${escHtml(d.gateway_status || '—')}</span>${d.method === 'zapupi' ? `<span class="ao-pill qty">CREDIT: ${escHtml(d.credit_status || '—')}</span><span class="ao-pill qty">ENV: ${escHtml(d.environment || '—')}</span>` : ''}<span class="ao-pill amt">${d.wallet_credited ? 'WALLET CREDITED' : 'NOT CREDITED'}</span></div>
                    ${d.credit_missing ? '<div class="ops-warn">Payment verified, but wallet credit is missing — review required.</div>' : ''}
                    <div class="ops-line">UTR / BANK REF: <b class="utr"></b></div>
                    <div class="ops-line">TXN ID: <b class="txn"></b></div>
                    <div class="ao-user"><span>👤 <b class="un"></b> <i>ID ${d.user_id}</i></span><span class="ops-line" style="margin:0">DEP #${d.id}</span></div>
                    <div class="ao-date">🕒 ${escHtml(d.created_at)}</div>
                    <div class="ao-actions"></div>`;
                card.querySelector('.ao-ref').textContent = '#' + d.order_ref;
                card.querySelector('.utr').textContent = d.utr || '—';
                card.querySelector('.txn').textContent = d.gateway_txn_id || '—';
                card.querySelector('.un').textContent = d.username;
                const acts = card.querySelector('.ao-actions');
                const add = (label, cls, fn) => { const b = document.createElement('button'); b.type = 'button'; b.textContent = label; if (cls) b.className = cls; b.addEventListener('click', fn); acts.appendChild(b); };
                add('VIEW DETAILS', '', () => depDetail(d.id));
                if (d.can_reconcile) add(d.method === 'zapupi' ? 'VERIFY WITH ZAPUPI' : 'VERIFY WITH PAYTM & CREDIT', 'unban', () => depReconcile(d));
                if (d.status === 'pending' && d.method !== 'paytm_qr' && d.method !== 'zapupi') {
                    add('APPROVE', 'unban', () => depLegacy('approve', d.id));
                    add('REJECT', 'ban', () => depLegacy('reject', d.id));
                }
                list.appendChild(card);
            });
        } catch (e) { toast(e.message || 'Admin request failed.', { variant: 'danger' }); }
    }
    async function depLegacy(action, id) {
        if (action === 'reject') {
            const note = prompt('Rejection reason (optional):', '');
            try { await api('api/admin/deposits.php', { method: 'POST', body: { action, id, note: note || '' } }); loadDeposits(); } catch (err) { toast(err.message, { variant: 'danger' }); }
        } else {
            if (!confirm('Approve this deposit? Wallet will be credited immediately.')) return;
            try { const r2 = await api('api/admin/deposits.php', { method: 'POST', body: { action, id } }); toast('Deposit approved. New balance: ₹' + r2.balance.toFixed(2), { duration: 3000 }); loadDeposits(); } catch (err) { toast(err.message, { variant: 'danger' }); }
        }
    }
    async function depReconcile(d) {
        if (!confirm(`Verify deposit #${d.id} with ${d.method === 'zapupi' ? 'ZapUPI' : 'Paytm'} now?\n\nThe wallet is credited ONLY if the gateway itself confirms the payment and no credit exists yet. Safe to repeat: it can never credit twice.`)) return;
        try {
            const r = await api('api/admin/deposits.php', { method: 'POST', body: { action: 'reconcile', id: d.id } });
            toast(r.message, { duration: 4200, variant: r.credited ? undefined : 'danger' });
            loadDeposits(); opsModalClose();
        } catch (err) { toast(err.message, { variant: 'danger' }); loadDeposits(); }
    }
    async function depDetail(id) {
        try {
            const r = await api('api/admin/deposits.php?id=' + id); const d = r.deposit; const g = d.gateway || {};
            opsModalOpen(`<h3 class="ops-h">DEPOSIT #${d.id}</h3>` + (d.credit_missing ? '<div class="ops-warn">Payment verified, but wallet credit is missing — review required.</div>' : '') + opsKV([
                ['Reference', escHtml(d.order_ref)], ['User', `${escHtml(d.username)} (ID ${d.user_id})`], ['Amount', money(d.amount)], ['Status', escHtml(d.status)],
                ['Created', escHtml(d.created_at)], ['Updated', escHtml(d.updated_at)], ['Expires', escHtml(d.expires_at || '—')],
                ['Payment method', escHtml(d.method)], ['UTR / bank reference', escHtml(d.utr || '—')], ['Transaction ID', escHtml(d.gateway_txn_id || '—')],
                ['Gateway order ID', escHtml(d.gateway_order_id || '—')], ['EID / other reference', 'Not provided by the Paytm integration'],
                ['Gateway status', escHtml(d.gateway_status || '—')], ['Paytm payment mode', escHtml(g.payment_mode || '—')], ['Paytm message', escHtml(g.resp_msg || '—')], ['Paytm txn date', escHtml(g.txn_date || '—')],
                ...(d.method === 'zapupi' ? [['Gateway', 'ZapUPI'], ['Credit status', escHtml(d.credit_status || '—')], ['Environment', escHtml(d.environment || '—')], ['Paid at', escHtml(d.paid_at || '—')]] : []),
                ['Payment verified by gateway', d.verified ? 'YES' : 'NO'], ['Wallet credited', d.wallet_credited ? 'YES' : 'NO'], ['Wallet ledger reference', escHtml(d.ledger_ref || '—')],
                ['Pending / failure reason', escHtml(d.pending_reason || '—')], ['Reviewed by', escHtml(d.reviewer || '—')], ['Admin note', escHtml(d.admin_note || '—')],
            ]) + (d.can_reconcile ? '<div class="ao-actions" style="margin-top:12px"><button type="button" class="unban" id="opsDepReconcile">' + (d.method === 'zapupi' ? 'VERIFY WITH ZAPUPI' : 'VERIFY WITH PAYTM & CREDIT') + '</button></div>' : ''));
            opsEl('opsDepReconcile') && opsEl('opsDepReconcile').addEventListener('click', () => depReconcile(d));
        } catch (err) { toast(err.message, { variant: 'danger' }); }
    }
    const depReload = debounce(() => loadDeposits(true), 350);
    opsEl('adminDepositFilter') && opsEl('adminDepositFilter').addEventListener('change', () => loadDeposits(true));
    ['adminDepositFrom', 'adminDepositTo', 'adminDepositGateway'].forEach(id => opsEl(id) && opsEl(id).addEventListener('change', () => loadDeposits(true)));
    opsEl('adminDepositSearch') && opsEl('adminDepositSearch').addEventListener('input', depReload);
    opsEl('adminDepositReset') && opsEl('adminDepositReset').addEventListener('click', () => { ['adminDepositSearch', 'adminDepositFrom', 'adminDepositTo'].forEach(i => opsEl(i).value = ''); opsEl('adminDepositFilter').value = 'all'; if (opsEl('adminDepositGateway')) opsEl('adminDepositGateway').value = 'all'; loadDeposits(true); });

    /* ---- Alerts ---- */
    const alState = { page: 1 };
    const ALERT_LABEL = { stock_zero: 'LOCAL STOCK ZERO', provider_stock: 'PROVIDER OUT OF STOCK', generation_failed: 'KEY GENERATION FAILED', uncertain_order: 'UNCERTAIN ORDER', provider_unreachable: 'PROVIDER UNREACHABLE', provider_balance: 'PROVIDER BALANCE', refund_failed: 'REFUND FAILED', order_inconsistent: 'ORDER CHECK', wallet_inconsistent: 'WALLET CHECK' };
    async function loadAlerts(resetPage) {
        if (resetPage === true) alState.page = 1;
        const q = new URLSearchParams({ status: opsVal('alStatus') || 'open', type: opsVal('alType') || 'all', severity: opsVal('alSeverity') || 'all', q: opsVal('alSearch'), page: String(alState.page) });
        try {
            const r = await api('api/admin/alerts.php?' + q.toString());
            alState.page = r.page;
            const c = r.counts;
            opsEl('alSummary').innerHTML = [['OPEN', c.open], ['CRITICAL', c.critical], ['RESOLVED', c.resolved]].map(x => `<div class="ao-stat"><span>${x[0]}</span><b>${Number(x[1] || 0).toLocaleString()}</b></div>`).join('');
            const badge = opsEl('adminAlertsBadge'); if (badge) { badge.hidden = !c.open; badge.textContent = c.open > 99 ? '99+' : String(c.open); }
            opsPager('alPager', 'alCount', r, 'alerts', pg => { alState.page = pg; loadAlerts(); });
            const list = opsEl('alList'); list.innerHTML = '';
            if (!r.alerts.length) { list.innerHTML = '<div class="admin-order-empty">No alerts.</div>'; return; }
            r.alerts.forEach(a => {
                const card = document.createElement('article'); card.className = 'ao-card';
                card.innerHTML = `
                    <div class="ao-top"><span class="ao-ref"></span><span class="ao-status s-${a.status === 'resolved' ? 'completed' : (a.severity === 'critical' ? 'failed' : 'pending')}">${a.status === 'resolved' ? 'RESOLVED' : escHtml(String(a.severity).toUpperCase())}</span></div>
                    <div class="ao-prod"><span aria-hidden="true">🚨</span><strong class="pn"></strong></div>
                    <div class="ao-pills"><span class="ao-pill dur"></span><span class="ao-pill qty"></span><span class="ao-pill amt"></span></div>
                    <div class="ops-line reason"></div>
                    <div class="ops-line act"></div>
                    <div class="ao-date">🕒 last ${escHtml(a.last_seen)} • first ${escHtml(a.first_seen)} • seen ×${a.occurrences}</div>
                    <div class="ao-actions"><button type="button" data-a="detail">DETAILS</button>${a.status === 'open' ? '<button type="button" class="unban" data-a="resolve">MARK RESOLVED</button>' : ''}</div>`;
                card.querySelector('.ao-ref').textContent = ALERT_LABEL[a.type] || a.type;
                card.querySelector('.pn').textContent = a.product_name ? `${a.product_name} (ID ${a.product_id ?? '—'})` : (a.provider || 'System');
                card.querySelector('.dur').textContent = a.duration || '—';
                card.querySelector('.qty').textContent = a.stock_source ? 'STOCK: ' + a.stock_source.toUpperCase() : (a.provider || '—');
                card.querySelector('.amt').textContent = a.order_ref ? '#' + a.order_ref : 'NO ORDER';
                card.querySelector('.reason').textContent = a.reason || '';
                card.querySelector('.act').textContent = '➜ ' + (a.recommended_action || '');
                card.querySelector('[data-a="detail"]').addEventListener('click', () => alertDetail(a.id));
                const rs = card.querySelector('[data-a="resolve"]'); if (rs) rs.addEventListener('click', () => alertResolve(a.id));
                list.appendChild(card);
            });
        } catch (e) { toast(e.message || 'Admin request failed.', { variant: 'danger' }); }
    }
    async function alertResolve(id) {
        const note = prompt('Resolution note (optional):', '');
        if (note === null) return;
        try { await api('api/admin/alerts.php', { method: 'POST', body: { action: 'resolve', id, note } }); toast('Alert resolved.', { duration: 1800 }); opsModalClose(); loadAlerts(); } catch (err) { toast(err.message, { variant: 'danger' }); }
    }
    async function alertDetail(id) {
        try {
            const r = await api('api/admin/alerts.php?id=' + id); const a = r.alert; const o = a.order;
            let html = `<h3 class="ops-h">${escHtml(ALERT_LABEL[a.type] || a.type)}</h3>` + opsKV([
                ['Status', escHtml(a.status)], ['Severity', escHtml(a.severity)], ['Product', a.product_name ? `${escHtml(a.product_name)} (ID ${a.product_id})` : '—'], ['Plan / duration', escHtml(a.duration || '—')],
                ['Order', escHtml(a.order_ref || '—')], ['Provider', escHtml(a.provider || '—')], ['Stock source', escHtml(a.stock_source || '—')],
                ['Reason', escHtml(a.reason || '—')], ['Recommended action', escHtml(a.recommended_action || '—')],
                ['First seen', escHtml(a.first_seen)], ['Last seen', escHtml(a.last_seen)], ['Occurrences', String(a.occurrences)], ['Resolved', a.resolved_at ? escHtml(a.resolved_at + ' — ' + (a.resolved_note || '')) : '—'],
            ]);
            if (o) {
                html += '<h3 class="ops-h" style="margin-top:14px">RELATED ORDER</h3>' + opsKV([['Order', escHtml(o.order_ref)], ['User', `${escHtml(o.username)} (ID ${o.user_id})`], ['Status', escHtml(o.status)], ['Total', money(o.total_price)], ['Quantity', String(o.quantity)], ['Keys stored', String(o.key_count)], ['Created', escHtml(o.created_at)], ['Failure note', escHtml(o.failure_reason || '—')]]);
                if (o.status === 'pending' && a.status === 'open') {
                    html += `<div class="ops-box"><b>This order is still pending (wallet charged, no key delivered).</b>
                        <textarea id="opsKeys" placeholder="Provider DID generate key(s)? Paste them here, one per line, then press COMPLETE ORDER."></textarea>
                        <div class="ao-actions"><button type="button" class="unban" id="opsComplete">COMPLETE ORDER WITH KEY</button><button type="button" class="ban" id="opsRefund">REFUND ORDER</button></div>
                        <small>Refund credits the wallet once only. Complete attaches the key without touching the wallet.</small></div>`;
                }
            }
            if (a.status === 'open') html += '<div class="ao-actions" style="margin-top:12px"><button type="button" class="unban" id="opsResolve">MARK RESOLVED</button></div>';
            opsModalOpen(html);
            opsEl('opsResolve') && opsEl('opsResolve').addEventListener('click', () => alertResolve(a.id));
            opsEl('opsRefund') && opsEl('opsRefund').addEventListener('click', async () => {
                if (!confirm('Refund this order to the customer wallet? This can only happen once.')) return;
                try { await api('api/admin/alerts.php', { method: 'POST', body: { action: 'refund_order', id: a.id } }); toast('Order refunded.', { duration: 2200 }); opsModalClose(); loadAlerts(); } catch (err) { toast(err.message, { variant: 'danger' }); }
            });
            opsEl('opsComplete') && opsEl('opsComplete').addEventListener('click', async () => {
                const keys = opsVal('opsKeys').split(/\r?\n/).map(x => x.trim()).filter(Boolean);
                if (!keys.length) { toast('Paste at least one key.', { variant: 'danger' }); return; }
                if (!confirm('Attach ' + keys.length + ' key(s) and mark the order completed?')) return;
                try { await api('api/admin/alerts.php', { method: 'POST', body: { action: 'complete_order', id: a.id, keys } }); toast('Order completed.', { duration: 2200 }); opsModalClose(); loadAlerts(); } catch (err) { toast(err.message, { variant: 'danger' }); }
            });
        } catch (err) { toast(err.message, { variant: 'danger' }); }
    }
    const alReload = debounce(() => loadAlerts(true), 350);
    ['alStatus', 'alType', 'alSeverity'].forEach(id => opsEl(id) && opsEl(id).addEventListener('change', () => loadAlerts(true)));
    opsEl('alSearch') && opsEl('alSearch').addEventListener('input', alReload);
    opsEl('alReset') && opsEl('alReset').addEventListener('click', () => { opsEl('alSearch').value = ''; opsEl('alStatus').value = 'open'; opsEl('alType').value = 'all'; opsEl('alSeverity').value = 'all'; loadAlerts(true); });
    opsEl('alScan') && opsEl('alScan').addEventListener('click', async () => { try { await api('api/admin/alerts.php', { method: 'POST', body: { action: 'scan' } }); toast('Check finished.', { duration: 1500 }); loadAlerts(true); } catch (err) { toast(err.message, { variant: 'danger' }); } });

    /* ---- Audit log / price changes / archived products ---- */
    const auState = { tab: 'audit', page: 1 };
    function auShowTab(tab) {
        auState.tab = tab; auState.page = 1;
        document.querySelectorAll('#auTabs button').forEach(b => b.classList.toggle('on', b.dataset.v === tab));
        opsEl('auAuditPanel').hidden = tab !== 'audit'; opsEl('auPricePanel').hidden = tab !== 'prices';
        loadAudit();
    }
    async function loadAudit() {
        const list = opsEl('auList'); list.innerHTML = '';
        try {
            if (auState.tab === 'archived') {
                const r = await api('api/admin/products.php?archived=1');
                opsEl('auPager').innerHTML = ''; opsEl('auCount').textContent = r.products.length ? `${r.products.length} archived product(s)` : 'No archived products.';
                r.products.forEach(p => {
                    const card = document.createElement('article'); card.className = 'ao-card';
                    card.innerHTML = `<div class="ao-top"><span class="ao-ref"></span><span class="ao-status s-refunded">ARCHIVED</span></div>
                        <div class="ops-line">${escHtml(p.category_name || '')} • ${p.order_count} order(s) • ${(p.plans || []).length} duration(s)</div>
                        <div class="ao-date">🗄 archived ${escHtml(p.archived_at || '')}</div>
                        <div class="ao-actions"><button type="button" class="unban" data-a="restore">RESTORE</button></div>`;
                    card.querySelector('.ao-ref').textContent = `${p.name} (ID ${p.id})`;
                    card.querySelector('[data-a="restore"]').addEventListener('click', async () => {
                        if (!confirm('Restore this product from the archive? It stays hidden from the Store until you enable it.')) return;
                        try { await api('api/admin/products.php', { method: 'POST', body: { action: 'restore', id: p.id } }); toast('Product restored (hidden until enabled).', { duration: 2400 }); loadAudit(); } catch (err) { toast(err.message, { variant: 'danger' }); }
                    });
                    list.appendChild(card);
                });
                return;
            }
            let r;
            if (auState.tab === 'prices') {
                r = await api('api/admin/audit-logs.php?' + new URLSearchParams({ view: 'prices', q: opsVal('auPriceSearch'), page: String(auState.page) }).toString());
                opsPager('auPager', 'auCount', r, 'price changes', pg => { auState.page = pg; loadAudit(); });
                if (!r.rows.length) { list.innerHTML = '<div class="admin-order-empty">No price changes recorded.</div>'; return; }
                r.rows.forEach(x => {
                    const up = x.direction === 'increased';
                    const card = document.createElement('article'); card.className = 'ao-card';
                    card.innerHTML = `<div class="ao-top"><span class="ao-ref"></span><span class="ao-status s-${up ? 'failed' : 'completed'}">${up ? '▲ INCREASED' : '▼ DECREASED'}</span></div>
                        <div class="ops-line"><b>${escHtml(x.duration)}</b> • ${money(x.old_price)} → <b>${money(x.new_price)}</b></div>
                        <div class="ao-date">👤 ${escHtml(x.admin || '—')} • 🕒 ${escHtml(x.at)}</div>`;
                    card.querySelector('.ao-ref').textContent = `${x.product} (ID ${x.product_id})`;
                    list.appendChild(card);
                });
                return;
            }
            r = await api('api/admin/audit-logs.php?' + new URLSearchParams({ view: 'audit', q: opsVal('auSearch'), event: opsVal('auEvent'), result: opsVal('auResult') || 'all', from: opsVal('auFrom'), to: opsVal('auTo'), page: String(auState.page) }).toString());
            opsPager('auPager', 'auCount', r, 'log entries', pg => { auState.page = pg; loadAudit(); });
            if (!r.rows.length) { list.innerHTML = '<div class="admin-order-empty">No log entries.</div>'; return; }
            r.rows.forEach(x => {
                const card = document.createElement('article'); card.className = 'ao-card ops-log';
                card.innerHTML = `<div class="ao-top"><span class="ao-ref"></span><span class="ao-status s-${x.result === 'success' ? 'completed' : (x.result === 'info' ? 'pending' : 'failed')}">${escHtml(String(x.result).toUpperCase())}</span></div>
                    <div class="ops-line reason"></div>
                    <div class="ao-date"></div>`;
                card.querySelector('.ao-ref').textContent = x.event_type;
                card.querySelector('.reason').textContent = x.reason || '—';
                card.querySelector('.ao-date').textContent = `🕒 ${x.created_at}` + (x.actor_label ? ` • 👤 ${x.actor_label}` : '') + (x.order_ref ? ` • #${x.order_ref}` : '') + (x.entity_type ? ` • ${x.entity_type} ${x.entity_id || ''}` : '') + (x.ip ? ` • ${x.ip}` : '');
                list.appendChild(card);
            });
        } catch (e) { toast(e.message || 'Admin request failed.', { variant: 'danger' }); }
    }
    document.querySelectorAll('#auTabs button').forEach(b => b.addEventListener('click', () => auShowTab(b.dataset.v)));
    const auReload = debounce(() => { auState.page = 1; loadAudit(); }, 350);
    ['auSearch', 'auPriceSearch'].forEach(id => opsEl(id) && opsEl(id).addEventListener('input', auReload));
    ['auEvent', 'auResult', 'auFrom', 'auTo'].forEach(id => opsEl(id) && opsEl(id).addEventListener('change', () => { auState.page = 1; loadAudit(); }));
    opsEl('auReset') && opsEl('auReset').addEventListener('click', () => { ['auSearch', 'auFrom', 'auTo'].forEach(i => opsEl(i).value = ''); opsEl('auEvent').value = ''; opsEl('auResult').value = 'all'; auState.page = 1; loadAudit(); });
    // Alert badge on first load (cheap: one request, no scan side effects beyond the throttled check)
    setTimeout(() => { api('api/admin/alerts.php?status=open&page=1').then(r => { const b = opsEl('adminAlertsBadge'); if (b) { b.hidden = !r.counts.open; b.textContent = r.counts.open > 99 ? '99+' : String(r.counts.open); } }).catch(() => {}); }, 1500);

    /* ------------------ Provider 5 (LICENCE DASHBOARD) settings ------------------ */
    // Same pattern as providers 3/4. The token only ever travels browser -> this site (POST, CSRF) and is never read back.
    const api5Msg = t => { const m = document.getElementById('adminApi5SettingsMessage'); if (m) m.textContent = t; };
    const api5Out = document.getElementById('adminApi5Output');
    function api5Show(title, r) {
        if (!api5Out) return;
        const rows = (r.items || []).map(it => `<div class="admin-ld-row"><span>${it.id ? '<b>ID ' + escHtml(it.id) + '</b> — ' : ''}${escHtml(it.name || '(no name field)')}${it.mode ? ' • mode: ' + escHtml(it.mode) : ''}</span>${it.extra ? '<small>' + escHtml(it.extra) + '</small>' : ''}</div>`).join('');
        api5Out.innerHTML = `<div class="admin-branding-label">${escHtml(title)} (${Number(r.count) || 0})</div>${rows || '<div class="admin-ld-row"><small>No list recognised in the response. See the raw response below.</small></div>'}<details><summary>RAW RESPONSE${r.truncated ? ' (TRUNCATED)' : ''}</summary><pre>${escHtml(r.raw || '')}</pre></details>`;
        api5Out.hidden = false;
    }
    document.getElementById('adminApi5Toggle')?.addEventListener('click', async () => {
        const btn = document.getElementById('adminApi5Toggle');
        const on = !btn.classList.contains('on');
        try {
            await api('api/admin/settings.php', { method: 'POST', body: { action: 'toggle_api5_provider', enabled: on } });
            btn.classList.toggle('on', on); btn.textContent = on ? 'ON' : 'OFF';
            api5Msg(on ? 'Provider enabled.' : 'Provider disabled. Token and product assignments are kept.');
            loadApiSettings();
        } catch (err) { api5Msg(err.message); }
    });
    document.getElementById('adminApi5Save')?.addEventListener('click', async () => {
        const body = {
            action: 'save_api5_settings',
            endpoint: document.getElementById('adminApi5Endpoint').value.trim(),
            service_name: document.getElementById('adminApi5ServiceName').value.trim(),
            master_token: document.getElementById('adminApi5MasterToken').value,
            enabled: document.getElementById('adminApi5Toggle').classList.contains('on'),
        };
        try {
            await api('api/admin/settings.php', { method: 'POST', body });
            api5Msg(body.master_token ? 'Settings and new token saved.' : 'Settings saved. Existing token was kept.');
            document.getElementById('adminApi5MasterToken').value = '';
            loadApiSettings();
        } catch (err) { api5Msg(err.message); loadApiSettings(); }
    });
    document.getElementById('adminApi5RemoveToken')?.addEventListener('click', async () => {
        if (!confirm('Remove the stored token? This provider will be switched OFF.')) return;
        try { await api('api/admin/settings.php', { method: 'POST', body: { action: 'remove_api5_token' } }); api5Msg('Token removed. Provider is OFF.'); loadApiSettings(); }
        catch (err) { api5Msg(err.message); }
    });
    async function runApi5Check(label, showBalance) {
        api5Msg(label + '…');
        try {
            const r = await api('api/admin/settings.php', { method: 'POST', body: { action: 'test_api5' } });
            const hasBal = r.balance !== null && r.balance !== undefined && isFinite(Number(r.balance));
            const balTxt = hasBal ? String(r.balance) : (r.detail ? 'see ' + r.detail : 'not reported');
            api5Msg(showBalance ? 'Balance: ' + balTxt : 'SUCCESS — connection OK (balance: ' + balTxt + ')');
        } catch (err) { api5Msg(String(err.message || 'FAILED: Unknown error')); }
    }
    document.getElementById('adminApi5Test')?.addEventListener('click', () => runApi5Check('Testing connection', false));
    document.getElementById('adminApi5Balance')?.addEventListener('click', () => runApi5Check('Checking balance', true));
    document.getElementById('adminApi5Catalog')?.addEventListener('click', async () => {
        api5Msg('Loading catalog…');
        try { const r = await api('api/admin/settings.php', { method: 'POST', body: { action: 'api5_catalog' } }); api5Show('PROVIDER CATALOG', r); api5Msg('Catalog loaded. Type the ID into the duration\'s PROVIDER PRODUCT ID field.'); }
        catch (err) { api5Msg(String(err.message || 'FAILED: Unknown error')); }
    });
    document.getElementById('adminApi5History')?.addEventListener('click', async () => {
        api5Msg('Loading history…');
        try { const r = await api('api/admin/settings.php', { method: 'POST', body: { action: 'api5_history' } }); api5Show('RECENT KEYS', r); api5Msg('History loaded.'); }
        catch (err) { api5Msg(String(err.message || 'FAILED: Unknown error')); }
    });

    /* ------------------ Provider 6 (VIP SELLER HUB) settings ------------------ */
    // Same pattern as provider 5. The token only ever travels browser -> this site (POST, CSRF) and is never read back.
    const api6Msg = t => { const m = document.getElementById('adminApi6SettingsMessage'); if (m) m.textContent = t; };
    const api6Out = document.getElementById('adminApi6Output');
    function api6Show(title, r) {
        if (!api6Out) return;
        const rows = (r.items || []).map(it => `<div class="admin-ld-row"><span>${it.id ? '<b>ID ' + escHtml(it.id) + '</b> — ' : ''}${escHtml(it.name || '(no name field)')}</span>${it.extra ? '<small>' + escHtml(it.extra) + '</small>' : ''}</div>`).join('');
        api6Out.innerHTML = `<div class="admin-branding-label">${escHtml(title)} (${Number(r.count) || 0})</div>${rows || '<div class="admin-ld-row"><small>No list recognised in the response. See the raw response below.</small></div>'}<details><summary>RAW RESPONSE${r.truncated ? ' (TRUNCATED)' : ''}</summary><pre>${escHtml(r.raw || '')}</pre></details>`;
        api6Out.hidden = false;
    }
    document.getElementById('adminApi6Toggle')?.addEventListener('click', async () => {
        const btn = document.getElementById('adminApi6Toggle');
        const on = !btn.classList.contains('on');
        try {
            await api('api/admin/settings.php', { method: 'POST', body: { action: 'toggle_api6_provider', enabled: on } });
            btn.classList.toggle('on', on); btn.textContent = on ? 'ON' : 'OFF';
            api6Msg(on ? 'Provider enabled.' : 'Provider disabled. Token and product assignments are kept.');
            loadApiSettings();
        } catch (err) { api6Msg(err.message); }
    });
    document.getElementById('adminApi6Save')?.addEventListener('click', async () => {
        const body = {
            action: 'save_api6_settings',
            endpoint: document.getElementById('adminApi6Endpoint').value.trim(),
            service_name: document.getElementById('adminApi6ServiceName').value.trim(),
            master_token: document.getElementById('adminApi6MasterToken').value,
            enabled: document.getElementById('adminApi6Toggle').classList.contains('on'),
        };
        try {
            await api('api/admin/settings.php', { method: 'POST', body });
            api6Msg(body.master_token ? 'Settings and new token saved.' : 'Settings saved. Existing token was kept.');
            document.getElementById('adminApi6MasterToken').value = '';
            loadApiSettings();
        } catch (err) { api6Msg(err.message); loadApiSettings(); }
    });
    document.getElementById('adminApi6RemoveToken')?.addEventListener('click', async () => {
        if (!confirm('Remove the stored token? This provider will be switched OFF.')) return;
        try { await api('api/admin/settings.php', { method: 'POST', body: { action: 'remove_api6_token' } }); api6Msg('Token removed. Provider is OFF.'); loadApiSettings(); }
        catch (err) { api6Msg(err.message); }
    });
    async function runApi6Check(label, showBalance) {
        api6Msg(label + '…');
        try {
            const r = await api('api/admin/settings.php', { method: 'POST', body: { action: 'test_api6' } });
            const hasBal = r.balance !== null && r.balance !== undefined && isFinite(Number(r.balance));
            const balTxt = hasBal ? String(r.balance) : (r.detail ? 'see ' + r.detail : 'not reported');
            api6Msg(showBalance ? 'Balance: ' + balTxt + (r.detail ? ' — ' + r.detail : '') : 'SUCCESS — connection OK (balance: ' + balTxt + ')');
        } catch (err) { api6Msg(String(err.message || 'FAILED: Unknown error')); }
    }
    document.getElementById('adminApi6Test')?.addEventListener('click', () => runApi6Check('Testing connection', false));
    document.getElementById('adminApi6Balance')?.addEventListener('click', () => runApi6Check('Checking balance', true));
    document.getElementById('adminApi6Catalog')?.addEventListener('click', async () => {
        api6Msg('Loading catalog…');
        try { const r = await api('api/admin/settings.php', { method: 'POST', body: { action: 'api6_catalog' } }); api6Show('PROVIDER CATALOG', r); api6Msg('Catalog loaded. Type the variant ID into the duration\'s PROVIDER VARIANT ID field.'); }
        catch (err) { api6Msg(String(err.message || 'FAILED: Unknown error')); }
    });
    document.getElementById('adminApi6History')?.addEventListener('click', async () => {
        api6Msg('Loading history…');
        try { const r = await api('api/admin/settings.php', { method: 'POST', body: { action: 'api6_history' } }); api6Show('RECENT ORDERS', r); api6Msg('History loaded.'); }
        catch (err) { api6Msg(String(err.message || 'FAILED: Unknown error')); }
    });

    /* ------------------ Dashboard display (3 slides) + Telegram support ------------------ */
    // Same pipeline as the global-logo upload: optimise in the browser (canvas -> WebP quality ladder, JPEG fallback),
    // then multipart POST with the CSRF header; the server re-validates type/size exactly like save_logo.
    // Slides are cropped to the dashboard frame ratio (16:7) so the preview is identical to what users see.
    const DISPLAY_W = 1600, DISPLAY_H = 700;
    const displayState = { 1: { file: null, clear: false }, 2: { file: null, clear: false }, 3: { file: null, clear: false } };

    async function prepareDisplaySlideUpload(file) {
        const SAFE_BYTES = Math.floor(1.45 * 1024 * 1024);   // same safe ceiling as the logo
        const TARGET_BYTES = 900 * 1024;                      // aim smaller: this is a fast-loading banner
        if (!/^image\/(png|jpeg|webp)$/i.test(file.type)) throw new Error('Please choose a PNG, JPG or WEBP image.');
        const dataUrl = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(new Error('Could not read the selected image.'));
            reader.readAsDataURL(file);
        });
        const img = await new Promise((resolve, reject) => {
            const image = new Image();
            image.onload = () => resolve(image);
            image.onerror = () => reject(new Error('Could not decode the selected image.'));
            image.src = dataUrl;
        });
        const iw = img.naturalWidth || img.width, ih = img.naturalHeight || img.height;
        // Centre-crop to 16:7, never upscale.
        const ratio = DISPLAY_W / DISPLAY_H;
        let cw = Math.min(iw, ih * ratio), ch = cw / ratio;
        const sx = (iw - cw) / 2, sy = (ih - ch) / 2;
        const outW = Math.max(1, Math.min(DISPLAY_W, Math.round(cw)));
        const outH = Math.max(1, Math.round(outW / ratio));
        const canvas = document.createElement('canvas');
        canvas.width = outW; canvas.height = outH;
        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('Your browser cannot prepare this image for upload.');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, sx, sy, cw, ch, 0, 0, outW, outH);

        const toBlob = (c, type, q) => new Promise(resolve => c.toBlob(resolve, type, q));
        let blob = null;
        for (const q of [0.86, 0.80, 0.74, 0.68, 0.62, 0.56, 0.50, 0.44]) {
            blob = await toBlob(canvas, 'image/webp', q);
            if (blob && blob.type === 'image/webp' && blob.size <= TARGET_BYTES) break;
        }
        if (!blob || blob.type !== 'image/webp' || blob.size > SAFE_BYTES) {
            const jc = document.createElement('canvas');
            jc.width = outW; jc.height = outH;
            const jctx = jc.getContext('2d');
            jctx.fillStyle = '#ffffff'; jctx.fillRect(0, 0, outW, outH); jctx.drawImage(canvas, 0, 0);
            for (const q of [0.84, 0.76, 0.68, 0.60, 0.52]) {
                blob = await toBlob(jc, 'image/jpeg', q);
                if (blob && blob.size <= TARGET_BYTES) break;
            }
        }
        if (!blob || blob.size > SAFE_BYTES) throw new Error('This image is unusually large or complex. Please use a simpler image.');
        const ext = blob.type === 'image/webp' ? 'webp' : 'jpg';
        return new File([blob], 'slide.' + ext, { type: blob.type, lastModified: Date.now() });
    }

    function setDisplayPreview(n, url) {
        const img = document.getElementById('adminDisplayPreview' + n);
        const pick = document.getElementById('adminDisplayPick' + n);
        if (!img) return;
        if (url) { img.src = url; img.hidden = false; } else { img.removeAttribute('src'); img.hidden = true; }
        if (pick) pick.textContent = url ? 'CHANGE IMAGE' : 'UPLOAD IMAGE';
    }
    window.KPAdminSetDisplaySlides = urls => { (urls || []).forEach((u, i) => { setDisplayPreview(i + 1, u || ''); }); };

    document.querySelectorAll('[data-display-pick]').forEach(btn => btn.addEventListener('click', () => {
        document.getElementById('adminDisplayFile' + btn.dataset.displayPick).click();
    }));
    [1, 2, 3].forEach(n => {
        const input = document.getElementById('adminDisplayFile' + n);
        if (!input) return;
        input.addEventListener('change', () => {
            const msg = document.getElementById('adminDisplayMessage');
            const f = input.files && input.files[0];
            if (!f) return;
            if (!/^image\/(png|jpeg|webp)$/i.test(f.type)) { msg.textContent = 'Slide 0' + n + ': use a PNG, JPG or WEBP image.'; input.value = ''; return; }
            displayState[n] = { file: f, clear: false };
            setDisplayPreview(n, URL.createObjectURL(f));     // instant local preview, same 16:7 box
            msg.textContent = 'Slide 0' + n + ' ready. Press SAVE DISPLAY SETTINGS to apply all changes.';
        });
    });
    document.querySelectorAll('[data-display-clear]').forEach(btn => btn.addEventListener('click', () => {
        const n = btn.dataset.displayClear;
        displayState[n] = { file: null, clear: true };
        const input = document.getElementById('adminDisplayFile' + n); if (input) input.value = '';
        setDisplayPreview(n, '');
        document.getElementById('adminDisplayMessage').textContent = 'Slide 0' + n + ' will be removed when you press SAVE DISPLAY SETTINGS.';
    }));

    document.getElementById('adminDisplaySave') && document.getElementById('adminDisplaySave').addEventListener('click', async () => {
        const msg = document.getElementById('adminDisplayMessage');
        const btn = document.getElementById('adminDisplaySave');
        const changed = [1, 2, 3].filter(n => displayState[n].file || displayState[n].clear);
        if (!changed.length) { msg.textContent = 'Nothing to save. Choose or remove a slide image first.'; return; }
        const label = btn.textContent;
        btn.disabled = true; btn.textContent = 'SAVING…';
        try {
            msg.textContent = 'Optimizing images…';
            const fd = new FormData();
            fd.append('action', 'save_display');
            for (const n of changed) {
                if (displayState[n].file) fd.append('slide' + n, await prepareDisplaySlideUpload(displayState[n].file));
                else fd.append('clear_slide_' + n, '1');
            }
            msg.textContent = 'Saving display settings…';
            const r = await fetch(window.KP.baseUrl + '/api/admin/settings.php', {
                method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.KP.csrf }, body: fd,
            });
            const raw = await r.text();
            let j = null;
            try { j = JSON.parse(raw); } catch (e) { throw new Error('Server returned an invalid upload response. Check uploads/display permissions and PHP upload limits.'); }
            if (!r.ok || !j.ok) throw new Error(j.error || ('Save failed (' + r.status + ').'));
            window.KPAdminSetDisplaySlides(j.slides);
            [1, 2, 3].forEach(n => { displayState[n] = { file: null, clear: false }; const i = document.getElementById('adminDisplayFile' + n); if (i) i.value = ''; });
            msg.textContent = 'Display settings saved. Users see the new slides on their dashboard.';
        } catch (err) {
            msg.textContent = err && err.message ? err.message : 'Could not save display settings.';
        } finally {
            btn.disabled = false; btn.textContent = label;
        }
    });

    document.getElementById('adminStoreLinksSave') && document.getElementById('adminStoreLinksSave').addEventListener('click', async () => {
        const msg = document.getElementById('adminStoreLinksMessage');
        try {
            await api('api/admin/settings.php', { method: 'POST', body: {
                action: 'save_store_links',
                update_link: document.getElementById('adminStoreUpdateLink').value.trim(),
                feedback_link: document.getElementById('adminStoreFeedbackLink').value.trim(),
            }});
            msg.textContent = 'Saved. Store product cards use these links automatically (empty = button hidden).';
        } catch (err) { msg.textContent = err.message; }
    });

    document.getElementById('adminTelegramSave') && document.getElementById('adminTelegramSave').addEventListener('click', async () => {
        const msg = document.getElementById('adminTelegramMessage');
        const input = document.getElementById('adminTelegramUsername');
        try {
            const r = await api('api/admin/settings.php', { method: 'POST', body: { action: 'save_telegram', username: input.value.trim() } });
            input.value = r.username ? '@' + r.username : '';
            msg.textContent = r.username ? 'Saved. The SUPPORT button now opens t.me/' + r.username + '.' : 'Saved. Username is empty, so the SUPPORT button is hidden.';
        } catch (err) { msg.textContent = err.message; }
    });

    /* ------------------ Settings (branding / join / maintenance) ------------------ */
    async function loadSettings() {
        try {
            const r = await api('api/admin/settings.php');
            const s = r.settings;
            document.getElementById('adminJoinGroupLink').value = s.join_link || '';
            document.getElementById('adminPanelName').value = s.panel_name || '';
            const tgInput = document.getElementById('adminTelegramUsername');
            if (tgInput) tgInput.value = s.telegram_username ? '@' + s.telegram_username : '';
            if (window.KPAdminSetDisplaySlides) window.KPAdminSetDisplaySlides(s.display_slides || []);
            const ul = document.getElementById('adminStoreUpdateLink'); if (ul) ul.value = s.store_update_link || '';
            const fl = document.getElementById('adminStoreFeedbackLink'); if (fl) fl.value = s.store_feedback_link || '';
            const logoPreview = document.getElementById('adminBrandLogoPreview');
            if (logoPreview) {
                logoPreview.src = s.logo_path ? (window.KP.baseUrl + '/' + String(s.logo_path).replace(/^\/+/, '') + '?v=' + Date.now()) : (window.KP.baseUrl + '/assets/images/default-logo.svg?v=' + Date.now());
            }
            const maint = s.maintenance_mode === '1';
            const toggle = document.getElementById('adminSiteMaintenanceToggle');
            toggle.classList.toggle('on', maint);
            toggle.setAttribute('aria-pressed', maint ? 'true' : 'false');
            toggle.querySelector('b').textContent = maint ? 'ON' : 'OFF';
        } catch (e) { toast(e.message || 'Admin request failed.', {variant:'danger'}); }
        loadAccessKeys();
    }

    /* ------------------ Access Keys (one-time signup keys, bound to a username) ------------------ */
    // Everything is created / validated / consumed on the server. The browser only shows the list and sends requests.
    async function loadAccessKeys() {
        const list = document.getElementById('adminAccessKeyList');
        if (!list) return;
        try {
            const r = await api('api/admin/access-keys.php');
            const reqBtn = document.getElementById('adminAccessKeyRequireToggle');
            if (reqBtn) { const on = r.required !== false; reqBtn.classList.toggle('on', on); reqBtn.setAttribute('aria-pressed', on ? 'true' : 'false'); reqBtn.querySelector('b').textContent = on ? 'ON' : 'OFF'; }
            const c = r.counts || {};
            const counts = document.getElementById('adminAccessKeyCounts');
            if (counts) counts.textContent = 'ACTIVE ' + (c.active || 0) + ' • USED ' + (c.used || 0) + ' • REVOKED ' + (c.revoked || 0);
            if (!r.keys.length) { list.innerHTML = '<div class="admin-ak-empty">NO ACCESS KEYS YET</div>'; return; }
            list.innerHTML = r.keys.map(k => `
                <div class="admin-ak-row ${escHtml(k.status)}">
                    <div class="admin-ak-main">
                        <b class="admin-ak-key">${escHtml(k.key)}</b>
                        <span class="admin-ak-user">@${escHtml(k.username)}</span>
                    </div>
                    <div class="admin-ak-side">
                        <span class="admin-ak-badge ${escHtml(k.status)}">${escHtml(String(k.status).toUpperCase())}</span>
                        ${k.status === 'active'
                            ? `<button class="admin-small-btn gold" data-ak-copy="${escHtml(k.key)}" type="button">COPY</button><button class="admin-small-btn danger" data-ak-revoke="${k.id}" type="button">REVOKE</button>`
                            : ''}
                    </div>
                </div>`).join('');
        } catch (err) {
            list.innerHTML = '<div class="admin-ak-empty">' + escHtml(err.message || 'Could not load access keys.') + '</div>';
        }
    }
    function akCopyText(text) {
        if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
        return new Promise((resolve, reject) => {
            const ta = document.createElement('textarea');
            ta.value = text; ta.setAttribute('readonly', ''); ta.style.cssText = 'position:fixed;opacity:0;left:-9999px';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy') ? resolve() : reject(new Error('copy failed')); } catch (e) { reject(e); }
            ta.remove();
        });
    }
    document.getElementById('adminAccessKeyRequireToggle')?.addEventListener('click', async () => {
        const btn = document.getElementById('adminAccessKeyRequireToggle');
        const msg = document.getElementById('adminAccessKeyMessage');
        const next = !btn.classList.contains('on');
        if (!next && !confirm('Turn OFF the Access Key requirement? Anyone will be able to create an account without a key.')) return;
        try {
            const r = await api('api/admin/access-keys.php', { method: 'POST', body: { action: 'set_required', enabled: next } });
            btn.classList.toggle('on', r.required); btn.setAttribute('aria-pressed', r.required ? 'true' : 'false'); btn.querySelector('b').textContent = r.required ? 'ON' : 'OFF';
            msg.classList.remove('error'); msg.textContent = r.required ? 'Access Key is now REQUIRED for registration.' : 'Access Key is now OFF: registration works without a key.';
        } catch (err) { msg.classList.add('error'); msg.textContent = err.message; loadAccessKeys(); }
    });
    document.getElementById('adminAccessKeyGenerate') && document.getElementById('adminAccessKeyGenerate').addEventListener('click', async () => {
        const msg = document.getElementById('adminAccessKeyMessage');
        const input = document.getElementById('adminAccessKeyUsername');
        const btn = document.getElementById('adminAccessKeyGenerate');
        const box = document.getElementById('adminAccessKeyResult');
        const username = input.value.trim();
        msg.classList.remove('error'); box.hidden = true;
        if (!username) { msg.classList.add('error'); msg.textContent = 'Enter the username this key is for.'; return; }
        btn.disabled = true;
        try {
            const r = await api('api/admin/access-keys.php', { method: 'POST', body: { action: 'generate', username } });
            document.getElementById('adminAccessKeyResultKey').textContent = r.key;
            box.hidden = false;
            msg.textContent = 'Key generated for @' + r.username + '. Share it with that user: it works only for this username, only once.';
            input.value = '';
            loadAccessKeys();
        } catch (err) { msg.classList.add('error'); msg.textContent = err.message; }
        finally { btn.disabled = false; }
    });
    document.getElementById('adminAccessKeyCopy') && document.getElementById('adminAccessKeyCopy').addEventListener('click', async () => {
        const msg = document.getElementById('adminAccessKeyMessage');
        try { await akCopyText(document.getElementById('adminAccessKeyResultKey').textContent); msg.classList.remove('error'); msg.textContent = 'Access Key copied.'; }
        catch (e) { msg.classList.add('error'); msg.textContent = 'Copy failed. Select and copy the key manually.'; }
    });
    document.getElementById('adminAccessKeyList') && document.getElementById('adminAccessKeyList').addEventListener('click', async ev => {
        const msg = document.getElementById('adminAccessKeyMessage');
        const copyBtn = ev.target.closest('[data-ak-copy]');
        const revokeBtn = ev.target.closest('[data-ak-revoke]');
        if (copyBtn) {
            try { await akCopyText(copyBtn.getAttribute('data-ak-copy')); msg.classList.remove('error'); msg.textContent = 'Access Key copied.'; }
            catch (e) { msg.classList.add('error'); msg.textContent = 'Copy failed.'; }
        } else if (revokeBtn) {
            if (!window.confirm('Revoke this Access Key? It will stop working immediately.')) return;
            try {
                await api('api/admin/access-keys.php', { method: 'POST', body: { action: 'revoke', id: Number(revokeBtn.getAttribute('data-ak-revoke')) } });
                msg.classList.remove('error'); msg.textContent = 'Access Key revoked.';
                loadAccessKeys();
            } catch (err) { msg.classList.add('error'); msg.textContent = err.message; loadAccessKeys(); }
        }
    });

    document.getElementById('adminJoinSave') && document.getElementById('adminJoinSave').addEventListener('click', async () => {
        const link = document.getElementById('adminJoinGroupLink').value.trim();
        try {
            await api('api/admin/settings.php', { method: 'POST', body: { action: 'save_join_link', link } });
            document.getElementById('adminJoinMessage').textContent = 'Saved.';
        } catch (err) { document.getElementById('adminJoinMessage').textContent = err.message; }
    });

    document.getElementById('adminJoinClear') && document.getElementById('adminJoinClear').addEventListener('click', async () => {
        try {
            await api('api/admin/settings.php', { method: 'POST', body: { action: 'save_join_link', link: '' } });
            document.getElementById('adminJoinGroupLink').value = '';
            document.getElementById('adminJoinMessage').textContent = 'Cleared.';
        } catch (err) { document.getElementById('adminJoinMessage').textContent = err.message; }
    });

    document.getElementById('adminPanelNameSave') && document.getElementById('adminPanelNameSave').addEventListener('click', async () => {
        const name = document.getElementById('adminPanelName').value.trim();
        try {
            await api('api/admin/settings.php', { method: 'POST', body: { action: 'save_panel_name', name } });
            document.getElementById('adminPanelNameMessage').textContent = 'Saved.';
            setTimeout(() => window.location.reload(), 700);
        } catch (err) { document.getElementById('adminPanelNameMessage').textContent = err.message; }
    });

    document.getElementById('adminSiteMaintenanceToggle') && document.getElementById('adminSiteMaintenanceToggle').addEventListener('click', async () => {
        try {
            const turningOn = !document.getElementById('adminSiteMaintenanceToggle').classList.contains('on');
            if (turningOn && !confirm('Turn Maintenance ON? Users will be blocked and NEW orders (website and API) will be refused until you turn it OFF.')) return;
            const r = await api('api/admin/settings.php', { method: 'POST', body: { action: 'toggle_maintenance', state: turningOn ? 'on' : 'off' } });
            const on = r.maintenance_mode;
            const toggle = document.getElementById('adminSiteMaintenanceToggle');
            toggle.classList.toggle('on', on);
            toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
            toggle.querySelector('b').textContent = on ? 'ON' : 'OFF';
            document.getElementById('adminSiteMaintenanceMessage').textContent = on ? 'Maintenance ON — users blocked.' : 'Maintenance OFF — users restored.';
        } catch (err) { document.getElementById('adminSiteMaintenanceMessage').textContent = err.message; }
    });

    // Some shared hosts ignore .user.ini and keep upload_max_filesize at 2M.
    // Compress oversized logos in the browser before FormData is sent, so the
    // request never hits PHP's pre-application upload limit. WebP preserves
    // transparency and is supported by the server-side MIME allow-list.
    async function prepareGlobalLogoUpload(file) {
        const SAFE_BYTES = Math.floor(1.45 * 1024 * 1024); // safely below a 2M PHP limit
        if (file.size <= SAFE_BYTES) return file;

        if (!/^image\/(png|jpeg|webp)$/i.test(file.type)) {
            throw new Error('Please choose a PNG, JPG or WEBP logo.');
        }

        const dataUrl = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(new Error('Could not read the selected logo.'));
            reader.readAsDataURL(file);
        });

        const img = await new Promise((resolve, reject) => {
            const image = new Image();
            image.onload = () => resolve(image);
            image.onerror = () => reject(new Error('Could not decode the selected logo.'));
            image.src = dataUrl;
        });

        // Keep the logo sharp while preventing huge camera/editor exports from
        // producing a multi-megapixel upload.
        const maxSide = 1800;
        const scale = Math.min(1, maxSide / Math.max(img.naturalWidth || img.width, img.naturalHeight || img.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round((img.naturalWidth || img.width) * scale));
        canvas.height = Math.max(1, Math.round((img.naturalHeight || img.height) * scale));
        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('Your browser cannot prepare this logo for upload.');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

        const blobFromCanvas = (type, quality) => new Promise(resolve => canvas.toBlob(resolve, type, quality));
        let blob = null;
        // WebP keeps transparent logos transparent while dramatically reducing
        // large PNG exports. Try progressively lower quality until under the
        // safe PHP limit.
        for (const quality of [0.88, 0.82, 0.76, 0.70, 0.64, 0.58, 0.52, 0.46]) {
            blob = await blobFromCanvas('image/webp', quality);
            if (blob && blob.size <= SAFE_BYTES) break;
        }

        // Very unusual browser fallback: use JPEG if WebP encoding is absent.
        // A white background avoids an unexpected black background for logos
        // with transparency on this fallback path.
        if (!blob || blob.size > SAFE_BYTES) {
            const jpegCanvas = document.createElement('canvas');
            jpegCanvas.width = canvas.width;
            jpegCanvas.height = canvas.height;
            const jpegCtx = jpegCanvas.getContext('2d');
            jpegCtx.fillStyle = '#ffffff';
            jpegCtx.fillRect(0, 0, jpegCanvas.width, jpegCanvas.height);
            jpegCtx.drawImage(canvas, 0, 0);
            for (const quality of [0.82, 0.74, 0.66, 0.58, 0.50]) {
                blob = await new Promise(resolve => jpegCanvas.toBlob(resolve, 'image/jpeg', quality));
                if (blob && blob.size <= SAFE_BYTES) break;
            }
        }

        if (!blob || blob.size > SAFE_BYTES) {
            throw new Error('This logo is unusually large or complex. Please use a smaller image (under 2 MB).');
        }

        const ext = blob.type === 'image/webp' ? 'webp' : 'jpg';
        return new File([blob], 'global-logo.' + ext, { type: blob.type, lastModified: Date.now() });
    }

    document.getElementById('adminBrandLogoSave') && document.getElementById('adminBrandLogoSave').addEventListener('click', async () => {
        const fileInput = document.getElementById('adminBrandLogoFile');
        const message = document.getElementById('adminBrandLogoMessage');
        if (!fileInput.files.length) { message.textContent = 'Select an image first.'; return; }
        const saveBtn = document.getElementById('adminBrandLogoSave');
        const originalLabel = saveBtn ? saveBtn.textContent : '';
        try {
            if (saveBtn) saveBtn.disabled = true;
            message.textContent = 'Preparing logo for the server…';
            const uploadFile = await prepareGlobalLogoUpload(fileInput.files[0]);
            message.textContent = uploadFile.size < fileInput.files[0].size
                ? 'Logo optimized for the hosting upload limit. Saving…'
                : 'Saving logo…';
            const fd = new FormData();
            fd.append('action', 'save_logo');
            fd.append('logo', uploadFile);
            const r = await fetch(window.KP.baseUrl + '/api/admin/settings.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'X-CSRF-Token': window.KP.csrf },
                body: fd,
            });
            const raw = await r.text();
            let j = null;
            try { j = JSON.parse(raw); } catch (e) { throw new Error('Server returned an invalid upload response. Check uploads/logo permissions and PHP upload limits.'); }
            if (!r.ok || !j.ok) throw new Error(j.error || ('Logo upload failed (' + r.status + ').'));
            const fresh = j.logo_url + (j.logo_url.includes('?') ? '&' : '?') + 'v=' + (j.version || Date.now());
            const preview = document.getElementById('adminBrandLogoPreview');
            const adminLogo = document.getElementById('adminLogo');
            const maintenanceLogo = document.getElementById('maintenanceLogo');
            if (window.KPSetGlobalLogo) window.KPSetGlobalLogo(fresh); else document.querySelectorAll('[data-global-logo]').forEach(el => { el.src = fresh; });
            if (preview) preview.src = fresh;
            if (adminLogo) adminLogo.src = fresh;
            if (maintenanceLogo) maintenanceLogo.src = fresh;
            document.querySelectorAll('link[rel="icon"], link[rel="shortcut icon"]').forEach(el => { el.href = fresh; });
            message.textContent = 'Logo saved successfully. No page reload required.';
            fileInput.value = '';
        } catch (err) {
            message.textContent = err && err.message ? err.message : 'Logo upload failed.';
        } finally {
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = originalLabel; }
        }
    });

    document.getElementById('adminBrandLogoClear') && document.getElementById('adminBrandLogoClear').addEventListener('click', async () => {
        try {
            await api('api/admin/settings.php', { method: 'POST', body: { action: 'clear_logo' } });
            const fresh = window.KP.baseUrl + '/assets/images/default-logo.svg?v=' + Date.now();
            const preview = document.getElementById('adminBrandLogoPreview');
            const adminLogo = document.getElementById('adminLogo');
            const maintenanceLogo = document.getElementById('maintenanceLogo');
            if (preview) preview.src = fresh;
            if (adminLogo) adminLogo.src = fresh;
            if (maintenanceLogo) maintenanceLogo.src = fresh;
            document.querySelectorAll('link[rel="icon"], link[rel="shortcut icon"]').forEach(el => { el.href = fresh; });
            document.getElementById('adminBrandLogoMessage').textContent = 'Default logo restored. No page reload required.';
        } catch (err) { document.getElementById('adminBrandLogoMessage').textContent = err.message; }
    });

    /* ------------------ Boot ------------------ */
    showPage('dashboard');
    setInterval(() => {
        if (currentAdminPage === 'dashboard' && document.getElementById('adminScreen').classList.contains('active')) loadDashboard();
    }, 30000);
})();