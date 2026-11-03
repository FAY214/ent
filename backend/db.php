<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = envValue('DB_HOST', '127.0.0.1');
    $port = envValue('DB_PORT', '3306');
    $name = envValue('DB_NAME', 'moodle');
    $user = envValue('DB_USER', 'root');
    $pass = envValue('DB_PASSWORD', envValue('DB_PASS', ''));
    $sslMode = strtolower((string)envValue('DB_SSL_MODE', 'disabled'));
    $sslCa = (string)envValue('DB_SSL_CA', '');
    $useTls = !in_array($sslMode, ['', 'disabled', 'off'], true) || $sslCa !== '';
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if ($useTls) {
        if ($sslCa === '' && is_file('/etc/ssl/certs/ca-certificates.crt')) $sslCa = '/etc/ssl/certs/ca-certificates.crt';
        if (!is_readable($sslCa)) throw new RuntimeException('Set DB_SSL_CA to a readable CA bundle');
        $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    $pdo = new PDO($dsn, $user, $pass, $options);
    $timezone = (string)envValue('DB_TIMEZONE', '+08:00');
    if (!preg_match('/^[+-](?:0[0-9]|1[0-4]):[0-5][0-9]$/', $timezone)) throw new RuntimeException('Invalid DB_TIMEZONE');
    $pdo->exec('SET time_zone = ' . $pdo->quote($timezone));
    return $pdo;
}

function tableName(string $name): string
{
    $prefix = envValue('MOODLE_TABLE_PREFIX', '');
    if (!preg_match('/^[a-zA-Z0-9_]*$/', (string)$prefix)) throw new RuntimeException('Invalid table prefix');
    return $prefix . $name;
}
