# v67 - Master Frontend Control (colours, fonts, scopes, deposit QR + success redesign, ALL RESET)

Base: v66 (includes Provider 6). **No database schema change, no migration.** Everything is stored as ONE row in the existing `settings` table: key `theme_config` (JSON).

## Verification status (honest)
No PHP, MySQL, browser or phone in the build environment. **NOT run:** `php -l`, any page load, DB save/load, visual/responsive/flicker test, QR scan, real payment. Run: `node --check` on all changed JS, `tools/php_lint_approx.py` (0 findings, NOT a real lint), `tools/check_v67_static.py` (34 source-text checks - they read files only), existing v63-v65 checks.
Server: `find . -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors'`.

## What was built
- **Admin -> Master Frontend Control** (menu link, page `admin-theme.php`; modules in `includes/theme.php`, `api/admin/theme.php`, `assets/js/admin-theme.js`, `assets/css/admin-theme.css`).
- **Scopes ALL / USER / ADMIN** (Colour and Font studios each). Two independent stores (`user`, `admin`). ALL writes the same values into both; afterwards they stay independent. USER never touches admin, ADMIN never touches user.
- **Colour Studio**: 23 colours, each with swatch, picker, HEX input, live preview, Apply (into the draft), Save (persist that one colour), reset-to-original. SAVE & APPLY ALL stores the full set. Main/Secondary background and Primary/Secondary card are separate variables and separate rules - changing one never changes another.
- **Font Studio**: Normal/Original (removes every override - each element keeps its own original font), Dashboard Font (the dashboard's own pairing: Inter text + Chakra Petch display), Premium 1-4 (Poppins, Montserrat, Outfit, Playfair Display) with heading/paragraph/button/card/form-label previews. Monospace (`--font-mono`) is never overridden.
- **Permanent + zero flash**: `theme_head()` renders the saved theme as an inline `<style>` at the end of `<head>` (after the site's CSS) on the server - it is in the first paint, no JS, no async fetch. Injected on user pages (header.php, register, welcome, maintenance, login) and admin pages (admin.php, admin-monitor.php, admin login). If anything fails, `theme_head()` returns '' = original theme.
- **ALL RESET**: confirm dialog (Cancel / Confirm Reset) -> deletes only the `theme_config` row -> original CSS is back immediately and after refresh/login. No user, wallet, deposit, order, payment or gateway data is touched (the code contains exactly one DELETE, on that row).
- **Deposit QR page**: larger QR (up to 360 px) on a pure-white quiet zone with a four-sided gold frame, big amount, clear Order ID, big expiry timer, status, 4 payment steps, security chips, global logo in the top bar, mobile-first. **CSS/markup only**: `assets/css/deposit-premium.css` + extra markup in `deposit.php`. All element IDs kept. QR image: no filter/blend/overlay. QR generation, UPI data, order id, expiry, verification, wallet credit: untouched.
- **Payment Success**: global logo (`panel_logo_url()` + `data-global-logo`, so the existing Global Logo upload updates it) in a rounded gold frame, check animation, amount, Order ID, wallet balance. One display-only line added to `deposit.js` to show the Order ID; no logic changed.

## Files
Created: `includes/theme.php`, `api/admin/theme.php`, `admin-theme.php`, `assets/js/admin-theme.js`, `assets/css/admin-theme.css`, `assets/css/deposit-premium.css`, `tools/check_v67_static.py`, `README_V67.md`.
Modified: `includes/bootstrap.php` (1 require), `includes/header.php`, `admin.php` (+nav link), `admin-monitor.php`, `login.php`, `register.php`, `welcome.php`, `maintenance.php` (one `theme_head()` line each), `deposit.php`, `assets/js/deposit.js` (1 display line).
Database: none (one `settings` row `theme_config`, created on first save, removed by ALL RESET).

## Known limitations (read)
- Colours work through the site's CSS variables plus a CURATED selector list (`theme_bridge_rules()`); only for colours you actually set. Hard-coded colours in other components (many in `reference.css` / `admin.css`) are not changed - add their selectors to that list if you see one that does not follow. Status badges, key text and the QR white are intentionally left alone.
- Many labels/buttons use the monospace font on purpose, so premium fonts mostly change body/heading text, not those labels.
- A Google font is fetched once; on a first-ever visit the text may swap from the fallback font to the web font (colours never flash). Fonts need internet access to Google Fonts.
- The control page itself never applies the saved theme (it always keeps the original look so a bad colour can't lock you out). `admin.php?safe=1` also opens the admin without the theme.
- A theme that makes text unreadable is possible (no contrast check). ALL RESET fixes it.
- "Apply" on a colour row = commit to the draft/preview; "Save" = store it for the selected scope.

## Deployment / rollback
1. Back up DB + files. 2. Extract over the site (keep `config/`, `uploads/`). 3. `php -l` command above. 4. Admin -> Master Frontend Control: save a test colour for USER, refresh a user page, then ADMIN, then try ALL RESET. 5. Deposit: create a test order, **scan the QR with a real UPI app** before going live, then complete a real payment test. Rollback: restore the previous files; optionally `DELETE FROM settings WHERE setting_key='theme_config'`.

## Tests still to do on a real server (not performed here)
Scope isolation on both sides, colour independence, persistence after logout/login, ALL RESET, flicker test on desktop + phone, 320-430 px layout of the QR/success pages, QR scan, payment + wallet credit regression, PHP error log, browser console.
