<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../services/auth_service.php';

applyCors();
requireMethod('POST');

$body = getJsonBody();
$email = trim((string)($body['email'] ?? ''));
$displayName = trim((string)($body['display_name'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($email === '' || $displayName === '' || $password === '') {
    jsonResponse(['success' => false, 'message' => 'email, display_name, password are required'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191 || mb_strlen($displayName) > 120
    || mb_strlen($password) < 8 || strlen($password) > 1024) {
    jsonResponse(['success' => false, 'message' => 'Use a valid email, name up to 120 characters and password of at least 8 characters'], 422);
}

try {
    $pdo = db();
    ensurePasswordSchema($pdo);
    $table = tableName('puffsqueeze_users');

    $existsStmt = $pdo->prepare("SELECT id FROM {$table} WHERE email = :email LIMIT 1");
    $existsStmt->execute(['email' => $email]);
    $exists = $existsStmt->fetch();
    if ($exists) {
        jsonResponse(['success' => false, 'message' => 'Email already exists'], 409);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO {$table} (email, display_name, password_hash, plain_password, created_at, updated_at)
         VALUES (:email, :display_name, :password_hash, '', NOW(), NOW())"
    );
    $stmt->execute([
        'email' => $email,
        'display_name' => $displayName,
        'password_hash' => appPasswordHash($password),
    ]);

    $id = (int)$pdo->lastInsertId();
    jsonResponse([
        'success' => true,
        'message' => 'User registered',
        'data' => [
            'id' => $id,
            'email' => $email,
            'display_name' => $displayName,
            'care_token' => issueCareToken($pdo, $id),
        ],
    ], 201);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}

