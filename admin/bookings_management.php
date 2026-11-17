<?php
require_once __DIR__ . '/../functions/permissions.php';
cg_require_page('bookings.view');

require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';

$current_admin_page = 'bookings';
$page_title = 'Bookings';
$admin_user = cg_admin_user();

$BOOKING_STATUSES = ['Pending', 'Confirmed', 'Completed', 'Cancelled'];

// --- Fetch services (for the filter dropdown) ---
$services = $pdo->query("SELECT service_id, name FROM services ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

/**
 * ---------------------------------------------------------------
 * Filters (all optional, all via GET so the page is bookmarkable)
 * ---------------------------------------------------------------
 */
$f_search     = trim($_GET['search'] ?? '');
$f_status     = trim($_GET['status'] ?? '');
$f_service_id = trim($_GET['service_id'] ?? '');

$per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

$where  = [];
$params = [];

if ($f_search !== '') {
    $where[] = "(u.first_name ILIKE :search OR u.last_name ILIKE :search OR u.email ILIKE :search)";
    $params[':search'] = '%' . $f_search . '%';
}
if ($f_status !== '' && in_array($f_status, $BOOKING_STATUSES, true)) {
    $where[] = "b.booking_status = :status";
    $params[':status'] = $f_status;
}
if ($f_service_id !== '') {
    $where[] = "b.service_id = :service_id";
    $params[':service_id'] = (int) $f_service_id;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

function buildQueryString(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    $query = array_filter($query, fn($v) => $v !== '' && $v !== null);
    return htmlspecialchars('?' . http_build_query($query));
}

$stmtCount = $pdo->prepare("
    SELECT COUNT(*) FROM service_bookings b
    JOIN users u ON u.user_id = b.user_id
    JOIN services s ON s.service_id = b.service_id
    $whereSql
");
$stmtCount->execute($params);
$total_rows = (int) $stmtCount->fetchColumn();
$total_pages = max(1, (int) ceil($total_rows / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql = "
    SELECT
        b.booking_id, b.appointment_date, b.appointment_time, b.custom_requirements,
        b.estimated_price, b.payment_method, b.payment_status, b.booking_status, b.created_at,
        s.name AS service_name,
        u.first_name, u.last_name, u.email
    FROM service_bookings b
    JOIN users u ON u.user_id = b.user_id
    JOIN services s ON s.service_id = b.service_id
    $whereSql
    ORDER BY b.appointment_date ASC, b.appointment_time ASC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bookings — Celestia Gems Admin</title>
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
                            <input type="text" class="form-control" name="search" placeholder="Customer name or email"
                                value="<?= htmlspecialchars($f_search) ?>">
                        </div>
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label fw-medium d-block">Status</label>
                            <select class="form-select" name="status">
                                <option value="">All</option>
                                <?php foreach ($BOOKING_STATUSES as $s): ?>
                                    <option value="<?= $s ?>" <?= $f_status === $s ? 'selected' : '' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 col-lg-3">
                            <label class="form-label fw-medium d-block">Service</label>
                            <select class="form-select" name="service_id">
                                <option value="">All Services</option>
                                <?php foreach ($services as $svc): ?>
                                    <option value="<?= (int) $svc['service_id'] ?>" <?= $f_service_id === (string) $svc['service_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($svc['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-2 col-lg-2">
                            <button type="submit" class="btn btn-outline-muted w-100">Filter</button>
                        </div>
                    </div>
                </form>

                <div class="admin-panel shadow-sm p-4">
                    <?php if (empty($bookings)): ?>
                        <div class="empty-state d-flex flex-column align-items-center justify-content-center p-5">
                            <svg class="icon mb-3 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" style="width: 48px; height: 48px;">
                                <rect x="3" y="4" width="18" height="17" rx="2" />
                                <path d="M3 9h18" />
                                <path d="M8 2v4M16 2v4" />
                            </svg>
                            <div class="title fs-5 mb-2">No bookings found</div>
                            <div class="hint text-muted">
                                <?= ($f_search || $f_status || $f_service_id)
                                    ? 'Try widening or clearing your filters.'
                                    : 'No service appointments have been booked yet.' ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="admin-table table-hover align-middle w-100">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Service</th>
                                        <th>Appointment</th>
                                        <th>Price</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bookings as $b): ?>
                                        <?php
                                        $apptDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $b['appointment_date'] . ' ' . $b['appointment_time']);
                                        $statusClass = match ($b['booking_status']) {
                                            'Pending' => 'pending',
                                            'Confirmed' => 'processing',
                                            'Completed' => 'delivered',
                                            'Cancelled' => 'cancelled',
                                            default => '',
                                        };
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="fw-medium text-cg"><?= htmlspecialchars(trim($b['first_name'] . ' ' . $b['last_name'])) ?></div>
                                                <div class="text-muted" style="font-size:.8rem;"><?= htmlspecialchars($b['email']) ?></div>
                                            </td>
                                            <td><?= htmlspecialchars($b['service_name']) ?></td>
                                            <td class="text-muted">
                                                <?= $apptDateTime ? $apptDateTime->format('M j, Y') : htmlspecialchars($b['appointment_date']) ?><br>
                                                <span style="font-size:.8rem;"><?= $apptDateTime ? $apptDateTime->format('g:i A') : htmlspecialchars($b['appointment_time']) ?></span>
                                            </td>
                                            <td style="color: var(--cg-gold-light);">₱<?= number_format((float) $b['estimated_price'], 2) ?></td>
                                            <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($b['booking_status']) ?></span></td>
                                            <td class="text-end">
                                                <button type="button"
                                                    class="btn btn-outline-muted btn-sm px-2 py-1 view-booking-btn"
                                                    style="border-radius:3px; font-size:.72rem; letter-spacing:.04em; text-transform:uppercase;"
                                                    data-id="<?= (int) $b['booking_id'] ?>"
                                                    data-customer="<?= htmlspecialchars(trim($b['first_name'] . ' ' . $b['last_name'])) ?>"
                                                    data-email="<?= htmlspecialchars($b['email']) ?>"
                                                    data-service="<?= htmlspecialchars($b['service_name']) ?>"
                                                    data-date="<?= $apptDateTime ? $apptDateTime->format('D, M j, Y') : htmlspecialchars($b['appointment_date']) ?>"
                                                    data-time="<?= $apptDateTime ? $apptDateTime->format('g:i A') : htmlspecialchars($b['appointment_time']) ?>"
                                                    data-price="₱<?= number_format((float) $b['estimated_price'], 2) ?>"
                                                    data-payment="<?= $b['payment_method'] === 'paymongo' ? 'Paid Online via PayMongo' : 'Cash' ?>"
                                                    data-payment-status="<?= htmlspecialchars($b['payment_status']) ?>"
                                                    data-notes="<?= htmlspecialchars($b['custom_requirements'] ?? '') ?>"
                                                    data-status="<?= htmlspecialchars($b['booking_status']) ?>">
                                                    View
                                                </button>
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

    <!-- ============ BOOKING DETAIL MODAL ============ -->
    <div class="modal fade" id="bookingModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bkModalTitle">Booking</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="bkModalBody"></div>
                <div class="modal-footer" id="bkModalFooter"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const bookingModalEl = document.getElementById('bookingModal');
        const bookingModal = new bootstrap.Modal(bookingModalEl);
        const bkModalTitle = document.getElementById('bkModalTitle');
        const bkModalBody = document.getElementById('bkModalBody');
        const bkModalFooter = document.getElementById('bkModalFooter');

        function renderBookingFooter(id, status) {
            let html = '';
            if (status === 'Pending') {
                html += `<button type="button" class="btn btn-gold btn-sm px-3" onclick="updateBookingStatus(${id}, 'Confirmed')">Confirm</button>`;
            }
            if (status === 'Confirmed') {
                html += `<button type="button" class="btn btn-gold btn-sm px-3" onclick="updateBookingStatus(${id}, 'Completed')">Mark Completed</button>`;
            }
            if (status === 'Pending' || status === 'Confirmed') {
                html += `<button type="button" class="btn btn-outline-danger-soft btn-sm px-3" onclick="updateBookingStatus(${id}, 'Cancelled')">Cancel Booking</button>`;
            }
            html += `<button type="button" class="btn btn-outline-muted btn-sm px-3" data-bs-dismiss="modal">Close</button>`;
            return html;
        }

        document.querySelectorAll('.view-booking-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const d = btn.dataset;
                bkModalTitle.textContent = d.service;

                bkModalBody.innerHTML = '';
                const meta = document.createElement('div');
                meta.className = 'od-meta';
                meta.innerHTML = `
                    <div><span>Customer</span><strong></strong></div>
                    <div><span>Email</span><strong></strong></div>
                    <div><span>Appointment</span><strong></strong></div>
                    <div><span>Price</span><strong></strong></div>
                    <div><span>Payment</span><strong></strong></div>
                    <div><span>Status</span><strong></strong></div>
                `;
                const strongs = meta.querySelectorAll('strong');
                strongs[0].textContent = d.customer;
                strongs[1].textContent = d.email;
                strongs[2].textContent = `${d.date} at ${d.time}`;
                strongs[3].textContent = d.price;
                strongs[4].textContent = `${d.payment} — ${d.paymentStatus}`;
                strongs[5].textContent = d.status;
                bkModalBody.appendChild(meta);

                if (d.notes) {
                    const notesSection = document.createElement('div');
                    notesSection.className = 'od-section';
                    const kicker = document.createElement('p');
                    kicker.className = 'od-kicker';
                    kicker.textContent = 'Customer Notes';
                    const notesBody = document.createElement('p');
                    notesBody.style.fontSize = '.9rem';
                    notesBody.style.whiteSpace = 'pre-wrap';
                    notesBody.textContent = d.notes;
                    notesSection.appendChild(kicker);
                    notesSection.appendChild(notesBody);
                    bkModalBody.appendChild(notesSection);
                }

                bkModalFooter.innerHTML = renderBookingFooter(d.id, d.status);
                bookingModal.show();
            });
        });

        function updateBookingStatus(bookingId, newStatus) {
            if (!confirm(`Update this booking to ${newStatus}?`)) return;

            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('booking_id', bookingId);
            formData.append('status', newStatus);

            fetch('../functions/booking-action.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) location.reload();
                    else alert(data.message || 'Could not update this booking.');
                });
        }

        document.querySelectorAll('.audit-pagination-controls .disabled').forEach(a => {
            a.addEventListener('click', e => e.preventDefault());
        });
    </script>
</body>

</html>
