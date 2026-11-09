/* Page-entry navigation controller.
 * The full welcome/loading screen is a real page (welcome.php).
 * It is shown only on a browser refresh of an existing page, or on the
 * initial website entry through index.php. Normal links between pages do
 * not show the loading screen.
 */
(function () {
    'use strict';
    if (window.top !== window.self) return;

    // Admin login route: never send it through the welcome/splash page. That page runs the maintenance
    // gate for not-yet-authenticated visitors, which is what bounced the admin back to Maintenance on refresh.
    if (document.body && document.body.getAttribute('data-admin-login') === '1') return;

    var nav = null;
    try {
        var entries = performance.getEntriesByType && performance.getEntriesByType('navigation');
        if (entries && entries.length) nav = entries[0];
    } catch (e) {}

    var isReload = !!(nav && nav.type === 'reload');
    var firstVisit = false;
    try { firstVisit = sessionStorage.getItem('kpWelcomeSeen') !== '1'; } catch (e) {}
    if (!isReload && !firstVisit) return;

    var meta = document.querySelector('meta[name="base-url"]');
    var base = meta ? meta.getAttribute('content') : '';
    if (!base) return;

    var current = location.pathname + location.search + location.hash;
    var welcome = base.replace(/\/$/, '') + '/welcome.php?return=' + encodeURIComponent(current);
    location.replace(welcome);
})();
