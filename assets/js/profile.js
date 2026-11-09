/* =====================================================================
   Profile: change password
   ===================================================================== */
(function () {
    'use strict';
    const { api } = window.KP;
    const page = document.body.dataset.page;
    if (page !== 'profile') return;

    const btn = document.getElementById('profileChangePassword');
    const oldP = document.getElementById('profileOldPassword');
    const newP = document.getElementById('profileNewPassword');
    const conf = document.getElementById('profileConfirmPassword');
    const msg = document.getElementById('profilePasswordMessage');

    function setMsg(text, kind) {
        msg.textContent = text || '';
        msg.className = 'profile-password-message ' + (kind || '');
    }

    btn.addEventListener('click', async () => {
        const o = oldP.value, n = newP.value, c = conf.value;
        if (!o || !n || !c) { setMsg('All three fields required.', 'error'); return; }
        if (n.length < 6) { setMsg('New password must be at least 6 characters.', 'error'); return; }
        if (n !== c) { setMsg('Passwords do not match.', 'error'); return; }
        if (n === o) { setMsg('New password must differ from old.', 'error'); return; }

        setMsg('Updating…');
        try {
            await api('api/profile.php', { method: 'POST', body: {
                action: 'change-password',
                old_password: o,
                new_password: n,
                confirm_password: c,
            }});
            oldP.value = newP.value = conf.value = '';
            setMsg('Password changed successfully.', 'success');
        } catch (err) {
            setMsg(err.message || 'Failed to change password.', 'error');
        }
    });
})();