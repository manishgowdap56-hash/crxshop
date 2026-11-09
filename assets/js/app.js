/* =====================================================================
   ROYAL PANEL — global app bootstrap
   Handles: CSRF, JSON helper, tap sound, toasts, drawer, copy helper.
   ===================================================================== */

(function () {
    'use strict';

    const BASE = (document.querySelector('meta[name="base-url"]') || {}).content || '';
    const CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    /* -------- Fetch helper with CSRF + JSON ------------------------- */
    async function api(path, options = {}) {
        const opts = Object.assign({ credentials: 'same-origin', headers: {} }, options);

        // Merge default JSON headers
        if (opts.body && !(opts.body instanceof FormData)) {
            opts.headers['Content-Type'] = opts.headers['Content-Type'] || 'application/json';
            if (typeof opts.body !== 'string') opts.body = JSON.stringify(opts.body);
        }
        if (CSRF && opts.method && opts.method.toUpperCase() !== 'GET') {
            opts.headers['X-CSRF-Token'] = CSRF;
        }

        const url = path.startsWith('http') ? path : (BASE + '/' + path.replace(/^\/+/, ''));
        let res, text;
        try {
            res = await fetch(url, opts);
            text = await res.text();
        } catch (e) {
            throw new Error('Network error. Check your connection.');
        }

        let json = null;
        try { json = JSON.parse(text); } catch (e) {}
        if (!json) throw new Error('Server returned a non-JSON response.');

        if (!json.ok) {
            const err = new Error(json.error || 'Request failed');
            err.payload = json;
            err.status = res.status;
            throw err;
        }
        return json;
    }

    /* -------- Toast --------------------------------------------------- */
    function toast(message, opts = {}) {
        const stack = document.getElementById('globalToastStack');
        if (!stack) return;
        const el = document.createElement('div');
        const cls = opts.variant === 'danger' ? 'g-toast danger' : 'g-toast';
        el.className = cls;
        const icon = opts.icon || (opts.variant === 'danger' ? '⛔' : '✅');
        const title = opts.title || (opts.variant === 'danger' ? 'Failed' : 'Success');
        el.innerHTML = `
          <div class="g-toast-icon">${icon}</div>
          <div style="flex:1;min-width:0">
            <div class="g-toast-title"></div>
            <div class="g-toast-msg"></div>
          </div>
          <button class="g-toast-close" aria-label="Close">×</button>`;
        el.querySelector('.g-toast-title').textContent = title;
        el.querySelector('.g-toast-msg').textContent = message;
        const close = () => { el.classList.add('out'); setTimeout(() => el.remove(), 200); };
        el.querySelector('.g-toast-close').addEventListener('click', close);
        stack.appendChild(el);
        setTimeout(close, opts.duration || 4200);
    }

    /* -------- Copy helper (Clipboard API + fallback) ------------------ */
    async function copyText(value) {
        const text = String(value == null ? '' : value);
        if (!text) throw new Error('Nothing to copy');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            try { return await navigator.clipboard.writeText(text); } catch (e) {}
        }
        // Fallback
        return new Promise((resolve, reject) => {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            ta.style.opacity = '0';
            ta.setAttribute('readonly', '');     // prevents the keyboard opening on iOS
            document.body.appendChild(ta);
            ta.select();
            try { ta.setSelectionRange(0, text.length); } catch (e) {}   // iOS Safari
            let ok = false;
            try { ok = document.execCommand('copy'); } catch (e) {}
            ta.remove();
            ok ? resolve() : reject(new Error('Copy failed'));
        });
    }

    /* -------- Username copy button ---------------------------------- */
    // Copies ONLY the value the server rendered for the authenticated user (data-copy-username),
    // never the visible label/markup. Nothing is sent anywhere; the copy is local to the browser.
    function initCopyUsername() {
        document.addEventListener('click', async (ev) => {
            const btn = ev.target.closest('[data-copy-username]');
            if (!btn) return;
            ev.preventDefault();
            ev.stopPropagation();
            const name = btn.getAttribute('data-copy-username') || '';
            if (!name) return;
            const label = btn.querySelector('.vip-copy-label');
            const ico = btn.querySelector('.vip-copy-ico');
            try {
                await copyText(name);
                btn.classList.add('copied');
                if (label) label.textContent = 'Copied';
                if (ico) ico.textContent = '✓';
                clearTimeout(btn._copyTimer);
                btn._copyTimer = setTimeout(() => {
                    btn.classList.remove('copied');
                    if (label) label.textContent = 'Copy';
                    if (ico) ico.textContent = '📋';
                }, 1500);
            } catch (e) {
                toast('Could not copy. Press and hold the username to copy it manually.', { variant: 'danger', title: 'Copy failed' });
            }
        });
    }

    /* -------- Tap sound --------------------------------------------- */
    const SOUND_KEY = 'keysPanelTapSound';
    let soundEnabled = localStorage.getItem(SOUND_KEY) !== 'off';
    let audioCtx = null;
    let busy = false;
    function playTap() {
        if (!soundEnabled || busy) return;
        busy = true;
        try {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            if (!audioCtx) audioCtx = new AC();
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const t = audioCtx.currentTime;
            [0, 0.095].forEach((off, i) => {
                const osc = audioCtx.createOscillator();
                const g = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(i ? 1320 : 1040, t + off);
                osc.frequency.exponentialRampToValueAtTime(i ? 980 : 820, t + off + 0.075);
                g.gain.setValueAtTime(0.0001, t + off);
                g.gain.exponentialRampToValueAtTime(0.085, t + off + 0.008);
                g.gain.exponentialRampToValueAtTime(0.0001, t + off + 0.085);
                osc.connect(g); g.connect(audioCtx.destination);
                osc.start(t + off); osc.stop(t + off + 0.09);
            });
        } catch (e) {}
        setTimeout(() => { busy = false; }, 170);
    }
    document.addEventListener('pointerdown', (e) => {
        if (!soundEnabled) return;
        if (e.target.closest && e.target.closest('#profileSoundToggle')) return;
        playTap();
    }, { passive: true });

    /* -------- Dashboard drawer -------------------------------------- */
    function initDrawer() {
        if (document.body.dataset.page === 'admin') return;
        const btn = document.getElementById('dashMenu');
        const drawer = document.getElementById('dashDrawer');
        const close = document.getElementById('drawerClose');
        if (!btn || !drawer) return;
        const openDrawer = () => { drawer.classList.add('open'); drawer.setAttribute('aria-hidden','false'); btn.setAttribute('aria-expanded','true'); document.body.classList.add('user-drawer-open'); };
        const closeDrawer = () => { drawer.classList.remove('open'); drawer.setAttribute('aria-hidden','true'); btn.setAttribute('aria-expanded','false'); document.body.classList.remove('user-drawer-open'); };
        btn.addEventListener('click', openDrawer);
        close && close.addEventListener('click', closeDrawer);
        document.addEventListener('click', (e) => {
            if (!drawer.classList.contains('open')) return;
            if (drawer.contains(e.target) || btn.contains(e.target)) return;
            closeDrawer();
        });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDrawer(); });
    }

    /* -------- Password toggle --------------------------------------- */
    function initPasswordToggles() {
        document.querySelectorAll('.password-toggle, .profile-eye').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.target);
                if (!input) return;
                input.type = input.type === 'password' ? 'text' : 'password';
                btn.classList.toggle('is-visible', input.type === 'text');
            });
        });
    }

    /* -------- Dashboard join button -------------------------------- */
    function initJoinButton() {
        const btn = document.getElementById('dashboardJoinButton');
        if (!btn) return;
        btn.addEventListener('click', () => {
            const link = btn.dataset.joinLink || '';
            if (!link) { toast('Join link is not configured by admin.', { variant: 'danger' }); return; }
            window.location.href = link;
        });
    }

    /* -------- Sound toggle button (profile page) ------------------- */
    function initSoundToggle() {
        const btn = document.getElementById('profileSoundToggle');
        if (!btn) return;
        const setUI = () => {
            btn.classList.toggle('off', !soundEnabled);
            btn.setAttribute('aria-pressed', soundEnabled ? 'true' : 'false');
            const state = btn.querySelector('.profile-sound-state');
            const icon = btn.querySelector('.profile-sound-icon');
            if (state) state.textContent = soundEnabled ? 'ON' : 'OFF';
            if (icon) icon.textContent = soundEnabled ? '🔊' : '🔇';
        };
        setUI();
        btn.addEventListener('click', async () => {
            soundEnabled = !soundEnabled;
            localStorage.setItem(SOUND_KEY, soundEnabled ? 'on' : 'off');
            setUI();
            if (soundEnabled) playTap();
            try { await api('api/preferences.php', { method: 'POST', body: { key: 'tap_sound', value: soundEnabled ? 'on' : 'off' } }); } catch (e) {}
        });
    }

    /* -------- Global branding sync --------------------------------- */
    // Every page receives the canonical logo from PHP. This small helper also
    // keeps all currently rendered logo slots/favicons in sync when branding
    // changes without a full page reload (for example from the admin panel).
    function syncGlobalLogo(src) {
        if (!src) return;
        const url = String(src);
        document.querySelectorAll('[data-global-logo]').forEach(el => {
            el.src = url;
        });
        document.querySelectorAll('link[rel="icon"], link[rel="shortcut icon"]').forEach(el => {
            el.href = url;
        });
        window.dispatchEvent(new CustomEvent('keys-panel:logo-updated', { detail: { src: url } }));
    }
    window.KPSetGlobalLogo = syncGlobalLogo;

    /* -------- Global helper exports --------------------------------- */
    window.KP = { api, toast, copyText, baseUrl: BASE, csrf: CSRF, sound: () => soundEnabled };

    document.addEventListener('DOMContentLoaded', () => {
        initDrawer();
        initCopyUsername();
        initPasswordToggles();
        initJoinButton();
        initSoundToggle();
    });
})();