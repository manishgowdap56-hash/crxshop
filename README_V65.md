# v65 - Automatic price fill: independent Normal / Reseller inputs

Base: v64. No DB change, no migration. Membership, wallet, dual pricing, Check Again cards, correction buttons, Auto Sync, custom/manual/pending protection are untouched.

## Verification status (honest)
No PHP, MySQL, browser, jsdom or provider in the build environment. **Not run:** `php -l` (run `find . -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors'` on the server), any real browser/DOM test of the editor, any live provider/DB test (spec tests 1-12 must be done on staging).
Run and passing: `node --check` on all JS, approx tokenizer lint (0 findings), all existing `tools/*.py` checks, new `tools/check_v65_static.py` (11 source-text checks) and `tools/mirror_v65_check.py` (10 checks - a Python MIRROR of the fill rules, not the JS itself).

## What was wrong (found by reading v64)
- Fill eligibility relied only on "empty / 0 / dataset.auto" and `lkReset()` cleared only the Normal input's auto value, so a stale auto Reseller value (or any value with no origin) was handled inconsistently; a programmatic fill and a manual edit were not distinguished per input; a saved plan whose mapping changed had no clear rule.
- `findVariants()` already tolerated a missing rate for one category, but did not report per-category reasons and showed `₹null` in candidate labels.
- A new Provider 5 duration with an empty Reseller price was silently saved as the Normal price (legacy fallback), which can hide an invalid Reseller calculation.

## Changes
`assets/js/admin.js`
- Origin tracked per input (`data-origin`): `auto` (provider calculation), `manual` (typed), `saved` (loaded from DB), empty. Programmatic fills never count as manual.
- Fill after any exact match or CHECK AGAIN: `categories.normal.calculated -> .dur-price`, `categories.reseller.calculated -> .dur-rprice` (explicit category keys, never positions). Eligible = empty / 0 / auto. Manual and saved values are never overwritten; they stay, the calculated price is shown in the card, and the mismatch is reported ("manual/saved price preserved").
- A category with an invalid rate (`INVALID RATE`) is not filled and says so; the other category still fills.
- A mapping change (name/duration/ID) clears auto values of BOTH inputs; manual/saved values stay.
- Saved plan matched by id+product+provider: never auto-filled; use the existing USE NORMAL / USE RESELLER buttons (server validation, custom/manual/review protection unchanged). Saved price whose ID was changed is compared as a saved value with a mismatch and a USE button that only fills that one input.
- Status line lists what happened (ID detected, price auto-filled, preserved, invalid rate) - only what really happened.
- Duplicate identical catalog search is ignored while running; sequence counters/stale checks kept; state stays per duration row.
- Save: a new Provider 5 duration with an empty Reseller price is refused with a message (no silent copy of the Normal price). Other providers/local stock unchanged.
`includes/price_sync.php`: `findVariants()` candidates stay valid when identity + provider price are valid; per-category `normal_reason` / `reseller_reason`; unsupported currency flagged as the candidate's problem.
New: `tools/check_v65_static.py`, `tools/mirror_v65_check.py`, `README_V65.md`.

## Deployment
1. Back up DB (`mysqldump --single-transaction`) and the site files.
2. Extract this ZIP over the site (keep `config/`, `uploads/`). No SQL.
3. Run the `php -l` command above; hard-refresh the admin.
4. Staging tests 1-12 from the task, especially: exact name+duration -> both inputs fill (65/35, $5 -> 325/175); type 400/250 then CHECK AGAIN (kept + mismatch); clear one rate in Monitor; rapid name/duration edits; save, reopen, check `price` vs `reseller_price`.
5. Rollback: restore the previous files (no DB change).
