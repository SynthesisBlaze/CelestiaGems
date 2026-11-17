<?php
/**
 * POST /api/book.php   (Authorization: Bearer <token>)
 * Body: { service_ids: [1,2], appointment_date: "2026-11-26",
 *         appointment_time: "11:30:00", payment_method: "cash"|"paymongo",
 *         notes?: "..." }
 *
 * functions/process_booking.php rewritten to return JSON instead of
 * redirecting with a $_SESSION flash. Same validation, same table, same
 * folding of extra services into custom_requirements and estimated_price,
 * and the same PayMongo session — only the PayMongo return URL differs,
 * because functions/booking-payment-return.php needs a PHP session that a
 * browser opened from the app does not have.
 *
 * Responses:
 *   cash     -> { success: true, booking_id, status, total, requires_payment: false }
 *   paymongo -> { success: true, booking_id, status, total, requires_payment: true, checkout_url }
 *   taken    -> 409 { success: false, message }   (someone took the slot first)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../functions/booking_helpers.php';
require_once __DIR__ . '/../functions/paymongo.php';
require_once __DIR__ . '/../functions/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

$user = require_auth($pdo);
$userId = (int) $user['user_id'];
$in = read_input();

// --- Services: accept an array, or the web's comma-separated string ---
$rawIds = $in['service_ids'] ?? '';
if (!is_array($rawIds)) {
    $rawIds = explode(',', (string) $rawIds);
}
$serviceIds = array_values(array_unique(array_filter(array_map('intval', $rawIds))));

if (empty($serviceIds)) {
    json_error('Please select at least one service.');
}

$appointmentDate = trim($in['appointment_date'] ?? '');
$appointmentTime = trim($in['appointment_time'] ?? '');
$paymentMethod   = trim($in['payment_method'] ?? '');
$notes           = trim($in['notes'] ?? '');

// --- Date must be real, and today or later ---
$d = DateTime::createFromFormat('Y-m-d', $appointmentDate);
$today = new DateTime('today');
if (!$d || $d->format('Y-m-d') !== $appointmentDate || $d < $today) {
    json_error('Please choose a valid, upcoming date.');
}

// --- Time must be one of the slots we actually offer (blocks tampering) ---
if (!isValidTimeSlot($appointmentTime)) {
    json_error('Please choose a valid appointment time.');
}

// --- A slot earlier today has already gone ---
if (new DateTime($appointmentDate . ' ' . $appointmentTime) <= new DateTime()) {
    json_error('That time has already passed today. Please choose a later slot.');
}

$allowedPayments = ['cash', 'paymongo'];
if (!in_array($paymentMethod, $allowedPayments, true)) {
    json_error('Please choose a payment method.');
}

try {
    // Re-read the services from the database so prices can never be set
    // by the client.
    $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
    $stmt = $pdo->prepare("SELECT service_id, name, base_price FROM services WHERE service_id IN ($placeholders)");
    $stmt->execute($serviceIds);
    $chosen = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($chosen) === 0) {
        json_error('The selected service(s) could not be found.');
    }

    // Keep the customer's own ordering.
    $byId = [];
    foreach ($chosen as $s) {
        $byId[(int) $s['service_id']] = $s;
    }
    $ordered = [];
    foreach ($serviceIds as $id) {
        if (isset($byId[$id])) {
            $ordered[] = $byId[$id];
        }
    }

    $primary    = $ordered[0];
    $additional = array_slice($ordered, 1);
    $total      = array_sum(array_column($ordered, 'base_price'));

    // One service_id per row, so extras for the same visit are summed into
    // estimated_price and named in custom_requirements — nothing is lost.
    $parts = [];
    if (!empty($additional)) {
        $names = array_map(
            fn($s) => $s['name'] . ' (' . pesoFormat((float) $s['base_price']) . ')',
            $additional
        );
        $parts[] = 'Also booked in the same visit: ' . implode(', ', $names) . '.';
    }
    if ($notes !== '') {
        $parts[] = 'Customer notes: ' . $notes;
    }
    $customRequirements = $parts ? implode("\n\n", $parts) : null;

    $insert = $pdo->prepare("
        INSERT INTO service_bookings
            (user_id, service_id, appointment_date, appointment_time, custom_requirements, estimated_price, booking_status, payment_method)
        VALUES
            (:user_id, :service_id, :appointment_date, :appointment_time, :custom_requirements, :estimated_price, 'Pending', :payment_method)
        RETURNING booking_id
    ");
    $insert->execute([
        ':user_id'             => $userId,
        ':service_id'          => (int) $primary['service_id'],
        ':appointment_date'    => $appointmentDate,
        ':appointment_time'    => $appointmentTime,
        ':custom_requirements' => $customRequirements,
        ':estimated_price'     => $total,
        ':payment_method'      => $paymentMethod,
    ]);

    $bookingId = (int) $insert->fetchColumn();
    $serviceNames = array_column($ordered, 'name');

    if ($paymentMethod === 'cash') {
        json_out([
            'success' => true,
            'booking_id' => $bookingId,
            'requires_payment' => false,
            'status' => 'Pending confirmation',
            'total' => (float) $total,
            'services' => $serviceNames,
            'appointment_date' => $appointmentDate,
            'appointment_time' => $appointmentTime,
        ]);
    }

    // PayMongo — hosted checkout for the total, returning to the
    // session-free handler the app can survive.
    $returnBase = APP_BASE_URL . '/api/payment-return.php';
    $checkout = create_paymongo_checkout_session(
        (float) $total,
        implode(', ', $serviceNames),
        'BK-' . $bookingId,
        $returnBase . '?booking_id=' . $bookingId . '&result=success',
        $returnBase . '?booking_id=' . $bookingId . '&result=cancel'
    );

    if ($checkout === null) {
        // Don't lose the booking over a PayMongo hiccup — the web falls
        // back to pay-at-appointment, so this does too.
        $pdo->prepare("UPDATE service_bookings SET payment_method = 'cash' WHERE booking_id = ?")
            ->execute([$bookingId]);

        json_out([
            'success' => true,
            'booking_id' => $bookingId,
            'requires_payment' => false,
            'status' => 'Pending confirmation — online payment unavailable, please pay at your appointment',
            'total' => (float) $total,
            'services' => $serviceNames,
            'appointment_date' => $appointmentDate,
            'appointment_time' => $appointmentTime,
        ]);
    }

    $pdo->prepare("UPDATE service_bookings SET paymongo_checkout_id = ? WHERE booking_id = ?")
        ->execute([$checkout['id'], $bookingId]);

    json_out([
        'success' => true,
        'booking_id' => $bookingId,
        'requires_payment' => true,
        'status' => 'Awaiting payment',
        'total' => (float) $total,
        'services' => $serviceNames,
        'appointment_date' => $appointmentDate,
        'appointment_time' => $appointmentTime,
        'checkout_url' => $checkout['checkout_url'],
    ]);
} catch (PDOException $e) {
    // 23505 = unique_violation: the uq_appointment_slot constraint fired,
    // so someone took this exact date and time in between.
    if (($e->errorInfo[0] ?? '') === '23505') {
        json_error('Sorry — that date and time was just booked by someone else. Please pick another slot.', 409);
    }
    error_log('book.php error: ' . $e->getMessage());
    json_error('Something went wrong while creating your booking. Please try again.', 500);
}
