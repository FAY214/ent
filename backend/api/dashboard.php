<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/auth_service.php';
require_once __DIR__ . '/../services/metrics_service.php';

applyCors();
requireMethod('GET');

$userId = authenticatedUserId();
if ($userId <= 0) {
    jsonResponse(['success' => false, 'message' => 'user_id is required'], 422);
}

try {
    $pdo = db();
    ensureUserExists($pdo, $userId);
    $dashboard = buildDashboard($pdo, $userId);
    jsonResponse(['success' => true, 'data' => $dashboard]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
