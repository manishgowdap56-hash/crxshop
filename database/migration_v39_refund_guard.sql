-- v39 OPTIONAL hardening: database-level "one refund per order" guarantee.
-- The application ALREADY refunds exactly once (orders row is locked FOR UPDATE, status must be 'pending', and the ledger is
-- checked inside the same transaction). This adds a second, independent safety net in the database itself.
-- Needs MySQL 5.7+ / MariaDB 10.2+ (generated columns). SAFE TO SKIP. No data is changed or deleted.
--
-- STEP 1 - check for existing duplicate refunds. This must return ZERO rows, otherwise STEP 2 will fail (nothing is changed by the failure):
SELECT user_id, reference_id, COUNT(*) AS refunds
FROM wallet_ledger
WHERE type = 'refund' AND reference_id IS NOT NULL
GROUP BY user_id, reference_id
HAVING COUNT(*) > 1;

-- STEP 2 - run ONLY if STEP 1 returned no rows and these statements have not been run before (they error harmlessly if repeated):
ALTER TABLE wallet_ledger
  ADD COLUMN refund_once_key VARCHAR(70)
    GENERATED ALWAYS AS (CASE WHEN type = 'refund' THEN CONCAT(user_id, ':', reference_id) ELSE NULL END) STORED,
  ADD UNIQUE KEY uniq_refund_once (refund_once_key);
