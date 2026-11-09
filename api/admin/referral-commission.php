<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
$admin = require_admin();

/*
 * Admin > REFERRAL COMMISSION MANAGEMENT  (admin only; every number comes from the database)
 *   GET  ?view=settings                      active percentage, allowed range, quick options, last change
 *   GET  ?view=summary                       overall cards (commission ledger + referrals + deposits of referred users)
 *   GET  ?view=users   &q= &page=            user-wise earnings (one row per referring user)
 *   GET  ?view=history &q= &from= &to= &status= &percent= &referrer= &referred= &uid= &page=
 *   GET  ?view=detail  &id=                  one commission: order, wallet ledger entry, percentage that was applied
 *   GET  ?export=1     (same filters as history)   CSV, admin only
 *   POST action=save_percent, percent        change the ACTIVE percentage (affects future commissions only)
 * The browser never decides a percentage or a commission amount; it only displays what this file returns.
 */
$pdo = db();
$adminId = (int)$admin['id'];

set_exception_handler(static function (Throwable $e): void {
    $c = ops_classify_db_error($e);
    error_log('[referral-commission] ' . $c['code'] . ' ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    if (!headers_sent()) json_error($c['message'], $c['status'], ['code' => $c['code'], 'detail' => $c['detail']]);
});

const RCM_PER_PAGE = 20;
const RCM_STATUSES = ['credited', 'pending', 'failed', 'reversed'];

/* ------------------------------ helpers ------------------------------ */

function rcm_date(string $v): ?string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return null;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : null;
}

function rcm_like(string $v): string
{
    return '%' . addcslashes($v, '\\%_') . '%';
}

/** username LIKE, or exact id when the text is all digits. Parameter names are unique (native prepares cannot reuse one). */
function rcm_user_cond(string $alias, string $v, callable $p): string
{
    if (ctype_digit($v)) return '(' . $alias . '.id = ' . $p((int)$v) . ' OR ' . $alias . '.username LIKE ' . $p(rcm_like($v)) . ')';
    return $alias . '.username LIKE ' . $p(rcm_like($v));
}

/** Parse filters from the request and build the WHERE clause for referral_commissions c / users ru (referrer) / users du (referred). */
function rcm_history_where(array &$params): string
{
    $n = 0;
    $p = static function ($v) use (&$params, &$n): string { $k = ':f' . (++$n); $params[$k] = $v; return $k; };
    $w = [];

    $q = trim(input_str('q'));
    if ($q !== '') {
        $parts = [rcm_user_cond('ru', $q, $p), rcm_user_cond('du', $q, $p), 'c.order_ref LIKE ' . $p(rcm_like($q))];
        if (ctype_digit($q)) $parts[] = 'c.order_id = ' . $p((int)$q);
        $w[] = '(' . implode(' OR ', $parts) . ')';
    }
    $from = rcm_date(input_str('from')); $to = rcm_date(input_str('to'));
    if ($from) $w[] = 'c.created_at >= ' . $p($from . ' 00:00:00');
    if ($to)   $w[] = 'c.created_at < DATE_ADD(' . $p($to . ' 00:00:00') . ', INTERVAL 1 DAY)';
    $st = input_str('status');
    if ($st !== '' && in_array($st, RCM_STATUSES, true)) $w[] = 'c.status = ' . $p($st);
    $pc = input_str('percent');
    if ($pc !== '' && is_numeric($pc)) $w[] = 'c.percent = ' . $p(number_format((float)$pc, 2, '.', ''));
    $rf = trim(input_str('referrer'));
    if ($rf !== '') $w[] = rcm_user_cond('ru', $rf, $p);
    $rd = trim(input_str('referred'));
    if ($rd !== '') $w[] = rcm_user_cond('du', $rd, $p);
    $uid = input_int('uid');
    if ($uid > 0) $w[] = 'c.referrer_id = ' . $p($uid);

    return $w ? ' WHERE ' . implode(' AND ', $w) : '';
}

const RCM_FROM = ' FROM referral_commissions c LEFT JOIN users ru ON ru.id = c.referrer_id LEFT JOIN users du ON du.id = c.referred_id';

