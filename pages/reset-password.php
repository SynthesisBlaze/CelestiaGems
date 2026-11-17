<?php
$current_page = 'login';

session_start();
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/password_reset.php';
require_once __DIR__ . '/../functions/audit_logger.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';
$success = false;

// Check the token up front so an expired/invalid link shows the right
// message immediately, instead of only failing after the form is submitted.
$tokenUserId = $token !== '' ? check_password_reset_token($pdo, $token) : null;
$tokenValid = $tokenUserId !== null;

if ($tokenValid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match.";
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $resetUserId = consume_password_reset_token($pdo, $token, $newHash);

        if ($resetUserId === null) {
            // Token got used/expired between the page load and the submit
            // (e.g. opened in two tabs, or sat open past the 1-hour window).
            $tokenValid = false;
            $error = "This reset link is no longer valid. Please request a new one.";
        } else {
            $roleStmt = $pdo->prepare("SELECT role_id FROM users WHERE user_id = ?");
            $roleStmt->execute([$resetUserId]);
            $roleId = (int) $roleStmt->fetchColumn();

            log_audit(
                $pdo,
                $resetUserId,
                actor_type_for_role($roleId),
                'password_reset',
                'user',
                $resetUserId,
                null,
                null
            );

            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — Celestia Gems</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/register.css">
</head>
<body>
    <?php include __DIR__ . '/../components/navbar.php'; ?>
    <main class="py-5 d-flex align-items-center min-vh-100">
        <div class="container d-flex justify-content-center">
            <div class="auth-card p-5 rounded-2" style="max-width: 450px;">
                <?php if ($success): ?>
                    <div class="text-center">
                        <h1 class="mb-3">Password Reset!</h1>
                        <p class="text-muted mb-4">Your password has been changed. You can now log in with your new password.</p>
                        <a href="login.php" class="btn btn-gold w-100 py-3">Go to Login</a>
                    </div>
                <?php elseif (!$tokenValid): ?>
                    <div class="text-center">
                        <h1 class="mb-3">Link Invalid or Expired</h1>
                        <p class="text-muted mb-4">This password reset link is invalid, expired, or has already been used. You can request a new one.</p>
                        <a href="forgot-password.php" class="btn btn-gold w-100 py-3">Request New Link</a>
                    </div>
                <?php else: ?>
                    <div class="text-center mb-4">
                        <h1 class="mb-2">Set New Password</h1>
                        <p class="text-muted">Choose a new password for your account</p>
                    </div>

                    <?php if (!empty($error)): ?>
                        <div class="alert text-center py-2 cg-notice cg-notice--error">
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form action="#" method="POST">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                        <div class="form-floating mb-3">
                            <input type="password" class="form-control" id="new_password" name="new_password" placeholder="New Password" required>
                            <label for="new_password">New Password</label>
                        </div>

                        <div class="form-floating mb-4">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm New Password" required>
                            <label for="confirm_password">Confirm New Password</label>
                        </div>

                        <button type="submit" class="btn btn-gold w-100 py-3 mt-2">Reset Password</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/../components/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>