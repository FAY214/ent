<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/auth_service.php';
require_once __DIR__ . '/../services/ai_service.php';
require_once __DIR__ . '/../services/metrics_service.php';

applyCors();
requireMethod('POST');

$body = getJsonBody();
$userId = authenticatedUserId();
$message = trim((string)($body['message'] ?? ''));
$history = is_array($body['history'] ?? null) ? $body['history'] : [];
$strikeLevel = trim((string)($body['strike_level'] ?? 'unknown'));
$deviceData = is_array($body['device_data'] ?? null) ? $body['device_data'] : [];

if ($userId <= 0 || $message === '') {
    jsonResponse(['success' => false, 'message' => 'user_id and message are required'], 422);
}
if (mb_strlen($message) > 20000) jsonResponse(['success' => false, 'message' => 'Message is too long'], 422);

try {
    $pdo = db();
    ensureUserExists($pdo, $userId);
    $chatTable = tableName('puffsqueeze_chat_logs');
    $usageTable = tableName('puffsqueeze_usage_logs');
    $dashboard = buildDashboard($pdo, $userId);

    $mergedDeviceData = array_merge($deviceData, [
        'today_squeezes' => $dashboard['today_squeezes'],
        'current_stress' => $dashboard['current_stress'],
        'weekly_average_squeezes' => $dashboard['weekly_average_squeezes'],
    ]);
    $reply = geminiChat($history, $message, $strikeLevel, $mergedDeviceData);

    $chatStmt = $pdo->prepare(
        "INSERT INTO {$chatTable}
         (user_id, message_text, ai_reply_text, strike_level, device_data_json, created_at)
         VALUES
         (:user_id, :message_text, :ai_reply_text, :strike_level, :device_data_json, NOW())"
    );
    $chatStmt->execute([
        'user_id' => $userId,
        'message_text' => $message,
        'ai_reply_text' => $reply,
        'strike_level' => $strikeLevel,
        'device_data_json' => json_encode($mergedDeviceData, JSON_UNESCAPED_UNICODE),
    ]);
    $chatId = (int)$pdo->lastInsertId();

    $usageStmt = $pdo->prepare(
        "INSERT INTO {$usageTable}
         (user_id, action_type, action_detail, created_at)
         VALUES
         (:user_id, :action_type, :action_detail, NOW())"
    );
    $usageStmt->execute([
        'user_id' => $userId,
        'action_type' => 'ai_chat',
        'action_detail' => json_encode([
            'strike_level' => $strikeLevel,
            'history_count' => count($history),
        ], JSON_UNESCAPED_UNICODE),
    ]);

    jsonResponse([
        'success' => true,
        'data' => [
            'reply' => $reply,
            'chat_id' => $chatId,
        ],
    ]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