function rcm_row(array $r): array
{
    return [
        'id'              => (int)$r['id'],
        'referrer_id'     => (int)$r['referrer_id'],
        'referrer'        => $r['referrer_name'] ?? null,
        'referred_id'     => (int)$r['referred_id'],
        'referred'        => $r['referred_name'] ?? null,
        'order_id'        => (int)$r['order_id'],
        'order_ref'       => $r['order_ref'],
        'eligible_amount' => (float)$r['eligible_amount'],
        'percent'         => (float)$r['percent'],
        'commission'      => (float)$r['commission'],
        'status'          => $r['status'],
        'channel'         => $r['channel'],
        'wallet_ledger_id'=> $r['wallet_ledger_id'] !== null ? (int)$r['wallet_ledger_id'] : null,
        'reversed_amount' => (float)$r['reversed_amount'],
        'note'            => $r['note'],
        'created_at'      => $r['created_at'],
    ];
}

const RCM_SELECT = 'SELECT c.id, c.referrer_id, ru.username AS referrer_name, c.referred_id, du.username AS referred_name, c.order_id, c.order_ref,
        c.eligible_amount, c.percent, c.commission, c.status, c.channel, c.wallet_ledger_id, c.reversed_amount, c.note, c.created_at';

function rcm_require_ledger(PDO $pdo): void
{
    if (referral_schema_ensure($pdo)) return;
    $e = referral_schema_error() ?: ['code' => 'SCHEMA_INCOMPLETE', 'message' => 'The commission ledger table (referral_commissions) is missing and could not be created automatically. Run database/migration_v37_ops.sql.'];
    json_error($e['message'], 500, ['code' => $e['code'], 'missing' => ['table referral_commissions']]);
}

function rcm_settings(PDO $pdo): array
{
    $updated = null;
    try {
        $q = $pdo->prepare('SELECT updated_at FROM settings WHERE setting_key = :k');
        $q->execute([':k' => REFERRAL_SETTING_KEY]);
        $v = $q->fetchColumn();
        $updated = $v !== false ? (string)$v : null;
    } catch (Throwable $e) { error_log('[referral-commission:settings] ' . $e->getMessage()); }
    return [
        'percent'       => referral_percent(),
        'label'         => referral_percent_label(),
        'min'           => REFERRAL_MIN_PERCENT,
        'max'           => REFERRAL_MAX_PERCENT,
        'options'       => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
        'stored'        => get_setting(REFERRAL_SETTING_KEY, '') !== '' && get_setting(REFERRAL_SETTING_KEY, '') !== null,
        'history_imported' => get_setting('referral_ledger_backfilled', '') === '1',
        'updated_at'    => $updated,
    ];
}

/* ------------------------------ POST ------------------------------ */
if (request_method() === 'POST') {
    csrf_verify();
    $action = input_str('action');
    if ($action !== 'save_percent') json_error('Unknown action.', 400);

    [$ok, $value, $msg] = referral_parse_percent(input_param('percent', ''));
    if (!$ok) json_error($msg, 422, ['code' => 'INVALID_PERCENT']);

    rcm_require_ledger($pdo);
    $old = referral_percent();
    set_setting(REFERRAL_SETTING_KEY, number_format($value, 2, '.', ''));
    audit_log('referral.percent_changed', 'success', ['entity_type' => 'setting', 'entity_id' => REFERRAL_SETTING_KEY, 'actor_id' => $adminId,
        'reason' => 'Active referral commission changed from ' . referral_percent_label($old) . ' to ' . referral_percent_label($value) . ' (applies to future purchases only)']);
    json_ok(['settings' => rcm_settings($pdo), 'previous' => $old, 'message' => 'Active commission is now ' . referral_percent_label($value) . '. It applies to future eligible purchases only; past commissions are unchanged.']);
}

/* ------------------------------ GET ------------------------------ */
$view = input_str('view', 'settings');

if ($view === 'settings') {
    json_ok(['settings' => rcm_settings($pdo)]);
}

rcm_require_ledger($pdo);

