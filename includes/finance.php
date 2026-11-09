<?php
declare(strict_types=1);

/*
 * v36 — Admin daily financial overview. Every figure comes from the existing tables; nothing is hard-coded or sampled.
 *
 * Definitions (all for "today" in APP_TIMEZONE, default Asia/Kolkata, 00:00:00 inclusive -> next 00:00:00 exclusive):
 *   deposits   wallet_ledger rows type='deposit', amount > 0. The ledger row only exists after a deposit was really
 *              credited (Paytm confirmed / admin approved), so pending, rejected and expired deposits never count.
 *   sales      orders.status = 'completed' (pending / failed / refunded orders are excluded), valued at orders.total_price,
 *              dated by orders.created_at (the orders table has no completion timestamp).
 *   spending   what customers' wallets were debited for COMPLETED orders (wallet_ledger type='purchase' joined to the
 *              completed order). It is the wallet-side view of the same purchases; it is not added to sales.
 *   refunds    wallet_ledger rows type='refund', amount > 0 (money actually returned to a wallet).
 *   net sales  sales - refunds that belong to orders which had counted as a sale. A refund of a pending/failed order never
 *              counted as a sale, so it is not deducted a second time.
 *   withdrawals NOT AVAILABLE: this project has no withdrawal feature, table or ledger type. Reported as null / "N/A".
 *   Wallet deposits are never counted as sales revenue.
 *
 * Duplicate protection: ledger-based sums group by (user, reference) so a duplicated ledger row can never be counted twice.
 */

/**
 * Today's boundaries expressed in the DATABASE's clock. created_at columns are written by MySQL NOW(), so if the MySQL
 * server runs in a different zone than the site, the site-day is shifted by the measured gap (rounded to 15 minutes).
 */
function fin_day_bounds(PDO $pdo): array
{
    try { $tz = new DateTimeZone(APP_TIMEZONE); } catch (Throwable $e) { $tz = new DateTimeZone('Asia/Kolkata'); }
    $now   = new DateTimeImmutable('now', $tz);
    $start = $now->setTime(0, 0, 0);
    $end   = $start->modify('+1 day');

    $gap = 0;
    try {
        $dbNow = (string)$pdo->query('SELECT NOW()')->fetchColumn();
        $raw   = strtotime($dbNow . ' UTC') - strtotime($now->format('Y-m-d H:i:s') . ' UTC');
        $gap   = (int)(round($raw / 900) * 900);
    } catch (Throwable $e) {
        error_log('[fin_day_bounds] ' . $e->getMessage());
    }
    $shift = static fn(DateTimeImmutable $d): string => gmdate('Y-m-d H:i:s', strtotime($d->format('Y-m-d H:i:s') . ' UTC') + $gap);

    return [
        'start' => $shift($start), 'end' => $shift($end),
        'date' => $start->format('Y-m-d'), 'timezone' => $tz->getName(), 'db_gap_seconds' => $gap,
    ];
}

/** Run one metric; a failing query yields null + an entry in $errors instead of breaking the whole dashboard. */
function fin_metric(array &$errors, string $name, callable $fn)
{
    try { return $fn(); }
    catch (Throwable $e) { $errors[$name] = 'unavailable'; error_log('[finance:' . $name . '] ' . $e->getMessage()); return null; }
}

function fin_today(PDO $pdo): array
{
    $b = fin_day_bounds($pdo);
    $p = [':s' => $b['start'], ':e' => $b['end']];
    $errors = [];

    $one = static function (string $sql) use ($pdo, $p): array {
        $q = $pdo->prepare($sql);
        $q->execute($p);
        return $q->fetch() ?: [];
    };

    $deposits = fin_metric($errors, 'deposits', static function () use ($one) {
        $r = $one("SELECT COUNT(*) AS c, COALESCE(SUM(a), 0) AS s FROM (
                       SELECT MAX(amount) AS a FROM wallet_ledger
                       WHERE type = 'deposit' AND amount > 0 AND created_at >= :s AND created_at < :e
                       GROUP BY user_id, COALESCE(reference_id, CONCAT('id:', id))) t");
        return ['amount' => round((float)$r['s'], 2), 'count' => (int)$r['c']];
    });

    $sales = fin_metric($errors, 'sales', static function () use ($one) {
        $r = $one("SELECT COUNT(*) AS c, COALESCE(SUM(total_price), 0) AS s, COUNT(DISTINCT user_id) AS u
                   FROM orders WHERE status = 'completed' AND created_at >= :s AND created_at < :e");
        return ['amount' => round((float)$r['s'], 2), 'count' => (int)$r['c'], 'unique_users' => (int)$r['u']];
    });

    $spending = fin_metric($errors, 'spending', static function () use ($one) {
        $r = $one("SELECT COALESCE(SUM(a), 0) AS s FROM (
                       SELECT MAX(-l.amount) AS a FROM wallet_ledger l
                       JOIN orders o ON o.order_ref = l.reference_id AND o.user_id = l.user_id
                       WHERE l.type = 'purchase' AND l.amount < 0 AND o.status = 'completed'
                         AND l.created_at >= :s AND l.created_at < :e
                       GROUP BY l.user_id, l.reference_id) t");
        return round((float)$r['s'], 2);
    });

    $refunds = fin_metric($errors, 'refunds', static function () use ($one) {
        $r = $one("SELECT COUNT(*) AS c, COALESCE(SUM(a), 0) AS s FROM (
                       SELECT MAX(amount) AS a FROM wallet_ledger
                       WHERE type = 'refund' AND amount > 0 AND created_at >= :s AND created_at < :e
                       GROUP BY user_id, COALESCE(reference_id, CONCAT('id:', id))) t");
        return ['amount' => round((float)$r['s'], 2), 'count' => (int)$r['c']];
    });

    $refundsOfSales = fin_metric($errors, 'net_sales', static function () use ($one) {
        $r = $one("SELECT COALESCE(SUM(a), 0) AS s FROM (
                       SELECT MAX(l.amount) AS a FROM wallet_ledger l
                       JOIN orders o ON o.order_ref = l.reference_id AND o.user_id = l.user_id
                       WHERE l.type = 'refund' AND l.amount > 0 AND o.status IN ('completed', 'refunded')
                         AND l.created_at >= :s AND l.created_at < :e
                       GROUP BY l.user_id, l.reference_id) t");
        return round((float)$r['s'], 2);
    });

    $counts = fin_metric($errors, 'pending_orders', static function () use ($pdo) { return rc_counts($pdo); });

    return [
        'date'            => $b['date'],
        'timezone'        => $b['timezone'],
        'db_gap_seconds'  => $b['db_gap_seconds'],
        'deposits'        => $deposits,
        'sales'           => $sales,
        'spending'        => $spending,
        'unique_buyers'   => $sales['unique_users'] ?? null,
        'refunds'         => $refunds,
        'net_sales'       => ($sales !== null && $refundsOfSales !== null) ? round($sales['amount'] - $refundsOfSales, 2) : null,
        'withdrawals'     => [
            'supported' => false,
            'total'     => null,
            'pending'   => null,
            'note'      => 'This project has no withdrawal feature or table, so there is no real data to show.',
        ],
        'pending_orders'  => $counts['pending'] ?? null,
        'expired_pending' => $counts['expired'] ?? null,
        'needs_review'    => $counts['review'] ?? null,
        'processing_window_seconds' => rc_window_seconds(),
        'errors'          => $errors,
    ];
}
