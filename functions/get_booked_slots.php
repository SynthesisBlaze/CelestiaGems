<?php
// get_booked_slots.php — AJAX endpoint: returns already-booked time slots for a given date.
// Called from booking.php whenever the customer changes the date field.

header('Content-Type: application/json');

require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/booking_helpers.php';

$date = $_GET['date'] ?? '';

// Must be a real YYYY-MM-DD date.
$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date']);
    exit;
}

try {
    // Any row for this date that isn't cancelled blocks that slot —
    // this matches the uq_appointment_slot UNIQUE(appointment_date, appointment_time) constraint.
    $stmt = $pdo->prepare("
        SELECT appointment_time
        FROM service_bookings
        WHERE appointment_date = :date
          AND booking_status != 'Cancelled'
    ");
    $stmt->execute([':date' => $date]);
    $booked = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'appointment_time');

    // For today, slots that have already passed are unavailable too.
    if ($date === date('Y-m-d')) {
        $now = date('H:i:s');
        foreach (generateTimeSlots() as $slot) {
            if ($slot['value'] <= $now) {
                $booked[] = $slot['value'];
            }
        }
        $booked = array_values(array_unique($booked));
    }

    echo json_encode(['booked' => $booked]);
} catch (Exception $e) {
    error_log('get_booked_slots error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
