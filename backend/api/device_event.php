<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/auth_service.php';
require_once __DIR__ . '/../services/metrics_service.php';

applyCors();
requireMethod('POST');

$body = getJsonBody();
$userId = authenticatedUserId();
$deviceId = trim((string)($body['device_id'] ?? 'M5StickCPlus'));
$strikeLevel = trim((string)($body['strike_level'] ?? 'unknown'));
$strikeValue = isset($body['strike_value']) ? (float)$body['strike_value'] : null;
$rawData = $body['raw_data'] ?? null;

if ($userId <= 0) {
    jsonResponse(['success' => false, 'message' => 'user_id is required'], 422);
}

try {
    $pdo = db();
    ensureUserExists($pdo, $userId);
    $table = tableName('puffsqueeze_device_events');

    $stmt = $pdo->prepare(
        "INSERT INTO {$table} (user_id, device_id, strike_level, strike_value, raw_data_json, event_time, created_at)
         VALUES (:user_id, :device_id, :strike_level, :strike_value, :raw_data_json, NOW(), NOW())"
    );

    $stmt->execute([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'strike_level' => $strikeLevel,
        'strike_value' => $strikeValue,
        'raw_data_json' => $rawData !== null ? json_encode($rawData, JSON_UNESCAPED_UNICODE) : null,
    ]);

    jsonResponse([
        'success' => true,
        'message' => 'Device event stored',
        'data' => ['id' => (int)$pdo->lastInsertId()],
    ], 201);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
