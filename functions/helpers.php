<?php
/**
 * Shared helper functions. Include with require_once wherever needed.
 */

if (!function_exists('format_order_number')) {
    function format_order_number(int $order_id): string
    {
        return 'CG-' . str_pad((string) $order_id, 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('parse_order_number')) {
    // Accepts "CG-000123", "000123", or "123" and returns the int order_id, or null if not numeric.
    function parse_order_number(string $value): ?int
    {
        $digits = preg_replace('/[^0-9]/', '', $value);
        if ($digits === '') {
            return null;
        }
        return (int) $digits;
    }
}

if (!function_exists('cg_base_url')) {
    /**
     * Absolute base URL of the site, e.g. "https://celestiagems.com" or
     * "http://localhost" — taken from the current request, so payment
     * return links and email links work on any domain without editing code.
     *
     * To force a specific URL (e.g. behind a proxy), define CG_BASE_URL in a
     * config file: define('CG_BASE_URL', 'https://celestiagems.com');
     */
    function cg_base_url(): string
    {
        if (defined('CG_BASE_URL')) {
            return rtrim(CG_BASE_URL, '/');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return ($https ? 'https' : 'http') . '://' . $host;
    }
}

if (!function_exists('restore_order_stock')) {
    /**
     * Put an order's items back into stock (used when an order is cancelled
     * or its payment fails). Call inside the same transaction that changes
     * the order's status, and only when the order is actually moving into a
     * cancelled state — otherwise stock gets added twice.
     */
    function restore_order_stock(PDO $pdo, int $orderId): void
    {
        $itemsStmt = $pdo->prepare("SELECT size_id, quantity FROM order_items WHERE order_id = ? AND size_id IS NOT NULL");
        $itemsStmt->execute([$orderId]);
        $restoreStmt = $pdo->prepare("UPDATE product_sizes SET stock_quantity = stock_quantity + ? WHERE size_id = ?");
        foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $restoreStmt->execute([(int) $item['quantity'], (int) $item['size_id']]);
        }
    }
}

if (!function_exists('cancel_unpaid_order')) {
    /**
     * Cancel an order whose online payment failed or was abandoned — but only
     * if it is still Pending. Returns true if this call cancelled it (and
     * released its stock), false if it was already handled (refresh, back
     * button, or a payment that went through), so stock is never restored twice.
     */
    function cancel_unpaid_order(PDO $pdo, int $orderId): bool
    {
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare("SELECT order_status FROM orders WHERE order_id = ? FOR UPDATE");
            $lock->execute([$orderId]);
            if ($lock->fetchColumn() !== 'Pending') {
                $pdo->rollBack();
                return false;
            }
            $paid = $pdo->prepare("SELECT 1 FROM payments WHERE order_id = ? AND payment_status = 'Paid'");
            $paid->execute([$orderId]);
            if ($paid->fetchColumn()) {
                $pdo->rollBack();
                return false;
            }

            restore_order_stock($pdo, $orderId);
            $pdo->prepare("UPDATE payments SET payment_status = 'Failed' WHERE order_id = ?")->execute([$orderId]);
            $pdo->prepare("UPDATE orders SET order_status = 'Cancelled' WHERE order_id = ?")->execute([$orderId]);
            $pdo->prepare("UPDATE deliveries SET delivery_status = 'Cancelled' WHERE order_id = ?")->execute([$orderId]);
            $pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('cancel_unpaid_order(' . $orderId . ') failed: ' . $e->getMessage());
            return false;
        }
    }
}
