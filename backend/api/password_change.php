<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/auth_service.php';
applyCors(); requireMethod('POST');
$userId = authenticatedUserId();
$body = getJsonBody();
$current = (string)($body['current_password'] ?? '');
$next = (string)($body['new_password'] ?? '');
if (mb_strlen($next) < 8 || strlen($next) > 1024 || $next === $current) jsonResponse(['success' => false, 'message' => 'Choose a different password with at least 8 characters'], 422);
try {
    $pdo = db(); ensurePasswordSchema($pdo);
    $users = tableName('puffsqueeze_users');
    $sessions = tableName('puffsqueeze_care_sessions');
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT password_hash,plain_password FROM {$users} WHERE id=? FOR UPDATE");
    $stmt->execute([$userId]); $user = $stmt->fetch();
    $valid = $user && ($user['password_hash'] ? appPasswordVerify($current, $user['password_hash']) : ($user['plain_password'] !== '' && hash_equals($user['plain_password'], $current)));
    if (!$valid) { $pdo->rollBack(); jsonResponse(['success' => false, 'message' => 'Current password is incorrect'], 422); }
    $stmt = $pdo->prepare("UPDATE {$users} SET password_hash=?,plain_password='',updated_at=NOW() WHERE id=?");
    $stmt->execute([appPasswordHash($next), $userId]);
    $stmt = $pdo->prepare("DELETE FROM {$sessions} WHERE user_id=?"); $stmt->execute([$userId]);
    $token = issueCareToken($pdo, $userId); $pdo->commit();
    jsonResponse(['success' => true, 'data' => ['care_token' => $token]]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Password change: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Password change unavailable'], 500);
}
