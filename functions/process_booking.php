<?php
// process_booking.php — validates and creates the appointment from the booking.php form.
//
// NOTE: this assumes a `payment_method` column has been added to service_bookings.
// See the ALTER TABLE statement provided alongside this file.

session_status() === PHP_SESSION_NONE && session_start();

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/booking_helpers.php';
require_once __DIR__ . '/paymongo.php';
require_once __DIR__ . '/helpers.php';

function backToBooking(array $flash, ?int $serviceId = null): void
{
    $_SESSION['booking_flash'] = $flash;
    $target = '../pages/booking.php' . ($serviceId ? ('?service_id=' . $serviceId) : '');
    header('Location: ' . $target);
    exit;
}

// Must be signed in — mirrors the check at the top of booking.php.
if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php?redirect=' . urlencode('booking.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/booking.php');
    exit;
}

$userId          = (int) $_SESSION['user_id'];
$serviceIdsRaw   = $_POST['service_ids'] ?? '';
$appointmentDate = trim($_POST['appointment_date'] ?? '');
$appointmentTime = trim($_POST['appointment_time'] ?? '');
$paymentMethod   = trim($_POST['payment_method'] ?? '');
$notes           = trim($_POST['notes'] ?? '');

// --- Parse & validate selected services ---
$serviceIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $serviceIdsRaw)))));

if (empty($serviceIds)) {
    backToBooking(['type' => 'error', 'message' => 'Please select at least one service.']);
}

// --- Validate date (must be a real date, today or later) ---
$d = DateTime::createFromFormat('Y-m-d', $appointmentDate);
$today = new DateTime('today');
if (!$d || $d->format('Y-m-d') !== $appointmentDate || $d < $today) {
    backToBooking(['type' => 'error', 'message' => 'Please choose a valid, upcoming date.']);
}

// --- Validate time slot against the real slot list (blocks tampered times) ---
if (!isValidTimeSlot($appointmentTime)) {
    backToBooking(['type' => 'error', 'message' => 'Please choose a valid appointment time.']);
}

// --- A slot earlier today has already passed ---
if (new DateTime($appointmentDate . ' ' . $appointmentTime) <= new DateTime()) {
    backToBooking(['type' => 'error', 'message' => 'That time has already passed today. Please choose a later slot.']);
}

// --- Validate payment method ---
$allowedPayments = ['cash', 'paymongo'];
if (!in_array($paymentMethod, $allowedPayments, true)) {
    backToBooking(['type' => 'error', 'message' => 'Please choose a payment method.']);
}

try {
    // Re-fetch the selected services from the DB so price can never be tampered with client-side.
    $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
    $stmt = $pdo->prepare("SELECT service_id, name, base_price FROM services WHERE service_id IN ($placeholders)");
    $stmt->execute($serviceIds);
    $chosenServices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($chosenServices) === 0) {
        backToBooking(['type' => 'error', 'message' => 'The selected service(s) could not be found.']);
    }

    // Keep them in the order the customer picked them.
    $byId = [];
    foreach ($chosenServices as $s) {
        $byId[(int) $s['service_id']] = $s;
    }
    $orderedServices = [];
    foreach ($serviceIds as $id) {
        if (isset($byId[$id])) $orderedServices[] = $byId[$id];
    }

    $primaryService = $orderedServices[0];
    $additional     = array_slice($orderedServices, 1);
    $total          = array_sum(array_column($orderedServices, 'base_price'));

    // service_bookings stores one service_id per row. Any extra services chosen for the
    // same visit are summed into estimated_price and listed in custom_requirements so
    // nothing is lost. See the chat reply for more on this trade-off.
    $requirementParts = [];
    if (!empty($additional)) {
        $names = array_map(
            fn($s) => $s['name'] . ' (' . pesoFormat((float) $s['base_price']) . ')',
            $additional
        );
        $requirementParts[] = 'Also booked in the same visit: ' . implode(', ', $names) . '.';
    }
    if ($notes !== '') {
        $requirementParts[] = 'Customer notes: ' . $notes;
    }
    $customRequirements = $requirementParts ? implode("\n\n", $requirementParts) : null;

    $insert = $pdo->prepare("
        INSERT INTO service_bookings
            (user_id, service_id, appointment_date, appointment_time, custom_requirements, estimated_price, booking_status, payment_method)
        VALUES
            (:user_id, :service_id, :appointment_date, :appointment_time, :custom_requirements, :estimated_price, 'Pending', :payment_method)
        RETURNING booking_id
    ");
    $insert->execute([
        ':user_id'             => $userId,
        ':service_id'          => (int) $primaryService['service_id'],
        ':appointment_date'    => $appointmentDate,
        ':appointment_time'    => $appointmentTime,
        ':custom_requirements' => $customRequirements,
        ':estimated_price'     => $total,
        ':payment_method'      => $paymentMethod,
    ]);

    $bookingId = (int) $insert->fetchColumn();

    // Cash: booking's created, nothing more to do — same flow as before.
    if ($paymentMethod === 'cash') {
        $_SESSION['booking_flash'] = [
            'type'       => 'success',
            'booking_id' => $bookingId,
            'date'       => $appointmentDate,
            'time'       => $appointmentTime,
            'total'      => $total,
            'payment'    => 'cash',
            'status'     => 'Pending confirmation',
            'services'   => array_column($orderedServices, 'name'),
        ];
        header('Location: ../pages/booking.php');
        exit;
    }

    // PayMongo: send the customer to a hosted checkout page for the total.
    $baseUrl = cg_base_url();
    $description = implode(', ', array_column($orderedServices, 'name'));
    $checkout = create_paymongo_checkout_session(
        $total,
        $description,
        'BK-' . $bookingId,
        $baseUrl . '/functions/booking-payment-return.php?booking_id=' . $bookingId . '&result=success',
        $baseUrl . '/functions/booking-payment-return.php?booking_id=' . $bookingId . '&result=cancel'
    );

    if ($checkout === null) {
        // PayMongo API hiccup — don't lose the booking, just fall back to pay-at-appointment.
        $_SESSION['booking_flash'] = [
            'type'       => 'success',
            'booking_id' => $bookingId,
            'date'       => $appointmentDate,
            'time'       => $appointmentTime,
            'total'      => $total,
            'payment'    => 'cash',
            'status'     => 'Pending confirmation — online payment unavailable, please pay at your appointment',
            'services'   => array_column($orderedServices, 'name'),
        ];
        header('Location: ../pages/booking.php');
        exit;
    }

    $pdo->prepare("UPDATE service_bookings SET paymongo_checkout_id = ? WHERE booking_id = ?")
        ->execute([$checkout['id'], $bookingId]);

    header('Location: ' . $checkout['checkout_url']);
    exit;
} catch (PDOException $e) {
    // SQLSTATE 23505 = unique_violation — the uq_appointment_slot UNIQUE constraint fired,
    // meaning someone else grabbed this exact date/time between page load and submit.
    if ($e->errorInfo[0] === '23505') {
        backToBooking(['type' => 'error', 'message' => 'Sorry — that date and time was just booked by someone else. Please pick another slot.']);
    }
    error_log('process_booking error: ' . $e->getMessage());
    backToBooking(['type' => 'error', 'message' => 'Something went wrong while creating your booking. Please try again.']);
}
