<?php
$current_page = 'login';
$cart_count   = 2;

session_start();
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/two_factor.php';
require_once __DIR__ . '/../functions/login_logger.php';

if (empty($_SESSION['pending_2fa_user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = (int)$_SESSION['pending_2fa_user_id'];
$error = '';
$info = '';

if (isset($_SESSION['info_msg'])) {
    $info = $_SESSION['info_msg'];
    unset($_SESSION['info_msg']);
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    // Shouldn't happen, but bail out cleanly if the session is stale.
    unset($_SESSION['pending_2fa_user_id']);
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['otp'] ?? '');

    if (verify_2fa_code($pdo, $userId, $code)) {
        unset($_SESSION['pending_2fa_user_id']);

        // New session ID now that the user is fully signed in.
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['role_id'] = $user['role_id'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];

        log_login_attempt($pdo, (int)$user['user_id'], $user['email'], 'success');

        // role_id: 1 = guest, 2 = user, 3 = staff, 4 = admin, 5 = super_admin
        if (in_array((int) $user['role_id'], [3, 4, 5], true)) {
            header("Location: ../admin/dashboard.php");
        } else {
            header("Location: account.php");
        }
        exit;
    } else {
        log_login_attempt($pdo, (int)$user['user_id'], $user['email'], 'failed', 'invalid_2fa_code');
        $error = "That code is invalid or expired. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code — Celestia Gems</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/register.css">
</head>
<body>
    <?php include __DIR__ . '/../components/navbar.php'; ?>
    <main class="py-5 d-flex align-items-center min-vh-100">
        <div class="container d-flex justify-content-center">
            <div class="auth-card p-5 rounded-2" style="max-width: 450px;">
                <div class="text-center mb-4">
                    <h1 class="mb-2">Enter Your Code</h1>
                    <p class="text-muted">We sent a 6-digit code to <?= htmlspecialchars($user['email']) ?></p>
                </div>

                <?php if (!empty($info)): ?>
                    <div class="alert text-center py-2 cg-notice cg-notice--success">
                        <?= htmlspecialchars($info) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert text-center py-2 cg-notice cg-notice--error">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form action="#" method="POST">
                    <div class="form-floating mb-4">
                        <input type="text" class="form-control" id="otp" name="otp" placeholder="6-digit code" required maxlength="6" pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code">
                        <label for="otp">6-Digit Code</label>
                    </div>
                    <button type="submit" class="btn btn-gold w-100 py-3 mt-2">Verify</button>
                    <p class="text-center mt-3 mb-0 text-muted" style="font-size: 0.85rem;">
                        Didn't get it? <a href="../functions/resend-2fa.php" class="auth-link">Resend code</a>
                    </p>
                </form>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/../components/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>