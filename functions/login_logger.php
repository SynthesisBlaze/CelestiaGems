<?php
/**
 * Helper for writing to the login_logs table. Records every login
 * attempt — success or failure — since failed attempts (wrong password,
 * unknown email, etc.) are exactly the ones worth being able to see later.
 *
 * Include this AFTER db_connection.php (needs $pdo).
 *
 * Usage in login.php:
 *
 *   // On success, right after you've verified the password and before redirecting:
 *   log_login_attempt($pdo, $user['user_id'], $email, 'success');
 *
 *   // On failure — user not found:
 *   log_login_attempt($pdo, null, $email, 'failed', 'user_not_found');
 *
 *   // On failure — wrong password:
 *   log_login_attempt($pdo, $user['user_id'], $email, 'failed', 'invalid_password');
 *
 * A logging failure is caught and written to the PHP error log instead of
 * being thrown — it should never be the reason a real login succeeds or fails.
 */
function log_login_attempt(
    PDO $pdo,
    ?int $userId,
    string $emailAttempted,
    string $status,
    ?string $failureReason = null
): void {
    try {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($userAgent !== null && strlen($userAgent) > 255) {
            $userAgent = substr($userAgent, 0, 255);
        }

        $stmt = $pdo->prepare("
            INSERT INTO login_logs
                (user_id, email_attempted, status, failure_reason, user_agent, created_at)
            VALUES
                (:user_id, :email_attempted, :status, :failure_reason, :user_agent, NOW())
        ");

        $stmt->execute([
            ':user_id'         => $userId,
            ':email_attempted' => $emailAttempted,
            ':status'          => $status,
            ':failure_reason'  => $failureReason,
            ':user_agent'      => $userAgent,
        ]);
    } catch (PDOException $e) {
        error_log('log_login_attempt() failed: ' . $e->getMessage());
    }
}