<?php
session_start();

require_once __DIR__ . '/db_connection.php'; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $subject  = trim($_POST['subject'] ?? 'Other');
    $message  = trim($_POST['message'] ?? '');

    $redirectUrl = $_SERVER['HTTP_REFERER'] ?? '../pages/contactus.php';

    if (empty($fullName) || empty($email) || empty($message)) {
        $_SESSION['error_msg'] = "Please fill in all required fields.";
        header("Location: " . $redirectUrl);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error_msg'] = "Please provide a valid email address.";
        header("Location: " . $redirectUrl);
        exit;
    }

    try {
        // Insert into the database
        $stmt = $pdo->prepare("
            INSERT INTO contact_inquiries (full_name, email, phone, subject, message) 
            VALUES (:full_name, :email, :phone, :subject, :message)
        ");
        
        $stmt->execute([
            ':full_name' => $fullName,
            ':email'     => $email,
            ':phone'     => $phone,
            ':subject'   => $subject,
            ':message'   => $message
        ]);

        $_SESSION['success_msg'] = "Thank you! Your message has been received.";
    } catch (PDOException $e) {
        error_log("Contact form error: " . $e->getMessage());
        $_SESSION['error_msg'] = "A server error occurred while saving your message. Please try again later.";
    }

    header("Location: " . $redirectUrl);
    exit;
} 
else {
    header("Location: ../index.php");
    exit;
}