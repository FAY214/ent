<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/auth_service.php';
require_once __DIR__ . '/../services/metrics_service.php';
require_once __DIR__ . '/../services/ai_service.php';

applyCors();
requireMethod('GET');

$userId = authenticatedUserId();
$lang = isset($_GET['lang']) ? strtolower((string)$_GET['lang']) : 'en';
if ($lang !== 'zh') {
    $lang = 'en';
}
if ($userId <= 0) {
    jsonResponse(['success' => false, 'message' => 'user_id is required'], 422);
}

try {
    $pdo = db();
    ensureUserExists($pdo, $userId);
    $dashboard = buildDashboard($pdo, $userId);

    $chatTable = tableName('puffsqueeze_chat_logs');
    $chatStmt = $pdo->prepare(
        "SELECT message_text, ai_reply_text, created_at
         FROM {$chatTable}
         WHERE user_id = :user_id
         ORDER BY created_at DESC
         LIMIT 10"
    );
    $chatStmt->execute(['user_id' => $userId]);
    $chatRows = $chatStmt->fetchAll();

    $recentQuestions = [];
    foreach ($chatRows as $row) {
        $q = trim((string)($row['message_text'] ?? ''));
        if ($q !== '') {
            $recentQuestions[] = $q;
        }
    }

    $stress = (float)$dashboard['current_stress'];
    $strikeLevel = 'low';
    if ($stress >= 75) {
        $strikeLevel = 'high';
    } elseif ($stress >= 50) {
        $strikeLevel = 'medium';
    }

    if ($lang === 'zh') {
        $prompt = sprintf(
            "你是一位温暖、自然、像真人一样的心理陪伴教练。请用中文生成一份简洁但有温度的报告。指标：today_squeezes=%d, total_squeezes=%d, current_stress=%.2f, weekly_average_squeezes=%.1f, stress_status=%s。结合用户近期提问和近期数据。固定输出4段并使用这些标题：1) 你今天的状态 2) 你最近在关心什么 3) 值得留意的信号与积极变化 4) 接下来8小时行动计划（3条具体可执行步骤）。语气要有人情味、具体、可执行，不要使用markdown符号。",
            (int)$dashboard['today_squeezes'],
            (int)$dashboard['total_squeezes'],
            $stress,
            (float)$dashboard['weekly_average_squeezes'],
            (string)$dashboard['stress_status']
        );
    } else {
        $prompt = sprintf(
            "You are a caring wellbeing coach writing in natural English. Create a short, warm, human-sounding report for this user. Metrics: today_squeezes=%d, total_squeezes=%d, current_stress=%.2f, weekly_average_squeezes=%.1f, stress_status=%s. Use recent questions and recent data. Output exactly 4 sections with these headings: 1) How You Seem Today 2) What You Have Been Asking About 3) Signals to Watch and Positive Signs 4) Next 8 Hours Plan (3 concrete steps). Keep it practical, empathetic, and vivid. Avoid robotic tone and avoid markdown symbols.",
            (int)$dashboard['today_squeezes'],
            (int)$dashboard['total_squeezes'],
            $stress,
            (float)$dashboard['weekly_average_squeezes'],
            (string)$dashboard['stress_status']
        );
    }

    $report = geminiChat([], $prompt, $strikeLevel, [
        'weekly' => $dashboard['weekly'],
        'recent_questions' => $recentQuestions,
        'recent_question_count' => count($recentQuestions),
    ]);

    jsonResponse([
        'success' => true,
        'data' => [
            'report' => $report,
            'dashboard' => $dashboard,
            'recent_questions' => $recentQuestions,
        ],
    ]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
