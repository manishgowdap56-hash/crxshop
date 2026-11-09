/* Dashboard display: simple automatic cross-fade between the configured slides. */
(function () {
    'use strict';
    var root = document.getElementById('dashDisplay');
    if (!root) return;
    var slides = Array.prototype.slice.call(root.querySelectorAll('.dd-slide'));
    if (slides.length < 2) return;           // one slide = static, nothing to rotate

    var INTERVAL = 4500, index = 0, timer = null;

    function show(next) {
        slides[index].classList.remove('active');
        index = next;
        slides[index].classList.add('active');
    }
    function start() {
        stop();
        timer = window.setInterval(function () { show((index + 1) % slides.length); }, INTERVAL);
    }
    function stop() {
        if (timer) { window.clearInterval(timer); timer = null; }
    }

    // No background work while the tab/app is hidden.
    document.addEventListener('visibilitychange', function () { document.hidden ? stop() : start(); });
    start();
})();
