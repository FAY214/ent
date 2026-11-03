<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../services/auth_service.php';
try {
    if (envValue('APP_ENV') === 'production') {
        $password = (string)envValue('DB_PASSWORD', envValue('DB_PASS', ''));
        if ($password === '' || str_starts_with($password, 'CHANGE_')) throw new RuntimeException('Set a unique database password before starting production');
    }
    $pdo = db();
    $prefix = (string)envValue('MOODLE_TABLE_PREFIX', '');
    if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) throw new RuntimeException('Invalid table prefix');
    foreach (['moodle_tables.sql', 'companion_care.sql', 'account_records.sql', 'voice_profiles.sql'] as $file) {
        $sql = file_get_contents(__DIR__ . '/../sql/' . $file);
        if ($sql === false) throw new RuntimeException('Missing schema: ' . $file);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (explode(';', str_replace('puffsqueeze_', $prefix . 'puffsqueeze_', $sql)) as $statement) {
            if (trim($statement) !== '') $pdo->exec($statement);
        }
    }
    $migrated = migrateLegacyPasswords($pdo);
    $sessions = tableName('puffsqueeze_care_sessions');
    $pdo->exec("DELETE FROM {$sessions} WHERE expires_at < UTC_TIMESTAMP()");
    echo "Database ready. Legacy password records migrated: {$migrated}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Database preparation failed: ' . $e->getMessage() . "\n");
    exit(1);
}
