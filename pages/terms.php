<?php
session_start();
require_once __DIR__ . '/../functions/db_connection.php';
$current_page = 'terms';
$lastUpdated = 'October 4, 2026';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of Service — Celestia Gems</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/legal.css">
</head>

<body>
    <?php include __DIR__ . '/../components/navbar.php'; ?>

    <main class="py-5">
        <div class="container">
            <div class="legal-hero">
                <p class="eyebrow">Legal</p>
                <h1>Terms of Service</h1>
                <p>Last updated <?= htmlspecialchars($lastUpdated) ?></p>
            </div>

            <div class="legal-layout">
                <nav class="legal-toc" aria-label="On this page">
                    <p>On this page</p>
                    <ol>
                        <li><a href="#account">Your account</a></li>
                        <li><a href="#orders">Orders and pricing</a></li>
                        <li><a href="#payment">Payment</a></li>
                        <li><a href="#shipping">Shipping</a></li>
                        <li><a href="#cancel">Cancellations</a></li>
                        <li><a href="#returns">Returns and refunds</a></li>
                        <li><a href="#bookings">Service appointments</a></li>
                        <li><a href="#reviews">Reviews and messages</a></li>
                        <li><a href="#liability">Liability</a></li>
                        <li><a href="#contact">Contact us</a></li>
                    </ol>
                </nav>

                <article class="legal-body">
                    <p class="mb-4">
                        These terms apply when you browse, create an account, shop or book a service on the Celestia Gems
                        website. By using the site, you agree to them. Please also read our
                        <a href="/pages/privacy.php">Privacy Policy</a>.
                    </p>

                    <section id="account">
                        <h2>Your account</h2>
                        <ul>
                            <li>You must be at least 16 years old to create an account.</li>
                            <li>Give accurate details and keep them up to date, especially your email, phone and delivery address.</li>
                            <li>Keep your password private. You're responsible for activity on your account.</li>
                            <li>We may suspend accounts used for fraud or abuse.</li>
                        </ul>
                    </section>

                    <section id="orders">
                        <h2>Orders and pricing</h2>
                        <p>
                            Prices are in Philippine pesos (₱). Placing an order is an offer to buy; it's accepted when we
                            confirm it. If an item becomes unavailable or a price was shown in error, we'll contact you and
                            you can choose to cancel for a full refund of anything you've paid.
                        </p>
                        <p>Product photos are taken with care, but natural gemstones vary slightly in color and inclusions from piece to piece.</p>
                    </section>

                    <section id="payment">
                        <h2>Payment</h2>
                        <ul>
                            <li><strong>Cash on Delivery:</strong> pay the courier when your order arrives.</li>
                            <li><strong>Online payment:</strong> GCash, card, Maya and GrabPay through PayMongo's secure checkout. An online order is confirmed once PayMongo confirms the payment.</li>
                        </ul>
                        <p>If an online payment isn't completed, the order is cancelled and the items are released back into stock.</p>
                    </section>

                    <section id="shipping">
                        <h2>Shipping</h2>
                        <p>
                            Shipping fees, if any, are shown at checkout before you pay. Delivery dates shown on the site are
                            estimates. Please check your package when it arrives and tell us within 48 hours if anything is
                            missing or damaged.
                        </p>
                    </section>

                    <section id="cancel">
                        <h2>Cancellations</h2>
                        <p>
                            You can request a cancellation from <a href="/pages/account.php">My Account</a> while an order is
                            Pending or Processing. Once an order has shipped, it can't be cancelled, but you may be able to
                            return it.
                        </p>
                    </section>

                    <section id="returns">
                        <h2>Returns and refunds</h2>
                        <ul>
                            <li>Request a return from My Account within 30 days of delivery.</li>
                            <li>Items must be unworn, in their original condition and packaging.</li>
                            <li>Custom, engraved and resized pieces can't be returned unless they arrived faulty.</li>
                            <li>Approved refunds go back to your original payment method; Cash on Delivery orders are refunded by bank transfer or e-wallet.</li>
                        </ul>
                    </section>

                    <section id="bookings">
                        <h2>Service appointments</h2>
                        <p>
                            Appointments for custom work, repairs, cleaning and appraisals can be paid online or at the
                            appointment. Prices shown are estimates; we'll confirm the final price before starting any work.
                            You can cancel an upcoming appointment from My Account before its start time.
                        </p>
                    </section>

                    <section id="reviews">
                        <h2>Reviews and messages</h2>
                        <p>
                            Reviews must be honest and about a product you bought. We may reply publicly to reviews and may
                            remove reviews that are abusive, off-topic or contain personal information.
                        </p>
                    </section>

                    <section id="liability">
                        <h2>Liability</h2>
                        <p>
                            We aim to keep the site accurate and available, but can't promise it will always be error-free.
                            Nothing in these terms limits your rights as a consumer under Philippine law, including the
                            Consumer Act of the Philippines (Republic Act No. 7394). These terms are governed by the laws
                            of the Philippines.
                        </p>
                    </section>

                    <section id="contact">
                        <h2>Contact us</h2>
                        <p>Questions about these terms can be sent to:</p>
                        <?php include __DIR__ . '/../components/legal_contact.php'; ?>
                    </section>
                </article>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../components/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
