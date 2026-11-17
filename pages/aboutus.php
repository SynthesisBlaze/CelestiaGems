<?php
// Make sure to include the database connection
require_once __DIR__ . '/../functions/db_connection.php'; // Adjust this path based on your folder structure

// Fetch About Data
$stmtAbout = $pdo->prepare("SELECT * FROM about_page LIMIT 1");
$stmtAbout->execute();
$about = $stmtAbout->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us - Celestia Gems</title>

    <!-- Bootstrap -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

    <!-- Your CSS -->
    <link rel="stylesheet" href="/styles/aboutus.css?v=<?= (int) @filemtime(__DIR__ . '/../styles/aboutus.css') ?>">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>

<body>

    <?php $current_page = 'about'; ?>
    <?php include __DIR__ . '/../components/navbar.php'; ?>

    <main class="about-page">

        <section class="about-hero">
            <div class="container">
                <div class="about-hero-inner">
                    <div class="about-hero-copy">
                        <span class="eyebrow">Our story</span>
                        <h1><?= htmlspecialchars($about['about_heading'] ?? 'About Us') ?></h1>
                        <p class="about-intro">
                            Celestia Gems brings together modern craftsmanship, meaningful design, and the quiet brilliance of fine jewelry for life’s most memorable moments.
                        </p>
                    </div>

                    <div class="about-hero-panel">
                        <div class="hero-stat">
                            <span class="stat-label">Crafted with</span>
                            <strong>Intention</strong>
                        </div>
                        <div class="hero-stat">
                            <span class="stat-label">Designed for</span>
                            <strong>Forever</strong>
                        </div>
                        <div class="hero-stat">
                            <span class="stat-label">Made to feel</span>
                            <strong>Celestial</strong>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ABOUT US SECTION -->
        <section class="about-section">
            <div class="container">
                <div class="about-box">
                    <span class="eyebrow">Who we are</span>
                    <!-- nl2br is used so paragraph line breaks added in the admin dashboard display correctly -->
                    <p><?= nl2br(htmlspecialchars($about['about_body'] ?? '')) ?></p>
                </div>
            </div>
        </section>

        <!-- VISION & MISSION -->
        <section class="vision-mission-section">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow">Our values</span>
                    <h2>What guides every detail</h2>
                </div>
                <div class="vision-mission-box">
                    <div class="vm-card">
                        <div class="card-icon">✦</div>
                        <h2><?= htmlspecialchars($about['vision_heading'] ?? 'Our Vision') ?></h2>
                        <p><?= nl2br(htmlspecialchars($about['vision_text'] ?? '')) ?></p>
                    </div>

                    <div class="vm-card">
                        <div class="card-icon">✧</div>
                        <h2><?= htmlspecialchars($about['mission_heading'] ?? 'Our Mission') ?></h2>
                        <p><?= nl2br(htmlspecialchars($about['mission_text'] ?? '')) ?></p>
                    </div>
                </div>
            </div>
        </section>

        <!-- TEAM MEMBERS -->
        <section class="mem-section">
            <div class="container">
                <div class="section-heading centered">
                    <span class="eyebrow">The team</span>
                    <h2>People behind the sparkle</h2>
                </div>
                <div class="team-members-box">

                    <!-- Ron -->
                    <div class="team-card">
                        <span class="team-role">Team Leader</span>
                        <div class="team-photo-wrap">
                            <img src="/img/ron.png" alt="Ron Dannie Argao" class="team-member-img">
                        </div>
                        <div class="team-info">
                            <h2>Ron Dannie Argao</h2>
                        </div>
                    </div>

                    <!-- Ruel -->
                    <div class="team-card">
                        <span class="team-role">Member 1</span>
                        <div class="team-photo-wrap">
                            <img src="/img/ruel.png" alt="Ruel" class="team-member-img">
                        </div>
                        <div class="team-info">
                            <h2>Ruel Ira B Alvarez</h2>
                        </div>
                    </div>

                    <!-- Sta Ana -->
                    <div class="team-card">
                        <span class="team-role">Member 2</span>
                        <div class="team-photo-wrap">
                            <img src="/img/marte.JPG" alt="Sta Ana" class="team-member-img">
                        </div>
                        <div class="team-info">
                            <h2>Marte Alejandro Santa. Ana</h2>
                        </div>
                    </div>

                    <!-- Ventura -->
                    <div class="team-card">
                        <span class="team-role">Member 3</span>
                        <div class="team-photo-wrap">
                            <img src="/img/kev.png" alt="Kevin Luis F. Ventura" class="team-member-img">
                        </div>
                        <div class="team-info">
                            <h2>Kevin Luis F. Ventura</h2>
                        </div>
                    </div>

                </div>
            </div>
        </section>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/../components/footer.php'; ?>

</body>

</html>