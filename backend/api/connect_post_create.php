<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/auth_service.php';
require_once __DIR__ . '/../services/metrics_service.php';

applyCors();
requireMethod('POST');

$body = getJsonBody();
$userId = authenticatedUserId();
$contentText = trim((string)($body['content_text'] ?? ''));
$mediaType = trim((string)($body['media_type'] ?? 'none'));
$mediaMime = trim((string)($body['media_mime'] ?? ''));
$mediaData = (string)($body['media_data'] ?? '');
$mediaUrl = trim((string)($body['media_url'] ?? ''));

if ($userId <= 0) {
    jsonResponse(['success' => false, 'message' => 'user_id is required'], 422);
}

$allowedMediaTypes = ['none', 'image', 'video'];
if (!in_array($mediaType, $allowedMediaTypes, true)) {
    jsonResponse(['success' => false, 'message' => 'media_type must be none, image, or video'], 422);
}

if ($contentText === '' && $mediaType === 'none') {
    jsonResponse(['success' => false, 'message' => 'content_text or media is required'], 422);
}

if ($contentText !== '' && strlen($contentText) > 20000) {
    jsonResponse(['success' => false, 'message' => 'content_text is too long'], 422);
}

if (($mediaType === 'image' || $mediaType === 'video') && $mediaData === '' && $mediaUrl === '') {
    jsonResponse(['success' => false, 'message' => 'media_data or media_url is required for image/video'], 422);
}
if ($mediaUrl !== '' && (!filter_var($mediaUrl, FILTER_VALIDATE_URL) || strtolower((string)parse_url($mediaUrl, PHP_URL_SCHEME)) !== 'https')) {
    jsonResponse(['success' => false, 'message' => 'Media links must use HTTPS'], 422);
}
if ($mediaData !== '') {
    if (strlen($mediaData) > 9 * 1024 * 1024 || !preg_match('#^data:([a-z0-9.+/-]+);base64,(.*)$#s', $mediaData, $match)) {
        jsonResponse(['success' => false, 'message' => 'Invalid or oversized media'], 422);
    }
    $binary = base64_decode($match[2], true);
    $detected = $binary !== false ? (new finfo(FILEINFO_MIME_TYPE))->buffer($binary) : '';
    $allowed = $mediaType === 'image' ? ['image/jpeg','image/png','image/webp','image/gif'] : ['video/mp4','video/webm'];
    if ($binary === false || strlen($binary) > 6 * 1024 * 1024 || !in_array($detected, $allowed, true)) {
        jsonResponse(['success' => false, 'message' => 'Use JPEG, PNG, WebP, GIF, MP4 or WebM up to 6 MB'], 422);
    }
    $mediaMime = $detected;
}

try {
    $pdo = db();
    ensureUserExists($pdo, $userId);
    $postsTable = tableName('puffsqueeze_connect_posts');

    $stmt = $pdo->prepare(
        "INSERT INTO {$postsTable}
         (user_id, content_text, media_type, media_mime, media_data, media_url, visibility, published_at, created_at, updated_at)
         VALUES
         (:user_id, :content_text, :media_type, :media_mime, :media_data, :media_url, 'public', NOW(), NOW(), NOW())"
    );

    $stmt->execute([
        'user_id' => $userId,
        'content_text' => $contentText !== '' ? $contentText : null,
        'media_type' => $mediaType,
        'media_mime' => $mediaMime !== '' ? $mediaMime : null,
        'media_data' => $mediaData !== '' ? $mediaData : null,
        'media_url' => $mediaUrl !== '' ? $mediaUrl : null,
    ]);

    jsonResponse([
        'success' => true,
        'message' => 'Post created',
        'data' => [
            'id' => (int)$pdo->lastInsertId(),
        ],
    ], 201);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
