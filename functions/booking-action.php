<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/audit_logger.php';
require_once __DIR__ . '/notifier.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

require_once __DIR__ . '/permissions.php';
cg_require_json('bookings.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$BOOKING_STATUSES = ['Pending', 'Confirmed', 'Completed', 'Cancelled'];

$action    = $_POST['action'] ?? '';
$bookingId = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;
$actor_id  = (int) $_SESSION['user_id'];

if ($bookingId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking.']);
    exit;
}

try {
    if ($action === 'update_status') {
        $status = $_POST['status'] ?? '';
        if (!in_array($status, $BOOKING_STATUSES, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status.']);
            exit;
        }

        $bookingStmt = $pdo->prepare("
            SELECT b.booking_status, b.user_id, b.appointment_date, b.appointment_time, s.name AS service_name
            FROM service_bookings b
            JOIN services s ON s.service_id = b.service_id
            WHERE b.booking_id = ?
        ");
        $bookingStmt->execute([$bookingId]);
        $bookingRow = $bookingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$bookingRow) {
            echo json_encode(['success' => false, 'message' => 'Booking not found.']);
            exit;
        }
        $oldStatus = $bookingRow['booking_status'];

        $stmt = $pdo->prepare("UPDATE service_bookings SET booking_status = ? WHERE booking_id = ?");
        $stmt->execute([$status, $bookingId]);

        log_audit(
            $pdo,
            $actor_id,
            admin_actor_type((int) $_SESSION['role_id']),
            'update',
            'service_booking',
            $bookingId,
            ['booking_status' => $oldStatus],
            ['booking_status' => $status]
        );

        $apptDateTime = DateTime::createFromFormat(
            'Y-m-d H:i:s',
            $bookingRow['appointment_date'] . ' ' . $bookingRow['appointment_time']
        );
        $whenLabel = $apptDateTime ? $apptDateTime->format('M j, Y \a\t g:i A') : $bookingRow['appointment_date'];

        notify_user(
            $pdo,
            (int) $bookingRow['user_id'],
            'booking_status',
            'Booking Update',
            'Your ' . $bookingRow['service_name'] . ' appointment on ' . $whenLabel . ' is now ' . $status . '.',
            'service_booking',
            $bookingId
        );

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
