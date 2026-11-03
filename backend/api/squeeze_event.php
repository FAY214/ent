<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/auth_service.php';
require_once __DIR__ . '/../services/metrics_service.php';

applyCors();
requireMethod('POST');

$body = getJsonBody();
$userId = authenticatedUserId();
$deviceId = trim((string)($body['device_id'] ?? 'M5StickCPlus'));
$strikeValue = isset($body['strike_value']) ? (float)$body['strike_value'] : 50.0;
$rawData = $body['raw_data'] ?? null;

if ($userId <= 0) {
    jsonResponse(['success' => false, 'message' => 'user_id is required'], 422);
}

try {
    $pdo = db();
    ensureUserExists($pdo, $userId);
    $deviceTable = tableName('puffsqueeze_device_events');
    $usageTable = tableName('puffsqueeze_usage_logs');
    $stressTable = tableName('puffsqueeze_stress_records');

    $strikeLevel = strikeLevelFromValue($strikeValue);

    $deviceStmt = $pdo->prepare(
        "INSERT INTO {$deviceTable}
         (user_id, device_id, strike_level, strike_value, raw_data_json, event_time, created_at)
         VALUES
         (:user_id, :device_id, :strike_level, :strike_value, :raw_data_json, NOW(), NOW())"
    );
    $deviceStmt->execute([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'strike_level' => $strikeLevel,
        'strike_value' => $strikeValue,
        'raw_data_json' => $rawData !== null ? json_encode($rawData, JSON_UNESCAPED_UNICODE) : null,
    ]);

    $usageStmt = $pdo->prepare(
        "INSERT INTO {$usageTable}
         (user_id, action_type, action_detail, created_at)
         VALUES
         (:user_id, :action_type, :action_detail, NOW())"
    );
    $usageStmt->execute([
        'user_id' => $userId,
        'action_type' => 'squeeze',
        'action_detail' => json_encode([
            'strike_value' => $strikeValue,
            'strike_level' => $strikeLevel,
            'device_id' => $deviceId,
        ], JSON_UNESCAPED_UNICODE),
    ]);

    $stressIndex = computeRollingStressIndex($pdo, $userId);
    $stressStmt = $pdo->prepare(
        "INSERT INTO {$stressTable}
         (user_id, stress_index, source, event_time, created_at)
         VALUES
         (:user_id, :stress_index, :source, NOW(), NOW())"
    );
    $stressStmt->execute([
        'user_id' => $userId,
        'stress_index' => $stressIndex,
        'source' => 'from_squeeze_rolling_avg',
    ]);

    $dashboard = buildDashboard($pdo, $userId);

    jsonResponse([
        'success' => true,
        'message' => 'Squeeze event stored',
        'data' => [
            'strike_level' => $strikeLevel,
            'stress_index' => $stressIndex,
            'dashboard' => $dashboard,
        ],
    ], 201);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
