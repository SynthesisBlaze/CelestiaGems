<?php

/**
 * Shared helpers for the jewelry service booking flow.
 * Drop this file into the same "functions" folder as db_connection.php.
 * Used by booking.php, get_booked_slots.php, and process_booking.php.
 */

// Business hours for in-store appointments. Adjust to match real store hours.
define('BOOKING_OPEN_TIME', '09:00');
define('BOOKING_CLOSE_TIME', '18:00');
define('BOOKING_SLOT_MINUTES', 30);

/**
 * Returns the full list of appointment slots offered per day.
 * Each entry: ['value' => '09:00:00', 'label' => '9:00 AM']
 */
function generateTimeSlots(): array
{
    $slots = [];
    $start = strtotime(BOOKING_OPEN_TIME);
    $end   = strtotime(BOOKING_CLOSE_TIME);
    $step  = BOOKING_SLOT_MINUTES * 60;

    for ($t = $start; $t < $end; $t += $step) {
        $slots[] = [
            'value' => date('H:i:s', $t),
            'label' => date('g:i A', $t),
        ];
    }
    return $slots;
}

/** Confirms a submitted time string is one of the slots we actually offer. */
function isValidTimeSlot(string $time): bool
{
    foreach (generateTimeSlots() as $slot) {
        if ($slot['value'] === $time) {
            return true;
        }
    }
    return false;
}

/** Formats a float as Philippine peso currency, matching the front-end fmt() helper in service.php. */
function pesoFormat(float $amount): string
{
    $decimals = (fmod($amount, 1.0) === 0.0) ? 0 : 2;
    return '₱' . number_format($amount, $decimals);
}
