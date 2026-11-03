<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/auth_service.php';
require_once __DIR__ . '/../services/metrics_service.php';
require_once __DIR__ . '/../services/ai_service.php';

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
    $stress = (float)$dashboard['current_stress'];
    $strikeLevel = $stress >= 75 ? 'high' : ($stress >= 50 ? 'medium' : 'low');

    $summary = sprintf(
        "User metrics: today_squeezes=%d, total_squeezes=%d, current_stress=%.2f, weekly_average_squeezes=%.1f, stress_status=%s. Give one concise, practical suggestion in Chinese.",
        (int)$dashboard['today_squeezes'],
        (int)$dashboard['total_squeezes'],
        $stress,
        (float)$dashboard['weekly_average_squeezes'],
        (string)$dashboard['stress_status']
    );

    $suggestion = geminiChat([], $summary, $strikeLevel, [
        'weekly' => $dashboard['weekly'],
    ]);

    jsonResponse([
        'success' => true,
        'data' => [
            'suggestion' => $suggestion,
            'dashboard' => $dashboard,
        ],
    ]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
