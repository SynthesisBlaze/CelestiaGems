<?php
// booking-payment-return.php — where PayMongo redirects the customer back
// to after a Hosted Checkout attempt (both success_url and cancel_url point
// here, distinguished by the `result` param).
//
// IMPORTANT: the `result` query param is just a hint for which message to
// show — it is NEVER trusted on its own to mark something as paid. Payment
// is only ever confirmed by calling PayMongo's API and checking for a real
// paid payment against the checkout session ID we ourselves stored when the
// booking was created. This app has no webhook yet (see the chat reply for
// why), so this "verify on return" check is the substitute for one.

session_status() === PHP_SESSION_NONE && session_start();

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/paymongo.php';
require_once __DIR__ . '/audit_logger.php';
require_once __DIR__ . '/password_reset.php'; // for actor_type_for_role()
require_once __DIR__ . '/notifier.php';

$bookingId = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
$result = $_GET['result'] ?? '';

function backToBookingPage(array $flash): void
{
    $_SESSION['booking_flash'] = $flash;
    header('Location: ../pages/booking.php');
    exit;
}

if ($bookingId <= 0) {
    backToBookingPage(['type' => 'error', 'message' => 'Invalid booking reference.']);
}

$stmt = $pdo->prepare("
    SELECT sb.booking_id, sb.user_id, sb.booking_status, sb.payment_status,
           sb.paymongo_checkout_id, sb.estimated_price, sb.appointment_date, sb.appointment_time,
           s.name AS service_name, u.role_id
    FROM service_bookings sb
    JOIN services s ON s.service_id = sb.service_id
    JOIN users u ON u.user_id = sb.user_id
    WHERE sb.booking_id = ?
");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    backToBookingPage(['type' => 'error', 'message' => 'That booking could not be found.']);
}

// Already confirmed paid (e.g. the customer refreshed this page) — don't
// re-process, just show the same success state again.
if ($booking['payment_status'] === 'Paid') {
    backToBookingPage([
        'type'       => 'success',
        'booking_id' => $bookingId,
        'date'       => $booking['appointment_date'],
        'time'       => $booking['appointment_time'],
        'total'      => (float) $booking['estimated_price'],
        'payment'    => 'paymongo',
        'status'     => 'Confirmed',
        'services'   => [$booking['service_name']],
    ]);
}

$actuallyPaid = $booking['paymongo_checkout_id']
    ? paymongo_checkout_is_paid($booking['paymongo_checkout_id'])
    : false;

if ($result === 'success' && $actuallyPaid) {
    $pdo->prepare("UPDATE service_bookings SET payment_status = 'Paid', booking_status = 'Confirmed' WHERE booking_id = ?")
        ->execute([$bookingId]);

    log_audit(
        $pdo,
        (int) $booking['user_id'],
        actor_type_for_role((int) $booking['role_id']),
        'payment',
        'service_booking',
        $bookingId,
        ['payment_status' => 'Unpaid', 'booking_status' => $booking['booking_status']],
        ['payment_status' => 'Paid', 'booking_status' => 'Confirmed']
    );

    notify_user(
        $pdo,
        (int) $booking['user_id'],
        'booking_status',
        'Payment Received',
        'Your payment for ' . $booking['service_name'] . ' on ' .
            date('M j, Y', strtotime($booking['appointment_date'])) . ' has been received — your appointment is confirmed.',
        'service_booking',
        $bookingId
    );

    backToBookingPage([
        'type'       => 'success',
        'booking_id' => $bookingId,
        'date'       => $booking['appointment_date'],
        'time'       => $booking['appointment_time'],
        'total'      => (float) $booking['estimated_price'],
        'payment'    => 'paymongo',
        'status'     => 'Confirmed',
        'services'   => [$booking['service_name']],
    ]);
}

// Either the customer cancelled/abandoned checkout, or `result=success` came
// back but PayMongo doesn't actually show a paid payment yet — either way,
// don't touch the booking. It stays Pending/Unpaid so staff can see it in
// Bookings Management and the customer can pay in person or try again.
backToBookingPage([
    'type'       => 'success',
    'booking_id' => $bookingId,
    'date'       => $booking['appointment_date'],
    'time'       => $booking['appointment_time'],
    'total'      => (float) $booking['estimated_price'],
    'payment'    => 'cash',
    'status'     => 'Pending confirmation — online payment was not completed, please pay at your appointment',
    'services'   => [$booking['service_name']],
]);
