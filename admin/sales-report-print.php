<?php
require_once __DIR__ . '/../functions/permissions.php';
cg_require_page('dashboard.revenue');

require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';

/**
 * Printable sales report.
 * Revenue definition matches the dashboard: orders with a payments row
 * where payment_status = 'Paid', dated by payments.paid_at.
 */

// --- Date range (defaults to the current month) ---
function cg_valid_date(?string $d): ?string
{
    if ($d === null || $d === '') return null;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}

$preset = $_GET['range'] ?? 'month';
$today = date('Y-m-d');
switch ($preset) {
    case 'today':
        $from = $to = $today;
        break;
    case '7':
        $from = date('Y-m-d', strtotime('-6 days'));
        $to = $today;
        break;
    case '30':
        $from = date('Y-m-d', strtotime('-29 days'));
        $to = $today;
        break;
    case 'year':
        $from = date('Y-01-01');
        $to = $today;
        break;
    case 'all':
        $from = $to = null;
        break;
    case 'custom':
        $from = cg_valid_date($_GET['from'] ?? null) ?? date('Y-m-01');
        $to = cg_valid_date($_GET['to'] ?? null) ?? $today;
        if ($from > $to) { [$from, $to] = [$to, $from]; }
        break;
    default:
        $preset = 'month';
        $from = date('Y-m-01');
        $to = $today;
}

$where = "pay.payment_status = 'Paid'";
$params = [];
if ($from !== null) {
    $where .= " AND pay.paid_at >= :from";
    $params['from'] = $from . ' 00:00:00';
}
if ($to !== null) {
    $where .= " AND pay.paid_at < :to_excl";
    $params['to_excl'] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
}

