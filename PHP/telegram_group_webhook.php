<?php
declare(strict_types=1);

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
    jsonResponse([
        'success' => true,
        'message' => 'Telegram notification endpoint is ready',
        'expected_method' => 'POST',
        'required_body' => ['message' => 'Text to send'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    jsonResponse(['success' => false, 'message' => 'Invalid JSON body'], 422);
}

$message = trim((string)($payload['message'] ?? $payload['text'] ?? ''));
if ($message === '') {
    jsonResponse(['success' => false, 'message' => 'Missing message'], 422);
}

$result = sendTelegramTextNotification($message);
$statusCode = !empty($result['sent']) ? 200 : 500;

if (empty($result['enabled'])) {
    $statusCode = 422;
    $result['message'] = 'Missing TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID';
}

jsonResponse($result, $statusCode);