/* ---- CSV export (same filters, real rows only) ---- */
if (input_str('export') === '1') {
    $params = [];
    $w = rcm_history_where($params);
    $st = $pdo->prepare(RCM_SELECT . RCM_FROM . $w . ' ORDER BY c.id DESC LIMIT 10000');
    $st->execute($params);
    audit_log('referral.export', 'info', ['entity_type' => 'report', 'actor_id' => $adminId, 'reason' => 'Commission history CSV exported']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="referral-commissions-' . date('Ymd-His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $safe = static function ($v) { $s = (string)$v; return ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) ? "'" . $s : $s; };   // no spreadsheet formula injection
    fputcsv($out, ['Commission ID', 'Referrer ID', 'Referrer', 'Referred ID', 'Referred', 'Order ID', 'Order ref', 'Eligible amount', 'Percent applied', 'Commission', 'Status', 'Channel', 'Wallet ledger ID', 'Reversed amount', 'Created at'], ',', '"', '\\');
    foreach ($st as $r) {
        fputcsv($out, [$r['id'], $r['referrer_id'], $safe($r['referrer_name']), $r['referred_id'], $safe($r['referred_name']), $r['order_id'], $safe($r['order_ref']),
            $r['eligible_amount'], $r['percent'], $r['commission'], $r['status'], $r['channel'], $r['wallet_ledger_id'], $r['reversed_amount'], $r['created_at']], ',', '"', '\\');
    }
    fclose($out);
    exit;
}

if ($view === 'summary') {
    $c = $pdo->query(
        "SELECT COUNT(*) AS purchases,
                COALESCE(SUM(eligible_amount), 0) AS purchase_value,
                COALESCE(SUM(CASE WHEN status IN ('credited','reversed','pending') THEN commission END), 0) AS earned,
                COALESCE(SUM(CASE WHEN status IN ('credited','reversed') THEN commission END), 0) AS credited,
                COALESCE(SUM(CASE WHEN status = 'pending'  THEN commission END), 0) AS pending,
                COALESCE(SUM(CASE WHEN status = 'failed'   THEN commission END), 0) AS failed,
                COALESCE(SUM(CASE WHEN status = 'reversed' THEN reversed_amount END), 0) AS reversed,
                COUNT(DISTINCT CASE WHEN status IN ('credited','reversed') THEN referrer_id END) AS earners,
                COALESCE(SUM(status IN ('credited','reversed')), 0) AS transactions,
                COALESCE(SUM(status = 'failed'), 0) AS failed_count,
                COALESCE(SUM(status = 'pending'), 0) AS pending_count,
                COALESCE(SUM(status = 'reversed'), 0) AS reversed_count
         FROM referral_commissions"
    )->fetch();
    $registered = (int)$pdo->query('SELECT COUNT(*) FROM referrals')->fetchColumn();
    $converted  = (int)$pdo->query('SELECT COUNT(*) FROM referrals WHERE active = 1')->fetchColumn();
    $dep = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM deposits WHERE status = 'approved' AND user_id IN (SELECT referred_id FROM referrals)")->fetch();
    $events = (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM referral_events')->fetchColumn();
    $credited = (float)$c['credited'];
    json_ok(['summary' => [
        'registered_referrals'  => $registered,
        'referrals_who_bought'  => $converted,
        'eligible_purchases'    => (int)$c['purchases'],
        'purchase_value'        => (float)$c['purchase_value'],
        'commission_earned'     => (float)$c['earned'],
        'commission_credited'   => $credited,
        'commission_pending'    => (float)$c['pending'],
        'commission_failed'     => (float)$c['failed'],
        'commission_reversed'   => (float)$c['reversed'],
        'net_credited'          => round($credited - (float)$c['reversed'], 2),
        'users_paid'            => (int)$c['earners'],
        'transactions'          => (int)$c['transactions'],
        'pending_count'         => (int)$c['pending_count'],
        'failed_count'          => (int)$c['failed_count'],
        'reversed_count'        => (int)$c['reversed_count'],
        // Informational only: deposits / wallet top-ups of referred users are NOT purchases and never earn commission.
        'referred_deposits'     => ['count' => (int)$dep['n'], 'amount' => (float)$dep['total']],
        // Reconciliation: the legacy per-event table and the new ledger must agree on what was credited.
        'legacy_events_total'   => $events,
        'ledger_matches_events' => abs($events - $credited) < 0.01,
    ], 'settings' => rcm_settings($pdo)]);
}

if ($view === 'users') {
    $page = max(1, input_int('page', 1));
    $q = trim(input_str('q'));
    $params = []; $w = '';
    if ($q !== '') {
        $w = ' WHERE (u.username LIKE :s1' . (ctype_digit($q) ? ' OR u.id = :s2' : '') . ')';
        $params[':s1'] = rcm_like($q);
        if (ctype_digit($q)) $params[':s2'] = (int)$q;
    }
    $base = ' FROM (SELECT referrer_id AS uid FROM referrals UNION SELECT referrer_id FROM referral_commissions) k
              JOIN users u ON u.id = k.uid
              LEFT JOIN (SELECT referrer_id,
                                COUNT(*) AS x_purchases,
                                SUM(eligible_amount) AS x_value,
                                SUM(CASE WHEN status IN (\'credited\',\'reversed\',\'pending\') THEN commission ELSE 0 END) AS x_earned,
                                SUM(CASE WHEN status IN (\'credited\',\'reversed\') THEN commission ELSE 0 END) AS x_credited,
                                SUM(CASE WHEN status = \'pending\' THEN commission ELSE 0 END) AS x_pending,
                                SUM(CASE WHEN status = \'failed\' THEN commission ELSE 0 END) AS x_failed,
                                SUM(CASE WHEN status = \'reversed\' THEN reversed_amount ELSE 0 END) AS x_reversed,
                                MAX(created_at) AS x_last
                         FROM referral_commissions GROUP BY referrer_id) c ON c.referrer_id = u.id' . $w;
    $cnt = $pdo->prepare('SELECT COUNT(*)' . $base);
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $pages = max(1, (int)ceil($total / RCM_PER_PAGE));
    $page = min($page, $pages);
    $l = $pdo->prepare('SELECT u.id, u.username,
            (SELECT COUNT(*) FROM referrals r WHERE r.referrer_id = u.id) AS referred_users,
            COALESCE(c.x_purchases, 0) AS purchases, COALESCE(c.x_value, 0) AS purchase_value, COALESCE(c.x_earned, 0) AS earned,
            COALESCE(c.x_credited, 0) AS credited, COALESCE(c.x_pending, 0) AS pending, COALESCE(c.x_failed, 0) AS failed,
            COALESCE(c.x_reversed, 0) AS reversed, c.x_last AS last_at' . $base . '
            ORDER BY earned DESC, u.id ASC LIMIT ' . RCM_PER_PAGE . ' OFFSET ' . (($page - 1) * RCM_PER_PAGE));
    $l->execute($params);
    $rows = array_map(static fn(array $r): array => [
        'user_id' => (int)$r['id'], 'username' => $r['username'], 'referred_users' => (int)$r['referred_users'], 'purchases' => (int)$r['purchases'],
        'purchase_value' => (float)$r['purchase_value'], 'earned' => (float)$r['earned'], 'credited' => (float)$r['credited'], 'pending' => (float)$r['pending'],
        'failed' => (float)$r['failed'], 'reversed' => (float)$r['reversed'], 'last_at' => $r['last_at'],
    ], $l->fetchAll());
    json_ok(['users' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total]);
}

if ($view === 'history') {
    $page = max(1, input_int('page', 1));
    $params = [];
    $w = rcm_history_where($params);
    $cnt = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(c.eligible_amount), 0), COALESCE(SUM(CASE WHEN c.status IN (\'credited\',\'reversed\') THEN c.commission ELSE 0 END), 0)' . RCM_FROM . $w);
    $cnt->execute($params);
    $t = $cnt->fetch(PDO::FETCH_NUM);
    $total = (int)$t[0];
    $pages = max(1, (int)ceil($total / RCM_PER_PAGE));
    $page = min($page, $pages);
    $l = $pdo->prepare(RCM_SELECT . RCM_FROM . $w . ' ORDER BY c.id DESC LIMIT ' . RCM_PER_PAGE . ' OFFSET ' . (($page - 1) * RCM_PER_PAGE));
    $l->execute($params);
    json_ok(['rows' => array_map('rcm_row', $l->fetchAll()), 'page' => $page, 'pages' => $pages, 'total' => $total,
             'filtered' => ['purchase_value' => (float)$t[1], 'commission_credited' => (float)$t[2]]]);
}

if ($view === 'detail') {
    $id = input_int('id');
    if ($id <= 0) json_error('Invalid commission.', 422);
    $q = $pdo->prepare(RCM_SELECT . ', c.reversal_ledger_id, c.reversed_at' . RCM_FROM . ' WHERE c.id = :id');
    $q->execute([':id' => $id]);
    $r = $q->fetch();
    if (!$r) json_error('Commission not found.', 404);
    $out = rcm_row($r) + ['reversal_ledger_id' => $r['reversal_ledger_id'] !== null ? (int)$r['reversal_ledger_id'] : null, 'reversed_at' => $r['reversed_at']];

    $wallet = null;
    if ($r['wallet_ledger_id'] !== null) {
        $wq = $pdo->prepare('SELECT id, amount, balance_after, type, reference_id, description, created_at FROM wallet_ledger WHERE id = :id');
        $wq->execute([':id' => (int)$r['wallet_ledger_id']]);
        $wallet = $wq->fetch() ?: null;
    }
    $oq = $pdo->prepare('SELECT order_ref, status, total_price, source, created_at FROM orders WHERE id = :id');
    $oq->execute([':id' => (int)$r['order_id']]);
    json_ok(['commission' => $out, 'wallet_entry' => $wallet, 'order' => $oq->fetch() ?: null]);
}

json_error('Unknown view.', 400);
