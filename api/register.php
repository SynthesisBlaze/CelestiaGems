<?php
/**
 * POST /api/register.php
 * Body: { first_name, last_name, email, phone, dob, password, confirm_password }
 *
 * Mirrors pages/register.php's validation exactly, but replies with JSON
 * instead of redirecting. The account is created unverified — same as the
 * web flow, the user must click the link Brevo emails them before they can
 * log in. That link opens fine in the phone's browser since it's just a
 * normal web page (pages/verify-email.php).
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../functions/audit_logger.php';
require_once __DIR__ . '/../functions/email_verify.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

$in = read_input();

$first_name = trim($in['first_name'] ?? '');
$last_name  = trim($in['last_name'] ?? '');
$email      = trim($in['email'] ?? '');
$phone      = trim($in['phone'] ?? '');
$dob        = $in['dob'] ?? ''; // expected "YYYY-MM-DD"
$pass       = $in['password'] ?? '';
$confirm    = $in['confirm_password'] ?? '';

if ($first_name === '' || $last_name === '' || $email === '') {
    json_error('First name, last name, and email are required.');
}

if (!preg_match('/^\d{11}$/', $phone)) {
    json_error('Phone number must contain exactly 11 digits.');
}

if (!empty($dob)) {
    try {
        $birthDate = new DateTime($dob);
    } catch (Exception $e) {
        json_error('Invalid date of birth.');
    }
    $age = (int) (new DateTime('today'))->diff($birthDate)->y;
    if ($age < 16) {
        json_error('You must be at least 16 years old to register.');
    }
}

if (strlen($pass) < 8) {
    json_error('Password must be at least 8 characters long.');
}
if ($pass !== $confirm) {
    json_error('Passwords do not match.');
}

$hash = password_hash($pass, PASSWORD_DEFAULT);

try {
    // role_id 2 = regular customer, same as the web registration form
    $stmt = $pdo->prepare("
        INSERT INTO users (role_id, first_name, last_name, email, password_hash, phone, dob)
        VALUES (2, ?, ?, ?, ?, ?, ?) RETURNING user_id
    ");
    $stmt->execute([$first_name, $last_name, $email, $hash, $phone, $dob ?: null]);
    $newUserId = (int) $stmt->fetchColumn();

    log_audit(
        $pdo,
        $newUserId,
        'customer',
        'register',
        'user',
        $newUserId,
        null,
        [
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'email'      => $email,
            'phone'      => $phone,
            'dob'        => $dob,
            'role_id'    => 2,
            'source'     => 'mobile',
        ]
    );

    $token = create_verification_token($pdo, $newUserId);
    send_verification_email($email, $first_name, $token);

    json_out([
        'success' => true,
        'message' => 'Account created. Please check your email to verify your account before logging in.',
    ]);
} catch (PDOException $e) {
    json_error('Registration failed. That email might already be in use.');
}
