<?php
/**
 * Shared bootstrap for every /api/*.php endpoint.
 *
 * Responsibilities:
 *  - Turn on JSON responses (no HTML error pages leaking to the app)
 *  - Provide json_out() / json_error() helpers so every endpoint replies
 *    in the same {success, message, data} shape
 *  - Provide require_auth($pdo) — reads "Authorization: Bearer <token>",
 *    checks it against api_tokens, and returns the logged-in user row
 *
 * No session_start() here on purpose. Mobile requests are stateless and
 * authenticate with a token on every request, the same way the web app's
 * PHP session authenticates every page load.
 */

require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/app_config.php';

header('Content-Type: application/json');

// Basic CORS — harmless for a native app (it doesn't enforce CORS the way
// a browser does) and convenient if you ever test endpoints from a web
// tool or the Expo web preview.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function json_error(string $message, int $code = 400): void
{
    json_out(['success' => false, 'message' => $message], $code);
}

/**
 * Reads the input body as JSON if present, falling back to $_POST — lets
 * every endpoint accept either a JSON body (what the RN app will send) or
 * a plain form post (handy for quick curl/Postman testing).
 */
function read_input(): array
{
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

function get_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower($name) === 'authorization') {
                $header = $value;
                break;
            }
        }
    }

    if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Generates and stores a new API token for a user (called right after a
 * successful login / 2FA verification / registration-with-2FA-off).
 */
function issue_api_token(PDO $pdo, int $userId, ?string $deviceInfo = null): string
{
    $token = bin2hex(random_bytes(32)); // 64 hex chars — fits api_tokens.token varchar(64)
    $expiresAt = (new DateTime('+30 days'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "INSERT INTO api_tokens (user_id, token, device_info, expires_at) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$userId, $token, $deviceInfo, $expiresAt]);

    return $token;
}

/**
 * Validates the request's bearer token and returns the authenticated
 * user's row (user_id, role_id, first_name, last_name, email, phone).
 * Sends a 401 JSON response and exits if the token is missing/invalid/expired.
 */
function require_auth(PDO $pdo): array
{
    $token = get_bearer_token();
    if (!$token) {
        json_error('Missing or malformed Authorization header. Expected: Bearer <token>.', 401);
    }

    $stmt = $pdo->prepare("
        SELECT u.user_id, u.role_id, u.first_name, u.last_name, u.email, u.phone, u.created_at
        FROM api_tokens t
        JOIN users u ON u.user_id = t.user_id
        WHERE t.token = ? AND t.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        json_error('Session expired or invalid. Please log in again.', 401);
    }

    return $user;
}
