<?php
/**
 * POST /api/ai-chat.php   (Authorization: Bearer <token>)
 * Body: { message, history?: [{role: "user"|"model", text: "..."}] }
 *
 * Same Gemini-backed shopping assistant as functions/ai-chat.php (same
 * system prompt, same catalog/FAQ grounding), just token-authed instead of
 * session-authed. Rate limiting is done against api_tokens.user_id in a
 * tiny in-memory-per-request window using the token row itself, since a
 * stateless API call has no PHP session to keep counters in.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../functions/gemini_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

$user = require_auth($pdo);
$in = read_input();

$userMessage = trim($in['message'] ?? '');
if ($userMessage === '') {
    json_error('Please type a message.');
}
if (mb_strlen($userMessage) > 500) {
    json_error('Message is too long — please keep it under 500 characters.');
}

// --- Simple rate limit: 20 messages per 10 minutes per user, tracked in a
// small table so it survives across stateless requests. Create this table
// once (see README) — it's not in the original schema since the web
// version could lean on $_SESSION instead. ---
try {
    $pdo->prepare("DELETE FROM ai_chat_log WHERE user_id = ? AND created_at < NOW() - INTERVAL '10 minutes'")
        ->execute([$user['user_id']]);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ai_chat_log WHERE user_id = ?");
    $countStmt->execute([$user['user_id']]);
    if ((int) $countStmt->fetchColumn() >= 20) {
        json_error("You're sending messages a bit quickly — please wait a few minutes and try again.", 429);
    }
    $pdo->prepare("INSERT INTO ai_chat_log (user_id, created_at) VALUES (?, NOW())")->execute([$user['user_id']]);
} catch (PDOException $e) {
    // If the table doesn't exist yet, don't block the feature over it —
    // just skip rate limiting until the table is created.
    error_log('ai_chat_log rate limit skipped: ' . $e->getMessage());
}

$rawHistory = $in['history'] ?? [];
$history = [];
if (is_array($rawHistory)) {
    foreach (array_slice($rawHistory, -12) as $turn) {
        $role = ($turn['role'] ?? '') === 'model' ? 'model' : 'user';
        $text = mb_substr(trim((string) ($turn['text'] ?? '')), 0, 500);
        if ($text !== '') {
            $history[] = ['role' => $role, 'text' => $text];
        }
    }
}

$allUserText = implode(' ', array_map(fn($t) => $t['text'], array_filter($history, fn($t) => $t['role'] === 'user')));
$allUserText .= ' ' . $userMessage;

$context = '';
try {
    $stopwords = [
        'the', 'and', 'for', 'you', 'your', 'with', 'that', 'this', 'have',
        'are', 'was', 'were', 'can', 'could', 'would', 'please', 'recommend',
        'suggest', 'show', 'find', 'looking', 'want', 'need', 'one', 'some',
        'any', 'all', 'about', 'more', 'tell', 'told', 'yes', 'no', 'okay',
        'hello', 'hi', 'there', 'what', 'which', 'give',
    ];

    $words = preg_split('/\s+/', strtolower($allUserText));
    $keywords = [];
    foreach ($words as $w) {
        $w = preg_replace('/[^a-z0-9]/', '', $w);
        if (strlen($w) >= 3 && !in_array($w, $stopwords, true)) {
            $keywords[] = $w;
        }
    }
    $keywords = array_slice(array_unique($keywords), 0, 6);

    $matchedProducts = [];
    if (!empty($keywords)) {
        $conditions = [];
        $params = [];
        foreach ($keywords as $i => $kw) {
            $conditions[] = "(p.name LIKE :kw{$i} OR p.description LIKE :kw{$i} OR c.name LIKE :kw{$i})";
            $params[":kw{$i}"] = '%' . $kw . '%';
        }
        $productStmt = $pdo->prepare("
            SELECT p.name, p.description, p.price, c.name AS category
            FROM products p LEFT JOIN categories c ON c.category_id = p.category_id
            WHERE " . implode(' OR ', $conditions) . " LIMIT 8
        ");
        $productStmt->execute($params);
        $matchedProducts = $productStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($matchedProducts) && empty($keywords)) {
        $matchedProducts = $pdo->query("
            SELECT p.name, p.description, p.price, c.name AS category
            FROM products p LEFT JOIN categories c ON c.category_id = p.category_id
            ORDER BY p.created_at DESC LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!empty($matchedProducts)) {
        $context .= "Some products currently in the catalog:\n";
        foreach ($matchedProducts as $p) {
            $desc = mb_substr((string) ($p['description'] ?? ''), 0, 120);
            $context .= "- {$p['name']} ({$p['category']}) — ₱" . number_format((float) $p['price'], 2) . ": {$desc}\n";
        }
    } else {
        $context .= "(No specific products matched this message — nothing relevant was found in the catalog for this query.)\n";
    }

    $faqStmt = $pdo->query("SELECT question, answer FROM faqs WHERE is_published = 1 ORDER BY sort_order ASC LIMIT 10");
    $faqs = $faqStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($faqs)) {
        $context .= "\nFrequently asked questions:\n";
        foreach ($faqs as $f) {
            $context .= "Q: {$f['question']}\nA: {$f['answer']}\n";
        }
    }
} catch (PDOException $e) {
    error_log('ai-chat.php context fetch failed: ' . $e->getMessage());
}

$systemPrompt = "You are the shopping assistant for Celestia Gems, an online jewelry store. "
    . "Only help with questions about Celestia Gems products, orders, shipping, and jewelry care/advice. "
    . "Politely decline anything unrelated and steer the conversation back to how you can help them shop. "
    . "When the catalog list below has relevant items, name 1-2 of them specifically by name and price. "
    . "If the list says nothing matched, say so plainly and ask a clarifying question instead of stalling. "
    . "Never invent product names, prices, or policies that aren't in the context below. "
    . "Keep replies short and conversational, like a helpful boutique associate, not a wall of text.\n\n"
    . $context;

$contents = [];
foreach ($history as $turn) {
    $contents[] = ['role' => $turn['role'], 'parts' => [['text' => $turn['text']]]];
}
$contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

$payload = [
    'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
    'contents' => $contents,
    'generationConfig' => ['maxOutputTokens' => 2048, 'temperature' => 0.7],
];

$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode < 200 || $httpCode >= 300) {
    error_log("Gemini API error ($httpCode): $curlError $response");
    json_error("Sorry, I'm having trouble responding right now. Please try again in a moment.", 502);
}

$data = json_decode($response, true);
$reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

if (!$reply) {
    json_error("Sorry, I couldn't come up with a response. Please try rephrasing that.", 502);
}

json_out(['success' => true, 'reply' => trim($reply)]);
