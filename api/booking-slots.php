<?php
/**
 * GET /api/booking-slots.php?date=YYYY-MM-DD
 *
 * Every appointment slot for that day with whether it can still be taken —
 * the mobile equivalent of functions/get_booked_slots.php, but returning
 * the whole grid rather than only the blocked times, so the app can draw
 * the picker without also knowing the store's opening hours.
 *
 * Public on purpose: the grid is the same for everyone, and letting
 * someone see whether a time is free before signing in avoids making an
 * account only to find the slot gone.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../functions/booking_helpers.php';

$date = $_GET['date'] ?? '';

$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date) {
    json_error('A valid date (YYYY-MM-DD) is required.');
}

// Anything not cancelled holds the slot — this mirrors the
// uq_appointment_slot UNIQUE(appointment_date, appointment_time) constraint.
$stmt = $pdo->prepare("
    SELECT appointment_time
    FROM service_bookings
    WHERE appointment_date = :date AND booking_status != 'Cancelled'
");
$stmt->execute([':date' => $date]);
$taken = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'appointment_time');

// Postgres may hand back TIME values as 'HH:MM:SS' or 'HH:MM'; normalise so
// the comparison against generateTimeSlots() can't silently miss.
$taken = array_map(fn($t) => substr((string) $t, 0, 8), $taken);

$isToday = ($date === date('Y-m-d'));
$now = date('H:i:s');

$slots = array_map(function ($slot) use ($taken, $isToday, $now) {
    $hasPassed = $isToday && $slot['value'] <= $now;
    return [
        'value' => $slot['value'],
        'label' => $slot['label'],
        'available' => !$hasPassed && !in_array($slot['value'], $taken, true),
        'reason' => $hasPassed ? 'passed' : (in_array($slot['value'], $taken, true) ? 'booked' : null),
    ];
}, generateTimeSlots());

$availableCount = 0;
foreach ($slots as $s) {
    if ($s['available']) {
        $availableCount++;
    }
}

json_out([
    'success' => true,
    'date' => $date,
    'slots' => $slots,
    'available_count' => $availableCount,
]);
