/* v36 user-header live clock: 12-hour time with AM/PM + date, updated every second, in the website timezone.
   The browser's own clock is not trusted: the server sends its current time once and the clock runs from that offset. */
(function () {
    'use strict';
    var root = document.getElementById('dashClock');
    if (!root) return;
    var timeEl = root.querySelector('.dash-clock-time');
    var dateEl = root.querySelector('.dash-clock-date');
    if (!timeEl || !dateEl) return;

    var tz = root.getAttribute('data-tz') || 'Asia/Kolkata';
    var serverMs = parseInt(root.getAttribute('data-server-ms') || '', 10);
    var offset = isFinite(serverMs) ? serverMs - Date.now() : 0;

    var fmt;
    try {
        fmt = new Intl.DateTimeFormat('en-GB', { timeZone: tz, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true, day: '2-digit', month: 'short', year: 'numeric' });
        fmt.format(0);
    } catch (e) { fmt = null; }          // unknown timezone / no Intl: fall back to the device's local time below

    function parts(ms) {
        var d = new Date(ms), p = {};
        if (fmt) {
            fmt.formatToParts(d).forEach(function (x) { p[x.type] = x.value; });
            var h = parseInt(p.hour, 10);
            if (!isFinite(h) || h === 0) h = 12;                       // some engines return 00 for 12 AM
            if (h > 12) h -= 12;
            return { h: String(h).padStart(2, '0'), m: p.minute, s: p.second, ap: String(p.dayPeriod || '').toUpperCase().replace(/\./g, ''), d: p.day, mo: p.month, y: p.year };
        }
        var hh = d.getHours(), ap = hh >= 12 ? 'PM' : 'AM';
        hh = hh % 12 || 12;
        var mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][d.getMonth()];
        var z = function (n) { return String(n).padStart(2, '0'); };
        return { h: z(hh), m: z(d.getMinutes()), s: z(d.getSeconds()), ap: ap, d: z(d.getDate()), mo: mon, y: String(d.getFullYear()) };
    }

    var lastDate = '';
    function tick() {
        var p = parts(Date.now() + offset);
        timeEl.textContent = p.h + ':' + p.m + ':' + p.s + ' ' + p.ap;
        var ds = p.d + ' ' + p.mo + ' ' + p.y;
        if (ds !== lastDate) { dateEl.textContent = ds; lastDate = ds; }
    }
    tick();
    // Re-align to the next whole second, then every second (no drift, no timer pile-up when the tab is throttled).
    setTimeout(function () { tick(); setInterval(tick, 1000); }, 1000 - ((Date.now() + offset) % 1000));
})();
