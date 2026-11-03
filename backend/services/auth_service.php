<?php
declare(strict_types=1);
require_once __DIR__ . '/care_service.php';

function ensurePasswordSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $users = tableName('puffsqueeze_users');
    if (!$pdo->query("SHOW COLUMNS FROM {$users} LIKE 'password_hash'")->fetch()) {
        try { $pdo->exec("ALTER TABLE {$users} ADD COLUMN password_hash VARCHAR(255) NULL"); }
        catch (PDOException $e) {
            // A parallel request may have already added the column.
            if (!$pdo->query("SHOW COLUMNS FROM {$users} LIKE 'password_hash'")->fetch()) throw $e;
        }
    }
    $ready = true;
}

function appPasswordHash(string $password): string
{
    // A prehash also preserves passwords over bcrypt's 72-byte input limit.
    return 'ps1:' . password_hash(base64_encode(hash('sha256', $password, true)), PASSWORD_DEFAULT);
}

function appPasswordVerify(string $password, string $hash): bool
{
    return str_starts_with($hash, 'ps1:')
        ? password_verify(base64_encode(hash('sha256', $password, true)), substr($hash, 4))
        : password_verify($password, $hash);
}

function migrateLegacyPasswords(PDO $pdo): int
{
    ensurePasswordSchema($pdo);
    $table = tableName('puffsqueeze_users');
    $count = 0;
    do {
        $rows = $pdo->query("SELECT id, plain_password, password_hash FROM {$table} WHERE plain_password<>'' LIMIT 100")->fetchAll();
        foreach ($rows as $row) {
            $hash = $row['password_hash'] ?: appPasswordHash((string)$row['plain_password']);
            $save = $pdo->prepare("UPDATE {$table} SET password_hash=?,plain_password='' WHERE id=? AND plain_password=?");
            $save->execute([$hash, $row['id'], $row['plain_password']]);
            $count += $save->rowCount();
        }
    } while (count($rows) === 100);
    return $count;
}

function authenticatedUserId(): int
{
    static $id = null;
    if ($id !== null) return $id;
    try {
        $pdo = db();
        ensureCareSchema($pdo);
        return $id = requireCareUser($pdo);
    } catch (Throwable $e) {
        error_log('Authentication: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Authentication unavailable'], 503);
    }
}