function cg_fetch_all(PDO $pdo, string $sql, array $params): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Summary
$summary = cg_fetch_all($pdo, "
    SELECT COALESCE(SUM(o.total_amount), 0) AS revenue, COUNT(*) AS order_count
    FROM orders o JOIN payments pay ON pay.order_id = o.order_id
    WHERE $where", $params)[0];
$totalRevenue = (float) $summary['revenue'];
$orderCount   = (int) $summary['order_count'];
$avgOrder     = $orderCount > 0 ? $totalRevenue / $orderCount : 0.0;

$unitsRow = cg_fetch_all($pdo, "
    SELECT COALESCE(SUM(oi.quantity), 0) AS units
    FROM order_items oi
    JOIN orders o ON o.order_id = oi.order_id
    JOIN payments pay ON pay.order_id = o.order_id
    WHERE $where", $params)[0];
$unitsSold = (int) $unitsRow['units'];

// Daily breakdown
$daily = cg_fetch_all($pdo, "
    SELECT DATE(pay.paid_at) AS day, COUNT(*) AS orders, SUM(o.total_amount) AS revenue
    FROM orders o JOIN payments pay ON pay.order_id = o.order_id
    WHERE $where
    GROUP BY DATE(pay.paid_at)
    ORDER BY day ASC", $params);

// Top products
$topProducts = cg_fetch_all($pdo, "
    SELECT pr.name, SUM(oi.quantity) AS qty, SUM(oi.quantity * oi.unit_price) AS revenue
    FROM order_items oi
    JOIN orders o ON o.order_id = oi.order_id
    JOIN payments pay ON pay.order_id = o.order_id
    JOIN products pr ON pr.product_id = oi.product_id
    WHERE $where
    GROUP BY pr.product_id, pr.name
    ORDER BY qty DESC, revenue DESC
    LIMIT 10", $params);

// Top spenders (registered accounts only)
$topSpenders = cg_fetch_all($pdo, "
    SELECT u.first_name, u.last_name, u.email, COUNT(*) AS orders, SUM(o.total_amount) AS spent
    FROM orders o
    JOIN payments pay ON pay.order_id = o.order_id
    JOIN users u ON u.user_id = o.user_id
    WHERE $where
    GROUP BY u.user_id, u.first_name, u.last_name, u.email
    ORDER BY spent DESC
    LIMIT 10", $params);

// Itemised paid orders
$orders = cg_fetch_all($pdo, "
    SELECT o.order_id, o.total_amount, o.created_at, o.guest_email, pay.payment_method, pay.paid_at,
           u.first_name, u.last_name
    FROM orders o
    JOIN payments pay ON pay.order_id = o.order_id
    LEFT JOIN users u ON u.user_id = o.user_id
    WHERE $where
    ORDER BY pay.paid_at ASC", $params);

// Store header
$store = [];
try {
    $store = $pdo->query("SELECT store_name, store_address, phone, email_general FROM contact_page LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
}
$storeName = $store['store_name'] ?? 'Celestia Gems';

$admin = cg_admin_user();
$adminName = $admin['name'] ?? '';

$periodLabel = ($from === null && $to === null)
    ? 'All time'
    : (($from === $to) ? date('F j, Y', strtotime($from)) : date('F j, Y', strtotime($from)) . ' – ' . date('F j, Y', strtotime($to)));

function peso(float $n): string { return '₱' . number_format($n, 2); }
function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function methodLabel(?string $m): string
{
    return match (strtolower((string) $m)) {
        'cod' => 'Cash on Delivery',
        'paymongo' => 'PayMongo',
        default => $m !== null && $m !== '' ? ucfirst($m) : '—',
    };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report — <?= e($storeName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Jost', sans-serif; color: #222; background: #f1efea; margin: 0; padding: 24px; font-size: 13px; }
        .toolbar { max-width: 900px; margin: 0 auto 16px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .toolbar a, .toolbar button, .toolbar select, .toolbar input { font-family: inherit; font-size: 13px; padding: 8px 14px; border-radius: 6px; border: 1px solid #b8975a; background: #fff; color: #222; text-decoration: none; }
        .toolbar button.primary { background: #b8975a; color: #fff; cursor: pointer; }
        .toolbar form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0; }
        .toolbar .custom { display: none; gap: 8px; align-items: center; }
        .toolbar .custom.show { display: flex; }
        .doc { max-width: 900px; margin: 0 auto; background: #fff; padding: 40px 44px; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #b8975a; padding-bottom: 14px; margin-bottom: 18px; }
        .head h1 { font-family: 'Cormorant Garamond', serif; font-size: 30px; margin: 0; }
        .head .meta { text-align: right; color: #555; line-height: 1.5; }
        .head .store { color: #555; line-height: 1.5; }
        .period { font-size: 15px; margin-bottom: 18px; }
        .summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 24px; }
        .summary div { border: 1px solid #ddd; border-radius: 6px; padding: 10px 12px; }
        .summary span { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #777; }
        .summary strong { font-size: 17px; }
        h2 { font-family: 'Cormorant Garamond', serif; font-size: 20px; margin: 26px 0 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e5e5e5; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #666; background: #faf8f4; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: 600; border-top: 2px solid #b8975a; border-bottom: none; }
        .empty { color: #777; font-style: italic; padding: 8px 0; }
        .foot { margin-top: 30px; font-size: 11px; color: #777; border-top: 1px solid #ddd; padding-top: 8px; }
        .two { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        @media (max-width: 700px) { .summary { grid-template-columns: 1fr 1fr; } .two { grid-template-columns: 1fr; } .doc { padding: 20px; } }
        @media print {
            @page { margin: 14mm; }
            body { background: #fff; padding: 0; }
            .toolbar { display: none; }
            .doc { box-shadow: none; padding: 0; max-width: none; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>

<body>
    <div class="toolbar">
        <a href="dashboard.php">&larr; Back to Dashboard</a>
        <form method="get">
            <select name="range" id="range">
                <?php foreach (['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', 'month' => 'This month', 'year' => 'This year', 'all' => 'All time', 'custom' => 'Custom range'] as $k => $label): ?>
                    <option value="<?= $k ?>" <?= (string) $preset === (string) $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <span class="custom <?= $preset === 'custom' ? 'show' : '' ?>" id="custom">
                <input type="date" name="from" value="<?= e($from ?? '') ?>" max="<?= $today ?>">
                <span>to</span>
                <input type="date" name="to" value="<?= e($to ?? '') ?>" max="<?= $today ?>">
            </span>
            <button type="submit">Apply</button>
        </form>
        <button type="button" class="primary" onclick="window.print()">Print Report</button>
    </div>

    <div class="doc">
        <div class="head">
            <div>
                <h1>Sales Report</h1>
                <div class="store"><?= e($storeName) ?><?php if (!empty($store['store_address'])): ?><br><?= e($store['store_address']) ?><?php endif; ?></div>
            </div>
            <div class="meta">
                Generated <?= e(date('F j, Y g:i A')) ?><?php if ($adminName !== ''): ?><br>by <?= e($adminName) ?><?php endif; ?>
            </div>
        </div>

        <div class="period"><strong>Period:</strong> <?= e($periodLabel) ?> &nbsp;·&nbsp; <em>Paid orders only</em></div>

        <div class="summary">
            <div><span>Total Revenue</span><strong><?= peso($totalRevenue) ?></strong></div>
            <div><span>Paid Orders</span><strong><?= number_format($orderCount) ?></strong></div>
            <div><span>Avg. Order Value</span><strong><?= peso($avgOrder) ?></strong></div>
            <div><span>Units Sold</span><strong><?= number_format($unitsSold) ?></strong></div>
        </div>

        <?php if ($orderCount === 0): ?>
            <p class="empty">No paid orders in this period.</p>
        <?php else: ?>

            <h2>Daily Revenue</h2>
            <table>
                <thead><tr><th>Date</th><th class="num">Orders</th><th class="num">Revenue</th></tr></thead>
                <tbody>
                    <?php foreach ($daily as $d): ?>
                        <tr>
                            <td><?= e(date('M j, Y', strtotime($d['day']))) ?></td>
                            <td class="num"><?= number_format((int) $d['orders']) ?></td>
                            <td class="num"><?= peso((float) $d['revenue']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td>Total</td><td class="num"><?= number_format($orderCount) ?></td><td class="num"><?= peso($totalRevenue) ?></td></tr></tfoot>
            </table>

            <div class="two">
                <div>
                    <h2>Top-Selling Products</h2>
                    <table>
                        <thead><tr><th>Product</th><th class="num">Qty</th><th class="num">Revenue</th></tr></thead>
                        <tbody>
                            <?php foreach ($topProducts as $p): ?>
                                <tr><td><?= e($p['name']) ?></td><td class="num"><?= number_format((int) $p['qty']) ?></td><td class="num"><?= peso((float) $p['revenue']) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h2>Top Spenders</h2>
                    <?php if (empty($topSpenders)): ?>
                        <p class="empty">No registered-customer purchases.</p>
                    <?php else: ?>
                        <table>
                            <thead><tr><th>Customer</th><th class="num">Orders</th><th class="num">Spent</th></tr></thead>
                            <tbody>
                                <?php foreach ($topSpenders as $s): ?>
                                    <?php $n = trim($s['first_name'] . ' ' . $s['last_name']); ?>
                                    <tr><td><?= e($n !== '' ? $n : $s['email']) ?></td><td class="num"><?= number_format((int) $s['orders']) ?></td><td class="num"><?= peso((float) $s['spent']) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <h2>Paid Orders</h2>
            <table>
                <thead><tr><th>Order</th><th>Paid On</th><th>Customer</th><th>Method</th><th class="num">Amount</th></tr></thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                        <?php
                        $cn = trim(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? ''));
                        $cn = $cn !== '' ? $cn : ($o['guest_email'] ?: 'Guest');
                        ?>
                        <tr>
                            <td><?= e(format_order_number((int) $o['order_id'])) ?></td>
                            <td><?= e(date('M j, Y', strtotime($o['paid_at']))) ?></td>
                            <td><?= e($cn) ?></td>
                            <td><?= e(methodLabel($o['payment_method'])) ?></td>
                            <td class="num"><?= peso((float) $o['total_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="4">Total</td><td class="num"><?= peso($totalRevenue) ?></td></tr></tfoot>
            </table>
        <?php endif; ?>

        <div class="foot">Revenue counts orders with a Paid payment status, dated by payment date. Confidential — for internal use.</div>
    </div>

    <script>
        const range = document.getElementById('range');
        const custom = document.getElementById('custom');
        range.addEventListener('change', () => custom.classList.toggle('show', range.value === 'custom'));
    </script>
</body>

</html>
