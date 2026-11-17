<?php
// Include your database connection
require_once __DIR__ . '/../functions/db_connection.php'; // Adjust path as necessary

$current_page = 'faq';
$faqs = [];

// Fetch published FAQs from the database, ordered by sort_order
$stmt = $pdo->prepare("SELECT question, answer FROM faqs WHERE is_published = 1 ORDER BY sort_order ASC");
$stmt->execute();
$faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<!-- ... Head content remains exactly the same ... -->
<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAQ — Celestia Gems</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/faq.css">
</head>

<body>

    <?php include __DIR__ . '/../components/navbar.php'; ?>

    <main class="py-5">
        <div class="container">

            <div class="faq-hero mb-5">
                <p class="eyebrow">Support</p>
                <h1>Frequently Asked Questions</h1>
                <p>Answers to common questions about orders, shipping, custom pieces, and care.</p>
            </div>

            <?php if (empty($faqs)): ?>

                <div class="faq-empty">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                        <path d="M9.1 9a3 3 0 015.8 1c0 2-3 2-3 4" />
                        <path d="M12 17h.01" />
                        <circle cx="12" cy="12" r="9" />
                    </svg>
                    <div class="title">FAQs coming soon</div>
                    <div class="hint">We're putting together answers to the questions we hear most. In the meantime, reach out and we'll help directly.</div>
                </div>

            <?php else: ?>

                <div class="faq-accordion">
                    <?php foreach ($faqs as $i => $faq): ?>
                        <div class="faq-item">
                            <button class="faq-question w-100 text-start border-0 bg-transparent py-3 fw-bold">
                                <?= htmlspecialchars($faq['question']) ?>
                            </button>
                            <div class="faq-answer" style="display: none;">
                                <p class="text-muted"><?= nl2br(htmlspecialchars($faq['answer'])) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>

            <div class="faq-cta mt-5">
                <p>Still have questions? Our team is happy to help.</p>
                <a href="/pages/contactus.php" class="btn btn-gold px-4 py-3">Contact Us</a>
            </div>

        </div>
    </main>

    <?php include __DIR__ . '/../components/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Modified your script slightly to ensure the answer toggles visibility visually
        document.querySelectorAll('.faq-question').forEach(btn => {
            btn.addEventListener('click', () => {
                const item = btn.closest('.faq-item');
                item.classList.toggle('open');
                const answer = item.querySelector('.faq-answer');
                answer.style.display = item.classList.contains('open') ? 'block' : 'none';
            });
        });
    </script>
</body>
</html>