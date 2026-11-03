<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/auth_service.php';
require_once __DIR__ . '/../services/metrics_service.php';

applyCors();
requireMethod('POST');

$body = getJsonBody();
$userId = authenticatedUserId();
$stressIndex = isset($body['stress_index']) ? (float)$body['stress_index'] : null;
$source = trim((string)($body['source'] ?? 'device'));

if ($userId <= 0 || $stressIndex === null) {
    jsonResponse(['success' => false, 'message' => 'user_id and stress_index are required'], 422);
}

try {
    $pdo = db();
    ensureUserExists($pdo, $userId);
    $stressTable = tableName('puffsqueeze_stress_records');
    $usageTable = tableName('puffsqueeze_usage_logs');
    $cleanStress = round(clamp($stressIndex, 0, 100), 2);

    $stmt = $pdo->prepare(
        "INSERT INTO {$stressTable}
         (user_id, stress_index, source, event_time, created_at)
         VALUES
         (:user_id, :stress_index, :source, NOW(), NOW())"
    );
    $stmt->execute([
        'user_id' => $userId,
        'stress_index' => $cleanStress,
        'source' => $source === '' ? 'device' : $source,
    ]);

    $usageStmt = $pdo->prepare(
        "INSERT INTO {$usageTable}
         (user_id, action_type, action_detail, created_at)
         VALUES
         (:user_id, :action_type, :action_detail, NOW())"
    );
    $usageStmt->execute([
        'user_id' => $userId,
        'action_type' => 'stress_record',
        'action_detail' => json_encode(['stress_index' => $cleanStress, 'source' => $source], JSON_UNESCAPED_UNICODE),
    ]);

    $dashboard = buildDashboard($pdo, $userId);
    jsonResponse([
        'success' => true,
        'message' => 'Stress record stored',
        'data' => ['dashboard' => $dashboard],
    ], 201);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
