<?php
session_start();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/gemini_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$userMessage = trim($_POST['message'] ?? '');

if ($userMessage === '') {
    echo json_encode(['success' => false, 'message' => 'Please type a message.']);
    exit;
}
if (mb_strlen($userMessage) > 500) {
    echo json_encode(['success' => false, 'message' => 'Message is too long — please keep it under 500 characters.']);
    exit;
}

// --- Conversation history (for real multi-turn context — follow-ups like
// "recommend me one" only make sense with the previous turns attached) ---
$rawHistory = json_decode($_POST['history'] ?? '[]', true);
$history = [];
if (is_array($rawHistory)) {
    foreach (array_slice($rawHistory, -12) as $turn) { // cap: last 12 turns is plenty, keeps the request small
        $role = ($turn['role'] ?? '') === 'model' ? 'model' : 'user';
        $text = mb_substr(trim((string) ($turn['text'] ?? '')), 0, 500);
        if ($text !== '') {
            $history[] = ['role' => $role, 'text' => $text];
        }
    }
}

// All the user's own turns (history + this new message) — used below so
// keyword extraction for product context isn't blind to what was asked
// a message or two ago.
$allUserText = implode(' ', array_map(fn($t) => $t['text'], array_filter($history, fn($t) => $t['role'] === 'user')));
$allUserText .= ' ' . $userMessage;

// --- Basic per-session rate limiting: 20 messages per 10 minutes ---
// This is the main defense against an open chat endpoint turning into a
// surprise bill — cheap to add, easy to forget, so it goes in first.
$now = time();
$_SESSION['ai_chat_log'] = array_filter($_SESSION['ai_chat_log'] ?? [], fn($t) => $t > $now - 600);
if (count($_SESSION['ai_chat_log']) >= 20) {
    echo json_encode(['success' => false, 'message' => "You're sending messages a bit quickly — please wait a few minutes and try again."]);
    exit;
}
$_SESSION['ai_chat_log'][] = $now;

/**
 * ---------------------------------------------------------------
 * Lightweight context — no vector DB / embeddings, just a simple
 * keyword match against the message (same idea as the navbar search),
 * falling back to a general catalog sample if nothing matches so the
 * assistant always has *something* real to ground answers in.
 * ---------------------------------------------------------------
 */
$context = '';
try {
    // Extract meaningful keywords rather than LIKE-matching the entire
    // sentence (which almost never matches anything literal in the DB —
    // that was the actual bug causing vague, product-less replies).
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
            $conditions[] = "(p.name ILIKE :kw{$i} OR p.description ILIKE :kw{$i} OR c.name ILIKE :kw{$i})";
            $params[":kw{$i}"] = '%' . $kw . '%';
        }
        $productStmt = $pdo->prepare("
            SELECT p.name, p.description, p.price, c.name AS category
            FROM products p
            LEFT JOIN categories c ON c.category_id = p.category_id
            WHERE " . implode(' OR ', $conditions) . "
            LIMIT 8
        ");
        $productStmt->execute($params);
        $matchedProducts = $productStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Only fall back to a general sample when the message truly had no
    // extractable keywords (e.g. "hi", "thanks") — not just because the
    // keyword search came up empty for a specific request.
    if (empty($matchedProducts) && empty($keywords)) {
        $matchedProducts = $pdo->query("
            SELECT p.name, p.description, p.price, c.name AS category
            FROM products p
            LEFT JOIN categories c ON c.category_id = p.category_id
            ORDER BY p.created_at DESC
            LIMIT 8
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
    // Not fatal — the assistant can still reply generically without context.
}

$systemPrompt = "You are the shopping assistant for Celestia Gems, an online jewelry store. "
    . "Only help with questions about Celestia Gems products, orders, shipping, and jewelry care/advice. "
    . "Politely decline anything unrelated (general trivia, other companies, coding help, etc.) and steer the conversation back to how you can help them shop. "
    . "When the catalog list below has relevant items, name 1-2 of them specifically by name and price rather than replying in vague generalities like 'I'd love to help' — customers asking for a recommendation want an actual pick, not enthusiasm. "
    . "If the list says nothing matched, say so plainly and ask a clarifying question (e.g. what style, price range, or occasion) instead of stalling. "
    . "Never invent product names, prices, or policies that aren't in the context below. "
    . "If the customer is rude or hostile, stay calm and professional, don't mirror their tone, and keep offering to help — don't just repeat a generic greeting as if nothing happened. "
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
    'generationConfig' => [
        'maxOutputTokens' => 2048, // Increased to provide runway for thinking + text
        'temperature' => 0.7
    ],
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
    echo json_encode(['success' => false, 'message' => "Sorry, I'm having trouble responding right now. Please try again in a moment."]);
    exit;
}

$data = json_decode($response, true);
$reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

if (!$reply) {
    echo json_encode(['success' => false, 'message' => "Sorry, I couldn't come up with a response. Please try rephrasing that."]);
    exit;
}

echo json_encode(['success' => true, 'reply' => trim($reply)]);