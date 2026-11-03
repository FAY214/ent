<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/auth_service.php';
require_once __DIR__ . '/../services/metrics_service.php';

applyCors();
requireMethod('GET');
authenticatedUserId();

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if ($limit <= 0) {
    $limit = 50;
}
if ($limit > 100) {
    $limit = 100;
}

try {
    $pdo = db();
    $postsTable = tableName('puffsqueeze_connect_posts');
    $usersTable = tableName('puffsqueeze_users');
    $profilesTable = tableName('puffsqueeze_user_profiles');

    $sql = "SELECT p.id, p.user_id, p.content_text, p.media_type, p.media_mime, p.media_data, p.media_url, p.visibility, p.published_at,
                   u.display_name,
                   pr.avatar_url
            FROM {$postsTable} p
            INNER JOIN {$usersTable} u ON u.id = p.user_id
            LEFT JOIN {$profilesTable} pr ON pr.user_id = p.user_id
            WHERE p.visibility = 'public'
            ORDER BY p.published_at DESC, p.id DESC
            LIMIT {$limit}";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    $posts = [];
    foreach ($rows as $row) {
        $posts[] = [
            'id' => (string)$row['id'],
            'user_id' => (int)$row['user_id'],
            'content_text' => (string)($row['content_text'] ?? ''),
            'media_type' => (string)$row['media_type'],
            'media_mime' => $row['media_mime'] !== null ? (string)$row['media_mime'] : '',
            'media_data' => $row['media_data'] !== null ? (string)$row['media_data'] : '',
            'media_url' => $row['media_url'] !== null ? (string)$row['media_url'] : '',
            'visibility' => (string)$row['visibility'],
            'published_at' => (string)$row['published_at'],
            'author' => [
                'name' => (string)$row['display_name'],
                'email' => '',
                'avatar' => $row['avatar_url'] !== null && $row['avatar_url'] !== '' ? (string)$row['avatar_url'] : 'https://picsum.photos/seed/user1/100/100',
            ],
        ];
    }

    jsonResponse([
        'success' => true,
        'data' => [
            'posts' => $posts,
        ],
    ]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}

