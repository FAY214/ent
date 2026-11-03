<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

applyCors();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

jsonResponse([
    'success' => true,
    'message' => 'PHP backend is running',
    'time' => date('c'),
]);

