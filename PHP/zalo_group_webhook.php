<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$expectedToken = $zaloUserAccessToken ?? '';

if ($expectedToken !== '') {
    $expectedHeader = 'Bearer ' . $expectedToken;
    if (!hash_equals($expectedHeader, $authHeader)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized webhook token'], 401);
    }
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    jsonResponse(['success' => false, 'message' => 'Invalid JSON body'], 422);
}

$groupId = trim((string)($payload['group_id'] ?? ''));
$message = trim((string)($payload['message'] ?? ''));

if ($groupId === '' || $message === '') {
    jsonResponse(['success' => false, 'message' => 'Missing group_id or message'], 422);
}

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

$logLine = json_encode([
    'time' => date('Y-m-d H:i:s'),
    'group_id' => $groupId,
    'message' => $message,
], JSON_UNESCAPED_UNICODE) . PHP_EOL;

file_put_contents($logDir . '/zalo_group_webhook.log', $logLine, FILE_APPEND | LOCK_EX);

jsonResponse([
    'success' => true,
    'message' => 'Webhook received',
    'group_id' => $groupId,
]);
