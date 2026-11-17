<?php
/**
 * GET /api/bookings.php   (Authorization: Bearer <token>)
 *
 * The signed-in customer's service bookings — what the web shows under
 * Account -> Bookings, plus the "upcoming" count the Overview tiles use.
 *
 * service_bookings stores one service_id per row; any extra services
 * chosen for the same visit were folded into custom_requirements and the
 * summed estimated_price when the booking was made, so this returns the
 * row as it stands rather than trying to unpick it.
 */
require_once __DIR__ . '/_bootstrap.php';

$user = require_auth($pdo);

$stmt = $pdo->prepare("
    SELECT b.booking_id, b.service_id, s.name AS service_name,
           b.appointment_date, b.appointment_time, b.custom_requirements,
           b.estimated_price, b.booking_status, b.payment_method, b.payment_status
    FROM service_bookings b
    LEFT JOIN services s ON s.service_id = b.service_id
    WHERE b.user_id = ?
    ORDER BY b.appointment_date DESC, b.appointment_time DESC
");
$stmt->execute([$user['user_id']]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');

$bookings = array_map(function ($r) use ($today) {
    $status = $r['booking_status'] ?? 'Pending';
    return [
        'booking_id'       => (int) $r['booking_id'],
        'service_id'       => (int) $r['service_id'],
        'service_name'     => $r['service_name'] ?? 'Service',
        'appointment_date' => $r['appointment_date'],
        'appointment_time' => $r['appointment_time'],
        'notes'            => $r['custom_requirements'],
        'estimated_price'  => (float) $r['estimated_price'],
        'booking_status'   => $status,
        'payment_method'   => $r['payment_method'],
        'payment_status'   => $r['payment_status'],
        'is_upcoming'      => $r['appointment_date'] >= $today && $status !== 'Cancelled',
    ];
}, $rows);

$upcoming = 0;
foreach ($bookings as $b) {
    if ($b['is_upcoming']) {
        $upcoming++;
    }
}

json_out([
    'success' => true,
    'bookings' => $bookings,
    'upcoming_count' => $upcoming,
    'total_count' => count($bookings),
]);
