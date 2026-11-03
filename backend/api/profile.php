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

    $userTable = tableName('puffsqueeze_users');
    $profileTable = tableName('puffsqueeze_user_profiles');

    $stmt = $pdo->prepare(
        "SELECT u.id, u.email, u.display_name, p.age, p.gender, p.signature, p.avatar_url
         FROM {$userTable} u
         LEFT JOIN {$profileTable} p ON p.user_id = u.id
         WHERE u.id = :user_id
         LIMIT 1"
    );
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'User not found'], 404);
    }

    jsonResponse([
        'success' => true,
        'data' => [
            'id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'display_name' => (string)$row['display_name'],
            'age' => $row['age'] !== null ? (int)$row['age'] : null,
            'gender' => $row['gender'] !== null ? (string)$row['gender'] : '',
            'signature' => $row['signature'] !== null ? (string)$row['signature'] : '',
            'avatar_url' => $row['avatar_url'] !== null ? (string)$row['avatar_url'] : '',
        ],
    ]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}

