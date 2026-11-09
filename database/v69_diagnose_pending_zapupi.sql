-- v69: READ-ONLY diagnostics for ZapUPI deposits. No schema change is needed for v69. Nothing here writes anything.

-- 1) Orders that were stuck by the v68 parsing bug: still pending, parked as verification_failed.
--    After deploying v69 the cron job (or the admin "VERIFY WITH ZAPUPI" button) re-checks them with ZapUPI and credits each at most once.
SELECT id, order_ref, user_id, amount, status, credit_status, gateway_status, verify_attempts, last_checked_at, created_at
FROM deposits
WHERE method = 'zapupi' AND status = 'pending'
ORDER BY id DESC LIMIT 100;

-- 2) What the provider actually told us (whitelisted fields only; no key is ever stored here).
SELECT id, order_ref, gateway_status, gateway_response FROM deposits WHERE method = 'zapupi' ORDER BY id DESC LIMIT 20;

-- 3) Did ZapUPI's webhook reach the site, and what did we do with it?
--    No rows at all  => the webhook never arrived (check the webhook URL / firewall / ZapUPI dashboard).
--    outcome processed_success => verified and credited.  processed_pending / processed_verification_failed => see 'detail'.
SELECT received_at, ip, order_ref, payload_status, environment, amount, outcome, detail
FROM zapupi_webhook_events ORDER BY id DESC LIMIT 50;

-- 4) Exactly-once proof: every approved ZapUPI deposit must have exactly one credit record and exactly one ledger row.
SELECT d.id, d.order_ref, d.amount,
       (SELECT COUNT(*) FROM payment_credits pc WHERE pc.deposit_id = d.id) AS credit_records,
       (SELECT COUNT(*) FROM wallet_ledger wl WHERE wl.reference_id = CONCAT('DEP#', d.id) AND wl.type = 'deposit') AS ledger_rows
FROM deposits d WHERE d.method = 'zapupi' AND d.status = 'approved'
HAVING credit_records <> 1 OR ledger_rows <> 1;      -- expected: 0 rows
