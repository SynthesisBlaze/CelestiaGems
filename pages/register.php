<?php
$current_page = 'register';
$cart_count   = 2;

session_start();
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/audit_logger.php';
require_once __DIR__ . '/../functions/email_verify.php';
require_once __DIR__ . '/../functions/phone_helper.php';

$errors = [];
$old = [
    'first_name' => '',
    'last_name'  => '',
    'email'      => '',
    'phone'      => '',
    'dob'        => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['first_name'] = trim($_POST['regFirstName'] ?? '');
    $old['last_name']  = trim($_POST['regLastName'] ?? '');
    $old['email']      = strtolower(trim($_POST['regEmail'] ?? ''));
    $old['phone']      = trim($_POST['regPhone'] ?? '');
    $old['dob']        = $_POST['dobInput'] ?? '';
    $pass    = $_POST['regPass'] ?? '';
    $confirm = $_POST['regConfirm'] ?? '';

    if ($old['first_name'] === '') {
        $errors['first_name'] = 'Please enter your first name.';
    } elseif (mb_strlen($old['first_name']) > 100) {
        $errors['first_name'] = 'First name is too long (100 characters max).';
    }

    if ($old['last_name'] === '') {
        $errors['last_name'] = 'Please enter your last name.';
    } elseif (mb_strlen($old['last_name']) > 100) {
        $errors['last_name'] = 'Last name is too long (100 characters max).';
    }

    if ($old['email'] === '') {
        $errors['email'] = 'Please enter your email address.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 150) {
        $errors['email'] = 'Please enter a valid email address, like name@example.com.';
    }

    $normalizedPhone = normalize_ph_mobile($old['phone']);
    if ($old['phone'] === '') {
        $errors['phone'] = 'Please enter your mobile number.';
    } elseif ($normalizedPhone === null) {
        $errors['phone'] = 'Enter a valid Philippine mobile number, like 09171234567.';
    } else {
        $old['phone'] = $normalizedPhone; // show the cleaned-up version if the form comes back
    }

    if ($old['dob'] === '') {
        $errors['dob'] = 'Please select your date of birth.';
    } else {
        try {
            $birthDate = new DateTime($old['dob']);
            $today = new DateTime('today');
            if ($birthDate > $today) {
                $errors['dob'] = 'Date of birth cannot be in the future.';
            } else {
                $age = (int) $today->diff($birthDate)->y;
                if ($age < 16) {
                    $errors['dob'] = 'You must be at least 16 years old to register.';
                } elseif ($age > 120) {
                    $errors['dob'] = 'Please enter a valid date of birth.';
                }
            }
        } catch (Exception $e) {
            $errors['dob'] = 'Please enter a valid date of birth.';
        }
    }

    if (strlen($pass) < 8) {
        $errors['pass'] = 'Password must be at least 8 characters long.';
    }
    if ($confirm === '') {
        $errors['confirm'] = 'Please confirm your password.';
    } elseif ($pass !== $confirm) {
        $errors['confirm'] = 'Passwords do not match.';
    }

    // The database's unique index is case-sensitive, so check case-insensitively
    // here too — otherwise "Kevin@…" and "kevin@…" could become two accounts.
    if (empty($errors)) {
        $dupStmt = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(email) = ? LIMIT 1");
        $dupStmt->execute([$old['email']]);
        if ($dupStmt->fetchColumn()) {
            $errors['email'] = 'An account with this email already exists. Try logging in or resetting your password.';
        }
    }

    if (empty($errors)) {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (role_id, first_name, last_name, email, password_hash, phone, dob) VALUES (2, ?, ?, ?, ?, ?, ?) RETURNING user_id");

        try {
            $stmt->execute([$old['first_name'], $old['last_name'], $old['email'], $hash, $old['phone'], $old['dob']]);
            $newUserId = (int)$stmt->fetchColumn();

            // Log the registration — the new user is the actor, acting on their own account.
            // Password/hash is intentionally never included in the logged values.
            log_audit(
                $pdo,
                $newUserId,
                'customer',
                'register',
                'user',
                $newUserId,
                null,
                [
                    'first_name' => $old['first_name'],
                    'last_name'  => $old['last_name'],
                    'email'      => $old['email'],
                    'phone'      => $old['phone'],
                    'dob'        => $old['dob'],
                    'role_id'    => 2,
                ]
            );

            // Send a verification email. The account is created either way —
            // a flaky email provider shouldn't block registration — but the
            // account stays unverified (is_email_verified = 0 by default)
            // until the link is clicked.
            $token = create_verification_token($pdo, $newUserId);
            send_verification_email($old['email'], $old['first_name'], $token);

            $_SESSION['pending_verification_email'] = $old['email'];
            header("Location: check-email.php");
            exit;
        } catch (PDOException $e) {
            // SQLSTATE 23505 = unique_violation (the email is already registered)
            if ($e->errorInfo[0] === '23505') {
                $errors['email'] = 'An account with this email already exists. Try logging in or resetting your password.';
            } else {
                error_log('register error: ' . $e->getMessage());
                $errors['general'] = 'Something went wrong while creating your account. Please try again.';
            }
        }
    }
}

