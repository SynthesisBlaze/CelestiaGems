<?php
session_start();

require_once __DIR__ . '/permissions.php';
cg_require_page('users.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../admin/users_management.php");
    exit;
}

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/audit_logger.php';

function back_with_error(string $message): void
{
    header("Location: ../admin/users_management.php?error=" . urlencode($message));
    exit;
}

$userId       = isset($_POST['user_id']) && $_POST['user_id'] !== '' ? (int)$_POST['user_id'] : null;
$firstName    = trim($_POST['first_name'] ?? '');
$lastName     = trim($_POST['last_name'] ?? '');
$email        = strtolower(trim($_POST['email'] ?? ''));
$phone        = trim($_POST['phone'] ?? '');
$dob          = trim($_POST['dob'] ?? '');
$roleId       = (int)($_POST['role_id'] ?? 0);
$password     = $_POST['password'] ?? '';
$isVerified   = isset($_POST['is_email_verified']) ? 1 : 0;

// Convert empty dob string to null for database
$dob = $dob !== '' ? $dob : null;

// --- Basic validation ---
if ($firstName === '' || $lastName === '' || $email === '' || $roleId <= 0) {
    back_with_error('Please fill in all required fields.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back_with_error('Please enter a valid email address.');
}

if ($userId === null && strlen($password) < 8) {
    back_with_error('Password must be at least 8 characters for a new user.');
}

if ($password !== '' && strlen($password) < 8) {
    back_with_error('Password must be at least 8 characters.');
}

// --- Make sure the chosen role actually exists ---
$roleCheck = $pdo->prepare("SELECT role_id FROM roles WHERE role_id = ?");
$roleCheck->execute([$roleId]);
if (!$roleCheck->fetch()) {
    back_with_error('Selected role does not exist.');
}

// --- Role hierarchy ---
// Admins manage Guest/Customer/Staff accounts; Super Admins manage everyone.
// Nobody changes their own role here (prevents self-promotion and lock-outs).
$actorRole = cg_current_role();
$actorId   = (int) $_SESSION['user_id'];

$editingSelf = $userId !== null && $userId === $actorId;

// (Editing your own profile keeps your current role — checked just below.)
if (!$editingSelf && !cg_can_manage_role($actorRole, $roleId)) {
    back_with_error('Your role (' . cg_role_name($actorRole) . ') cannot assign the ' . cg_role_name($roleId) . ' role.');
}

if ($userId !== null) {
    $targetStmt = $pdo->prepare("SELECT role_id FROM users WHERE user_id = ?");
    $targetStmt->execute([$userId]);
    $targetRole = $targetStmt->fetchColumn();

    if ($targetRole === false) {
        back_with_error('That user no longer exists.');
    }
    $targetRole = (int) $targetRole;

    if ($userId === $actorId && $roleId !== $targetRole) {
        back_with_error('You cannot change your own role.');
    }
    if ($userId !== $actorId && !cg_can_manage_role($actorRole, $targetRole)) {
        back_with_error('Your role (' . cg_role_name($actorRole) . ') cannot edit ' . cg_role_name($targetRole) . ' accounts.');
    }
}

// --- Make sure email isn't already used by a different user ---
$emailCheck = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = ? AND user_id != ?");
$emailCheck->execute([$email, $userId ?? 0]);
if ($emailCheck->fetch()) {
    back_with_error('That email address is already in use by another account.');
}

try {
    if ($userId === null) {
        // --- Create ---
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO users (first_name, last_name, email, phone, dob, role_id, password_hash, is_email_verified, created_at)
            VALUES (:first_name, :last_name, :email, :phone, :dob, :role_id, :password_hash, :is_email_verified, NOW())
            RETURNING user_id
        ");
        $stmt->execute([
            ':first_name'        => $firstName,
            ':last_name'         => $lastName,
            ':email'             => $email,
            ':phone'             => $phone !== '' ? $phone : null,
            ':dob'               => $dob,
            ':role_id'           => $roleId,
            ':password_hash'     => $passwordHash,
            ':is_email_verified' => $isVerified,
        ]);
        $newUserId = (int) $stmt->fetchColumn();

        log_audit(
            $pdo,
            (int)$_SESSION['user_id'],
            admin_actor_type((int)$_SESSION['role_id']),
            'create',
            'user',
            $newUserId,
            null,
            [
                'first_name'        => $firstName,
                'last_name'         => $lastName,
                'email'             => $email,
                'phone'             => $phone !== '' ? $phone : null,
                'dob'               => $dob,
                'role_id'           => $roleId,
                'is_email_verified' => $isVerified,
            ]
        );

        header("Location: ../admin/users_management.php?saved=created");
        exit;
    } else {
        // --- Update ---
        // Fetch the row as it stood before this update, for the audit log diff.
        $oldRowStmt = $pdo->prepare("SELECT first_name, last_name, email, phone, dob, role_id, is_email_verified FROM users WHERE user_id = ?");
        $oldRowStmt->execute([$userId]);
        $oldRow = $oldRowStmt->fetch(PDO::FETCH_ASSOC);

        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                UPDATE users
                SET first_name = :first_name,
                    last_name = :last_name,
                    email = :email,
                    phone = :phone,
                    dob = :dob,
                    role_id = :role_id,
                    password_hash = :password_hash,
                    is_email_verified = :is_email_verified
                WHERE user_id = :user_id
            ");
            $stmt->execute([
                ':first_name'        => $firstName,
                ':last_name'         => $lastName,
                ':email'             => $email,
                ':phone'             => $phone !== '' ? $phone : null,
                ':dob'               => $dob,
                ':role_id'           => $roleId,
                ':password_hash'     => $passwordHash,
                ':is_email_verified' => $isVerified,
                ':user_id'           => $userId,
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE users
                SET first_name = :first_name,
                    last_name = :last_name,
                    email = :email,
                    phone = :phone,
                    dob = :dob,
                    role_id = :role_id,
                    is_email_verified = :is_email_verified
                WHERE user_id = :user_id
            ");
            $stmt->execute([
                ':first_name'        => $firstName,
                ':last_name'         => $lastName,
                ':email'             => $email,
                ':phone'             => $phone !== '' ? $phone : null,
                ':dob'               => $dob,
                ':role_id'           => $roleId,
                ':is_email_verified' => $isVerified,
                ':user_id'           => $userId,
            ]);
        }

        // password_hash is never logged — a changed/unchanged flag is enough
        // to know a password rotation happened without exposing the hash.
        $newRow = [
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'email'             => $email,
            'phone'             => $phone !== '' ? $phone : null,
            'dob'               => $dob,
            'role_id'           => $roleId,
            'is_email_verified' => $isVerified,
            'password_changed'  => $password !== '',
        ];

        log_audit(
            $pdo,
            (int)$_SESSION['user_id'],
            admin_actor_type((int)$_SESSION['role_id']),
            'update',
            'user',
            $userId,
            $oldRow ?: null,
            $newRow
        );

        // Editing your own profile: keep the name in the topbar in sync.
        if ($userId === $actorId) {
            $_SESSION['first_name'] = $firstName;
            $_SESSION['last_name']  = $lastName;
        }

        header("Location: ../admin/users_management.php?saved=updated");
        exit;
    }
} catch (PDOException $e) {
    back_with_error('Something went wrong while saving the user. Please try again.');
}