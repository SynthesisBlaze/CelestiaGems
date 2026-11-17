<?php
$current_page = 'login';
$cart_count   = 2;

session_start();
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/login_logger.php';
require_once __DIR__ . '/../functions/two_factor.php';
require_once __DIR__ . '/../functions/settings_helper.php';

$error = '';
$success = '';
$oldEmail = '';

if (isset($_SESSION['success_msg'])) {
    $success = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']); 
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['loginEmail'] ?? '');
    $pass  = $_POST['loginPassword'] ?? '';
    $oldEmail = $email; // keep what they typed if the login fails

    // Emails are matched case-insensitively (Kevin@… and kevin@… are the same account).
    $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass, $user['password_hash'])) {
        // Block unverified email addresses
        if (empty($user['is_email_verified'])) {
            $_SESSION['pending_verification_email'] = $user['email'];
            log_login_attempt($pdo, (int)$user['user_id'], $email, 'failed', 'unverified_email');

            $error = 'Please verify your email address before logging in. Didn\'t get the email? <a href="../functions/resend-verification.php" class="auth-link">Resend verification</a>';
        } elseif (get_setting_bool($pdo, 'two_fa_enabled', false)) {
            // Password is correct and verified, proceeding to 2FA
            $_SESSION['pending_2fa_user_id'] = $user['user_id'];

            $code = create_2fa_code($pdo, (int)$user['user_id']);
            $name = trim($user['first_name'] . ' ' . $user['last_name']);
            $emailSent = send_2fa_email($user['email'], $name !== '' ? $name : $user['email'], $code);

            if ($emailSent) {
                header("Location: verify-2fa.php");
                exit;
            } else {
                unset($_SESSION['pending_2fa_user_id']);
                $error = "We couldn't send your verification code right now. Please try again in a moment.";
            }
        } else {
            // Normal login complete — new session ID so a pre-login session can't be reused.
            session_regenerate_id(true);
            $_SESSION['user_id']    = $user['user_id'];
            $_SESSION['role_id']    = $user['role_id'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name']  = $user['last_name'];

            log_login_attempt($pdo, (int)$user['user_id'], $email, 'success');

            // role_id: 1 = guest, 2 = user, 3 = staff, 4 = admin, 5 = super_admin
            // Staff, Admins and Super Admins land on the admin side.
            if (in_array((int) $user['role_id'], [3, 4, 5], true)) {
                header("Location: ../admin/dashboard.php");
            } else {
                header("Location: account.php");
            }
            exit;
        }
    } else {
        $failureReason = $user ? 'invalid_password' : 'user_not_found';
        log_login_attempt($pdo, $user ? (int)$user['user_id'] : null, $email, 'failed', $failureReason);

        $error = "Invalid email or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In — Celestia Gems</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/register.css">
</head>
<body>
    <?php include __DIR__ . '/../components/navbar.php'; ?>
    <main class="py-4 d-flex align-items-center" style="min-height: calc(100vh - 110px);">
        <div class="container d-flex justify-content-center">
            <div class="auth-card p-5 rounded-2" style="max-width: 450px;">
                <div class="text-center mb-4">
                    <h1 class="mb-2">Welcome Back</h1>
                    <p class="text-muted">Sign in to continue shopping</p>
                </div>

                <?php if (!empty($success)): ?>
                    <div class="alert text-center py-2 cg-notice cg-notice--success">
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert text-center py-2 cg-notice cg-notice--error">
                        <?= $error ?>
                    </div>
                <?php endif; ?>

                <form action="#" method="POST">
                    <div class="form-floating mb-3">
                        <input type="email" class="form-control<?= $error !== '' ? ' is-invalid' : '' ?>" id="loginEmail" name="loginEmail" placeholder="Email" value="<?= htmlspecialchars($oldEmail) ?>" required <?= $error === '' ? 'autofocus' : '' ?>>
                        <label for="loginEmail">Email</label>
                    </div>
    
                    <div class="form-floating mb-2">
                        <input type="password" class="form-control<?= $error !== '' ? ' is-invalid' : '' ?>" id="loginPassword" name="loginPassword" placeholder="Password" required <?= $error !== '' ? 'autofocus' : '' ?>>
                        <label for="loginPassword">Password</label>
                    </div>
                    <p class="text-end mb-4" style="font-size: 0.85rem;">
                        <a href="forgot-password.php" class="auth-link">Forgot password?</a>
                    </p>
    
                    <button type="submit" class="btn btn-gold w-100 py-3 mt-2">Log In</button>
                    <p class="text-center mt-3 mb-0 text-muted" style="font-size: 0.85rem;">
                        Don't have an account? <a href="register.php" class="auth-link">Register</a>
                    </p>
                </form>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/../components/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>