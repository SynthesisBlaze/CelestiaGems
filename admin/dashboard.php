<?php
require_once __DIR__ . '/../functions/permissions.php';
cg_require_page('dashboard.view');

require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';

$current_admin_page = 'dashboard';
$page_title = 'Dashboard';
$admin_user = cg_admin_user();

// Products at or below this total stock get flagged on the dashboard (same threshold as Products page)
const LOW_STOCK_THRESHOLD = 5;

/**
 * ---------------------------------------------------------------
 * STAT CARDS
 * ---------------------------------------------------------------
 */
$stats = [
    'users'    => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role_id NOT IN (4, 5)")->fetchColumn(),
    'orders'   => (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
    'products' => (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
    'feedback' => (int) $pdo->query("SELECT COUNT(*) FROM product_reviews")->fetchColumn(),
];

/**
 * ---------------------------------------------------------------
 * RECENT ORDERS (latest 8)
 * ---------------------------------------------------------------
 */
$recentOrdersStmt = $pdo->query("
    SELECT o.order_id, o.total_amount, o.order_status, o.created_at, o.guest_email,
           u.first_name, u.last_name
    FROM orders o
    LEFT JOIN users u ON u.user_id = o.user_id
    ORDER BY o.created_at DESC
    LIMIT 8
");
$recent_orders = $recentOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * ---------------------------------------------------------------
 * INVENTORY ALERTS (total stock below threshold, physical products)
 * ---------------------------------------------------------------
 */
$inventoryStmt = $pdo->query("
    SELECT p.product_id, p.name, COALESCE(SUM(ps.stock_quantity), 0) AS total_stock
    FROM products p
    LEFT JOIN product_sizes ps ON ps.product_id = p.product_id
    GROUP BY p.product_id, p.name
    HAVING COALESCE(SUM(ps.stock_quantity), 0) < " . LOW_STOCK_THRESHOLD . "
    ORDER BY total_stock ASC
    LIMIT 8
");
$inventory_alerts = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * ---------------------------------------------------------------
 * RATINGS
 * ---------------------------------------------------------------
 */
$ratingStmt = $pdo->query("SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM product_reviews");
$ratingRow = $ratingStmt->fetch(PDO::FETCH_ASSOC);
$rating = [
    'average' => $ratingRow['cnt'] > 0 ? (float) $ratingRow['avg_rating'] : null,
    'count'   => (int) $ratingRow['cnt'],
];

/**
 * ---------------------------------------------------------------
 * SALES & ANALYTICS
 * Revenue is defined as orders with a linked payments row where
 * payment_status = 'Paid'. Everything below (trend, top products,
 * top spenders) uses that same definition for consistency.
 * ---------------------------------------------------------------
 */
$revenueSummaryStmt = $pdo->query("
    SELECT COALESCE(SUM(o.total_amount), 0) AS total_revenue, COUNT(*) AS paid_order_count
    FROM orders o
    JOIN payments pay ON pay.order_id = o.order_id
    WHERE pay.payment_status = 'Paid'
");
$revenueSummary = $revenueSummaryStmt->fetch(PDO::FETCH_ASSOC);
$totalRevenue   = (float) $revenueSummary['total_revenue'];
$paidOrderCount = (int) $revenueSummary['paid_order_count'];
$avgOrderValue  = $paidOrderCount > 0 ? $totalRevenue / $paidOrderCount : 0.0;

$monthRevenueStmt = $pdo->query("
    SELECT COALESCE(SUM(o.total_amount), 0)
    FROM orders o
    JOIN payments pay ON pay.order_id = o.order_id
    WHERE pay.payment_status = 'Paid'
      AND EXTRACT(YEAR FROM pay.paid_at) = EXTRACT(YEAR FROM CURRENT_DATE)
      AND EXTRACT(MONTH FROM pay.paid_at) = EXTRACT(MONTH FROM CURRENT_DATE)
");
$monthRevenue = (float) $monthRevenueStmt->fetchColumn();

// --- Revenue trend: last 30 days, daily, zero-filled for days with no revenue ---
$trendStmt = $pdo->query("
    SELECT DATE(pay.paid_at) AS day, SUM(o.total_amount) AS revenue
    FROM orders o
    JOIN payments pay ON pay.order_id = o.order_id
    WHERE pay.payment_status = 'Paid'
      AND pay.paid_at >= (CURRENT_DATE - INTERVAL '29 days')
    GROUP BY DATE(pay.paid_at)
");
$trendRows = [];
foreach ($trendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $trendRows[$row['day']] = (float) $row['revenue'];
}

$trendLabels = [];
$trendValues = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[] = date('M j', strtotime($date));
    $trendValues[] = $trendRows[$date] ?? 0.0;
}

// --- Top-selling products (by quantity sold, paid orders only) ---
$topProductsStmt = $pdo->query("
    SELECT pr.product_id, pr.name, SUM(oi.quantity) AS total_qty, SUM(oi.quantity * oi.unit_price) AS total_revenue
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.order_id
    JOIN payments pay ON pay.order_id = o.order_id AND pay.payment_status = 'Paid'
    JOIN products pr ON pr.product_id = oi.product_id
    GROUP BY pr.product_id, pr.name
    ORDER BY total_qty DESC
    LIMIT 10
");
$top_products = $topProductsStmt->fetchAll(PDO::FETCH_ASSOC);

// --- Top spenders (paid orders only, guest orders excluded — no account to attribute to) ---
$topSpendersStmt = $pdo->query("
    SELECT u.user_id, u.first_name, u.last_name, u.email,
           SUM(o.total_amount) AS total_spent, COUNT(*) AS order_count
    FROM orders o
    JOIN payments pay ON pay.order_id = o.order_id AND pay.payment_status = 'Paid'
    JOIN users u ON u.user_id = o.user_id
    GROUP BY u.user_id, u.first_name, u.last_name, u.email
    ORDER BY total_spent DESC
    LIMIT 10
");
$top_spenders = $topSpendersStmt->fetchAll(PDO::FETCH_ASSOC);

function dashboardOrderStatusClass(string $status): string
{
    return strtolower(str_replace(' ', '-', $status));
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Celestia Gems Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/admin.css">
</head>

<body>

    <div class="admin-shell">
        <?php include __DIR__ . '/../components/admin_sidebar.php'; ?>

        <div class="admin-main">
            <?php include __DIR__ . '/../components/admin_topbar.php'; ?>

            <div class="admin-content">

                <?php if (!empty($_SESSION['admin_denied'])): ?>
                    <div class="alert cg-notice cg-notice--info alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_SESSION['admin_denied']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['admin_denied']); ?>
                <?php endif; ?>

                <!-- STAT CARDS -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3">
                        <div class="stat-card">
                            <div>
                                <div class="label">Total Users</div>
                                <div class="num"><?= number_format($stats['users']) ?></div>
                            </div>
                            <div class="stat-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c.7-3.6 3.4-6 6.5-6s5.8 2.4 6.5 6"/><circle cx="17.5" cy="9" r="2.4"/><path d="M15.5 13.2c2.4.4 4.3 2.4 4.8 5"/></svg>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-card">
                            <div>
                                <div class="label">Total Orders</div>
                                <div class="num"><?= number_format($stats['orders']) ?></div>
                            </div>
                            <div class="stat-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 7l8-4 8 4v10l-8 4-8-4z"/><path d="M4 7l8 4 8-4"/><path d="M12 11v10"/></svg>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-card">
                            <div>
                                <div class="label">Total Products</div>
                                <div class="num"><?= number_format($stats['products']) ?></div>
                            </div>
                            <div class="stat-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="stat-card">
                            <div>
                                <div class="label">Feedbacks</div>
                                <div class="num"><?= number_format($stats['feedback']) ?></div>
                            </div>
                            <div class="stat-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RECENT ORDERS -->
                <div class="admin-panel mb-4">
                    <div class="admin-panel-title">Recent Orders</div>

                    <?php if (empty($recent_orders)): ?>
                        <div class="empty-state">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><path d="M4 7l8-4 8 4v10l-8 4-8-4z"/><path d="M4 7l8 4 8-4"/><path d="M12 11v10"/></svg>
                            <div class="title">No orders yet</div>
                            <div class="hint">Orders will appear here once customers start checking out.</div>
                        </div>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                                <tr><th>Order ID</th><th>Customer</th><th>Date</th><th>Amount</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_orders as $order): ?>
                                    <?php
                                    $customerName = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
                                    $customerDisplay = $customerName !== '' ? $customerName : ($order['guest_email'] ?? 'Guest');
                                    ?>
                                    <tr>
                                        <td class="order-id-cell"><?= htmlspecialchars(format_order_number((int) $order['order_id'])) ?></td>
                                        <td><?= htmlspecialchars($customerDisplay) ?></td>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime($order['created_at']))) ?></td>
                                        <td class="order-amount">₱<?= number_format((float) $order['total_amount'], 2) ?></td>
                                        <td><span class="status-badge <?= dashboardOrderStatusClass($order['order_status']) ?>"><?= htmlspecialchars($order['order_status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="row g-4 mb-4">
                    <!-- INVENTORY ALERTS -->
                    <div class="col-lg-8">
                        <div class="admin-panel">
                            <div class="admin-panel-title">Inventory Alerts</div>

                            <?php if (empty($inventory_alerts)): ?>
                                <div class="empty-state">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
                                    <div class="title">No inventory alerts</div>
                                    <div class="hint">Low-stock and out-of-stock items will be flagged here once products are added.</div>
                                </div>
                            <?php else: ?>
                                <table class="admin-table">
                                    <thead><tr><th>Item</th><th>Quantity Left</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($inventory_alerts as $item): ?>
                                            <?php $stock = (int) $item['total_stock']; ?>
                                            <tr>
                                                <td><?= htmlspecialchars($item['name']) ?></td>
                                                <td>
                                                    <span class="status-badge <?= $stock <= 0 ? 'cancelled' : 'shipped' ?>">
                                                        <?= $stock <= 0 ? 'Out of Stock' : $stock . ' left' ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- RATINGS -->
                    <div class="col-lg-4">
                        <div class="admin-panel ratings-box">
                            <div class="admin-panel-title text-start">Ratings</div>
                            <?php if ($rating['count'] === 0): ?>
                                <div class="score">—</div>
                                <div class="stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                                <p class="hint mt-2 mb-0" style="font-size:.78rem; color:var(--cg-muted);">No ratings yet</p>
                            <?php else: ?>
                                <?php
                                $avg = $rating['average'];
                                $starsHtml = '';
                                for ($i = 1; $i <= 5; $i++) {
                                    $starsHtml .= $i <= round($avg) ? '<span class="filled">&#9733;</span>' : '&#9733;';
                                }
                                ?>
                                <div class="score"><?= number_format($avg, 1) ?>/5</div>
                                <div class="stars"><?= $starsHtml ?></div>
                                <p class="hint mt-2 mb-0" style="font-size:.78rem; color:var(--cg-muted);"><?= number_format($rating['count']) ?> review<?= $rating['count'] === 1 ? '' : 's' ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ============ SALES & ANALYTICS (Admin and Super Admin only) ============ -->
                <?php if (cg_can('dashboard.revenue')): ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="mb-0" style="font-family: var(--font-display); color:var(--cg-text);">Sales &amp; Analytics</h5>
                    <a href="sales-report-print.php" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:-3px; margin-right:6px;"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print Sales Report
                    </a>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-4">
                        <div class="stat-card">
                            <div>
                                <div class="label">Total Revenue</div>
                                <div class="num">₱<?= number_format($totalRevenue, 2) ?></div>
                            </div>
                            <div class="stat-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9.5 9.5c0-1.4 1.1-2.5 2.5-2.5s2.5 1 2.5 2c0 3-5 2-5 5 0 1 1.1 2 2.5 2s2.5-1.1 2.5-2.5"/></svg>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-4">
                        <div class="stat-card">
                            <div>
                                <div class="label">Revenue This Month</div>
                                <div class="num">₱<?= number_format($monthRevenue, 2) ?></div>
                            </div>
                            <div class="stat-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-4">
                        <div class="stat-card">
                            <div>
                                <div class="label">Avg. Order Value</div>
                                <div class="num">₱<?= number_format($avgOrderValue, 2) ?></div>
                            </div>
                            <div class="stat-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 17l6-6 4 4 8-8"/><path d="M17 7h4v4"/></svg>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- REVENUE TREND -->
                <div class="admin-panel mb-4">
                    <div class="admin-panel-title">Revenue — Last 30 Days</div>
                    <?php if ($paidOrderCount === 0): ?>
                        <div class="empty-state">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><path d="M3 17l6-6 4 4 8-8"/><path d="M17 7h4v4"/></svg>
                            <div class="title">No revenue yet</div>
                            <div class="hint">Once orders are marked Paid, the trend will appear here.</div>
                        </div>
                    <?php else: ?>
                        <div class="chart-container">
                            <canvas id="revenueTrendChart"></canvas>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="row g-4">
                    <!-- TOP-SELLING PRODUCTS -->
                    <div class="col-lg-7">
                        <div class="admin-panel">
                            <div class="admin-panel-title">Top-Selling Products</div>
                            <?php if (empty($top_products)): ?>
                                <div class="empty-state">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
                                    <div class="title">No sales yet</div>
                                    <div class="hint">Best sellers will show up here once paid orders come in.</div>
                                </div>
                            <?php else: ?>
                                <table class="admin-table">
                                    <thead><tr><th>Product</th><th>Qty Sold</th><th>Revenue</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($top_products as $p): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($p['name']) ?></td>
                                                <td><?= number_format((int) $p['total_qty']) ?></td>
                                                <td class="order-amount">₱<?= number_format((float) $p['total_revenue'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- TOP SPENDERS -->
                    <div class="col-lg-5">
                        <div class="admin-panel">
                            <div class="admin-panel-title">Top Spenders</div>
                            <?php if (empty($top_spenders)): ?>
                                <div class="empty-state">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c.7-3.6 3.4-6 6.5-6s5.8 2.4 6.5 6"/></svg>
                                    <div class="title">No customer data yet</div>
                                    <div class="hint">Top spenders will appear once paid orders come in.</div>
                                </div>
                            <?php else: ?>
                                <table class="admin-table">
                                    <thead><tr><th>Customer</th><th>Orders</th><th>Spent</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($top_spenders as $s): ?>
                                            <?php $name = trim($s['first_name'] . ' ' . $s['last_name']); ?>
                                            <tr>
                                                <td>
                                                    <?= htmlspecialchars($name !== '' ? $name : $s['email']) ?>
                                                    <span class="order-customer-email"><?= htmlspecialchars($s['email']) ?></span>
                                                </td>
                                                <td><?= number_format((int) $s['order_count']) ?></td>
                                                <td class="order-amount">₱<?= number_format((float) $s['total_spent'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($paidOrderCount > 0 && cg_can('dashboard.revenue')): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
        const revenueLabels = <?= json_encode($trendLabels) ?>;
        const revenueValues = <?= json_encode($trendValues) ?>;

        // Chart colors come from the theme tokens in styles/theme.css,
        // so the chart matches dark or light mode and redraws on toggle.
        function cgChartColors() {
            const css = getComputedStyle(document.documentElement);
            const v = (name) => css.getPropertyValue(name).trim();
            return {
                line: v('--cg-gold'),
                fill: 'rgba(' + v('--cg-gold-rgb') + ', 0.12)',
                ticks: v('--cg-muted'),
                grid: v('--cg-divider'),
            };
        }

        const chartColors = cgChartColors();
        const revenueChart = new Chart(document.getElementById('revenueTrendChart'), {
            type: 'line',
            data: {
                labels: revenueLabels,
                datasets: [{
                    label: 'Revenue',
                    data: revenueValues,
                    borderColor: chartColors.line,
                    backgroundColor: chartColors.fill,
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.3,
                    fill: true,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => '₱' + ctx.parsed.y.toLocaleString(undefined, { minimumFractionDigits: 2 })
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: chartColors.ticks, maxTicksLimit: 10 },
                        grid: { color: chartColors.grid }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: chartColors.ticks,
                            callback: (value) => '₱' + value.toLocaleString()
                        },
                        grid: { color: chartColors.grid }
                    }
                }
            }
        });

        document.addEventListener('cg-themechange', function () {
            const c = cgChartColors();
            const ds = revenueChart.data.datasets[0];
            ds.borderColor = c.line;
            ds.backgroundColor = c.fill;
            ['x', 'y'].forEach(function (axis) {
                revenueChart.options.scales[axis].ticks.color = c.ticks;
                revenueChart.options.scales[axis].grid.color = c.grid;
            });
            revenueChart.update('none');
        });
    </script>
    <?php endif; ?>
</body>

</html>