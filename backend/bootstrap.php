<?php
declare(strict_types=1);

function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        // Container/server variables take precedence over an old local .env.
        if (getenv($key) !== false) continue;
        $value = trim($parts[1]);
        $value = trim($value, "\"'");

        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

loadEnv(__DIR__ . '/.env');

function envValue(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string) $value;
}

date_default_timezone_set((string)envValue('APP_TIMEZONE', 'Asia/Shanghai'));

function jsonResponse(array $data, int $status = 200): void
{
    if ($status >= 500 && isset($data['message'])) {
        error_log('PuffSqueeze API: ' . (string)$data['message']);
        $data['message'] = 'Server temporarily unavailable. Please try again.';
    }
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function applyCors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = array_filter(array_map('trim', explode(',', (string)envValue('ALLOWED_ORIGIN', ''))));
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}

function getJsonBody(): array
{
    static $body = null;
    if ($body !== null) return $body;
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 12 * 1024 * 1024) {
        jsonResponse(['success' => false, 'message' => 'Request exceeds 12 MB'], 413);
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        jsonResponse(['success' => false, 'message' => 'Invalid JSON body'], 400);
    }

    $body = $data;
    return $body;
}

function requireMethod(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        applyCors();
        http_response_code(204);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        applyCors();
        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
}


