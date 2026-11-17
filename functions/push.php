<?php
/**
 * Sending push notifications through Expo.
 *
 * Put this file in functions/ alongside notifier.php.
 *
 * The app registers each device's Expo push token with push-register.php;
 * this sends to every token a customer has, so an order update reaches
 * their phone and their tablet. Tokens Expo tells us are dead get deleted,
 * which is the only way the table stays honest — people reinstall apps and
 * change phones, and an unpruned token list quietly becomes mostly rubbish.
 *
 * Everything here is best-effort and never throws. A push that fails must
 * not roll back the order it was announcing, and the in-app notification
 * has already been written by the time this runs, so nothing is actually
 * lost when the network misbehaves.
 */

if (!function_exists('cg_send_push')) {

    /**
     * Sends one notification to all of a customer's registered devices.
     *
     * @param array $data Payload the app reads to decide where to navigate
     *                    when the notification is tapped.
     */
    function cg_send_push(PDO $pdo, int $userId, string $title, string $body, array $data = []): void
    {
        try {
            $stmt = $pdo->prepare("SELECT token FROM push_tokens WHERE user_id = ?");
            $stmt->execute([$userId]);
            $tokens = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'token');

            if (empty($tokens)) {
                return; // nobody has the app installed — not an error
            }

            $messages = array_map(fn($token) => [
                'to'       => $token,
                'title'    => $title,
                'body'     => $body,
                'sound'    => 'default',
                'data'     => $data,
                'priority' => 'high',
            ], $tokens);

            $ch = curl_init('https://exp.host/--/api/v2/push/send');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($messages),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT => 8,
            ]);

            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                error_log('cg_send_push curl error: ' . $error);
                return;
            }

            // Expo answers with one ticket per message, in the order sent.
            // A DeviceNotRegistered ticket means that token will never work
            // again, so it goes rather than being retried forever.
            $decoded = json_decode((string) $response, true);
            $tickets = $decoded['data'] ?? [];

            foreach ($tickets as $i => $ticket) {
                $reason = $ticket['details']['error'] ?? null;
                if (($ticket['status'] ?? '') === 'error' && $reason === 'DeviceNotRegistered') {
                    if (isset($tokens[$i])) {
                        $pdo->prepare("DELETE FROM push_tokens WHERE token = ?")->execute([$tokens[$i]]);
                    }
                } elseif (($ticket['status'] ?? '') === 'error') {
                    error_log('Expo push ticket error: ' . json_encode($ticket));
                }
            }
        } catch (Throwable $e) {
            // Throwable, not PDOException: a push must never be the reason
            // an order fails to save.
            error_log('cg_send_push failed: ' . $e->getMessage());
        }
    }
}
