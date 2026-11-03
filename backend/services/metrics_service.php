<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function clamp(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

function strikeLevelFromValue(float $strikeValue): string
{
    if ($strikeValue < 35) {
        return 'low';
    }
    if ($strikeValue < 70) {
        return 'medium';
    }
    return 'high';
}

function computeRollingStressIndex(PDO $pdo, int $userId): float
{
    $deviceTable = tableName('puffsqueeze_device_events');
    $stmt = $pdo->prepare(
        "SELECT AVG(strike_value) AS avg_strike
         FROM (
            SELECT strike_value
            FROM {$deviceTable}
            WHERE user_id = :user_id AND strike_value IS NOT NULL
            ORDER BY event_time DESC
            LIMIT 20
         ) t"
    );
    $stmt->execute(['user_id' => $userId]);
    $avg = $stmt->fetchColumn();
    $avgFloat = is_numeric($avg) ? (float)$avg : 50.0;
    return round(clamp($avgFloat, 0, 100), 2);
}

function statusFromStress(float $stress): string
{
    if ($stress >= 75) {
        return 'High Alert';
    }
    if ($stress >= 50) {
        return 'Moderate';
    }
    return 'Stable / Optimal';
}

function weeklySkeleton(): array
{
    $days = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = new DateTimeImmutable("-{$i} day");
        $key = $date->format('Y-m-d');
        $days[$key] = [
            'date' => $key,
            'day' => $date->format('D'),
            'count' => 0,
            'stress' => 0,
        ];
    }
    return $days;
}

function buildDashboard(PDO $pdo, int $userId): array
{
    $deviceTable = tableName('puffsqueeze_device_events');
    $stressTable = tableName('puffsqueeze_stress_records');

    $todaySqueezesStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM {$deviceTable}
         WHERE user_id = :user_id AND DATE(event_time) = CURDATE()"
    );
    $todaySqueezesStmt->execute(['user_id' => $userId]);
    $todaySqueezes = (int)$todaySqueezesStmt->fetchColumn();

    $totalSqueezesStmt = $pdo->prepare("SELECT COUNT(*) FROM {$deviceTable} WHERE user_id = :user_id");
    $totalSqueezesStmt->execute(['user_id' => $userId]);
    $totalSqueezes = (int)$totalSqueezesStmt->fetchColumn();

    $currentStressStmt = $pdo->prepare(
        "SELECT stress_index FROM {$stressTable}
         WHERE user_id = :user_id
         ORDER BY event_time DESC
         LIMIT 1"
    );
    $currentStressStmt->execute(['user_id' => $userId]);
    $latestStress = $currentStressStmt->fetchColumn();
    $currentStress = is_numeric($latestStress) ? (float)$latestStress : 0.0;

    $weekly = weeklySkeleton();

    $squeezeWeeklyStmt = $pdo->prepare(
        "SELECT DATE(event_time) AS dt, COUNT(*) AS cnt
         FROM {$deviceTable}
         WHERE user_id = :user_id AND event_time >= DATE_SUB(NOW(), INTERVAL 6 DAY)
         GROUP BY DATE(event_time)"
    );
    $squeezeWeeklyStmt->execute(['user_id' => $userId]);
    foreach ($squeezeWeeklyStmt->fetchAll() as $row) {
        $dt = (string)$row['dt'];
        if (isset($weekly[$dt])) {
            $weekly[$dt]['count'] = (int)$row['cnt'];
        }
    }

    $stressWeeklyStmt = $pdo->prepare(
        "SELECT DATE(event_time) AS dt, AVG(stress_index) AS avg_stress
         FROM {$stressTable}
         WHERE user_id = :user_id AND event_time >= DATE_SUB(NOW(), INTERVAL 6 DAY)
         GROUP BY DATE(event_time)"
    );
    $stressWeeklyStmt->execute(['user_id' => $userId]);
    foreach ($stressWeeklyStmt->fetchAll() as $row) {
        $dt = (string)$row['dt'];
        if (isset($weekly[$dt])) {
            $weekly[$dt]['stress'] = round((float)$row['avg_stress'], 2);
        }
    }

    $weeklyRows = array_values($weekly);
    $weekTotal = array_sum(array_column($weeklyRows, 'count'));
    $weeklyAverage = round($weekTotal / 7, 1);

    return [
        'today_squeezes' => $todaySqueezes,
        'total_squeezes' => $totalSqueezes,
        'current_stress' => round($currentStress, 2),
        'stress_status' => statusFromStress($currentStress),
        'weekly_average_squeezes' => $weeklyAverage,
        'weekly' => $weeklyRows,
    ];
}

function ensureUserExists(PDO $pdo, int $userId): void
{
    $userTable = tableName('puffsqueeze_users');
    $checkStmt = $pdo->prepare("SELECT id FROM {$userTable} WHERE id = :id LIMIT 1");
    $checkStmt->execute(['id' => $userId]);
    if ($checkStmt->fetch()) {
        return;
    }

    jsonResponse(['success' => false, 'message' => 'Account not found'], 404);
}
