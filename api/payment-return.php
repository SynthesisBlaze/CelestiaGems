<?php
/**
 * GET /api/payment-return.php?order_id=123&result=success|cancel
 * GET /api/payment-return.php?booking_id=45&result=success|cancel
 *
 * Where PayMongo sends the customer back after paying from the app —
 * for a shop order or a service booking. The web equivalents
 * (functions/order-payment-return.php and booking-payment-return.php)
 * both require $_SESSION['user_id'], and the browser the app opens has no
 * PHP session, so they would bounce the customer straight back out.
 *
 * This renders a small page instead of redirecting, because there is
 * nowhere on the website to send a mobile customer. The app reads the real
 * outcome from orders.php / bookings.php once this browser closes.
 *
 * On not having a session: nothing is taken on trust from the query
 * string. Status can only move to Paid if PayMongo itself says the stored
 * checkout session was paid, so hitting this URL with someone else's id
 * cannot fake a payment — it can only re-run the check that customer's own
 * return would have run. No customer details are shown.
 */
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/paymongo.php';
require_once __DIR__ . '/../functions/helpers.php';

$orderId   = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
$bookingId = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
$result    = $_GET['result'] ?? '';

$state = 'error';   // paid | pending | cancelled | error
$reference = '';

/**
 * Shared tail for both flows: PayMongo decides, the query string doesn't.
 */
function resolve_state(?string $sessionId, string $result): string
{
    $status = !empty($sessionId) ? paymongo_checkout_status($sessionId) : 'unknown';

    if ($status === 'paid') {
        return 'paid';
    }
    // Never cancel on an unconfirmed 'success' — they may well have been
    // charged and the payment is simply still settling.
    return $result === 'cancel' ? 'cancelled' : 'pending';
}

if ($bookingId > 0) {
    $stmt = $pdo->prepare("SELECT paymongo_checkout_id, payment_status FROM service_bookings WHERE booking_id = ?");
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $reference = 'Booking #' . $bookingId;

        if (($row['payment_status'] ?? '') === 'Paid') {
            $state = 'paid';   // already handled, don't ask PayMongo again
        } else {
            $state = resolve_state($row['paymongo_checkout_id'] ?? null, $result);

            if ($state === 'paid') {
                $pdo->prepare("UPDATE service_bookings SET payment_status = 'Paid', booking_status = 'Confirmed' WHERE booking_id = ?")
                    ->execute([$bookingId]);
            }
        }
    }
} elseif ($orderId > 0) {
    $stmt = $pdo->prepare("
        SELECT p.transaction_id AS checkout_session_id, p.payment_status
        FROM orders o
        JOIN payments p ON p.order_id = o.order_id
        WHERE o.order_id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $reference = format_order_number($orderId);

        if (($row['payment_status'] ?? '') === 'Paid') {
            $state = 'paid';
        } else {
            $state = resolve_state($row['checkout_session_id'] ?? null, $result);

            if ($state === 'paid') {
                $pdo->prepare("UPDATE payments SET payment_status = 'Paid', paid_at = NOW() WHERE order_id = :order_id")
                    ->execute(['order_id' => $orderId]);
                $pdo->prepare("UPDATE orders SET order_status = 'Processing' WHERE order_id = :order_id AND order_status = 'Pending'")
                    ->execute(['order_id' => $orderId]);
            }
        }
    }
}

$isBooking = $bookingId > 0;

$copy = [
    'paid' => [
        'title' => 'Payment received',
        'body'  => $isBooking
            ? 'Thank you. Your appointment is confirmed — we will see you then.'
            : 'Thank you. Your order is confirmed and now being prepared.',
        'tone'  => '#C9A15C',
    ],
    'pending' => [
        'title' => 'Payment is still being confirmed',
        'body'  => 'We have your request and are waiting on confirmation from PayMongo. If you were charged, it will be confirmed shortly — nothing else is needed from you.',
        'tone'  => '#E4C68A',
    ],
    'cancelled' => [
        'title' => 'Payment cancelled',
        'body'  => $isBooking
            ? 'Your appointment is being held, unpaid. You can pay at the store, or book again.'
            : 'Your order is being held, unpaid. You can pay for it from your orders, or place it again.',
        'tone'  => '#ACA8BE',
    ],
    'error' => [
        'title' => 'We could not find that record',
        'body'  => 'Please return to the app and check your orders or bookings.',
        'tone'  => '#ACA8BE',
    ],
][$state];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($copy['title']) ?> — Celestia Gems</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0A0A1A;
            --surface: #12122A;
            --gold: #C9A15C;
            --text: #F4EFE6;
            --muted: rgba(244, 239, 230, 0.7);
            --hairline: rgba(201, 161, 92, 0.22);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--bg);
            color: var(--text);
            font-family: 'Jost', sans-serif;
            font-weight: 300;
        }

        .card {
            width: 100%;
            max-width: 420px;
            background: var(--surface);
            border: 1px solid var(--hairline);
            border-radius: 20px;
            padding: 36px 28px;
            text-align: center;
        }

        .mark {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.3rem;
            letter-spacing: 0.06em;
            color: var(--gold);
            margin-bottom: 28px;
        }

        h1 {
            font-family: 'Cormorant Garamond', serif;
            font-weight: 600;
            font-size: 1.9rem;
            line-height: 1.2;
            margin: 0 0 12px;
            color: <?= $copy['tone'] ?>;
        }

        p {
            margin: 0;
            font-size: 0.95rem;
            line-height: 1.65;
            color: var(--muted);
        }

        .reference {
            display: inline-block;
            margin-top: 22px;
            padding: 8px 16px;
            border: 1px solid var(--hairline);
            border-radius: 999px;
            font-size: 0.8rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--gold);
        }

        .hint {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid var(--hairline);
            font-size: 0.85rem;
            color: rgba(244, 239, 230, 0.45);
        }
    </style>
</head>

<body>
    <div class="card">
        <div class="mark">✦ Celestia Gems ✦</div>
        <h1><?= htmlspecialchars($copy['title']) ?></h1>
        <p><?= htmlspecialchars($copy['body']) ?></p>

        <?php if ($reference !== ''): ?>
            <div class="reference"><?= htmlspecialchars($reference) ?></div>
        <?php endif; ?>

        <div class="hint">You can close this window and return to the app.</div>
    </div>
</body>

</html>
