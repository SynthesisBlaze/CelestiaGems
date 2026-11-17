<?php
require_once __DIR__ . '/../functions/permissions.php';
cg_require_page('orders.view');

require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';

$current_admin_page = 'orders';
$page_title = 'Orders';
$admin_user = cg_admin_user();

/**
 * ---------------------------------------------------------------
 * Filters
 * ---------------------------------------------------------------
 */
$f_search = trim($_GET['search'] ?? '');
$f_status = trim($_GET['status'] ?? '');

$per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

$where  = [];
$params = [];

if ($f_search !== '') {
    // Search by order ID digits or customer name/email
    $searchId = parse_order_number($f_search);
    if ($searchId !== null) {
        $where[] = "o.order_id = :search_id";
        $params[':search_id'] = $searchId;
    } else {
        $where[] = "(u.first_name ILIKE :search OR u.last_name ILIKE :search OR u.email ILIKE :search OR o.guest_email ILIKE :search)";
        $params[':search'] = '%' . $f_search . '%';
    }
}

if ($f_status !== '') {
    $where[] = "o.order_status = :status";
    $params[':status'] = $f_status;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// --- Total count for pagination ---
$stmtCount = $pdo->prepare("
    SELECT COUNT(*) 
    FROM orders o 
    LEFT JOIN users u ON o.user_id = u.user_id 
    $whereSql
");
$stmtCount->execute($params);
$total_rows = (int)$stmtCount->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// --- Fetch orders ---
$sql = "
    SELECT 
        o.order_id, o.total_amount, o.order_status, o.created_at, o.guest_email,
        u.first_name, u.last_name, u.email,
        p.payment_status, p.payment_method,
        (SELECT rr.status FROM return_requests rr WHERE rr.order_id = o.order_id ORDER BY rr.requested_at DESC LIMIT 1) AS return_status,
        (SELECT rr.reason FROM return_requests rr WHERE rr.order_id = o.order_id ORDER BY rr.requested_at DESC LIMIT 1) AS return_reason
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.user_id
    LEFT JOIN payments p ON p.order_id = o.order_id
    $whereSql
    ORDER BY o.created_at DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

function buildQueryString(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    $query = array_filter($query, fn($v) => $v !== '' && $v !== null);
    return htmlspecialchars('?' . http_build_query($query));
}

// Helper for status badge colors
function getStatusBadgeClass($status) {
    $s = strtolower($status);
    if (str_contains($s, 'cancel') || $s === 'failed' || $s === 'refunded') return 'cancelled';
    if ($s === 'delivered' || $s === 'paid') return 'delivered';
    if ($s === 'shipped') return 'shipped';
    if ($s === 'processing') return 'processing';
    return 'pending';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders — Celestia Gems Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/../styles/admin.css">
</head>

<body>

    <div class="admin-shell">
        <?php include __DIR__ . '/../components/admin_sidebar.php'; ?>

        <div class="admin-main">
            <?php include __DIR__ . '/../components/admin_topbar.php'; ?>

            <div class="admin-content">

                <!-- ============ FILTER BAR ============ -->
                <form class="admin-panel shadow-sm filter-bar mb-4" method="get">
                    <div class="row g-3 align-items-end">
                        <div class="col-6 col-md-4 col-lg-3">
                            <label class="form-label fw-medium d-block">Search</label>
                            <input type="text" class="form-control" name="search" placeholder="Order #, Name, or Email"
                                value="<?= htmlspecialchars($f_search) ?>">
                        </div>
                        <div class="col-6 col-md-4 col-lg-3">
                            <label class="form-label fw-medium d-block">Status</label>
                            <select class="form-select" name="status">
                                <option value="">All statuses</option>
                                <?php
                                $statuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled', 'Cancellation Requested', 'Refunded'];
                                foreach ($statuses as $st): ?>
                                    <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2 d-flex gap-2">
                            <button type="submit" class="btn btn-gold px-3 py-2 flex-fill">Filter</button>
                        </div>
                    </div>
                    <?php if ($f_search || $f_status): ?>
                        <div class="mt-3 text-end">
                            <a href="orders_management.php" class="filter-clear-link text-decoration-none">Clear all filters</a>
                        </div>
                    <?php endif; ?>
                </form>

                <!-- ============ ORDERS TABLE ============ -->
                <div class="admin-panel shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="admin-panel-title d-flex align-items-center mb-0">
                            All Orders
                            <span class="audit-count ms-2">(<?= number_format($total_rows) ?> <?= $total_rows === 1 ? 'order' : 'orders' ?>)</span>
                        </div>
                    </div>

                    <?php if (empty($orders)): ?>
                        <div class="empty-state d-flex flex-column align-items-center justify-content-center p-5">
                            <svg class="icon mb-3 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" style="width: 48px; height: 48px;">
                                <path d="M4 7l8-4 8 4v10l-8 4-8-4z" />
                                <path d="M4 7l8 4 8-4" />
                                <path d="M12 11v10" />
                            </svg>
                            <div class="title fs-5 mb-2">No orders found</div>
                            <div class="hint text-muted">
                                <?= ($f_search || $f_status) ? 'Try widening or clearing your filters.' : 'Your store has no orders yet.' ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="admin-table table-hover align-middle w-100">
                                <thead>
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Date</th>
                                        <th>Customer</th>
                                        <th>Total</th>
                                        <th>Payment</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $o): ?>
                                        <?php 
                                            $orderNum = format_order_number((int)$o['order_id']); 
                                            $customerName = trim(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? ''));
                                            $customerEmail = $o['email'] ?? $o['guest_email'] ?? 'No email';
                                        ?>
                                        <tr>
                                            <td class="order-id-cell"><?= htmlspecialchars($orderNum) ?></td>
                                            <td class="text-muted"><?= date('M j, Y g:i A', strtotime($o['created_at'])) ?></td>
                                            <td>
                                                <span class="order-customer-name fw-medium"><?= htmlspecialchars($customerName !== '' ? $customerName : 'Guest') ?></span>
                                                <span class="order-customer-email"><?= htmlspecialchars($customerEmail) ?></span>
                                            </td>
                                            <td class="order-amount">₱<?= number_format((float)$o['total_amount'], 2) ?></td>
                                            <td>
                                                <span class="status-badge <?= getStatusBadgeClass($o['payment_status'] ?? 'Pending') ?>">
                                                    <?= htmlspecialchars($o['payment_status'] ?? 'Pending') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge <?= getStatusBadgeClass($o['order_status']) ?>">
                                                    <?= htmlspecialchars($o['order_status']) ?>
                                                </span>
                                                <?php if ($o['return_status'] === 'Pending'): ?>
                                                    <div class="mt-1">
                                                        <span class="status-badge shipped">Return Requested</span>
                                                        <div class="od-inline-note" style="max-width:220px;">
                                                            &ldquo;<?= htmlspecialchars(mb_substr($o['return_reason'] ?? '', 0, 90)) ?><?= mb_strlen($o['return_reason'] ?? '') > 90 ? '&hellip;' : '' ?>&rdquo;
                                                        </div>
                                                    </div>
                                                <?php elseif ($o['return_status'] === 'Approved'): ?>
                                                    <div class="mt-1"><span class="status-badge delivered">Return Approved</span></div>
                                                <?php elseif ($o['return_status'] === 'Denied'): ?>
                                                    <div class="mt-1"><span class="status-badge cancelled">Return Denied</span></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                    <?php if ($o['order_status'] === 'Cancellation Requested' && cg_can('orders.cancel')): ?>
                                                        <button type="button" class="btn btn-outline-danger-soft btn-sm px-2 py-1" onclick="handleCancelRequest(<?= (int)$o['order_id'] ?>, true)">Approve</button>
                                                        <button type="button" class="btn btn-outline-muted btn-sm px-2 py-1" onclick="handleCancelRequest(<?= (int)$o['order_id'] ?>, false)">Refuse</button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-outline-muted btn-sm px-2 py-1 view-order-btn" data-order-id="<?= (int)$o['order_id'] ?>" style="border-radius:3px; font-size:.72rem; letter-spacing:.04em; text-transform:uppercase;">
                                                            View
                                                        </button>
                                                        <div class="dropdown">
                                                            <button type="button" class="btn btn-outline-muted btn-sm px-2 py-1 dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" style="border-radius:3px; font-size:.72rem; letter-spacing:.04em; text-transform:uppercase;">
                                                                Print
                                                            </button>
                                                            <ul class="dropdown-menu dropdown-menu-end">
                                                                <li><a class="dropdown-item" href="order-print.php?order_id=<?= (int)$o['order_id'] ?>&type=invoice" target="_blank">Invoice</a></li>
                                                                <li><a class="dropdown-item" href="order-print.php?order_id=<?= (int)$o['order_id'] ?>&type=packing-slip" target="_blank">Packing Slip</a></li>
                                                            </ul>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($o['order_status'] === 'Cancellation Requested' && !cg_can('orders.cancel')): ?>
                                                        <span class="text-muted align-self-center" style="font-size:.72rem;">Awaiting Admin</span>
                                                    <?php endif; ?>
                                                    <?php if ($o['return_status'] === 'Pending' && !cg_can('returns.manage')): ?>
                                                        <span class="text-muted align-self-center" style="font-size:.72rem;">Return awaiting Admin</span>
                                                    <?php endif; ?>
                                                    <?php if ($o['return_status'] === 'Pending' && cg_can('returns.manage')): ?>
                                                        <button type="button" class="btn btn-outline-danger-soft btn-sm px-2 py-1" onclick="handleReturnRequest(<?= (int)$o['order_id'] ?>, true)">Approve Return</button>
                                                        <button type="button" class="btn btn-outline-muted btn-sm px-2 py-1" onclick="handleReturnRequest(<?= (int)$o['order_id'] ?>, false)">Deny Return</button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <div class="audit-pagination d-flex align-items-center justify-content-between mt-4">
                                <div class="audit-pagination-info">
                                    Page <span class="fw-medium"><?= $page ?></span> of <span class="fw-medium"><?= $total_pages ?></span>
                                </div>
                                <div class="audit-pagination-controls d-flex gap-2">
                                    <a href="<?= buildQueryString(['page' => max(1, $page - 1)]) ?>"
                                        class="btn btn-outline-muted btn-sm px-3 py-1 <?= $page <= 1 ? 'disabled' : '' ?>">Previous</a>
                                    <a href="<?= buildQueryString(['page' => min($total_pages, $page + 1)]) ?>"
                                        class="btn btn-outline-muted btn-sm px-3 py-1 <?= $page >= $total_pages ? 'disabled' : '' ?>">Next</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

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
                <div class="modal-footer" id="odModalFooter">
                    <!-- Dynamic Action Buttons will be injected here -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.audit-pagination-controls .disabled').forEach(a => {
            a.addEventListener('click', e => e.preventDefault());
        });

        // --- Cancellation Request Approval Flow ---
        function handleCancelRequest(orderId, isApprove) {
            if (!confirm(isApprove ? 'Approve this cancellation request?' : 'Refuse this request and resume processing?')) return;
            
            const formData = new FormData();
            formData.append('action', isApprove ? 'approve_cancellation' : 'refuse_cancellation');
            formData.append('order_id', orderId);
            
            fetch('../functions/order-action.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) location.reload();
                    else alert(data.message || 'Error processing request.');
                })
                .catch(() => alert('Network error.'));
        }

        // --- Return Request Approval Flow ---
        function handleReturnRequest(orderId, isApprove) {
            if (!confirm(isApprove ? 'Approve this return and mark the order as Refunded?' : 'Deny this return request?')) return;

            const formData = new FormData();
            formData.append('action', isApprove ? 'approve_return' : 'deny_return');
            formData.append('order_id', orderId);

            fetch('../functions/return-action.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) location.reload();
                    else alert(data.message || 'Error processing request.');
                })
                .catch(() => alert('Network error.'));
        }

        // --- Order Detail View ---
        const orderModalEl = document.getElementById('orderDetailModal');
        const orderModal = new bootstrap.Modal(orderModalEl);
        const odModalTitle = document.getElementById('odModalTitle');
        const odModalBody = document.getElementById('odModalBody');
        const odModalFooter = document.getElementById('odModalFooter');

        // What this role may do — buttons for anything else are not shown.
        // (The server checks again; this only keeps the UI honest.)
        const CAN = <?= cg_permissions_for_js(['orders.update_status', 'orders.cancel', 'orders.mark_paid']) ?>;

        // Everything inserted with innerHTML goes through this — order data
        // includes customer-typed text (names, delivery address).
        function esc(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        function pesoFmt(n) {
            return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        document.querySelectorAll('.view-order-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const orderId = btn.dataset.orderId;
                odModalBody.innerHTML = '<p class="text-muted text-center py-4">Loading order details&hellip;</p>';
                odModalFooter.innerHTML = '';
                orderModal.show();

                fetch('../functions/order-details.php?order_id=' + orderId)
                    .then(res => res.json())
                    .then(json => {
                        if (!json.success) {
                            odModalBody.innerHTML = '<p class="text-center py-4 text-danger">' + esc(json.message || 'Could not load this order.') + '</p>';
                            return;
                        }
                        
                        const data = json.order;
                        odModalTitle.textContent = data.order_number;

                        const itemsHtml = data.items.map(item => `
                            <div class="od-item-row">
                                <img src="${esc(item.image)}" alt="">
                                <div class="flex-grow-1">
                                    <div class="od-item-name">${esc(item.name)}</div>
                                    <div class="od-item-meta">Size ${esc(item.size)} &middot; Qty ${esc(item.quantity)}</div>
                                </div>
                                <div class="od-item-price">${pesoFmt(item.line_total)}</div>
                            </div>
                        `).join('');

                        odModalBody.innerHTML = `
                            <div class="od-meta">
                                <div><span>Customer</span><strong>${esc(data.customer_name)}</strong></div>
                                <div><span>Placed</span><strong>${esc(data.created_at)}</strong></div>
                                <div><span>Order status</span><strong>${esc(data.order_status)}</strong></div>
                                <div><span>Payment</span><strong>${esc(data.payment_method === 'cod' ? 'Cash on Delivery' : data.payment_method)} - ${esc(data.payment_status)}</strong></div>
                            </div>

                            <div class="od-section">
                                <p class="od-kicker">Delivery address</p>
                                <p style="font-size:.88rem;">${esc(data.address_line || 'No address on file')}</p>
                                ${data.delivery_status ? '<span class="status-badge ' + (data.order_status === 'Cancelled' ? 'cancelled' : '') + '">' + esc(data.order_status === 'Cancelled' ? 'Cancelled' : data.delivery_status) + '</span>' : ''}
                            </div>

                            <div class="od-section">
                                <p class="od-kicker">Items (${data.items.length})</p>
                                ${itemsHtml}
                                <div class="od-total-row"><span>Total</span><span>${pesoFmt(data.total_amount)}</span></div>
                            </div>
                        `;

                        // Add quick actions to the footer based on status
                        let footerHtml = '';
                        if (CAN['orders.update_status'] && (data.order_status === 'Pending' || data.order_status === 'Processing')) {
                            footerHtml += `<button class="btn btn-gold btn-sm px-3" onclick="updateOrderStatus(${orderId}, 'Shipped')">Mark as Shipped</button>`;
                        }
                        if (CAN['orders.update_status'] && data.order_status === 'Shipped') {
                            footerHtml += `<button class="btn btn-gold btn-sm px-3" onclick="updateOrderStatus(${orderId}, 'Delivered')">Mark as Delivered</button>`;
                        }
                        if (CAN['orders.mark_paid'] && data.payment_status === 'Pending' && data.order_status !== 'Cancelled') {
                            footerHtml += `<button class="btn btn-outline-delivered btn-sm px-3" onclick="markPaid(${orderId})">Mark Paid</button>`;
                        }
                        footerHtml += `<a href="order-print.php?order_id=${orderId}&type=invoice" target="_blank" class="btn btn-outline-muted btn-sm px-3">Invoice</a>`;
                        footerHtml += `<a href="order-print.php?order_id=${orderId}&type=packing-slip" target="_blank" class="btn btn-outline-muted btn-sm px-3">Packing Slip</a>`;
                        footerHtml += `<button type="button" class="btn btn-outline-muted btn-sm px-3" data-bs-dismiss="modal">Close</button>`;
                        odModalFooter.innerHTML = footerHtml;
                    })
                    .catch(() => {
                        odModalBody.innerHTML = '<p class="text-center py-4 text-danger">Could not load this order.</p>';
                    });
            });
        });

        function updateOrderStatus(orderId, newStatus) {
            if(!confirm(`Update order to ${newStatus}?`)) return;
            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('order_id', orderId);
            formData.append('status', newStatus);

            fetch('../functions/order-action.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) location.reload();
                    else alert(data.message);
                });
        }

        function markPaid(orderId) {
            if(!confirm(`Mark this order as paid?`)) return;
            const formData = new FormData();
            formData.append('action', 'mark_paid');
            formData.append('order_id', orderId);

            fetch('../functions/order-action.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) location.reload();
                    else alert(data.message);
                });
        }
    </script>
</body>

</html>