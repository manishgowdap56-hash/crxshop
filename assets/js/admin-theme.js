/* v67 Master Frontend Control - Color Studio, Font Studio, ALL RESET.
   Talks only to api/admin/theme.php. Scope "all" = write the same values to both sides (the server keeps the two sides independent). */
(function () {
    'use strict';
    const BASE = (document.querySelector('meta[name="base-url"]') || {}).content || '';
    const CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
    const HEX = /^#[0-9a-fA-F]{6}$/;

    let CATALOG = { colors: [], fonts: [] };
    let SAVED = { user: { colors: {}, font: 'original' }, admin: { colors: {}, font: 'original' } };
    let working = {};          // committed colours of the shown scope: key -> hex  (Apply / Save put a draft here)
    let draft = {};            // what each row currently shows: key -> hex
    let fontPick = 'original';

    function say(t, kind) { const m = $('#mfcMsg'); m.textContent = t || ''; m.className = 'mfc-msg' + (kind ? ' ' + kind : ''); }
    async function api(path, method, body) {
        const o = { method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (method !== 'GET') { o.headers['Content-Type'] = 'application/json'; o.headers['X-CSRF-Token'] = CSRF; o.body = JSON.stringify(body || {}); }
        const r = await fetch(BASE + '/' + path, o);
        let j = null; try { j = await r.json(); } catch (e) { /* handled below */ }
        if (!j) throw new Error('Server returned a non-JSON response.');
        if (!j.ok) throw new Error(j.error || 'Request failed');
        return j;
    }
    /* "all" starts from the user side's values (the admin can see/save them for both sides); a side scope shows its own. */
    const sideOf = scope => scope === 'admin' ? 'admin' : 'user';
    const scopeVal = name => ($('input[name="' + name + '"]:checked') || {}).value || 'all';
    function scopeHint(scope, kind) {
        if (scope === 'all') {
            const diff = JSON.stringify(SAVED.user[kind === 'color' ? 'colors' : 'font']) !== JSON.stringify(SAVED.admin[kind === 'color' ? 'colors' : 'font']);
            return 'ALL writes the same ' + (kind === 'color' ? 'colours' : 'font') + ' to Admin and User. Shown values are the User side\'s' + (diff ? ' (Admin side currently differs and will be overwritten if you save ALL).' : '.');
        }
        return scope === 'user' ? 'USER SIDE: only the user website changes. Admin settings are not touched.' : 'ADMIN SIDE: only the admin panel changes. User settings are not touched.';
    }

    /* ---------------- colours ---------------- */
    const PV = { bg: '--p-bg', bg2: '--p-bg2', card: '--p-card', card2: '--p-card2', accent: '--p-acc', accent2: '--p-acc2', btn_bg: '--p-btn', btn_text: '--p-btnt',
        text: '--p-text', muted: '--p-muted', heading: '--p-head', input_bg: '--p-ibg', input_border: '--p-ib', card_border: '--p-cb', table_bg: '--p-tb', table_head: '--p-th',
        nav_bg: '--p-nav', sidebar_bg: '--p-side', modal_bg: '--p-modal', success: '--p-ok', warning: '--p-warn', error: '--p-err', soft_border: '--p-sb' };
    const eff = c => draft[c.key] || c.original;
    function paintPreview() { const pv = $('#colorPreview'); CATALOG.colors.forEach(c => pv.style.setProperty(PV[c.key], eff(c))); }
    function rowState(c, row) {
        const v = eff(c), custom = !!working[c.key] || (draft[c.key] && draft[c.key] !== c.original);
        row.classList.toggle('custom', !!custom);
        $('.c-sw', row).style.background = v;
        $('input[type=color]', row).value = v;
        const t = $('input[type=text]', row); if (document.activeElement !== t) t.value = v;
        $('small', row).textContent = (working[c.key] ? 'Applied ' : (draft[c.key] ? 'Draft ' : 'Original ')) + (c.original === v ? '' : '') + '· ' + c.group + ' · ' + c.original;
    }
    function buildColors() {
        const list = $('#colorList'); list.innerHTML = '';
        CATALOG.colors.forEach(c => {
            const row = document.createElement('div'); row.className = 'c-row';
            row.innerHTML = '<div class="c-sw"></div><div class="c-main"><label></label><small></small>' +
                '<div class="c-ctl"><input type="color" aria-label="Pick"><input type="text" maxlength="7" spellcheck="false" aria-label="HEX"></div></div>' +
                '<div class="c-btns"><button type="button" class="mfc-mini" data-a="apply">Apply</button><button type="button" class="mfc-mini p" data-a="save">Save</button><button type="button" class="mfc-mini" data-a="orig" title="Back to the original colour">↺</button></div>';
            $('label', row).textContent = c.label;
            const pick = $('input[type=color]', row), hex = $('input[type=text]', row);
            const set = v => { draft[c.key] = v.toLowerCase(); rowState(c, row); paintPreview(); };
            pick.addEventListener('input', () => { hex.classList.remove('bad'); set(pick.value); });
            hex.addEventListener('input', () => { let v = hex.value.trim(); if (v && v[0] !== '#') v = '#' + v; if (HEX.test(v)) { hex.classList.remove('bad'); set(v); } else hex.classList.add('bad'); });
            row.addEventListener('click', async ev => {
                const b = ev.target.closest('button[data-a]'); if (!b) return;
                const a = b.dataset.a;
                if (a === 'orig') { delete draft[c.key]; delete working[c.key]; rowState(c, row); paintPreview(); say(c.label + ': back to original (not saved yet - press Save to store this).'); return; }
                if (draft[c.key] && !HEX.test(draft[c.key])) { say('Enter a valid HEX colour.', 'err'); return; }
                if (draft[c.key]) working[c.key] = draft[c.key];
                rowState(c, row);
                if (a === 'apply') { say(c.label + ' applied to the draft. Press SAVE & APPLY ALL (or Save on this row) to store it.', 'ok'); return; }
                b.disabled = true;
                try {   // Save = persist just this one colour (merge) for the chosen scope; null removes an override
                    await api('api/admin/theme.php', 'POST', { action: 'save', scope: scopeVal('cscope'), replace: 0, colors: { [c.key]: working[c.key] || null } });
                    await refresh(false); say(c.label + ' saved for ' + scopeVal('cscope').toUpperCase() + '.', 'ok');
                } catch (e) { say(e.message, 'err'); } finally { b.disabled = false; }
            });
            list.appendChild(row); c._row = row;
        });
    }
    function loadColors() {
        const s = scopeVal('cscope'); working = Object.assign({}, SAVED[sideOf(s)].colors); draft = Object.assign({}, working);
        CATALOG.colors.forEach(c => rowState(c, c._row)); paintPreview(); $('#cscopeHint').textContent = scopeHint(s, 'color');
    }
    $('#saveAllColors').addEventListener('click', async ev => {
        const b = ev.currentTarget; const out = {};
        for (const c of CATALOG.colors) { const v = draft[c.key]; if (v && !HEX.test(v)) { say('Fix the invalid HEX for "' + c.label + '".', 'err'); return; } if (v && v !== c.original) out[c.key] = v; else if (working[c.key]) out[c.key] = working[c.key]; }
        b.disabled = true;
        try { await api('api/admin/theme.php', 'POST', { action: 'save', scope: scopeVal('cscope'), replace: 1, colors: out }); await refresh(false); say('Colours saved for ' + scopeVal('cscope').toUpperCase() + ' and active on the next page load.', 'ok'); }
        catch (e) { say(e.message, 'err'); } finally { b.disabled = false; }
    });
    $('#revertColors').addEventListener('click', () => { loadColors(); say('Unsaved changes discarded.'); });
    $$('input[name="cscope"]').forEach(r => r.addEventListener('change', loadColors));

    /* ---------------- fonts ---------------- */
    const SAMPLE = { h: 'Premium Heading', p: 'The quick brown fox jumps over the lazy dog. 0123456789', lab: 'EMAIL ADDRESS' };
    function buildFonts() {
        const g = CATALOG.fonts.filter(f => f.google).map(f => 'family=' + f.google).join('&');
        if (g) { const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = 'https://fonts.googleapis.com/css2?' + g + '&family=Inter:wght@400;600;700&family=Chakra+Petch:wght@600;700&display=swap'; document.head.appendChild(l); }
        const box = $('#fontList'); box.innerHTML = '';
        CATALOG.fonts.forEach(f => {
            const fam = f.id === 'original' ? "'Inter',sans-serif" : f.family;
            const lab = document.createElement('label'); lab.className = 'f-card';
            lab.innerHTML = '<input type="radio" name="font" value="' + f.id + '"><div class="f-box" style="font-family:' + fam + '"><div class="f-name"></div>' +
                '<h4>' + SAMPLE.h + '</h4><p>' + SAMPLE.p + '</p><span class="f-lab">' + SAMPLE.lab + '</span><div><button type="button" tabindex="-1">Button</button></div>' +
                '<div class="f-mini">Card preview — ' + SAMPLE.h + '</div></div>';
            $('.f-name', lab).textContent = f.label.toUpperCase() + (f.id === 'original' ? ' (RESTORES EACH ELEMENT\'S OWN FONT)' : '');
            $('input', lab).addEventListener('change', () => { fontPick = f.id; });
            box.appendChild(lab);
        });
    }
    function loadFont() {
        const s = scopeVal('fscope'); fontPick = SAVED[sideOf(s)].font || 'original';
        $$('input[name="font"]').forEach(i => { i.checked = i.value === fontPick; }); $('#fscopeHint').textContent = scopeHint(s, 'font');
    }
    $('#saveFont').addEventListener('click', async ev => {
        const b = ev.currentTarget; b.disabled = true;
        try { await api('api/admin/theme.php', 'POST', { action: 'save', scope: scopeVal('fscope'), replace: 0, colors: {}, font: fontPick }); await refresh(false); say('Font saved for ' + scopeVal('fscope').toUpperCase() + '.', 'ok'); }
        catch (e) { say(e.message, 'err'); } finally { b.disabled = false; }
    });
    $$('input[name="fscope"]').forEach(r => r.addEventListener('change', loadFont));

    /* ---------------- reset ---------------- */
    const modal = $('#resetModal');
    $('#resetAll').addEventListener('click', () => { modal.hidden = false; $('#resetCancel').focus(); });
    $('#resetCancel').addEventListener('click', () => { modal.hidden = true; });
    modal.addEventListener('click', e => { if (e.target === modal) modal.hidden = true; });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') modal.hidden = true; });
    $('#resetConfirm').addEventListener('click', async ev => {
        const b = ev.currentTarget; b.disabled = true;
        try { await api('api/admin/theme.php', 'POST', { action: 'reset_all' }); modal.hidden = true; await refresh(true); say('Original theme restored for ALL, USER and ADMIN. Only appearance settings were removed.', 'ok'); }
        catch (e) { say(e.message, 'err'); } finally { b.disabled = false; }
    });

    $$('#mfcTabs button').forEach(b => b.addEventListener('click', () => {
        $$('#mfcTabs button').forEach(x => x.classList.toggle('on', x === b));
        $$('.mfc-pane').forEach(p => p.classList.toggle('on', p.id === 'pane-' + b.dataset.tab)); say('');
    }));

    async function refresh(rebuild) {
        const j = await api('api/admin/theme.php', 'GET'); SAVED = j.theme;
        if (rebuild || !CATALOG.colors.length) { CATALOG = { colors: j.colors, fonts: j.fonts }; buildColors(); buildFonts(); }
        loadColors(); loadFont();
    }
    refresh(true).catch(e => say(e.message, 'err'));
})();
