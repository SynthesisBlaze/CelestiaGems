<?php
session_status() === PHP_SESSION_NONE && session_start();

require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/booking_helpers.php';

// ---- Require login. Adjust $_SESSION['user_id'] below if your login.php uses a different key. ----
if (!isset($_SESSION['user_id'])) {
    $wantsService = isset($_GET['service_id']) ? (int) $_GET['service_id'] : 0;
    $redirectTarget = 'booking.php' . ($wantsService ? ('?service_id=' . $wantsService) : '');
    header('Location: login.php?redirect=' . urlencode($redirectTarget));
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ---- Pre-fill the signed-in customer's details ----
$customer = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''];
try {
    $stmt = $pdo->prepare("SELECT first_name, last_name, email, phone FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $customer = $row;
} catch (Exception $e) {
    error_log('booking.php customer fetch error: ' . $e->getMessage());
}

// ---- Pull services (grouped by category) for the picker ----
$services = [];
try {
    $stmt = $pdo->query("
        SELECT s.service_id AS id, s.category_id, c.name AS category_name,
               s.name, s.description, s.base_price AS price
        FROM services s
        JOIN categories c ON s.category_id = c.category_id
        ORDER BY c.name, s.name
    ");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('booking.php services fetch error: ' . $e->getMessage());
}

$grouped = [];
foreach ($services as $s) {
    $grouped[$s['category_name']][] = $s;
}

$preselectId = isset($_GET['service_id']) ? (int) $_GET['service_id'] : 0;
$timeSlots   = generateTimeSlots();

// ---- One-time flash message set by process_booking.php ----
$flash = $_SESSION['booking_flash'] ?? null;
unset($_SESSION['booking_flash']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book an Appointment — Atelier Services</title>

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,500;1,600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="../styles/service.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link href="../styles/booking.css?v=<?php echo time(); ?>" rel="stylesheet">
</head>

<body>
    <?php $current_page = 'services'; ?>
    <?php include __DIR__ . '/../components/navbar.php'; ?>

    <div class="service-page-wrapper booking-page-wrapper">
        <main class="container py-5">

            <!-- ============ HEADER ============ -->
            <section class="mb-5 pb-4 border-bottom border-secondary border-opacity-25">
                <p class="eyebrow text-gold fw-semibold fs-5 mb-1 text-uppercase tracking-wide">Book an Appointment</p>
                <h1 class="display-5 fw-medium mb-3">Reserve your <em class="text-gold">private</em> appointment.</h1>
                <p class="lead text-secondary mb-0 col-lg-8">Select your service, confirm your details, and choose a time that works for you. Our resident jewelers will take it from there.</p>
            </section>

            <?php if ($flash && $flash['type'] === 'success'): ?>
                <div class="flash-banner flash-success mb-5" role="status">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" opacity="0.4"></circle>
                        <path d="M8 12.5l2.5 2.5L16 9"></path>
                    </svg>
                    <div>
                        <h3>Booking confirmed</h3>
                        <p>
                            Reference #<?php echo str_pad((string) $flash['booking_id'], 6, '0', STR_PAD_LEFT); ?> —
                            <?php echo htmlspecialchars(implode(', ', $flash['services'])); ?><br>
                            <?php echo date('D, M j, Y', strtotime($flash['date'])); ?> at <?php echo date('g:i A', strtotime($flash['time'])); ?>
                            &middot; <?php echo $flash['payment'] === 'paymongo' ? 'Paid online via PayMongo' : 'Cash (pay at appointment)'; ?>
                            &middot; Total <?php echo pesoFormat((float) $flash['total']); ?>
                            &middot; Status: <?php echo htmlspecialchars($flash['status'] ?? 'Pending confirmation'); ?>
                        </p>
                    </div>
                </div>
            <?php elseif ($flash && $flash['type'] === 'error'): ?>
                <div class="flash-banner flash-error mb-5" role="alert">
                    <p><?php echo htmlspecialchars($flash['message']); ?></p>
                </div>
            <?php endif; ?>

            <form id="bookingForm" action="../functions/process_booking.php" method="POST" novalidate>
                <input type="hidden" name="service_ids" id="serviceIdsInput" value="<?php echo $preselectId ? (int) $preselectId : ''; ?>">
                <input type="hidden" name="appointment_date" id="appointmentDateInput">
                <input type="hidden" name="appointment_time" id="appointmentTimeInput">
                <input type="hidden" name="payment_method" id="paymentMethodInput">

                <div class="row g-4">
                    <div class="col-lg-8">

                        <!-- ============ STEP 1 — SERVICE ============ -->
                        <div class="step-panel mb-4">
                            <div class="step-head">
                                <span class="step-number">01</span>
                                <div>
                                    <h2 class="step-title">Jewelry Service</h2>
                                    <p class="step-sub">Choose a service below — add more if you'd like to bundle a few into the same visit.</p>
                                </div>
                            </div>

                            <?php foreach ($grouped as $catName => $items): ?>
                                <h6 class="picker-category-label"><?php echo htmlspecialchars($catName); ?></h6>
                                <?php foreach ($items as $s): ?>
                                    <div class="service-picker-row" data-id="<?php echo (int) $s['id']; ?>">
                                        <div class="picker-info">
                                            <p class="picker-name"><?php echo htmlspecialchars($s['name']); ?></p>
                                            <p class="picker-desc"><?php echo htmlspecialchars($s['description']); ?></p>
                                        </div>
                                        <div class="picker-action">
                                            <span class="picker-price"><?php echo pesoFormat((float) $s['price']); ?></span>
                                            <button type="button" class="picker-add-btn">Add</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>

                        <!-- ============ STEP 2 — CUSTOMER DETAILS ============ -->
                        <div class="step-panel mb-4">
                            <div class="step-head">
                                <span class="step-number">02</span>
                                <div>
                                    <h2 class="step-title">Your Details</h2>
                                    <p class="step-sub">Pulled straight from your account, so there's nothing to retype.</p>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <div class="detail-field">
                                        <span class="detail-label">First name</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($customer['first_name']); ?></span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="detail-field">
                                        <span class="detail-label">Last name</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($customer['last_name']); ?></span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="detail-field">
                                        <span class="detail-label">Email</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($customer['email']); ?></span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="detail-field">
                                        <span class="detail-label">Phone</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($customer['phone'] ?: '—'); ?></span>
                                    </div>
                                </div>
                            </div>
                            <p class="detail-note">Signed in as <?php echo htmlspecialchars(trim($customer['first_name'] . ' ' . $customer['last_name'])); ?>. To change any of these, update your account profile.</p>
                        </div>

                        <!-- ============ STEP 3 — DATE & TIME ============ -->
                        <div class="step-panel mb-4">
                            <div class="step-head">
                                <span class="step-number">03</span>
                                <div>
                                    <h2 class="step-title">Date &amp; Time</h2>
                                    <p class="step-sub">Already-booked slots are shown greyed out and can't be selected.</p>
                                </div>
                            </div>
                            <label for="appointmentDate" class="text-muted small text-uppercase tracking-wide mb-2 d-block">Select a date</label>
                            <input type="date" id="appointmentDate" class="booking-date-input">

                            <div id="timeSlotGrid" class="time-slot-grid"></div>
                            <p class="time-slot-hint" id="timeSlotHint">Pick a date to see available times.</p>
                        </div>

                        <!-- ============ STEP 4 — PAYMENT ============ -->
                        <div class="step-panel mb-4">
                            <div class="step-head">
                                <span class="step-number">04</span>
                                <div>
                                    <h2 class="step-title">Payment Method</h2>
                                    <p class="step-sub">Pay now online, or pay cash when you arrive for your appointment.</p>
                                </div>
                            </div>
                            <div class="payment-options">
                                <button type="button" class="payment-option" data-value="cash">
                                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="2" y="6" width="20" height="12" rx="1.5"></rect>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                    <span>Cash</span>
                                    <small class="d-block text-muted" style="font-size:.72rem;">Pay at your appointment</small>
                                </button>
                                <button type="button" class="payment-option" data-value="paymongo">
                                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                                        <line x1="2" y1="10" x2="22" y2="10"></line>
                                        <line x1="5" y1="14.5" x2="10" y2="14.5"></line>
                                    </svg>
                                    <span>Pay Online</span>
                                    <small class="d-block text-muted" style="font-size:.72rem;">GCash, card, GrabPay via PayMongo</small>
                                </button>
                            </div>

                            <label for="bookingNotes" class="text-muted small text-uppercase tracking-wide mt-4 mb-2 d-block">Notes for your jeweler (optional)</label>
                            <textarea id="bookingNotes" name="notes" class="booking-notes" placeholder="Anything we should know ahead of your visit?"></textarea>
                        </div>
                    </div>

                    <!-- ============ SUMMARY ============ -->
                    <div class="col-lg-4">
                        <div class="summary-panel sticky-top">
                            <h3 class="summary-title">Booking Summary</h3>

                            <div id="summaryServices">
                                <p class="text-muted small mb-0">No service selected yet.</p>
                            </div>

                            <div class="summary-divider"></div>

                            <div class="summary-meta-row"><span>Date</span><span id="summaryDate">—</span></div>
                            <div class="summary-meta-row"><span>Time</span><span id="summaryTime">—</span></div>
                            <div class="summary-meta-row"><span>Payment</span><span id="summaryPayment">—</span></div>

                            <div class="summary-divider"></div>

                            <div class="summary-total-row">
                                <span class="summary-total-label">Total</span>
                                <span class="summary-total-value" id="summaryTotal">₱0</span>
                            </div>

                            <button type="submit" id="confirmBtn" class="btn btn-dark w-100 rounded-pill py-2" disabled>Confirm Booking</button>
                            <p class="text-muted small mt-3 mb-0">Your appointment is held as "Pending" until our team confirms it.</p>
                        </div>
                    </div>
                </div>
            </form>

        </main>
    </div> <!-- /service-page-wrapper -->

    <!-- Global Footer -->
    <?php include __DIR__ . '/../components/footer.php'; ?>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        $(document).ready(function() {
            const services = <?php echo json_encode(array_values($services)); ?>;
            const timeSlots = <?php echo json_encode($timeSlots); ?>;
            const preselectId = <?php echo (int) $preselectId; ?>;

            const selected = new Set();
            let chosenDate = null;
            let chosenTime = null;
            let chosenPayment = null;

            if (preselectId) selected.add(preselectId);

            const fmt = (n) => "₱" + parseFloat(n).toLocaleString("en-PH", {
                minimumFractionDigits: n % 1 === 0 ? 0 : 2
            });

            function serviceById(id) {
                return services.find(s => s.id == id);
            }

            function timeLabel(v) {
                const s = timeSlots.find(t => t.value === v);
                return s ? s.label : v;
            }

            function paymentLabel(v) {
                return {
                    cash: "Cash",
                    gcash: "GCash",
                    card: "Card / Debit"
                } [v] || v;
            }

            function formatDateLabel(v) {
                const d = new Date(v + "T00:00:00");
                return d.toLocaleDateString("en-PH", {
                    weekday: "short",
                    month: "short",
                    day: "numeric",
                    year: "numeric"
                });
            }

            function toggleService(id) {
                if (selected.has(id)) selected.delete(id);
                else selected.add(id);
                renderPicker();
                renderSummary();
                validateForm();
            }

            function renderPicker() {
                $(".service-picker-row").each(function() {
                    const id = $(this).data("id");
                    const isSelected = selected.has(id);
                    $(this).toggleClass("selected", isSelected);
                    $(this).find(".picker-add-btn").text(isSelected ? "Added ✓" : "Add");
                });
            }

            $(document).on("click", ".picker-add-btn", function() {
                toggleService($(this).closest(".service-picker-row").data("id"));
            });

            function renderSummary() {
                const list = Array.from(selected).map(serviceById).filter(Boolean);
                const total = list.reduce((sum, s) => sum + parseFloat(s.price), 0);
                const $sum = $("#summaryServices");

                if (list.length === 0) {
                    $sum.html('<p class="text-muted small mb-0">No service selected yet.</p>');
                } else {
                    $sum.html(list.map(s => `
                        <div class="summary-row">
                            <span>${s.name}</span>
                            <span class="d-flex align-items-center gap-2">
                                ${fmt(s.price)}
                                <button type="button" class="summary-remove" data-id="${s.id}" aria-label="Remove ${s.name}">×</button>
                            </span>
                        </div>`).join(""));
                }

                $("#summaryDate").text(chosenDate ? formatDateLabel(chosenDate) : "—");
                $("#summaryTime").text(chosenTime ? timeLabel(chosenTime) : "—");
                $("#summaryPayment").text(chosenPayment ? paymentLabel(chosenPayment) : "—");
                $("#summaryTotal").text(fmt(total));
                $("#serviceIdsInput").val(Array.from(selected).join(","));
            }

            $(document).on("click", ".summary-remove", function() {
                toggleService(parseInt($(this).data("id"), 10));
            });

            function renderSlots() {
                const html = timeSlots.map(t =>
                    `<button type="button" class="time-slot" data-value="${t.value}">${t.label}</button>`
                ).join("");
                $("#timeSlotGrid").html(html);
            }
            renderSlots();

            $("#appointmentDate").on("change", function() {
                chosenDate = $(this).val();
                chosenTime = null;
                $("#appointmentDateInput").val(chosenDate);
                $("#appointmentTimeInput").val("");
                $("#timeSlotHint").text(chosenDate ? "Loading availability…" : "Pick a date to see available times.");

                if (!chosenDate) {
                    renderSummary();
                    validateForm();
                    return;
                }

                $("#timeSlotGrid").addClass("loading");
                $.getJSON("../functions/get_booked_slots.php", {
                        date: chosenDate
                    })
                    .done(function(res) {
                        const booked = res.booked || [];
                        $(".time-slot").each(function() {
                            const val = $(this).data("value");
                            const isBooked = booked.includes(val);
                            $(this).toggleClass("booked", isBooked).prop("disabled", isBooked).removeClass("active");
                        });
                        $("#timeSlotHint").text("Select an available time.");
                    })
                    .fail(function() {
                        $(".time-slot").prop("disabled", true);
                        $("#timeSlotHint").text("Couldn't load availability. Please try a different date.");
                    })
                    .always(function() {
                        $("#timeSlotGrid").removeClass("loading");
                    });

                renderSummary();
                validateForm();
            });

            $(document).on("click", ".time-slot:not(.booked):not(:disabled)", function() {
                $(".time-slot").removeClass("active");
                $(this).addClass("active");
                chosenTime = $(this).data("value");
                $("#appointmentTimeInput").val(chosenTime);
                renderSummary();
                validateForm();
            });

            $(document).on("click", ".payment-option", function() {
                $(".payment-option").removeClass("active");
                $(this).addClass("active");
                chosenPayment = $(this).data("value");
                $("#paymentMethodInput").val(chosenPayment);
                renderSummary();
                validateForm();
            });

            function validateForm() {
                const ok = selected.size > 0 && chosenDate && chosenTime && chosenPayment;
                $("#confirmBtn").prop("disabled", !ok);
            }

            // Restrict the date picker to today and later.
            const todayISO = new Date().toISOString().split("T")[0];
            $("#appointmentDate").attr("min", todayISO);

            $("#bookingForm").on("submit", function(e) {
                if (selected.size === 0 || !chosenDate || !chosenTime || !chosenPayment) {
                    e.preventDefault();
                }
            });

            renderPicker();
            renderSummary();
            validateForm();
        });
    </script>
</body>

</html>