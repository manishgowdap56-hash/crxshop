/* =====================================================================
   Auth: login + register
   ===================================================================== */
(function () {
    'use strict';
    const { api, toast } = window.KP;
    const adminOnly = document.body.dataset.adminLogin === '1';
    const userOnly = document.body.dataset.userLogin === '1';

    function setMessage(el, text, error) {
        if (!el) return;
        el.textContent = text || '';
        el.classList.toggle('error', !!error);
    }

    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const msg = document.getElementById('loginMessage');
            const username = loginForm.querySelector('input[name="username"]').value.trim();
            const password = loginForm.querySelector('input[name="password"]').value;
            if (!username || !password) {
                setMessage(msg, 'Enter username and password.', true);
                return;
            }
            setMessage(msg, 'Signing in…');
            try {
                const r = await api('api/auth-login.php', { method: 'POST', body: { username, password, admin_only: adminOnly, user_only: userOnly } });
                setMessage(msg, 'Success! Redirecting…');
                window.location.assign(r.redirect);
            } catch (err) {
                setMessage(msg, err.message || 'Login failed.', true);
            }
        });
    }

    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        registerForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const msg = document.getElementById('registerMessage');
            const username = registerForm.querySelector('input[name="username"]').value.trim();
            const password = registerForm.querySelector('input[name="password"]').value;
            const confirm  = registerForm.querySelector('input[name="confirm"]').value;
            const ref      = registerForm.querySelector('input[name="ref"]').value;
            const keyInput = registerForm.querySelector('input[name="access_key"]');   // absent when the admin switched the requirement OFF
            const accessKey = keyInput ? keyInput.value.trim() : '';

            if (username.length < 3) { setMessage(msg, 'Username must be at least 3 characters.', true); return; }
            if (password.length < 6) { setMessage(msg, 'Password must be at least 6 characters.', true); return; }
            if (password !== confirm) { setMessage(msg, 'Passwords do not match.', true); return; }
            // Presence only: the key is validated (bound username, unused) by the server, never in the browser.
            if (keyInput && !accessKey) { setMessage(msg, 'Access Key is required.', true); return; }

            setMessage(msg, 'Creating account…');
            try {
                const r = await api('api/auth-register.php', { method: 'POST', body: Object.assign({ username, password, confirm, ref }, keyInput ? { access_key: accessKey } : {}) });
                setMessage(msg, 'Success! Redirecting…');
                window.location.assign(r.redirect);
            } catch (err) {
                setMessage(msg, err.message || 'Registration failed.', true);
            }
        });
    }
})();