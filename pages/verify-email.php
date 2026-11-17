<?php
session_start();
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/email_verify.php';

$token = $_GET['token'] ?? '';
$verified = false;

if ($token !== '') {
    $userId = verify_token($pdo, $token);
    $verified = $userId !== null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email — Celestia Gems</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/register.css">
</head>
<body>
    <?php include __DIR__ . '/../components/navbar.php'; ?>
    <main class="py-5 d-flex align-items-center min-vh-100">
        <div class="container d-flex justify-content-center">
            <div class="auth-card p-5 rounded-2 text-center" style="max-width: 450px;">
                <?php if ($verified): ?>
                    <h1 class="mb-3">Email Verified!</h1>
                    <p class="text-muted mb-4">Your account is now active. You can log in below.</p>
                <?php else: ?>
                    <h1 class="mb-3">Verification Failed</h1>
                    <p class="text-muted mb-4">This link is invalid, expired, or has already been used. You can request a new one from the login page.</p>
                <?php endif; ?>
                <a href="login.php" class="btn btn-gold w-100 py-3">Go to Login</a>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/../components/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
