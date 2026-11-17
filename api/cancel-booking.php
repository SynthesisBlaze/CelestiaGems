<?php
/**
 * POST /api/cancel-booking.php   (Authorization: Bearer <token>)
 * Body: { booking_id: 12 }
 *
 * functions/cancel_booking.php with token auth instead of $_SESSION.
 * Same three guards, in the same order: the booking must belong to the
 * caller, it must not already be Completed or Cancelled, and the
 * appointment must still be in the future.
 *
 * Cancelling frees the slot: booking-slots.php only counts rows whose
 * status is not 'Cancelled', which is also what the uq_appointment_slot
 * constraint leaves room for.
 */
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

$user = require_auth($pdo);
$userId = (int) $user['user_id'];
$in = read_input();

$bookingId = (int) ($in['booking_id'] ?? 0);
if ($bookingId <= 0) {
    json_error('Missing booking reference.');
}

try {
    $stmt = $pdo->prepare("
        SELECT booking_status, appointment_date, appointment_time
        FROM service_bookings
        WHERE booking_id = ? AND user_id = ?
    ");
    $stmt->execute([$bookingId, $userId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        json_error('Booking not found.', 404);
    }

    if (in_array($booking['booking_status'], ['Completed', 'Cancelled'], true)) {
        json_error('This booking can no longer be cancelled.', 409);
    }

    $appt = DateTime::createFromFormat(
        'Y-m-d H:i:s',
        $booking['appointment_date'] . ' ' . substr((string) $booking['appointment_time'], 0, 8)
    );
    if ($appt && $appt < new DateTime()) {
        json_error('This appointment has already passed and can no longer be cancelled.', 409);
    }

    $pdo->prepare("
        UPDATE service_bookings
        SET booking_status = 'Cancelled'
        WHERE booking_id = ? AND user_id = ?
    ")->execute([$bookingId, $userId]);

    json_out([
        'success' => true,
        'booking_id' => $bookingId,
        'booking_status' => 'Cancelled',
        'message' => 'Your booking has been cancelled.',
    ]);
} catch (PDOException $e) {
    error_log('cancel-booking.php error: ' . $e->getMessage());
    json_error('We could not cancel that booking. Please try again.', 500);
}
