<?php
$current_page = 'login';

session_start();
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/password_reset.php';

$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT user_id, first_name, email FROM users WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Only generate/send a token if the account actually exists — but
        // the page shows the same message either way, so a visitor can't
        // use this form to discover which emails have accounts.
        if ($user) {
            $token = create_password_reset_token($pdo, (int) $user['user_id']);
            send_password_reset_email($user['email'], $user['first_name'], $token);
        }
    }

    $submitted = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — Celestia Gems</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/register.css">
</head>
<body>
    <?php include __DIR__ . '/../components/navbar.php'; ?>
    <main class="py-5 d-flex align-items-center min-vh-100">
        <div class="container d-flex justify-content-center">
            <div class="auth-card p-5 rounded-2" style="max-width: 450px;">
                <?php if ($submitted): ?>
                    <div class="text-center">
                        <h1 class="mb-3">Check Your Email</h1>
                        <p class="text-muted mb-4">If that email address has an account, we've sent a link to reset your password. The link expires in 1 hour.</p>
                        <a href="login.php" class="btn btn-gold w-100 py-3">Back to Login</a>
                    </div>
                <?php else: ?>
                    <div class="text-center mb-4">
                        <h1 class="mb-2">Forgot Password</h1>
                        <p class="text-muted">Enter your email and we'll send you a reset link</p>
                    </div>

                    <form action="#" method="POST">
                        <div class="form-floating mb-4">
                            <input type="email" class="form-control" id="email" name="email" placeholder="Email" required>
                            <label for="email">Email</label>
                        </div>

                        <button type="submit" class="btn btn-gold w-100 py-3 mt-2">Send Reset Link</button>
                        <p class="text-center mt-3 mb-0 text-muted" style="font-size: 0.85rem;">
                            Remembered your password? <a href="login.php" class="auth-link">Log In</a>
                        </p>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/../components/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
