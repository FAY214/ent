<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services/auth_service.php';

applyCors();
requireMethod('POST');

$body = getJsonBody();
$email = trim((string)($body['email'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($email === '' || $password === '') {
    jsonResponse(['success' => false, 'message' => 'email and password are required'], 422);
}

try {
    $pdo = db();
    ensurePasswordSchema($pdo);
    $table = tableName('puffsqueeze_users');

    $stmt = $pdo->prepare("SELECT id, email, display_name, password_hash, plain_password FROM {$table} WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    $valid = $user && ($user['password_hash']
        ? appPasswordVerify($password, (string)$user['password_hash'])
        : ((string)$user['plain_password'] !== '' && hash_equals((string)$user['plain_password'], $password)));
    if (!$valid) {
        jsonResponse(['success' => false, 'message' => 'Invalid credentials'], 401);
    }
    $hash = (string)$user['password_hash'];
    if (!$hash || !str_starts_with($hash, 'ps1:') || password_needs_rehash(substr($hash, 4), PASSWORD_DEFAULT) || $user['plain_password'] !== '') {
        $save = $pdo->prepare("UPDATE {$table} SET password_hash=?,plain_password='' WHERE id=?");
        $save->execute([appPasswordHash($password), $user['id']]);
    }

    jsonResponse([
        'success' => true,
        'message' => 'Login successful',
        'data' => [
            'id' => (int)$user['id'],
            'email' => (string)$user['email'],
            'display_name' => (string)$user['display_name'],
            'care_token' => issueCareToken($pdo, (int)$user['id']),
        ],
    ]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}

