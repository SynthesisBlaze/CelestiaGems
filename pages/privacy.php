<?php
session_start();
require_once __DIR__ . '/../functions/db_connection.php';
$current_page = 'privacy';
$lastUpdated = 'October 4, 2026';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy — Celestia Gems</title>
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
                <h1>Privacy Policy</h1>
                <p>Last updated <?= htmlspecialchars($lastUpdated) ?></p>
            </div>

            <div class="legal-layout">
                <nav class="legal-toc" aria-label="On this page">
                    <p>On this page</p>
                    <ol>
                        <li><a href="#collect">What we collect</a></li>
                        <li><a href="#use">How we use it</a></li>
                        <li><a href="#share">Who we share it with</a></li>
                        <li><a href="#keep">How long we keep it</a></li>
                        <li><a href="#rights">Your rights</a></li>
                        <li><a href="#security">Security</a></li>
                        <li><a href="#cookies">Cookies</a></li>
                        <li><a href="#changes">Changes</a></li>
                        <li><a href="#contact">Contact us</a></li>
                    </ol>
                </nav>

                <article class="legal-body">
                    <p class="mb-4">
                        This policy explains what personal information Celestia Gems collects when you use our website,
                        why we collect it, and the choices you have. We handle personal information in line with the
                        Philippine Data Privacy Act of 2012 (Republic Act No. 10173).
                    </p>

                    <section id="collect">
                        <h2>What we collect</h2>
                        <ul>
                            <li><strong>Account details:</strong> your name, email address, mobile number, date of birth and password (stored only as a secure hash — never in readable form).</li>
                            <li><strong>Delivery details:</strong> your shipping address and the map location you pin for it.</li>
                            <li><strong>Orders and bookings:</strong> the items you buy, appointment dates and times, the services you book, and any notes you add.</li>
                            <li><strong>Payments:</strong> your chosen payment method and payment status. Card, GCash, Maya and GrabPay details are entered on PayMongo's secure checkout page — we never see or store them.</li>
                            <li><strong>Messages and reviews:</strong> what you send through our Contact form, product reviews you post, and our replies.</li>
                            <li><strong>Shopping assistant chats:</strong> messages you type into the chat assistant on our site.</li>
                            <li><strong>Sign-in records:</strong> the time of each sign-in attempt and your browser type, used to protect your account.</li>
                        </ul>
                    </section>

                    <section id="use">
                        <h2>How we use it</h2>
                        <ul>
                            <li>To create and secure your account, including email verification and sign-in codes.</li>
                            <li>To process, deliver and support your orders, returns and appointments.</li>
                            <li>To reply to your messages and reviews, and to send you updates about your orders and bookings.</li>
                            <li>To keep the store running safely, prevent fraud and fix problems.</li>
                        </ul>
                        <p>We don't sell your personal information, and we don't send marketing emails without your consent.</p>
                    </section>

                    <section id="share">
                        <h2>Who we share it with</h2>
                        <p>We share only what each service needs to do its job for us:</p>
                        <ul>
                            <li><strong>PayMongo</strong> — to process online payments.</li>
                            <li><strong>Brevo and Mailjet</strong> — to send account, order and reply emails.</li>
                            <li><strong>Google Maps</strong> — to help you find and pin your delivery address.</li>
                            <li><strong>Google Gemini</strong> — to answer messages sent to the shopping assistant. Please don't share personal details in the chat.</li>
                            <li><strong>Supabase</strong> — our secure database host.</li>
                            <li><strong>Couriers</strong> — your name, address and phone number, to deliver your order.</li>
                        </ul>
                        <p>We may also disclose information when the law requires it.</p>
                    </section>

                    <section id="keep">
                        <h2>How long we keep it</h2>
                        <p>
                            We keep your account information while your account is open. Order, payment and booking
                            records are kept as long as needed for warranties, returns, accounting and legal requirements.
                            When information is no longer needed, we delete or anonymize it.
                        </p>
                    </section>

                    <section id="rights">
                        <h2>Your rights</h2>
                        <p>Under the Data Privacy Act you can:</p>
                        <ul>
                            <li>Ask what personal information we hold about you and get a copy of it.</li>
                            <li>Correct inaccurate information — most details can be updated anytime in <a href="/pages/account.php">My Account</a>.</li>
                            <li>Ask us to delete your account and information we no longer need to keep.</li>
                            <li>Object to how we use your information, or withdraw consent you've given.</li>
                            <li>File a complaint with the National Privacy Commission.</li>
                        </ul>
                        <p>To make a request, contact us using the details below.</p>
                    </section>

                    <section id="security">
                        <h2>Security</h2>
                        <p>
                            Passwords are stored hashed, sign-in can require a one-time code sent to your email, and
                            payment details stay with PayMongo. No system is perfectly secure, so please keep your
                            password private and let us know right away if you notice anything unusual on your account.
                        </p>
                    </section>

                    <section id="cookies">
                        <h2>Cookies</h2>
                        <p>
                            We use a session cookie to keep you signed in and to remember your cart, and your browser's
                            local storage to remember your light or dark theme choice. We don't use advertising cookies.
                        </p>
                    </section>

                    <section id="changes">
                        <h2>Changes to this policy</h2>
                        <p>
                            If we change this policy, we'll update the date at the top of this page. For significant
                            changes, we'll also let account holders know by email or on their account page.
                        </p>
                    </section>

                    <section id="contact">
                        <h2>Contact us</h2>
                        <p>Questions or privacy requests can be sent to:</p>
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
