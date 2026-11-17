<?php
// Place this file in your /functions/ folder, alongside account-update.php etc.,
// so the "../functions/cancel_booking.php" path called from account.php resolves.

session_status() === PHP_SESSION_NONE && session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in to manage your bookings.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$bookingId = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;
$userId    = (int) $_SESSION['user_id'];

if ($bookingId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing booking reference.']);
    exit;
}

try {
    // Confirm the booking belongs to this user before touching anything.
    $stmt = $pdo->prepare("
        SELECT booking_status, appointment_date, appointment_time
        FROM service_bookings
        WHERE booking_id = ? AND user_id = ?
    ");
    $stmt->execute([$bookingId, $userId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit;
    }

    if (in_array($booking['booking_status'], ['Completed', 'Cancelled'], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This booking can no longer be cancelled.']);
        exit;
    }

    $apptDateTime = DateTime::createFromFormat(
        'Y-m-d H:i:s',
        $booking['appointment_date'] . ' ' . $booking['appointment_time']
    );
    if ($apptDateTime && $apptDateTime < new DateTime()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This appointment has already passed and can no longer be cancelled.']);
        exit;
    }

    $update = $pdo->prepare("
        UPDATE service_bookings
        SET booking_status = 'Cancelled'
        WHERE booking_id = ? AND user_id = ?
    ");
    $update->execute([$bookingId, $userId]);

    echo json_encode(['success' => true, 'message' => 'Your booking has been cancelled.']);
} catch (Exception $e) {
    error_log('cancel_booking.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
