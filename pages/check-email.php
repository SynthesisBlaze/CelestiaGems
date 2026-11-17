<?php
$current_page = 'register';
$cart_count   = 2;

session_start();

// This page only makes sense right after a registration. If someone lands
// here directly without that session flag, send them back to register.
if (empty($_SESSION['pending_verification_email'])) {
    header("Location: register.php");
    exit;
}

$pendingEmail = $_SESSION['pending_verification_email'];
unset($_SESSION['pending_verification_email']); // one-time display only
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Your Email — Celestia Gems</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/register.css">
</head>
<body>
    <?php include __DIR__ . '/../components/navbar.php'; ?>
    <main class="py-5 d-flex align-items-center min-vh-100">
        <div class="container d-flex justify-content-center">
            <div class="auth-card p-5 rounded-2 text-center" style="max-width: 480px;">
                <h1 class="mb-3">Check Your Email</h1>
                <p class="text-muted mb-2">
                    We've sent a verification link to
                    <strong><?= htmlspecialchars($pendingEmail) ?></strong>.
                </p>
                <p class="text-muted mb-4" style="font-size: 0.9rem;">
                    Click the link in that email to activate your account. The link expires in 24 hours.
                    If you don't see it, check your spam folder.
                </p>

                <a href="login.php" class="btn btn-gold w-100 py-3 mb-3">Go to Login</a>

                <p class="text-center mb-0 text-muted" style="font-size: 0.85rem;">
                    Didn't get it?
                    <a href="../functions/resend-verification.php?email=<?= urlencode($pendingEmail) ?>" class="auth-link">Resend verification email</a>
                </p>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/../components/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
