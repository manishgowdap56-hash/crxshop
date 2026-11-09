<?php
declare(strict_types=1);

/*
 * v37 — Referral commission: ONE implementation shared by every purchase path
 * (website purchase, /api/purchase-api.php, reseller gateway "buy").
 *
 *   - The active percentage lives in the database (settings.referral_commission_percent). config.php's REFERRAL_PERCENT is
 *     only the first-run default used until an admin saves a value. The browser never supplies a percentage.
 *   - Commission = eligible purchase amount x active percent / 100, rounded to 2 decimals (see referral_compute()).
 *   - Eligible purchase = a COMPLETED order of the referred buyer. Deposits / wallet top-ups never reach this code.
 *   - Every commission is one row in `referral_commissions` (the commission ledger) with the percentage that was applied, so
 *     changing the percentage only affects FUTURE rows. Rows are never recalculated or deleted.
 *   - UNIQUE(order_id) + a wallet_ledger check make a repeated call for the same order a no-op (no second credit).
 *   - It runs inside the caller's DB transaction (it never begins/commits one). A failure is isolated with a SAVEPOINT so a
 *     commission problem cannot abort a customer's purchase; it is logged and recorded as status 'failed' for admin follow-up.
 */

const REFERRAL_SETTING_KEY  = 'referral_commission_percent';
const REFERRAL_MIN_PERCENT  = 0.10;
const REFERRAL_MAX_PERCENT  = 25.00;

/* ------------------------------------------------------------------ */
/*  Percentage                                                        */
/* ------------------------------------------------------------------ */

function referral_default_percent(): float
{
    return defined('REFERRAL_PERCENT') ? (float)REFERRAL_PERCENT : 5.0;
}

function referral_percent_valid(float $p): bool
{
    return is_finite($p) && $p >= REFERRAL_MIN_PERCENT - 1e-9 && $p <= REFERRAL_MAX_PERCENT + 1e-9;
}

/** The active percentage, read on the server from the database every time. Falls back to the config default if unset/invalid. */
function referral_percent(): float
{
    $raw = get_setting(REFERRAL_SETTING_KEY, '');
    if ($raw !== null && $raw !== '' && is_numeric($raw)) {
        $v = round((float)$raw, 2);
        if (referral_percent_valid($v)) return $v;
    }
    return referral_default_percent();
}

