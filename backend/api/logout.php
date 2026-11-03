<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/auth_service.php';
applyCors();
requireMethod('POST');
$userId = authenticatedUserId();
$token = substr($_SERVER['HTTP_AUTHORIZATION'] ?? '', 7);
$table = tableName('puffsqueeze_care_sessions');
$stmt = db()->prepare("DELETE FROM {$table} WHERE user_id=? AND token_hash=?");
$stmt->execute([$userId, hash('sha256', $token)]);
jsonResponse(['success' => true]);
