<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/phone_helper.php';

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../pages/account.php");
    exit;
}

$userId = $_SESSION['user_id'];
$hasProfileUpdate = isset($_POST['first_name']) || isset($_POST['last_name']) || isset($_POST['email']) || isset($_POST['phone']);
$hasShippingUpdate = isset($_POST['shipping_address']) || isset($_POST['shipping_city']) || isset($_POST['shipping_postal_code']);

try {
    if ($hasProfileUpdate) {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');

        if (empty($firstName) || empty($lastName) || empty($email)) {
            $_SESSION['error_msg'] = "First name, last name, and email are required.";
            header("Location: ../pages/account.php?tab=details");
            exit;
        }

        // Same rules as registration.
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            $_SESSION['error_msg'] = "Please enter a valid email address, like name@example.com.";
            header("Location: ../pages/account.php?tab=details");
            exit;
        }
        if (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            $_SESSION['error_msg'] = "Names can be up to 100 characters.";
            header("Location: ../pages/account.php?tab=details");
            exit;
        }
        if ($phone !== '') {
            $normalizedPhone = normalize_ph_mobile($phone);
            if ($normalizedPhone === null) {
                $_SESSION['error_msg'] = "Enter a valid Philippine mobile number, like 09171234567.";
                header("Location: ../pages/account.php?tab=details");
                exit;
            }
            $phone = $normalizedPhone;
        }

        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = ? AND user_id != ?");
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) {
            $_SESSION['error_msg'] = "That email address is already in use.";
            header("Location: ../pages/account.php?tab=details");
            exit;
        }

        $updateStmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE user_id = ?");
        $updateStmt->execute([$firstName, $lastName, $email, $phone, $userId]);

        $_SESSION['first_name'] = $firstName;
        $_SESSION['last_name'] = $lastName;
        $_SESSION['success_msg'] = "Account details updated successfully.";
    }

    if ($hasShippingUpdate) {
        $shippingAddress = trim($_POST['shipping_address'] ?? '');
        $shippingCity = trim($_POST['shipping_city'] ?? '');
        $shippingPostalCode = trim($_POST['shipping_postal_code'] ?? '');
        // Set by the Google Maps pin on account.php. Falls back to 0 if the
        // map didn't load or the user never touched the pin/search box.
        $shippingLat = is_numeric($_POST['shipping_lat'] ?? null) ? (float)$_POST['shipping_lat'] : 0.0;
        $shippingLng = is_numeric($_POST['shipping_lng'] ?? null) ? (float)$_POST['shipping_lng'] : 0.0;

        if (empty($shippingAddress) || empty($shippingCity) || empty($shippingPostalCode)) {
            $_SESSION['error_msg'] = "Shipping address, city, and postal code are required.";
            header("Location: ../pages/account.php?tab=details");
            exit;
        }

        $existingAddressStmt = $pdo->prepare("SELECT address_id FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, address_id DESC LIMIT 1");
        $existingAddressStmt->execute([$userId]);
        $existingAddress = $existingAddressStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingAddress) {
            $updateAddressStmt = $pdo->prepare("UPDATE user_addresses SET address_line = ?, city = ?, postal_code = ?, latitude = ?, longitude = ?, is_default = 1 WHERE address_id = ?");
            $updateAddressStmt->execute([$shippingAddress, $shippingCity, $shippingPostalCode, $shippingLat, $shippingLng, $existingAddress['address_id']]);
        } else {
            $insertAddressStmt = $pdo->prepare("INSERT INTO user_addresses (user_id, address_line, city, postal_code, latitude, longitude, is_default) VALUES (?, ?, ?, ?, ?, ?, 1)");
            $insertAddressStmt->execute([$userId, $shippingAddress, $shippingCity, $shippingPostalCode, $shippingLat, $shippingLng]);
        }

        if (!isset($_SESSION['success_msg'])) {
            $_SESSION['success_msg'] = "Shipping address saved successfully.";
        } else {
            $_SESSION['success_msg'] .= " Shipping address saved successfully.";
        }
    }
} catch (PDOException $e) {
    $_SESSION['error_msg'] = "An error occurred while updating your account.";
}

header("Location: ../pages/account.php?tab=details");
exit;