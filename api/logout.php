<?php
/**
 * POST /api/logout.php   (Authorization: Bearer <token>)
 * Deletes just this device's token — other logged-in devices are untouched.
 */
require_once __DIR__ . '/_bootstrap.php';

$token = get_bearer_token();
if ($token) {
    $pdo->prepare("DELETE FROM api_tokens WHERE token = ?")->execute([$token]);
}

json_out(['success' => true, 'message' => 'Logged out.']);