/** "5%" / "2.5%" — never rounds a fractional percentage to an integer. */
function referral_percent_label(?float $p = null): string
{
    $p = $p ?? referral_percent();
    $s = number_format($p, 2, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return ($s === '' ? '0' : $s) . '%';
}

/** The one commission formula. */
function referral_compute(float $eligibleAmount, float $percent): float
{
    if ($eligibleAmount <= 0 || $percent <= 0) return 0.0;
    return round($eligibleAmount * $percent / 100.0, 2);
}

/** Validate an admin-submitted percentage string. Returns [ok, value, message]. */
function referral_parse_percent($raw): array
{
    $s = trim((string)$raw);
    if ($s === '' || !preg_match('/^\d{1,2}(\.\d{1,2})?$/', $s)) {
        return [false, 0.0, 'Enter a percentage with at most 2 decimals, e.g. 3 or 2.5.'];
    }
    $v = round((float)$s, 2);
    if (!referral_percent_valid($v)) {
        return [false, 0.0, 'Percentage must be between ' . referral_percent_label(REFERRAL_MIN_PERCENT) . ' and ' . referral_percent_label(REFERRAL_MAX_PERCENT) . '.'];
    }
    return [true, $v, ''];
}

/* ------------------------------------------------------------------ */
/*  Schema (additive; runs once, flag settings.schema_v37)            */
/* ------------------------------------------------------------------ */

function referral_ledger_table_sql(): string
{
    return "CREATE TABLE IF NOT EXISTS referral_commissions (
      id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      referrer_id        INT UNSIGNED NOT NULL,
      referred_id        INT UNSIGNED NOT NULL,
      order_id           BIGINT UNSIGNED NOT NULL,
      order_ref          VARCHAR(40)  DEFAULT NULL,
      eligible_amount    DECIMAL(12,2) NOT NULL,
      percent            DECIMAL(5,2)  NOT NULL,
      commission         DECIMAL(12,2) NOT NULL,
      status             ENUM('credited','pending','failed','reversed') NOT NULL DEFAULT 'credited',
      channel            VARCHAR(12)  NOT NULL DEFAULT 'website',
      wallet_ledger_id   BIGINT UNSIGNED DEFAULT NULL,
      reversal_ledger_id BIGINT UNSIGNED DEFAULT NULL,
      reversed_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      reversed_at        DATETIME DEFAULT NULL,
      note               VARCHAR(255) DEFAULT NULL,
      created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_commission_order (order_id),
      KEY idx_rcm_referrer (referrer_id, created_at),
      KEY idx_rcm_referred (referred_id),
      KEY idx_rcm_status (status, created_at),
      KEY idx_rcm_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

/** Last reason referral_schema_ensure() failed (admin-safe: classified, no SQL / credentials), or null. */
function referral_schema_error(): ?array
{
    return $GLOBALS['__referral_schema_err'] ?? null;
}

/**
 * Make sure the commission ledger EXISTS (verified against information_schema on every request - the schema_v37 flag alone is
 * never trusted: a restored backup, a different database or a half-applied manual migration can leave the flag set without the
 * table, which is exactly how the "missing table referral_commissions" error happened), seed the default percentage and copy
 * the pre-v37 commissions into the ledger once. referral_events, referrals and wallet_ledger are only READ here.
 *
 * DDL implicitly COMMITs in MySQL, so the table is never created while the caller has a transaction open (a purchase);
 * in that case this only reports whether the table exists and the next admin / non-transactional request repairs it.
 */
function referral_schema_ensure(PDO $pdo): bool
{
    static $ok = false;
    if ($ok) return true;
    unset($GLOBALS['__referral_schema_err']);
    try {
        $created = false;
        if (!rp_table_exists($pdo, 'referral_commissions')) {
            if ($pdo->inTransaction()) {
                error_log('[referral_schema_ensure] referral_commissions is missing; not creating it inside an open transaction');
                $GLOBALS['__referral_schema_err'] = ['code' => 'SCHEMA_MISMATCH', 'message' => 'The commission ledger table is missing; it is recreated on the next admin page load.'];
                return false;
            }
            $pdo->exec(referral_ledger_table_sql());
            if (!rp_table_exists($pdo, 'referral_commissions')) {
                $GLOBALS['__referral_schema_err'] = ['code' => 'SCHEMA_INCOMPLETE', 'message' => 'The commission ledger table could not be created. Give the database user CREATE privilege, or run database/migration_v37_ops.sql in phpMyAdmin.'];
                return false;
            }
            $created = true;
            error_log('[referral_schema_ensure] created referral_commissions');
        }

        // Default percentage: inserted only when absent (an admin-saved value is never overwritten).
        if ($created || get_setting(REFERRAL_SETTING_KEY, '') === '') {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW())
                           ON DUPLICATE KEY UPDATE setting_key = setting_key")
                ->execute([':k' => REFERRAL_SETTING_KEY, ':v' => number_format(referral_default_percent(), 2, '.', '')]);
            settings_reload();
        }

        // History import. Idempotent (INSERT IGNORE + UNIQUE(order_id)); repeated when the table had to be recreated.
        // A failure here is NOT fatal: the ledger stays usable for new commissions and the error is logged.
        if (($created || get_setting('referral_ledger_backfilled', '') !== '1') && rp_table_exists($pdo, 'referral_events')) {
            try {
                $pdo->exec(referral_backfill_sql());
                set_setting('referral_ledger_backfilled', '1');
            } catch (Throwable $e) {
                error_log('[referral_schema_ensure] history import failed (ledger still usable): ' . $e->getMessage());
            }
        }
        if (get_setting('schema_v37', '') !== '1') set_setting('schema_v37', '1');
        return $ok = true;
    } catch (Throwable $e) {
        error_log('[referral_schema_ensure] ' . $e->getMessage());
        $GLOBALS['__referral_schema_err'] = function_exists('ops_classify_db_error') ? ops_classify_db_error($e) : ['code' => 'DB_ERROR', 'message' => 'Database error.'];
        return false;
    }
}

/** One ledger row per historical commission (pre-v37 referral_events). Never credits anything. */
function referral_backfill_sql(): string
{
    return "INSERT IGNORE INTO referral_commissions
                (referrer_id, referred_id, order_id, order_ref, eligible_amount, percent, commission, status, channel, wallet_ledger_id, note, created_at)
            SELECT e.referrer_id, e.referred_id, e.order_id, o.order_ref,
                   COALESCE(o.total_price, 0),
                   LEAST(999.99, CASE WHEN COALESCE(o.total_price, 0) > 0 THEN ROUND(e.amount / o.total_price * 100, 2) ELSE 0 END),
                   e.amount, 'credited', 'legacy',
                   (SELECT l.id FROM wallet_ledger l WHERE l.user_id = e.referrer_id AND l.type = 'referral' AND l.reference_id = CONCAT('ORD#', e.order_id) ORDER BY l.id ASC LIMIT 1),
                   'Imported from referral_events (pre-v37)', e.created_at
            FROM referral_events e
            LEFT JOIN orders o ON o.id = e.order_id
            WHERE e.order_id IS NOT NULL";
}

/* ------------------------------------------------------------------ */
/*  Credit                                                            */
/* ------------------------------------------------------------------ */

/**
 * Credit the referrer for one completed order. MUST be called inside the purchase transaction, after the order row is
 * 'completed'. Never throws. Returns 'credited' | 'duplicate' | 'not_referred' | 'ineligible' | 'zero' | 'failed'.
 *
 * $channel: website | api | reseller   (stored for reporting only)
 */
function referral_credit_commission(PDO $pdo, int $buyerId, int $orderId, float $amount, string $channel = 'website'): string
{
    $savepoint = false;
    try {
        $hasLedger = referral_schema_ensure($pdo);      // true only when the commission ledger table exists

        $u = $pdo->prepare('SELECT referred_by FROM users WHERE id = :id');
        $u->execute([':id' => $buyerId]);
        $referrerId = (int)$u->fetchColumn();
        if ($referrerId <= 0 || $referrerId === $buyerId) return 'not_referred';

        $r = $pdo->prepare('SELECT id FROM users WHERE id = :id');
        $r->execute([':id' => $referrerId]);
        if (!$r->fetchColumn()) return 'not_referred';

        // Eligibility comes from the ORDER row, not from the caller: it must be this buyer's, and COMPLETED (never failed/pending/cancelled).
        $o = $pdo->prepare('SELECT id, user_id, order_ref, total_price, status FROM orders WHERE id = :id');
        $o->execute([':id' => $orderId]);
        $order = $o->fetch();
        if (!$order || (int)$order['user_id'] !== $buyerId || (string)$order['status'] !== 'completed') return 'ineligible';
        $eligible = round((float)$order['total_price'], 2);
        if (abs($eligible - round($amount, 2)) > 0.009) {
            error_log('[referral] order ' . $orderId . ': caller amount ' . $amount . ' differs from order total ' . $eligible . '; using the order total');
        }

        $percent    = referral_percent();                     // read on the server, right now
        $commission = referral_compute($eligible, $percent);
        if ($commission <= 0) return 'zero';

        $refKey = 'ORD#' . $orderId;
        $dup = $pdo->prepare("SELECT id FROM wallet_ledger WHERE user_id = :u AND type = 'referral' AND reference_id = :r LIMIT 1");
        $dup->execute([':u' => $referrerId, ':r' => $refKey]);
        if ($dup->fetchColumn()) return 'duplicate';

        if ($pdo->inTransaction()) { $pdo->exec('SAVEPOINT referral_commission'); $savepoint = true; }

        // Reserve the order in the commission ledger first: UNIQUE(order_id) is the hard duplicate guard.
        $ledgerId = 0;
        if ($hasLedger) {
            $ex = $pdo->prepare('SELECT id, status FROM referral_commissions WHERE order_id = :o FOR UPDATE');
            $ex->execute([':o' => $orderId]);
            $row = $ex->fetch();
            if ($row && (string)$row['status'] !== 'failed') { if ($savepoint) $pdo->exec('RELEASE SAVEPOINT referral_commission'); return 'duplicate'; }
            if ($row) {
                $ledgerId = (int)$row['id'];                   // a previous attempt was recorded as failed: retry on the same row
                $pdo->prepare("UPDATE referral_commissions SET referrer_id=:r, referred_id=:u, order_ref=:ref, eligible_amount=:e, percent=:p, commission=:c, status='credited', channel=:ch, note=NULL WHERE id=:id")
                    ->execute([':r' => $referrerId, ':u' => $buyerId, ':ref' => (string)$order['order_ref'], ':e' => $eligible, ':p' => $percent, ':c' => $commission, ':ch' => substr($channel, 0, 12), ':id' => $ledgerId]);
            } else {
                try {
                    $pdo->prepare("INSERT INTO referral_commissions (referrer_id, referred_id, order_id, order_ref, eligible_amount, percent, commission, status, channel, created_at)
                                   VALUES (:r, :u, :o, :ref, :e, :p, :c, 'credited', :ch, NOW())")
                        ->execute([':r' => $referrerId, ':u' => $buyerId, ':o' => $orderId, ':ref' => (string)$order['order_ref'], ':e' => $eligible, ':p' => $percent, ':c' => $commission, ':ch' => substr($channel, 0, 12)]);
                    $ledgerId = (int)$pdo->lastInsertId();
                } catch (PDOException $e) {
                    if ((string)$e->getCode() === '23000') { if ($savepoint) $pdo->exec('RELEASE SAVEPOINT referral_commission'); return 'duplicate'; }
                    throw $e;
                }
            }
        }

        // Wallet credit (row-locked) + wallet ledger row — same statements the three old copies used.
        $lock = $pdo->prepare('SELECT balance FROM users WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $referrerId]);
        $newBal = round((float)$lock->fetchColumn() + $commission, 2);
        $pdo->prepare('UPDATE users SET balance = :b WHERE id = :id')->execute([':b' => $newBal, ':id' => $referrerId]);
        $pdo->prepare(
            "INSERT INTO wallet_ledger (user_id, amount, balance_after, type, reference_id, description, created_at)
             VALUES (:u, :a, :b, 'referral', :r, :d, NOW())"
        )->execute([':u' => $referrerId, ':a' => $commission, ':b' => $newBal, ':r' => $refKey, ':d' => 'Referral commission (' . referral_percent_label($percent) . ')']);
        $walletLedgerId = (int)$pdo->lastInsertId();

        if ($hasLedger && $ledgerId > 0) {
            $pdo->prepare('UPDATE referral_commissions SET wallet_ledger_id = :w WHERE id = :id')->execute([':w' => $walletLedgerId, ':id' => $ledgerId]);
        }

        // Legacy tables keep working: the user-facing Refer & Earn page reads them.
        $pdo->prepare(
            'INSERT INTO referrals (referrer_id, referred_id, active, total_earned, created_at)
             VALUES (:r, :u, 1, :e, NOW())
             ON DUPLICATE KEY UPDATE active = 1, total_earned = total_earned + VALUES(total_earned)'
        )->execute([':r' => $referrerId, ':u' => $buyerId, ':e' => $commission]);
        $pdo->prepare('INSERT INTO referral_events (referrer_id, referred_id, order_id, amount, created_at) VALUES (:r, :u, :o, :a, NOW())')
            ->execute([':r' => $referrerId, ':u' => $buyerId, ':o' => $orderId, ':a' => $commission]);

        if ($savepoint) $pdo->exec('RELEASE SAVEPOINT referral_commission');
        return 'credited';
    } catch (Throwable $e) {
        error_log('[referral_credit_commission] order ' . $orderId . ': ' . $e->getMessage());
        try {
            if ($savepoint) $pdo->exec('ROLLBACK TO SAVEPOINT referral_commission');
            // Record the failure (best effort) so an admin can see it; a later retry reuses this row.
            if (referral_schema_ensure($pdo) && isset($referrerId, $eligible, $percent, $commission) && $referrerId > 0) {
                $pdo->prepare("INSERT INTO referral_commissions (referrer_id, referred_id, order_id, order_ref, eligible_amount, percent, commission, status, channel, note, created_at)
                               VALUES (:r, :u, :o, :ref, :e, :p, :c, 'failed', :ch, :n, NOW())
                               ON DUPLICATE KEY UPDATE note = VALUES(note)")
                    ->execute([':r' => $referrerId, ':u' => $buyerId, ':o' => $orderId, ':ref' => (string)($order['order_ref'] ?? ''), ':e' => $eligible, ':p' => $percent, ':c' => $commission, ':ch' => substr($channel, 0, 12), ':n' => ops_sanitize('Credit failed: ' . $e->getMessage(), 250)]);
            }
        } catch (Throwable $e2) { error_log('[referral_credit_commission:record_failure] ' . $e2->getMessage()); }
        audit_log('referral.commission_failed', 'failure', ['entity_type' => 'order', 'entity_id' => $orderId, 'user_id' => $buyerId, 'reason' => 'Commission could not be credited; the purchase itself was not affected.']);
        return 'failed';
    }
}

/** Name kept for the existing call sites in purchase.php / purchase-api.php / reseller_gateway.php. */
function credit_referral_commission(PDO $pdo, int $buyerId, int $orderId, float $amount, string $channel = 'website'): void
{
    referral_credit_commission($pdo, $buyerId, $orderId, $amount, $channel);
}

/* ------------------------------------------------------------------ */
/*  Reversal (history is kept: the original row is flagged, not deleted) */
/* ------------------------------------------------------------------ */

/**
 * Take back a credited commission when its order is reversed. Runs inside the caller's transaction.
 * Debits at most the referrer's current balance (never makes a wallet negative) and records exactly what was taken back.
 * NOTE: in the current code base no path refunds a COMPLETED order (the refund controller only refunds PENDING orders, which
 * never earned a commission), so nothing calls this yet. It exists so that a future completed-order refund can do so safely.
 * Returns 'reversed' | 'not_applicable' | 'failed'.
 */
function referral_reverse_commission(PDO $pdo, int $orderId, string $reason): string
{
    try {
        if (!rp_table_exists($pdo, 'referral_commissions')) return 'not_applicable';
        $q = $pdo->prepare('SELECT * FROM referral_commissions WHERE order_id = :o FOR UPDATE');
        $q->execute([':o' => $orderId]);
        $c = $q->fetch();
        if (!$c || (string)$c['status'] !== 'credited') return 'not_applicable';

        $lock = $pdo->prepare('SELECT balance FROM users WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => (int)$c['referrer_id']]);
        $bal  = (float)$lock->fetchColumn();
        $take = round(min($bal, (float)$c['commission']), 2);
        $newBal = round($bal - $take, 2);
        $revLedger = null;
        if ($take > 0) {
            $pdo->prepare('UPDATE users SET balance = :b WHERE id = :id')->execute([':b' => $newBal, ':id' => (int)$c['referrer_id']]);
            $pdo->prepare("INSERT INTO wallet_ledger (user_id, amount, balance_after, type, reference_id, description, created_at) VALUES (:u, :a, :b, 'referral', :r, :d, NOW())")
                ->execute([':u' => (int)$c['referrer_id'], ':a' => -$take, ':b' => $newBal, ':r' => 'ORD#' . $orderId . ':REV', ':d' => 'Referral commission reversed']);
            $revLedger = (int)$pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE referral_commissions SET status = 'reversed', reversed_amount = :a, reversed_at = NOW(), reversal_ledger_id = :l, note = :n WHERE id = :id AND status = 'credited'")
            ->execute([':a' => $take, ':l' => $revLedger, ':n' => ops_sanitize($reason, 250), ':id' => (int)$c['id']]);
        audit_log('referral.commission_reversed', 'success', ['entity_type' => 'order', 'entity_id' => $orderId, 'user_id' => (int)$c['referrer_id'], 'reason' => 'Reversed ' . number_format($take, 2, '.', '') . ' of ' . number_format((float)$c['commission'], 2, '.', '') . ': ' . $reason]);
        return 'reversed';
    } catch (Throwable $e) {
        error_log('[referral_reverse_commission] ' . $e->getMessage());
        return 'failed';
    }
}
