(function () {
    'use strict';
    var loader = document.getElementById('siteBootLoader');
    if (!loader) return;

    var hidden = false;
    function hide() {
        if (hidden) return;
        hidden = true;
        loader.classList.add('is-hidden');
        window.setTimeout(function () {
            if (loader && loader.parentNode) loader.parentNode.removeChild(loader);
        }, 650);
    }

    // Match the supplied index.html splash behavior on every user-page refresh.
    window.setTimeout(hide, 2900);
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) window.setTimeout(hide, 80);
    }, { once: true });
})();