// Small helpers so the form markup below stays readable.
function field_class(array $errors, string $key): string
{
    return isset($errors[$key]) ? ' is-invalid' : '';
}
function field_error(array $errors, string $key): string
{
    return htmlspecialchars($errors[$key] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . "/../components/theme_init.php"; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — Celestia Gems</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/register.css">
</head>
<body>
    <?php include __DIR__ . '/../components/navbar.php'; ?>

    <main class="py-4 d-flex align-items-center" style="min-height: calc(100vh - 110px);">
        <div class="container d-flex justify-content-center">
            <div class="auth-card p-5 rounded-2">
                <div class="text-center mb-4">
                    <h1 class="mb-2">Create Account</h1>
                    <p class="text-muted">Join now to start shopping</p>
                </div>

                <?php if (!empty($errors['general'])): ?>
                    <div class="alert text-center py-2 cg-notice cg-notice--error">
                        <?= htmlspecialchars($errors['general']) ?>
                    </div>
                <?php elseif (!empty($errors)): ?>
                    <div class="alert text-center py-2 cg-notice cg-notice--error">
                        Please fix the highlighted fields below.
                    </div>
                <?php endif; ?>

                <form action="#" method="POST" id="registerForm" novalidate>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="text" class="form-control<?= field_class($errors, 'first_name') ?>" id="regFirstName" name="regFirstName" placeholder="First Name" value="<?= htmlspecialchars($old['first_name']) ?>" maxlength="100" required>
                                <label for="regFirstName">First Name</label>
                            </div>
                            <div class="field-error" id="err-regFirstName"><?= field_error($errors, 'first_name') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="text" class="form-control<?= field_class($errors, 'last_name') ?>" id="regLastName" name="regLastName" placeholder="Last Name" value="<?= htmlspecialchars($old['last_name']) ?>" maxlength="100" required>
                                <label for="regLastName">Last Name</label>
                            </div>
                            <div class="field-error" id="err-regLastName"><?= field_error($errors, 'last_name') ?></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="form-floating">
                            <input type="email" class="form-control<?= field_class($errors, 'email') ?>" id="regEmail" name="regEmail" placeholder="Email" value="<?= htmlspecialchars($old['email']) ?>" maxlength="150" required>
                            <label for="regEmail">Email</label>
                        </div>
                        <div class="field-error" id="err-regEmail"><?= field_error($errors, 'email') ?></div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="tel" class="form-control<?= field_class($errors, 'phone') ?>" id="regPhone" name="regPhone" placeholder="Phone Number" value="<?= htmlspecialchars($old['phone']) ?>" inputmode="tel" autocomplete="tel" required>
                                <label for="regPhone">Mobile Number (09XXXXXXXXX)</label>
                            </div>
                            <div class="field-error" id="err-regPhone"><?= field_error($errors, 'phone') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="input-group custom-date-wrap">
                                <div class="form-floating flex-grow-1">
                                    <input type="date" class="form-control<?= field_class($errors, 'dob') ?>" id="dobInput" name="dobInput" placeholder="Date of Birth" value="<?= htmlspecialchars($old['dob']) ?>" max="<?= date('Y-m-d') ?>" required>
                                    <label for="dobInput">Date of Birth</label>
                                </div>
                                <span class="input-group-text" id="calendarIcon" title="Choose Date">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                    </svg>
                                </span>
                            </div>
                            <div class="field-error" id="err-dobInput"><?= field_error($errors, 'dob') ?></div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="password" class="form-control<?= field_class($errors, 'pass') ?>" id="regPass" name="regPass" placeholder="Password" autocomplete="new-password" required>
                                <label for="regPass">Password</label>
                            </div>
                            <div class="field-error" id="err-regPass"><?= field_error($errors, 'pass') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="password" class="form-control<?= field_class($errors, 'confirm') ?>" id="regConfirm" name="regConfirm" placeholder="Confirm Password" autocomplete="new-password" required>
                                <label for="regConfirm">Confirm Password</label>
                            </div>
                            <div class="field-error" id="err-regConfirm"><?= field_error($errors, 'confirm') ?></div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-gold w-100 py-3 mt-2">Register</button>
                    <p class="text-center mt-3 mb-0 text-muted" style="font-size: 0.85rem;">
                        Already have an account? <a href="login.php" class="auth-link">Log in</a>
                    </p>
                </form>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../components/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const form = document.getElementById('registerForm');
        const phoneInput = document.getElementById('regPhone');
        const dobInput = document.getElementById('dobInput');

        // --- Phone: accept 09.., 9.., 63.. and +63 formats, normalize to 09XXXXXXXXX ---
        function normalizePhone(v) {
            let d = v.replace(/\D/g, '');
            if (d.length === 12 && d.startsWith('639')) d = '0' + d.slice(2);
            else if (d.length === 10 && d[0] === '9') d = '0' + d;
            return d;
        }
        function phoneProblem(v) {
            const d = normalizePhone(v);
            if (v.trim() === '') return 'Please enter your mobile number.';
            if (!/^09\d{9}$/.test(d)) return 'Enter a valid Philippine mobile number, like 09171234567.';
            if (/^09(\d)\1{8}$/.test(d)) return 'That number doesn\'t look real — please double-check it.';
            return '';
        }

        // Only digits / spaces / dashes / plus while typing; tidy up on blur or paste.
        phoneInput.addEventListener('input', function () {
            this.value = this.value.replace(/[^\d+\s-]/g, '').slice(0, 17);
        });
        phoneInput.addEventListener('blur', function () {
            const d = normalizePhone(this.value);
            if (/^09\d{9}$/.test(d)) this.value = d;
        });

        document.getElementById('calendarIcon').addEventListener('click', function () {
            dobInput.showPicker();
        });

        // --- Inline error helpers (same look as the server-rendered ones) ---
        function setError(input, message) {
            const box = document.getElementById('err-' + input.id);
            if (box) box.textContent = message;
            input.classList.toggle('is-invalid', message !== '');
        }
        // Clear a field's error as soon as the user starts fixing it.
        form.querySelectorAll('input').forEach(function (input) {
            input.addEventListener('input', function () { setError(input, ''); });
        });

        form.addEventListener('submit', function (event) {
            const checks = [];
            const v = id => document.getElementById(id);

            checks.push([v('regFirstName'), v('regFirstName').value.trim() === '' ? 'Please enter your first name.' : '']);
            checks.push([v('regLastName'), v('regLastName').value.trim() === '' ? 'Please enter your last name.' : '']);

            const email = v('regEmail').value.trim();
            let emailMsg = '';
            if (email === '') emailMsg = 'Please enter your email address.';
            else if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) emailMsg = 'Please enter a valid email address, like name@example.com.';
            checks.push([v('regEmail'), emailMsg]);

            checks.push([phoneInput, phoneProblem(phoneInput.value)]);

            let dobMsg = '';
            if (!dobInput.value) {
                dobMsg = 'Please select your date of birth.';
            } else {
                const birth = new Date(dobInput.value);
                const today = new Date();
                if (birth > today) {
                    dobMsg = 'Date of birth cannot be in the future.';
                } else {
                    let age = today.getFullYear() - birth.getFullYear();
                    const m = today.getMonth() - birth.getMonth();
                    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
                    if (age < 16) dobMsg = 'You must be at least 16 years old to register.';
                    else if (age > 120) dobMsg = 'Please enter a valid date of birth.';
                }
            }
            checks.push([dobInput, dobMsg]);

            const pass = v('regPass').value;
            const confirm = v('regConfirm').value;
            checks.push([v('regPass'), pass.length < 8 ? 'Password must be at least 8 characters long.' : '']);
            let confirmMsg = '';
            if (confirm === '') confirmMsg = 'Please confirm your password.';
            else if (pass !== confirm) confirmMsg = 'Passwords do not match.';
            checks.push([v('regConfirm'), confirmMsg]);

            let firstBad = null;
            checks.forEach(function (c) {
                setError(c[0], c[1]);
                if (c[1] && !firstBad) firstBad = c[0];
            });

            if (firstBad) {
                event.preventDefault();
                firstBad.focus();
                return;
            }

            // Submit the cleaned-up phone number.
            phoneInput.value = normalizePhone(phoneInput.value);
        });
    </script>
</body>
</html>