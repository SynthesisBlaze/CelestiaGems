<?php
$current_page = 'account';

session_start();
$error = '';
$success = '';

if (isset($_SESSION['success_msg'])) {
    $success = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}
if (isset($_SESSION['error_msg'])) {
    $error = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/google_maps_config.php';
require_once __DIR__ . '/../functions/helpers.php';

// Must be logged in to view this page
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$stmt = $pdo->prepare("SELECT first_name, last_name, email, phone, created_at FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$userRow = $stmt->fetch();

// Fallback in case the session is stale and the row no longer exists
if (!$userRow) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

$addressStmt = $pdo->prepare("SELECT address_line, city, postal_code, latitude, longitude, is_default FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, address_id DESC LIMIT 1");
$addressStmt->execute([$_SESSION['user_id']]);
$shippingAddress = $addressStmt->fetch();


function acctPesoFormat($amount)
{
    $amount = (float) $amount;
    $decimals = (fmod($amount, 1.0) === 0.0) ? 0 : 2;
    return '₱' . number_format($amount, $decimals);
}

$bookings = [];
try {
    $bookingsStmt = $pdo->prepare("
        SELECT b.booking_id, b.appointment_date, b.appointment_time, b.custom_requirements,
               b.estimated_price, b.payment_method, b.booking_status, b.created_at,
               s.name AS service_name
        FROM service_bookings b
        JOIN services s ON b.service_id = s.service_id
        WHERE b.user_id = ?
        ORDER BY b.appointment_date DESC, b.appointment_time DESC
    ");
    $bookingsStmt->execute([$_SESSION['user_id']]);
    $bookingRows = $bookingsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('account.php bookings fetch error: ' . $e->getMessage());
    $bookingRows = [];
}

$now = new DateTime();
foreach ($bookingRows as $row) {
    $apptDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $row['appointment_date'] . ' ' . $row['appointment_time']);
    $isUpcoming = $apptDateTime && $apptDateTime >= $now;
    $isCancellableStatus = in_array($row['booking_status'], ['Pending', 'Confirmed'], true);

    $bookings[] = [
        'id'           => (int) $row['booking_id'],
        'service_name' => $row['service_name'],
        'date_label'   => $apptDateTime ? $apptDateTime->format('D, M j, Y') : $row['appointment_date'],
        'time_label'   => $apptDateTime ? $apptDateTime->format('g:i A') : $row['appointment_time'],
        'notes'        => $row['custom_requirements'],
        'price'        => (float) $row['estimated_price'],
        'payment'      => $row['payment_method'],
        'status'       => $row['booking_status'],
        'can_cancel'   => $isCancellableStatus && $isUpcoming,
    ];
}

$upcomingBookingsCount = count(array_filter($bookings, fn($b) => $b['can_cancel']));

// ---- Orders ----
$orders = [];
try {
    $ordersStmt = $pdo->prepare("
        SELECT
            o.order_id, o.total_amount, o.order_status, o.created_at,
            d.delivery_status,
            p.payment_status,
            (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.order_id) AS item_count
        FROM orders o
        LEFT JOIN deliveries d ON d.order_id = o.order_id
        LEFT JOIN payments p ON p.order_id = o.order_id
        WHERE o.user_id = ?
        ORDER BY o.created_at DESC
    ");
    $ordersStmt->execute([$_SESSION['user_id']]);
    $orderRows = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('account.php orders fetch error: ' . $e->getMessage());
    $orderRows = [];
}

foreach ($orderRows as $row) {
    $orders[] = [
        'id'              => (int) $row['order_id'],
        'number'          => format_order_number((int) $row['order_id']),
        'date_label'      => date('M j, Y', strtotime($row['created_at'])),
        'time_label'      => date('g:i A', strtotime($row['created_at'])),
        'item_count'      => (int) $row['item_count'],
        'total'           => (float) $row['total_amount'],
        'order_status'    => $row['order_status'],
        'delivery_status' => $row['delivery_status'] ?? 'Pending',
        'payment_status'  => $row['payment_status'] ?? 'Pending',
    ];
}

// Pull a few product thumbnails per order so cards show real images, not just text
$thumbsByOrder = [];
$orderIds = array_column($orderRows, 'order_id');
if (!empty($orderIds)) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    // Find this block and update it:
    $thumbStmt = $pdo->prepare("
    SELECT oi.order_id, oi.order_item_id, oi.product_id, p.name AS product_name, img.image_url
    FROM order_items oi
    JOIN products p ON oi.product_id = p.product_id
    LEFT JOIN product_images img ON p.product_id = img.product_id AND img.is_primary = 1
    WHERE oi.order_id IN ($placeholders)
    ORDER BY oi.order_item_id ASC
    ");
    $thumbStmt->execute($orderIds);
    foreach ($thumbStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $thumbsByOrder[$row['order_id']][] = [
            'product_id' => $row['product_id'], // <-- ADD THIS LINE
            'name'       => $row['product_name'],
            'image'      => !empty($row['image_url']) ? '/img/' . $row['image_url'] : '/img/default-product.png',
        ];
    }
}
foreach ($orders as &$o) {
    $allThumbs = $thumbsByOrder[$o['id']] ?? [];
    $o['thumbnails']  = array_slice($allThumbs, 0, 4);
    $o['extra_count'] = max(0, count($allThumbs) - 4);
}
unset($o);

// One return request per order, latest first — a Delivered order can have
// at most one active (Pending/Approved) request at a time (enforced in
// return-request.php), but we still want to show a resolved Denied one too.
$returnRequestsByOrder = [];
if (!empty($orderIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $returnStmt = $pdo->prepare("
            SELECT order_id, status, reason
            FROM return_requests
            WHERE order_id IN ($placeholders)
            ORDER BY requested_at DESC
        ");
        $returnStmt->execute($orderIds);
        foreach ($returnStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($returnRequestsByOrder[$row['order_id']])) {
                $returnRequestsByOrder[$row['order_id']] = $row; // first hit per order_id = most recent, since ORDER BY requested_at DESC
            }
        }
    } catch (Exception $e) {
        error_log('account.php return_requests fetch error: ' . $e->getMessage());
    }
}
foreach ($orders as &$o) {
    $o['return_request'] = $returnRequestsByOrder[$o['id']] ?? null;
}
unset($o);

$ordersCount = count($orders);

// ---- Wishlist ----
$wishlistItems = [];
try {
    $wishStmt = $pdo->prepare("
        SELECT 
            p.product_id AS id, p.name, p.price,
            sub.name AS sub_name,
            img.image_url AS image
        FROM wishlist_items w
        INNER JOIN products p ON w.product_id = p.product_id
        LEFT JOIN categories sub ON p.category_id = sub.category_id
        LEFT JOIN product_images img ON p.product_id = img.product_id AND img.is_primary = 1
        WHERE w.user_id = ?
        ORDER BY w.created_at DESC
    ");
    $wishStmt->execute([$_SESSION['user_id']]);
    $wishlistItems = $wishStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// ---- Notifications ----
$notifications = [];
$unreadNotificationCount = 0;
try {
    $notifStmt = $pdo->prepare("
        SELECT notification_id, type, title, message, related_type, related_id, is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $notifStmt->execute([$_SESSION['user_id']]);
    $notifRows = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('account.php notifications fetch error: ' . $e->getMessage());
    $notifRows = [];
}

foreach ($notifRows as $row) {
    $notifications[] = [
        'id'           => (int) $row['notification_id'],
        'title'        => $row['title'],
        'message'      => $row['message'],
        'related_type' => $row['related_type'],
        'related_id'   => $row['related_id'] !== null ? (int) $row['related_id'] : null,
        'is_read'      => (int) $row['is_read'] === 1,
        'created_at'   => date('M j, Y \a\t g:i A', strtotime($row['created_at'])),
    ];
    if ((int) $row['is_read'] === 0) {
        $unreadNotificationCount++;
    }
}

$user = [
    'first_name'   => $userRow['first_name'],
    'last_name'    => $userRow['last_name'],
    'email'        => $userRow['email'],
    'phone'        => $userRow['phone'],
    'member_since' => $userRow['created_at'] ? date('Y', strtotime($userRow['created_at'])) : '',
    'shipping_address' => $shippingAddress['address_line'] ?? '',
    'shipping_city' => $shippingAddress['city'] ?? '',
    'shipping_postal_code' => $shippingAddress['postal_code'] ?? '',
    'shipping_lat' => (isset($shippingAddress['latitude']) && (float)$shippingAddress['latitude'] !== 0.0) ? $shippingAddress['latitude'] : 14.5995,
    'shipping_lng' => (isset($shippingAddress['longitude']) && (float)$shippingAddress['longitude'] !== 0.0) ? $shippingAddress['longitude'] : 120.9842,
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account — Celestia Gems</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/account.css?v=<?php echo time(); ?>">
</head>

<body>

    <?php include __DIR__ . '/../components/navbar.php'; ?>

    <main class="py-5">
        <div class="container">

            <div class="mb-5">
                <h1 class="acct-hello">Welcome back, <span class="acct-name"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>.</span></h1>
                <p class="acct-sub mb-0">Here's everything in your Celestia Gems account.</p>
            </div>

            <div class="row g-4">
                <!-- SIDEBAR -->
                <div class="col-lg-3">
                    <div class="acct-sidebar p-3">
                        <div class="d-flex align-items-center gap-3 p-2">
                            <div>
                                <div class="name"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
                                <div class="member-since">Member since <?= htmlspecialchars($user['member_since']) ?></div>
                            </div>
                        </div>

                        <nav class="acct-nav pt-2 mt-2">
                            <button class="acct-nav-link active" data-tab="overview">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <rect x="3" y="3" width="7" height="9" rx="1" />
                                    <rect x="14" y="3" width="7" height="5" rx="1" />
                                    <rect x="14" y="12" width="7" height="9" rx="1" />
                                    <rect x="3" y="16" width="7" height="5" rx="1" />
                                </svg>
                                Overview
                            </button>
                            <button class="acct-nav-link" data-tab="orders">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M4 7l8-4 8 4v10l-8 4-8-4z" />
                                    <path d="M4 7l8 4 8-4" />
                                    <path d="M12 11v10" />
                                </svg>
                                Orders
                            </button>
                            <button class="acct-nav-link" data-tab="bookings">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <rect x="3" y="4" width="18" height="18" rx="2" />
                                    <path d="M16 2v4M8 2v4M3 10h18" />
                                    <path d="M9 16l2 2 4-4" />
                                </svg>
                                Bookings
                            </button>
                            <button class="acct-nav-link" data-tab="wishlist">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M12 21s-7.5-4.6-10-9.1C.5 8 2.3 4.5 6 4c2-.3 3.8.7 6 3 2.2-2.3 4-3.3 6-3 3.7.5 5.5 4 4 7.9-2.5 4.5-10 9.1-10 9.1z" />
                                </svg>
                                Wishlist
                            </button>
                            <button class="acct-nav-link" data-tab="notifications">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M18 8a6 6 0 00-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
                                    <path d="M13.73 21a2 2 0 01-3.46 0" />
                                </svg>
                                Notifications
                                <?php if ($unreadNotificationCount > 0): ?>
                                    <span class="acct-notif-badge" id="notifBadge"><?= $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount ?></span>
                                <?php endif; ?>
                            </button>
                            <button class="acct-nav-link" data-tab="details">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <circle cx="12" cy="8" r="4" />
                                    <path d="M4 21c0-4 4-7 8-7s8 3 8 7" />
                                </svg>
                                Account Details
                            </button>

                            <div class="acct-signout-divider"></div>
                            <a href="/functions/logout.php" class="acct-nav-link acct-signout" id="signOutLink">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4" />
                                    <path d="M16 17l5-5-5-5" />
                                    <path d="M21 12H9" />
                                </svg>
                                Sign Out
                            </a>
                        </nav>
                    </div>
                </div>

                <!-- CONTENT -->
                <div class="col-lg-9">

                    <!-- OVERVIEW TAB -->
                    <div class="acct-tab active" id="tab-overview">
                        <h2 class="acct-section-title mb-1">Overview</h2>
                        <p class="acct-section-sub mb-4">A quick look at your orders, details and other information.</p>

                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <div class="num"><?= htmlspecialchars((string) $ordersCount) ?></div>
                                    <div class="label">Orders Placed</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <div class="num"><?= htmlspecialchars((string) $upcomingBookingsCount) ?></div>
                                    <div class="label">Upcoming Bookings</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <div class="num" id="wishlistCountStat"><?= count($wishlistItems) ?></div>
                                    <div class="label">Items on Wishlist</div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="acct-section-title mb-0" style="font-size:1rem;">Recent Bookings</h3>
                            <button class="btn btn-outline-gold btn-sm px-3" onclick="showTab('bookings')">View All</button>
                        </div>
                        <?php if (empty($bookings)): ?>
                            <div class="empty-state mb-5">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                                    <rect x="3" y="4" width="18" height="18" rx="2" />
                                    <path d="M16 2v4M8 2v4M3 10h18" />
                                    <path d="M9 16l2 2 4-4" />
                                </svg>
                                <div class="title">No bookings yet</div>
                                <div class="hint">Once you book an appointment, it'll show up here.</div>
                            </div>
                        <?php else: ?>
                            <div class="mb-5">
                                <?php foreach (array_slice($bookings, 0, 2) as $b): ?>
                                    <div class="booking-card mb-3">
                                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                            <div>
                                                <div class="booking-service"><?= htmlspecialchars($b['service_name']) ?></div>
                                                <div class="booking-meta mt-1"><?= htmlspecialchars($b['date_label']) ?> at <?= htmlspecialchars($b['time_label']) ?></div>
                                            </div>
                                            <span class="booking-status status-<?= strtolower($b['status']) ?>"><?= htmlspecialchars($b['status']) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="acct-section-title mb-0" style="font-size:1rem;">Recent Orders</h3>
                            <button class="btn btn-outline-gold btn-sm px-3" onclick="showTab('orders')">View All</button>
                        </div>
                        <?php if (empty($orders)): ?>
                            <div class="empty-state mb-5">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                                    <path d="M4 7l8-4 8 4v10l-8 4-8-4z" />
                                    <path d="M4 7l8 4 8-4" />
                                    <path d="M12 11v10" />
                                </svg>
                                <div class="title">No orders yet</div>
                                <div class="hint">Once you place an order, it'll show up here.</div>
                            </div>
                        <?php else: ?>
                            <div class="mb-5">
                                <?php foreach (array_slice($orders, 0, 2) as $o): ?>
                                    <div class="order-card mb-3">
                                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                            <div>
                                                <div class="order-number"><?= htmlspecialchars($o['number']) ?></div>
                                                <div class="order-meta mt-1"><strong><?= htmlspecialchars($o['date_label']) ?></strong> &middot; <?= $o['item_count'] ?> item<?= $o['item_count'] === 1 ? '' : 's' ?></div>
                                            </div>
                                            <span class="order-status status-<?= strtolower($o['order_status']) ?>"><?= htmlspecialchars($o['order_status']) ?></span>
                                        </div>
                                        <?php if (!empty($o['thumbnails'])): ?>
                                            <div class="order-thumb-strip mt-3">
                                                <?php foreach ($o['thumbnails'] as $t): ?>
                                                    <img src="<?= htmlspecialchars($t['image']) ?>" alt="<?= htmlspecialchars($t['name']) ?>" class="order-thumb" title="<?= htmlspecialchars($t['name']) ?>">
                                                <?php endforeach; ?>
                                                <?php if ($o['extra_count'] > 0): ?>
                                                    <span class="order-thumb-more">+<?= $o['extra_count'] ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="acct-section-title mb-0" style="font-size:1rem;">Your Wishlist</h3>
                            <button class="btn btn-outline-gold btn-sm px-3" onclick="showTab('wishlist')">View All</button>
                        </div>

                        <?php if (empty($wishlistItems)): ?>
                            <div class="empty-state">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                                    <path d="M12 21s-7.5-4.6-10-9.1C.5 8 2.3 4.5 6 4c2-.3 3.8.7 6 3 2.2-2.3 4-3.3 6-3 3.7.5 5.5 4 4 7.9-2.5 4.5-10 9.1-10 9.1z" />
                                </svg>
                                <div class="title">Your wishlist is empty</div>
                                <div class="hint">Save pieces you love and they'll appear here.</div>
                            </div>
                        <?php else: ?>
                            <div class="row row-cols-2 row-cols-md-3 g-3 mb-5">
                                <?php foreach (array_slice($wishlistItems, 0, 3) as $w): ?>
                                    <div class="col d-flex align-items-stretch" id="wishlist-overview-item-<?= $w['id'] ?>">
                                        <div class="card product-card w-100" style="cursor:pointer;" onclick="if(!event.target.closest('.wishlist-btn')) window.location.href='product.php?id=<?= $w['id'] ?>'">
                                            <div class="product-media">
                                                <?php if (!empty($w['image'])): ?>
                                                    <img src="/img/<?= htmlspecialchars($w['image']) ?>" alt="<?= htmlspecialchars($w['name']) ?>" style="width:100%;height:100%;object-fit:cover;opacity:0.95;">
                                                <?php else: ?>
                                                    <svg viewBox="0 0 48 48" width="50" height="50">
                                                        <path d="M10 16 L24 6 L38 16 L24 43 Z" stroke-width="1.6" stroke-linejoin="round" fill="none" />
                                                        <path d="M10 16 L38 16 M17 16 L24 43 M31 16 L24 43 M17 16 L24 6 M31 16 L24 6" stroke-width="1" fill="none" />
                                                    </svg>
                                                <?php endif; ?>
                                                <button type="button" class="btn wishlist-btn" aria-pressed="true" aria-label="Remove from wishlist" data-remove-wishlist="<?= $w['id'] ?>">
                                                    <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" fill="none">
                                                        <path d="M12 21s-7.5-4.8-10-9.3C0.3 8.1 2 4.5 5.6 4.1 8 3.9 10.2 5.3 12 8c1.8-2.7 4-4.1 6.4-3.9 3.6.4 5.3 4 3.6 7.6C19.5 16.2 12 21 12 21z" />
                                                    </svg>
                                                </button>
                                            </div>
                                            <div class="card-body product-info d-flex flex-column">
                                                <div>
                                                    <span class="product-sub d-block mb-1"><?= htmlspecialchars($w['sub_name']) ?></span>
                                                    <h3 class="product-name card-title mb-2"><?= htmlspecialchars($w['name']) ?></h3>
                                                </div>
                                                <div class="mt-auto pt-2 d-flex flex-column gap-2">
                                                    <span class="product-price">₱<?= number_format((float)$w['price'], 2) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ORDERS TAB -->
                    <div class="acct-tab" id="tab-orders">
                        <h2 class="acct-section-title mb-1">Orders</h2>
                        <p class="acct-section-sub mb-4">Track and review everything you've ordered.</p>

                        <div id="ordersAlert"></div>

                        <?php if (empty($orders)): ?>
                            <div class="empty-state lg">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                                    <path d="M4 7l8-4 8 4v10l-8 4-8-4z" />
                                    <path d="M4 7l8 4 8-4" />
                                    <path d="M12 11v10" />
                                </svg>
                                <div class="title">No orders yet</div>
                                <div class="hint">You haven't placed any orders. Order history will appear here once you do.</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($orders as $o): ?>
                                <div class="order-card mb-3" data-order-id="<?= $o['id'] ?>">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                        <div>
                                            <div class="order-number"><?= htmlspecialchars($o['number']) ?></div>
                                            <div class="order-ref">Placed <strong><?= htmlspecialchars($o['date_label']) ?></strong> at <?= htmlspecialchars($o['time_label']) ?></div>
                                        </div>
                                        <span class="order-status status-<?= strtolower($o['order_status']) ?>"><?= htmlspecialchars($o['order_status']) ?></span>
                                    </div>

                                    <?php if (!empty($o['thumbnails'])): ?>
                                        <div class="order-thumb-strip mt-3">
                                            <?php foreach ($o['thumbnails'] as $t): ?>
                                                <img src="<?= htmlspecialchars($t['image']) ?>" alt="<?= htmlspecialchars($t['name']) ?>" class="order-thumb" title="<?= htmlspecialchars($t['name']) ?>">
                                            <?php endforeach; ?>
                                            <?php if ($o['extra_count'] > 0): ?>
                                                <span class="order-thumb-more">+<?= $o['extra_count'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="row g-2 mt-3 order-meta">
                                        <div class="col-sm-4"><?= $o['item_count'] ?> item<?= $o['item_count'] === 1 ? '' : 's' ?></div>
                                        <div class="col-sm-4">Payment: <strong><?= htmlspecialchars($o['payment_status']) ?></strong></div>
                                        <div class="col-sm-4">Total: <strong class="order-price">₱<?= number_format($o['total'], 2) ?></strong></div>
                                    </div>

                                    <div class="d-flex justify-content-end align-items-center gap-2 mt-3 flex-wrap">
                                        <?php if ($o['order_status'] === 'Delivered'): ?>
                                            <?php
                                            $rr = $o['return_request'];
                                            // Grab the first product ID to link directly to the review section
                                            $firstProductId = !empty($o['thumbnails']) ? $o['thumbnails'][0]['product_id'] : 0;
                                            ?>

                                            <!-- NEW REVIEW BUTTON -->
                                            <?php if ($firstProductId): ?>
                                                <a href="product.php?id=<?= $firstProductId ?>#pdReviews" class="btn btn-outline-gold btn-sm px-3">Write a Review</a>
                                            <?php endif; ?>

                                            <?php if ($rr === null): ?>
                                                <button type="button" class="btn btn-outline-danger-soft btn-sm px-3 request-return-btn" data-order-id="<?= $o['id'] ?>" data-order-number="<?= htmlspecialchars($o['number']) ?>">
                                                    Request Return / Refund
                                                </button>
                                            <?php elseif ($rr['status'] === 'Pending'): ?>
                                                <span class="order-status status-pending">Return Requested</span>
                                            <?php elseif ($rr['status'] === 'Approved'): ?>
                                                <span class="order-status status-delivered">Return Approved</span>
                                            <?php else: ?>
                                                <span class="order-status status-cancelled">Return Declined</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline-muted btn-sm px-3 view-order-btn" data-order-id="<?= $o['id'] ?>">View Details</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- BOOKINGS TAB -->
                    <div class="acct-tab" id="tab-bookings">
                        <h2 class="acct-section-title mb-1">Bookings</h2>
                        <p class="acct-section-sub mb-4">Track your appointments and manage upcoming visits.</p>

                        <div id="bookingsAlert"></div>

                        <?php if (empty($bookings)): ?>
                            <div class="empty-state lg">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                                    <rect x="3" y="4" width="18" height="18" rx="2" />
                                    <path d="M16 2v4M8 2v4M3 10h18" />
                                    <path d="M9 16l2 2 4-4" />
                                </svg>
                                <div class="title">No bookings yet</div>
                                <div class="hint">You haven't booked any appointments. Your booking history will appear here once you do.</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($bookings as $b): ?>
                                <div class="booking-card mb-3" data-booking-id="<?= (int) $b['id'] ?>" data-status="<?= htmlspecialchars($b['status']) ?>">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                        <div>
                                            <div class="booking-service"><?= htmlspecialchars($b['service_name']) ?></div>
                                            <div class="booking-ref">Ref #<?= str_pad((string) $b['id'], 6, '0', STR_PAD_LEFT) ?></div>
                                        </div>
                                        <span class="booking-status status-<?= strtolower($b['status']) ?>"><?= htmlspecialchars($b['status']) ?></span>
                                    </div>

                                    <div class="row g-2 mt-2 booking-meta">
                                        <div class="col-sm-4"><strong><?= htmlspecialchars($b['date_label']) ?></strong> at <?= htmlspecialchars($b['time_label']) ?></div>
                                        <div class="col-sm-4">Payment: <strong><?= htmlspecialchars($b['payment'] ? ucfirst($b['payment']) : '—') ?></strong></div>
                                        <div class="col-sm-4">Total: <strong class="booking-price"><?= acctPesoFormat($b['price']) ?></strong></div>
                                    </div>

                                    <?php if (!empty($b['notes'])): ?>
                                        <div class="booking-notes"><?= nl2br(htmlspecialchars($b['notes'])) ?></div>
                                    <?php endif; ?>

                                    <div class="d-flex justify-content-end mt-3" data-cancel-slot>
                                        <?php if ($b['can_cancel']): ?>
                                            <button type="button" class="btn btn-outline-danger-soft btn-sm px-3" onclick="cancelBooking(<?= (int) $b['id'] ?>, this)">Cancel Booking</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- WISHLIST TAB -->
                    <div class="acct-tab" id="tab-wishlist">
                        <h2 class="acct-section-title mb-1">Wishlist</h2>
                        <p class="acct-section-sub mb-4">Pieces you've been eyeing for.</p>

                        <?php if (empty($wishlistItems)): ?>
                            <div class="empty-state lg">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                                    <path d="M12 21s-7.5-4.6-10-9.1C.5 8 2.3 4.5 6 4c2-.3 3.8.7 6 3 2.2-2.3 4-3.3 6-3 3.7.5 5.5 4 4 7.9-2.5 4.5-10 9.1-10 9.1z" />
                                </svg>
                                <div class="title">Your wishlist is empty</div>
                                <div class="hint">Tap the heart icon on any product to save it here.</div>
                            </div>
                        <?php else: ?>
                            <div class="row row-cols-2 row-cols-md-3 g-3">
                                <?php foreach ($wishlistItems as $w): ?>
                                    <div class="col d-flex align-items-stretch" id="wishlist-item-<?= $w['id'] ?>">
                                        <div class="card product-card w-100" style="cursor:pointer;" onclick="if(!event.target.closest('.wishlist-btn')) window.location.href='product.php?id=<?= $w['id'] ?>'">
                                            <div class="product-media">
                                                <?php if (!empty($w['image'])): ?>
                                                    <img src="/img/<?= htmlspecialchars($w['image']) ?>" alt="<?= htmlspecialchars($w['name']) ?>" style="width:100%;height:100%;object-fit:cover;opacity:0.95;">
                                                <?php else: ?>
                                                    <svg viewBox="0 0 48 48" width="50" height="50">
                                                        <path d="M10 16 L24 6 L38 16 L24 43 Z" stroke-width="1.6" stroke-linejoin="round" fill="none" />
                                                        <path d="M10 16 L38 16 M17 16 L24 43 M31 16 L24 43 M17 16 L24 6 M31 16 L24 6" stroke-width="1" fill="none" />
                                                    </svg>
                                                <?php endif; ?>
                                                <button type="button" class="btn wishlist-btn" aria-pressed="true" aria-label="Remove from wishlist" data-remove-wishlist="<?= $w['id'] ?>">
                                                    <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" fill="none">
                                                        <path d="M12 21s-7.5-4.8-10-9.3C0.3 8.1 2 4.5 5.6 4.1 8 3.9 10.2 5.3 12 8c1.8-2.7 4-4.1 6.4-3.9 3.6.4 5.3 4 3.6 7.6C19.5 16.2 12 21 12 21z" />
                                                    </svg>
                                                </button>
                                            </div>
                                            <div class="card-body product-info d-flex flex-column">
                                                <div>
                                                    <span class="product-sub d-block mb-1"><?= htmlspecialchars($w['sub_name']) ?></span>
                                                    <h3 class="product-name card-title mb-2"><?= htmlspecialchars($w['name']) ?></h3>
                                                </div>
                                                <div class="mt-auto pt-2 d-flex flex-column gap-2">
                                                    <span class="product-price">₱<?= number_format((float)$w['price'], 2) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- NOTIFICATIONS TAB -->
                    <div class="acct-tab" id="tab-notifications">
                        <h2 class="acct-section-title mb-1">Notifications</h2>
                        <p class="acct-section-sub mb-4">Updates on your orders, account, and offers.</p>

                        <?php if (empty($notifications)): ?>
                            <div class="empty-state lg">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                                    <path d="M18 8a6 6 0 00-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
                                    <path d="M13.73 21a2 2 0 01-3.46 0" />
                                </svg>
                                <div class="title">No notifications yet</div>
                                <div class="hint">You're all caught up. We'll let you know here when there's order or account activity.</div>
                            </div>
                        <?php else: ?>
                            <div>
                                <?php foreach ($notifications as $n): ?>
                                    <div class="notification-item <?= $n['is_read'] ? '' : 'unread' ?>">
                                        <?php if (!$n['is_read']): ?><span class="notification-dot"></span><?php endif; ?>
                                        <div class="notification-body">
                                            <div class="notification-title"><?= htmlspecialchars($n['title']) ?></div>
                                            <div class="notification-message"><?= htmlspecialchars($n['message']) ?></div>
                                            <div class="notification-time"><?= htmlspecialchars($n['created_at']) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ACCOUNT DETAILS TAB -->
                    <div class="acct-tab" id="tab-details">
                        <h2 class="acct-section-title mb-1">Account Details</h2>
                        <p class="acct-section-sub mb-4">Update your personal information and password.</p>

                        <!-- Success / Error Messages -->
                        <?php if (!empty($success)): ?>
                            <div class="alert text-center py-2 mb-4 cg-notice cg-notice--success">
                                <?= htmlspecialchars($success) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($error)): ?>
                            <div class="alert text-center py-2 mb-4 cg-notice cg-notice--error">
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <form class="details-card mb-4" action="../functions/account-update.php" method="post">
                            <h4>Personal Information</h4>
                            <p class="card-sub">These details are used for your orders and account.</p>

                            <div class="row g-3 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label">First Name:</label>
                                    <input type="text" class="form-control" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Last Name:</label>
                                    <input type="text" class="form-control" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email Address:</label>
                                    <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($user['email']) ?>" placeholder="you@example.com" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Phone Number:</label>
                                    <input type="tel" class="form-control" name="phone" value="<?= htmlspecialchars($user['phone']) ?>" placeholder="09xx xxx xxxx">
                                </div>
                            </div>

                            <div class="d-flex gap-2 mt-3">
                                <button type="submit" class="btn btn-gold px-4 py-2">Save Changes</button>
                                <button type="reset" class="btn btn-outline-muted px-4 py-2">Cancel</button>
                            </div>
                        </form>

                        <form class="details-card mb-4" action="../functions/account-update.php" method="post">
                            <h4>Shipping Address</h4>
                            <p class="card-sub">This address is saved to your account and shows in the database under the user address record.</p>

                            <input type="hidden" name="shipping_lat" id="shippingLat" value="<?= htmlspecialchars($user['shipping_lat']) ?>">
                            <input type="hidden" name="shipping_lng" id="shippingLng" value="<?= htmlspecialchars($user['shipping_lng']) ?>">

                            <div class="row g-3 mb-2">
                                <div class="col-12">
                                    <label class="form-label">Search Address:</label>
                                    <div id="addressSearchContainer" style="width: 100%;"></div>
                                </div>
                                <div class="col-12">
                                    <div id="addressMap" style="height: 300px; border-radius: 8px;"></div>
                                    <small class="text-muted">Drag the pin, click the map, or search above to set your exact location.</small>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Street Address:</label>
                                    <input type="text" class="form-control" name="shipping_address" value="<?= htmlspecialchars($user['shipping_address']) ?>" placeholder="123 Main Street">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">City:</label>
                                    <input type="text" class="form-control" name="shipping_city" value="<?= htmlspecialchars($user['shipping_city']) ?>" placeholder="Manila">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Postal Code:</label>
                                    <input type="text" class="form-control" name="shipping_postal_code" value="<?= htmlspecialchars($user['shipping_postal_code']) ?>" placeholder="1000">
                                </div>
                            </div>

                            <div class="d-flex gap-2 mt-3">
                                <button type="submit" class="btn btn-gold px-4 py-2">Save Address</button>
                                <button type="reset" class="btn btn-outline-muted px-4 py-2">Cancel</button>
                            </div>
                        </form>

                        <form class="details-card" action="../functions/account-password.php" method="post">
                            <h4>Change Password</h4>
                            <p class="card-sub">Choose a strong password you don't use elsewhere.</p>

                            <div class="row g-3 mb-2">
                                <div class="col-12">
                                    <label class="form-label">Current Password:</label>
                                    <input type="password" class="form-control" name="current_password" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">New Password:</label>
                                    <input type="password" class="form-control" name="new_password" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Confirm Password:</label>
                                    <input type="password" class="form-control" name="confirm_password" required>
                                </div>
                            </div>

                            <div class="d-flex gap-2 mt-3">
                                <button type="submit" class="btn btn-gold px-4 py-2">Save Changes</button>
                                <button type="reset" class="btn btn-outline-muted px-4 py-2">Cancel</button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>
        </div>
    </main>

    <!-- ORDER DETAIL MODAL -->
    <div class="modal fade" id="orderDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="odModalTitle">Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="odModalBody">
                    <p class="text-muted text-center py-4">Loading order details&hellip;</p>
                </div>
            </div>
        </div>
    </div>

    <!-- RETURN REQUEST MODAL -->
    <div class="modal fade" id="returnRequestModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Request Return / Refund</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="acct-section-sub mb-3" id="returnRequestOrderLabel"></p>
                    <label class="form-label">Why would you like to return this order?</label>
                    <textarea class="form-control" id="returnRequestReason" rows="4" maxlength="1000" placeholder="e.g. Item arrived damaged, wrong size, changed my mind..."></textarea>
                    <div id="returnRequestError" class="mt-2" style="color:var(--cg-danger); font-size:.85rem;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-muted" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-gold" id="returnRequestSubmit">Submit Request</button>
                </div>
            </div>
        </div>
    </div>

    <div class="added-toast" id="wishlistToast" aria-live="polite" aria-atomic="true" hidden>
        <div class="added-toast-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" fill="#C9A15C">
                <path d="M12 21s-7.5-4.8-10-9.3C0.3 8.1 2 4.5 5.6 4.1 8 3.9 10.2 5.3 12 8c1.8-2.7 4-4.1 6.4-3.9 3.6.4 5.3 4 3.6 7.6C19.5 16.2 12 21 12 21z" />
            </svg>
        </div>
        <div>
            <div class="added-toast-title">Wishlist</div>
            <div class="added-toast-copy" id="wishlistToastMsg">Updated.</div>
        </div>
    </div>

    <?php include __DIR__ . '/../components/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar tab switching — no page reload needed since there's no backend yet.
        function showTab(name) {
            document.querySelectorAll('.acct-tab').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.acct-nav-link[data-tab]').forEach(el => el.classList.remove('active'));
            document.getElementById('tab-' + name).classList.add('active');
            const link = document.querySelector('.acct-nav-link[data-tab="' + name + '"]');
            if (link) link.classList.add('active');

            // The map is inside a tab that may start hidden (display: none),
            // which breaks Google Maps' sizing. Nudge it once it's visible.
            if (name === 'details' && window.addressMap) {
                google.maps.event.trigger(window.addressMap, 'resize');
                if (window.addressMarker) {
                    window.addressMap.setCenter(window.addressMarker.getPosition());
                }
            }

            if (name === 'notifications') {
                markNotificationsRead();
            }
        }

        // --- Mark notifications read on open (once per page load) ---
        let notificationsMarkedRead = false;

        function markNotificationsRead() {
            if (notificationsMarkedRead) return;
            const badge = document.getElementById('notifBadge');
            if (!badge) return; // nothing unread, nothing to do

            notificationsMarkedRead = true;

            fetch('../functions/notifications-mark-read.php', {
                    method: 'POST',
                    credentials: 'same-origin'
                })
                .then(res => res.json().catch(() => ({
                    success: false
                })))
                .then(data => {
                    if (data.success) {
                        badge.remove();
                        document.querySelectorAll('.notification-item.unread').forEach(item => {
                            item.classList.remove('unread');
                            const dot = item.querySelector('.notification-dot');
                            if (dot) dot.remove();
                        });
                    }
                })
                .catch(() => {
                    notificationsMarkedRead = false; // allow a retry on next tab open
                });
        }
        document.querySelectorAll('.acct-nav-link[data-tab]').forEach(btn => {
            btn.addEventListener('click', () => showTab(btn.dataset.tab));
        });

        // --- Order detail modal ---
        const orderModalEl = document.getElementById('orderDetailModal');
        const orderModal = new bootstrap.Modal(orderModalEl);
        const odModalTitle = document.getElementById('odModalTitle');
        const odModalBody = document.getElementById('odModalBody');

        function pesoFmt(n) {
            return '₱' + Number(n).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // Escape text before inserting it with innerHTML.
        function esc(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        function renderOrderDetail(data, orderId) {
            odModalTitle.textContent = data.order_number;

            const itemsHtml = data.items.map(item => `
                <div class="od-item-row">
                    <img src="${esc(item.image)}" alt="">
                    <div class="flex-grow-1">
                        <div class="od-item-name">${esc(item.name)}</div>
                        <div class="od-item-meta">Size ${esc(item.size)} &middot; Qty ${esc(item.quantity)}</div>
                        
                        ${data.order_status === 'Delivered' && item.product_id ? `
                            <div class="mt-1">
                                <a href="product.php?id=${encodeURIComponent(item.product_id)}#pdReviews" style="font-size:0.75rem; color:var(--cg-gold); text-decoration:underline;">Write a Review</a>
                            </div>
                        ` : ''}
                        
                    </div>
                    <div class="od-item-price">${pesoFmt(item.line_total)}</div>
                </div>
            `).join('');

            // Generate the cancellation button if the order is eligible
            let cancelBtnHtml = '';
            if (data.order_status === 'Pending' || data.order_status === 'Processing') {
                cancelBtnHtml = `
                    <div class="d-flex justify-content-end mt-4 pt-3" style="border-top: 1px solid var(--cg-divider);">
                        <button type="button" class="btn btn-outline-danger-soft btn-sm px-4" onclick="requestOrderCancellation(${orderId}, this)">Request Cancellation</button>
                    </div>
                `;
            }

            const statusClass = 'status-' + (data.order_status ? data.order_status.toLowerCase().replace(/\s+/g, '-') : 'pending');

            odModalBody.innerHTML = `
                <div class="od-meta">
                    <div><span>Placed</span><strong>${esc(data.created_at)}</strong></div>
                    <div><span>Order status</span><div><span class="order-status ${esc(statusClass)}">${esc(data.order_status)}</span></div></div>
                    <div><span>Payment method</span><strong>${esc(data.payment_method || 'Cash on Delivery')}</strong></div>
                    <div><span>Payment status</span><strong>${esc(data.payment_status)}</strong></div>
                </div>

                <div class="od-section">
                    <p class="od-kicker">Delivery address</p>
                    <p style="font-size:.88rem;" class="mb-0">${esc(data.address_line || 'No address on file')}</p>
                </div>

                <div class="od-section">
                    <p class="od-kicker">Items (${data.items.length})</p>
                    ${itemsHtml}
                    <div class="od-total-row"><span>Total</span><span>${pesoFmt(data.total_amount)}</span></div>
                </div>
                
                ${cancelBtnHtml}
            `;
        }

        document.querySelectorAll('.view-order-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const orderId = btn.dataset.orderId; // Capture the order ID
                odModalBody.innerHTML = '<p class="text-muted text-center py-4">Loading order details&hellip;</p>';
                orderModal.show();

                fetch('../functions/my-order-details.php?order_id=' + orderId, {
                        credentials: 'same-origin'
                    })
                    .then(res => res.json())
                    .then(json => {
                        if (!json.success) {
                            odModalBody.innerHTML = '<p class="text-center py-4" style="color:var(--cg-danger);">' + esc(json.message || 'Could not load this order.') + '</p>';
                            return;
                        }
                        renderOrderDetail(json.order, orderId); // Pass the ID to the renderer
                    })
                    .catch(() => {
                        odModalBody.innerHTML = '<p class="text-center py-4" style="color:var(--cg-danger);">Could not load this order.</p>';
                    });
            });
        });

        // --- Order cancellation request ---
        function requestOrderCancellation(orderId, btn) {
            if (!confirm('Are you sure you want to request cancellation for this order?')) return;

            btn.disabled = true;
            const originalText = btn.textContent;
            btn.textContent = 'Requesting...';

            const formData = new FormData();
            formData.append('order_id', orderId);

            fetch('../functions/user-cancel-request.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(res => res.json().catch(() => ({
                    success: false,
                    message: 'Server error'
                })))
                .then(data => {
                    if (data.success) {
                        // Reload the page to reflect the new "Cancellation Requested" status on the cards
                        location.reload();
                    } else {
                        alert(data.message || 'Could not request cancellation.');
                        btn.disabled = false;
                        btn.textContent = originalText;
                    }
                })
                .catch(() => {
                    alert('Network error. Please try again.');
                    btn.disabled = false;
                    btn.textContent = originalText;
                });
        }

        // --- Booking cancellation ---
        function showBookingsAlert(type, message) {
            const box = document.getElementById('bookingsAlert');
            if (!box) return;
            const isSuccess = type === 'success';
            box.innerHTML = '<div class="alert text-center py-2 mb-4 cg-notice ' + (isSuccess ? 'cg-notice--success' : 'cg-notice--error') + '">' + esc(message) + '</div>';
        }

        function cancelBooking(bookingId, btn) {
            if (!confirm('Cancel this booking? This cannot be undone.')) return;

            btn.disabled = true;
            const originalLabel = btn.textContent;
            btn.textContent = 'Cancelling…';

            const formData = new FormData();
            formData.append('booking_id', bookingId);

            fetch('../functions/cancel_booking.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(res => res.json().catch(() => ({
                    success: false,
                    message: 'Unexpected server response.'
                })))
                .then(data => {
                    if (data.success) {
                        const card = document.querySelector('.booking-card[data-booking-id="' + bookingId + '"]');
                        if (card) {
                            card.dataset.status = 'Cancelled';
                            const badge = card.querySelector('.booking-status');
                            if (badge) {
                                badge.textContent = 'Cancelled';
                                badge.className = 'booking-status status-cancelled';
                            }
                            const slot = card.querySelector('[data-cancel-slot]');
                            if (slot) slot.innerHTML = '';
                        }
                        showBookingsAlert('success', data.message || 'Your booking has been cancelled.');
                    } else {
                        btn.disabled = false;
                        btn.textContent = originalLabel;
                        showBookingsAlert('error', data.message || 'Could not cancel this booking. Please try again.');
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.textContent = originalLabel;
                    showBookingsAlert('error', 'Something went wrong. Please check your connection and try again.');
                });
        }

        // --- Return / Refund requests ---
        function showOrdersAlert(type, message) {
            const box = document.getElementById('ordersAlert');
            if (!box) return;
            const isSuccess = type === 'success';
            box.innerHTML = '<div class="alert text-center py-2 mb-4 cg-notice ' + (isSuccess ? 'cg-notice--success' : 'cg-notice--error') + '">' + esc(message) + '</div>';
        }

        const returnModalEl = document.getElementById('returnRequestModal');
        const returnModal = new bootstrap.Modal(returnModalEl);
        const returnReasonInput = document.getElementById('returnRequestReason');
        const returnOrderLabel = document.getElementById('returnRequestOrderLabel');
        const returnErrorBox = document.getElementById('returnRequestError');
        const returnSubmitBtn = document.getElementById('returnRequestSubmit');
        let returnRequestOrderId = null;

        document.querySelectorAll('.request-return-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                returnRequestOrderId = btn.dataset.orderId;
                returnOrderLabel.textContent = 'Order ' + btn.dataset.orderNumber;
                returnReasonInput.value = '';
                returnErrorBox.textContent = '';
                returnModal.show();
            });
        });

        returnSubmitBtn.addEventListener('click', () => {
            const reason = returnReasonInput.value.trim();
            if (!reason) {
                returnErrorBox.textContent = 'Please provide a reason for the return.';
                return;
            }

            returnSubmitBtn.disabled = true;
            returnSubmitBtn.textContent = 'Submitting…';

            const formData = new FormData();
            formData.append('order_id', returnRequestOrderId);
            formData.append('reason', reason);

            fetch('../functions/return-request.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(res => res.json().catch(() => ({
                    success: false,
                    message: 'Unexpected server response.'
                })))
                .then(data => {
                    if (data.success) {
                        returnModal.hide();
                        showOrdersAlert('success', data.message || 'Your return request has been submitted.');
                        setTimeout(() => location.reload(), 900);
                    } else {
                        returnErrorBox.textContent = data.message || 'Could not submit your request.';
                    }
                })
                .catch(() => {
                    returnErrorBox.textContent = 'Something went wrong. Please check your connection and try again.';
                })
                .finally(() => {
                    returnSubmitBtn.disabled = false;
                    returnSubmitBtn.textContent = 'Submit Request';
                });
        });

        // Warn before signing out
        document.getElementById('signOutLink').addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to sign out?')) {
                e.preventDefault();
            }
        });

        // Optional: open a specific tab via ?tab=orders etc.
        const params = new URLSearchParams(window.location.search);
        if (params.get('tab')) showTab(params.get('tab'));

        // --- Shipping address map ---
        const GOOGLE_MAPS_KEY = "<?= GOOGLE_MAPS_API_KEY ?>";

        function initMap() {
            const defaultLat = <?= (float)$user['shipping_lat'] ?>;
            const defaultLng = <?= (float)$user['shipping_lng'] ?>;
            const startPos = {
                lat: defaultLat,
                lng: defaultLng
            };

            const map = new google.maps.Map(document.getElementById('addressMap'), {
                center: startPos,
                zoom: 15,
            });

            const marker = new google.maps.Marker({
                position: startPos,
                map: map,
                draggable: true,
            });

            window.addressMap = map;
            window.addressMarker = marker;

            function fillFromLatLng(latLng) {
                document.getElementById('shippingLat').value = latLng.lat();
                document.getElementById('shippingLng').value = latLng.lng();

                // The classic google.maps.Geocoder() service isn't part of
                // the demo key's free scope — use the newer Geocoding API v4
                // REST endpoint directly instead.
                const url = `https://geocode.googleapis.com/v4/geocode/location` +
                    `?location.latitude=${latLng.lat()}` +
                    `&location.longitude=${latLng.lng()}` +
                    `&key=${GOOGLE_MAPS_KEY}`;

                fetch(url)
                    .then(res => res.json())
                    .then(data => {
                        if (!data.results || !data.results.length) return;

                        // The top (most specific) result sometimes lacks a
                        // locality or postal_code component in Philippine
                        // addresses — scan every result and take the first
                        // match found for each field, most-specific first.
                        let streetNumber = '',
                            route = '',
                            city = '',
                            postal = '';
                        let formattedAddress = data.results[0].formattedAddress || '';

                        data.results.forEach(result => {
                            (result.addressComponents || []).forEach(c => {
                                if (!streetNumber && c.types.includes('street_number')) streetNumber = c.longText;
                                if (!route && c.types.includes('route')) route = c.longText;
                                if (!city && (c.types.includes('locality') || c.types.includes('administrative_area_level_2'))) city = c.longText;
                                if (!postal && c.types.includes('postal_code')) postal = c.longText;
                            });
                        });

                        const streetField = document.querySelector('input[name="shipping_address"]');
                        const cityField = document.querySelector('input[name="shipping_city"]');
                        const postalField = document.querySelector('input[name="shipping_postal_code"]');

                        streetField.value = (streetNumber + ' ' + route).trim() || formattedAddress;
                        if (city) cityField.value = city;
                        if (postal) postalField.value = postal;
                    })
                    .catch(err => console.error('Reverse geocoding failed:', err));
            }

            marker.addListener('dragend', () => fillFromLatLng(marker.getPosition()));

            map.addListener('click', (e) => {
                marker.setPosition(e.latLng);
                fillFromLatLng(e.latLng);
            });

            // New Places Autocomplete element — the legacy Autocomplete
            // widget isn't available on demo/2025+ API keys.
            const autocompleteElement = new google.maps.places.PlaceAutocompleteElement();
            autocompleteElement.id = 'addressSearch';
            document.getElementById('addressSearchContainer').appendChild(autocompleteElement);

            autocompleteElement.addEventListener('gmp-select', async ({
                placePrediction
            }) => {
                const place = placePrediction.toPlace();
                await place.fetchFields({
                    fields: ['location', 'formattedAddress']
                });
                if (!place.location) return;
                map.setCenter(place.location);
                map.setZoom(17);
                marker.setPosition(place.location);
                fillFromLatLng(place.location);
            });
        }

        document.querySelectorAll('[data-remove-wishlist]').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var id = this.dataset.removeWishlist;

                // Optimistically remove card from both the Wishlist Tab AND the Overview Tab
                var mainCard = document.getElementById('wishlist-item-' + id);
                var overviewCard = document.getElementById('wishlist-overview-item-' + id);

                if (mainCard) mainCard.remove();
                if (overviewCard) overviewCard.remove();

                // Dynamically update the count in the Overview tab
                var countEl = document.getElementById('wishlistCountStat');
                if (countEl) {
                    var currentCount = parseInt(countEl.textContent) || 0;
                    if (currentCount > 0) {
                        countEl.textContent = currentCount - 1;
                    }
                }

                fetch('../functions/wishlist-toggle.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            product_id: id
                        })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            showWishlistToast('Removed from your wishlist.');
                        }
                    });
            });
        });

        function showWishlistToast(message) {
            const toast = document.getElementById('wishlistToast');
            const msgEl = document.getElementById('wishlistToastMsg');

            if (!toast || !msgEl) return;

            msgEl.textContent = message;
            toast.removeAttribute('hidden');

            clearTimeout(showWishlistToast.timer);
            showWishlistToast.timer = setTimeout(function() {
                toast.setAttribute('hidden', 'hidden');
            }, 2500);
        }
    </script>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?= GOOGLE_MAPS_API_KEY ?>&libraries=places&callback=initMap" async defer></script>
</body>

</html>