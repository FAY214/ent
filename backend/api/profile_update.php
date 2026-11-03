<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/auth_service.php';
require_once __DIR__ . '/../services/metrics_service.php';

applyCors();
requireMethod('POST');

$body = getJsonBody();
$userId = authenticatedUserId();
$displayName = trim((string)($body['display_name'] ?? ''));
$ageRaw = $body['age'] ?? null;
$gender = trim((string)($body['gender'] ?? ''));
$signature = trim((string)($body['signature'] ?? ''));

if ($userId <= 0) {
    jsonResponse(['success' => false, 'message' => 'user_id is required'], 422);
}

$age = null;
if ($ageRaw !== null && $ageRaw !== '') {
    $age = (int)$ageRaw;
    if ($age < 1 || $age > 120) {
        jsonResponse(['success' => false, 'message' => 'age must be between 1 and 120'], 422);
    }
}

if (strlen($gender) > 20) {
    jsonResponse(['success' => false, 'message' => 'gender is too long'], 422);
}

if (strlen($signature) > 255) {
    jsonResponse(['success' => false, 'message' => 'signature is too long'], 422);
}

try {
    $pdo = db();
    ensureUserExists($pdo, $userId);

    $userTable = tableName('puffsqueeze_users');
    $profileTable = tableName('puffsqueeze_user_profiles');

    if ($displayName !== '') {
        $updateUser = $pdo->prepare("UPDATE {$userTable} SET display_name = :display_name, updated_at = NOW() WHERE id = :user_id");
        $updateUser->execute([
            'display_name' => $displayName,
            'user_id' => $userId,
        ]);
    }

    $upsert = $pdo->prepare(
        "INSERT INTO {$profileTable}
         (user_id, age, gender, signature, created_at, updated_at)
         VALUES
         (:user_id, :age, :gender, :signature, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           age = VALUES(age),
           gender = VALUES(gender),
           signature = VALUES(signature),
           updated_at = NOW()"
    );
    $upsert->execute([
        'user_id' => $userId,
        'age' => $age,
        'gender' => $gender,
        'signature' => $signature,
    ]);

    jsonResponse([
        'success' => true,
        'message' => 'Profile updated',
    ]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}

