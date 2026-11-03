<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/auth_service.php';
require_once __DIR__ . '/../services/metrics_service.php';

applyCors();
requireMethod('POST');

$body = getJsonBody();
$userId = authenticatedUserId();
$actionType = trim((string)($body['action_type'] ?? ''));
$actionDetail = $body['action_detail'] ?? null;

if ($userId <= 0 || $actionType === '') {
    jsonResponse(['success' => false, 'message' => 'user_id and action_type are required'], 422);
}

try {
    $pdo = db();
    ensureUserExists($pdo, $userId);
    $table = tableName('puffsqueeze_usage_logs');

    $stmt = $pdo->prepare(
        "INSERT INTO {$table} (user_id, action_type, action_detail, created_at)
         VALUES (:user_id, :action_type, :action_detail, NOW())"
    );
    $stmt->execute([
        'user_id' => $userId,
        'action_type' => $actionType,
        'action_detail' => $actionDetail !== null ? json_encode($actionDetail, JSON_UNESCAPED_UNICODE) : null,
    ]);

    jsonResponse([
        'success' => true,
        'message' => 'Usage stored',
        'data' => ['id' => (int)$pdo->lastInsertId()],
    ], 201);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
