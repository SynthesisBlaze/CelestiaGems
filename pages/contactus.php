<?php
session_start();
require_once __DIR__ . '/../functions/db_connection.php'; 

$current_page = 'contact';
$cart_count   = 1;

$auto_name = '';
$auto_email = '';
$auto_phone = '';

if (isset($_SESSION['user_id'])) {
    $stmtUser = $pdo->prepare("SELECT first_name, last_name, email, phone FROM users WHERE user_id = ? LIMIT 1");
    $stmtUser->execute([$_SESSION['user_id']]);
    $loggedInUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if ($loggedInUser) {
        $auto_name = trim($loggedInUser['first_name'] . ' ' . $loggedInUser['last_name']);
        $auto_email = $loggedInUser['email'];
        $auto_phone = $loggedInUser['phone'] ?? '';
    }
}

$stmt = $pdo->prepare("SELECT * FROM contact_page LIMIT 1");
$stmt->execute();
$contactData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contactData) {
    $contactData = [
        'heading' => 'Contact Our Celestial Artisans',
        'subtext' => 'Whether you are inquiring about our cosmic jewelry, interested in co-creating a custom astronomical masterpiece, or seeking support for an order, we are here to assist you.',
        'store_name' => 'Celestia Gems',
        'store_address' => "123 Sesame Street, Sample City\nManila, Philippines",
        'phone' => '+63 912-345-7891',
        'email_general' => 'hello@celestiagems.com',
        'email_custom' => 'design@celestiagems.com',
        'hours_weekday' => '10:00 AM – 8:00 PM',
        'hours_sunday' => '11:00 AM – 6:00 PM'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us — Celestia Gems</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/contactus.css">
</head>
<body>

    <?php include __DIR__ . '/../components/navbar.php'; ?>

    <main class="py-5">
        <div class="container">

            <div class="contact-hero mb-5">
                <p class="eyebrow">Get in Touch</p>
                <h1><?= htmlspecialchars($contactData['heading']) ?></h1>
                <p><?= nl2br(htmlspecialchars($contactData['subtext'])) ?></p>
            </div>

            <div class="row g-5">
                <div class="col-lg-7">
                    <div class="contact-card">
                        <h2>Send Us a Message</h2>

                        <?php if (isset($_SESSION['success_msg'])): ?>
                            <div class="alert text-center py-2 mb-4 cg-notice cg-notice--success">
                                <?= htmlspecialchars($_SESSION['success_msg']) ?>
                                <?php unset($_SESSION['success_msg']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($_SESSION['error_msg'])): ?>
                            <div class="alert text-center py-2 mb-4 cg-notice cg-notice--error">
                                <?= htmlspecialchars($_SESSION['error_msg']) ?>
                                <?php unset($_SESSION['error_msg']); ?>
                            </div>
                        <?php endif; ?>

                        <form action="../functions/contact-submit.php" method="post">
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Full Name</label>
                                <input type="text" id="full_name" class="form-control" name="full_name" 
                                       value="<?= htmlspecialchars($auto_name) ?>" placeholder="Enter your full name" required>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="email" class="form-label">Email Address</label>
                                    <input type="email" id="email" class="form-control" name="email" 
                                           value="<?= htmlspecialchars($auto_email) ?>" placeholder="name@example.com" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="tel" id="phone" class="form-control" name="phone" 
                                           value="<?= htmlspecialchars($auto_phone) ?>" placeholder="+63 (900) 000-0000">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="subject" class="form-label">Inquiry Subject</label>
                                <select id="subject" class="form-select" name="subject" required>
                                    <option value="" selected disabled>Select an inquiry type...</option>
                                    <option value="custom_design">Custom Design</option>
                                    <option value="repairs">Repairs</option>
                                    <option value="order_support">Order Support</option>
                                    <option value="wholesale">Wholesale / Partnerships</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>

                            <div class="mb-4">
                                <label for="message" class="form-label">Your Message</label>
                                <textarea id="message" class="form-control" name="message" rows="4" placeholder="Describe your request in detail..." required></textarea>
                            </div>

                            <button type="submit" class="btn btn-gold w-100 py-3">Send Message</button>
                        </form>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="col-lg-5 contact-info">
                    <h2>Locate Our Sanctuary</h2>
                    <div class="info-item">
                        <div class="info-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 21s7-6.5 7-12a7 7 0 10-14 0c0 5.5 7 12 7 12z" /><circle cx="12" cy="9" r="2.4" /></svg>
                        </div>
                        <div>
                            <h4><?= htmlspecialchars($contactData['store_name']) ?></h4>
                            <p><?= nl2br(htmlspecialchars($contactData['store_address'])) ?></p>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1.9.3 1.8.6 2.6a2 2 0 01-.4 2.1L8 9.8a16 16 0 006 6l1.4-1.3a2 2 0 012.1-.4c.8.3 1.7.5 2.6.6a2 2 0 011.9 2.2z" /></svg>
                        </div>
                        <div>
                            <h4>Call Us Directly</h4>
                            <p>Phone Number: <?= htmlspecialchars($contactData['phone']) ?></p>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 7l9 6 9-6" /></svg>
                        </div>
                        <div>
                            <h4>Email Address</h4>
                            <p>
                                General Inquiries: <?= htmlspecialchars($contactData['email_general']) ?><br>
                                Custom Commissions: <?= htmlspecialchars($contactData['email_custom']) ?>
                            </p>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3.5 2" /></svg>
                        </div>
                        <div>
                            <h4>Business Hours</h4>
                            <p>
                                Monday – Saturday: <?= htmlspecialchars($contactData['hours_weekday']) ?><br>
                                Sunday: <?= htmlspecialchars($contactData['hours_sunday']) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../components/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>