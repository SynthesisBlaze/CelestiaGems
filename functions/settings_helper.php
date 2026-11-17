<?php
/**
 * Read helpers for site_settings. Include with require_once wherever a
 * setting needs to be checked — navbar.php (announcement), checkout.php /
 * checkout-process.php (shipping + payment methods), login.php (2FA).
 *
 * Settings are cached per-request (static var) since several includes on
 * the same page load — e.g. navbar.php pulling in db_connection.php —
 * can end up calling these more than once per request.
 */

function get_all_settings(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $cache = $pdo->query("SELECT setting_key, setting_value FROM site_settings")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        error_log('get_all_settings() failed: ' . $e->getMessage());
        $cache = [];
    }

    return $cache;
}

function get_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $all = get_all_settings($pdo);
    return $all[$key] ?? $default;
}

function get_setting_bool(PDO $pdo, string $key, bool $default = false): bool
{
    $value = get_setting($pdo, $key, $default ? '1' : '0');
    return $value === '1';
}

function get_setting_float(PDO $pdo, string $key, float $default = 0.0): float
{
    $value = get_setting($pdo, $key);
    return ($value !== null && is_numeric($value)) ? (float) $value : $default;
}
