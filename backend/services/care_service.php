<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

const CARE_KINDS = ['bird', 'cat', 'dog', 'pig', 'penguin', 'owl', 'seagull', 'dolphin'];

function importOfflineCareBaseline(PDO $pdo, int $userId, string $kind, int $xp): int
{
    if (!in_array($kind, CARE_KINDS, true)) throw new InvalidArgumentException('Unknown companion');
    $table = tableName('puffsqueeze_pets');
    $members = tableName('puffsqueeze_pet_members');
    $stmt = $pdo->prepare("INSERT IGNORE INTO {$table} (owner_id,kind,updated_at,created_at) VALUES (?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $stmt->execute([$userId, $kind]);
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE owner_id=? AND kind=? FOR UPDATE");
    $stmt->execute([$userId, $kind]); $petId = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("INSERT IGNORE INTO {$members} (pet_id,user_id,joined_at) VALUES (?,?,UTC_TIMESTAMP())");
    $stmt->execute([$petId, $userId]);
    $stmt = $pdo->prepare("UPDATE {$table} SET xp=GREATEST(xp,?) WHERE id=?");
    $stmt->execute([min(1000000, max(0, $xp)), $petId]);
    return $petId;
}

function applyOfflineCareRecord(PDO $pdo, int $userId, array $data, string $eventTime): void
{
    $rewards = ['feed' => 10, 'pet' => 5, 'wash' => 8, 'play' => 12, 'rest' => 6, 'breathe' => 15];
    $action = (string)($data['action'] ?? ''); $kind = (string)($data['kind'] ?? '');
    $requestId = (string)($data['request_id'] ?? ''); $food = (string)($data['food'] ?? 'seeds');
    if (!isset($rewards[$action]) || !in_array($kind, CARE_KINDS, true) || !preg_match('/^[a-f0-9]{32}$/', $requestId)
        || !in_array($food, ['seeds','berry','biscuit'], true)) throw new InvalidArgumentException('Invalid offline care');
    $events = tableName('puffsqueeze_pet_events');
    $check = $pdo->prepare("SELECT id FROM {$events} WHERE user_id=? AND request_id=?");
    $check->execute([$userId, $requestId]); if ($check->fetchColumn() !== false) return;
    $petId = importOfflineCareBaseline($pdo, $userId, $kind, (int)($data['baseline_xp'] ?? 0));
    $pet = careNeeds(carePet($pdo, $petId, $userId, true));
    $changes = ['feed' => ['fullness' => 18, 'happiness' => 4, 'cleanliness' => -2], 'pet' => ['happiness' => 12],
        'wash' => ['cleanliness' => 20, 'happiness' => 4], 'play' => ['happiness' => 18, 'energy' => -6, 'fullness' => -3],
        'rest' => ['energy' => 22, 'happiness' => 3], 'breathe' => ['happiness' => 15, 'energy' => 8]];
    foreach ($changes[$action] as $key => $change) $pet[$key] = max(25, min(100, $pet[$key] + $change));
    $table = tableName('puffsqueeze_pets');
    $stmt = $pdo->prepare("UPDATE {$table} SET xp=LEAST(1000000,xp+?),happiness=?,fullness=?,cleanliness=?,energy=?,updated_at=UTC_TIMESTAMP() WHERE id=?");
    $stmt->execute([$rewards[$action],$pet['happiness'],$pet['fullness'],$pet['cleanliness'],$pet['energy'],$petId]);
    $stmt = $pdo->prepare("INSERT INTO {$events} (pet_id,user_id,request_id,action,food,xp_gain,created_at) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$petId,$userId,$requestId,$action,$action === 'feed' ? $food : '',$rewards[$action],$eventTime]);
}

function ensureCareSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $schema = file_get_contents(__DIR__ . '/../sql/companion_care.sql');
    if ($schema === false) throw new RuntimeException('Care schema is unavailable');
    $prefix = (string)envValue('MOODLE_TABLE_PREFIX', '');
    if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) throw new RuntimeException('Invalid table prefix');
    $schema = preg_replace('/^\s*--.*$/m', '', $schema);
    $schema = str_replace('puffsqueeze_', $prefix . 'puffsqueeze_', $schema);
    foreach (explode(';', $schema) as $statement) {
        if (trim($statement) !== '') $pdo->exec($statement);
    }
    $ready = true;
}

function issueCareToken(PDO $pdo, int $userId): string
{
    ensureCareSchema($pdo);
    $token = bin2hex(random_bytes(32));
    $table = tableName('puffsqueeze_care_sessions');
    $stmt = $pdo->prepare("INSERT INTO {$table} (token_hash, user_id, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([hash('sha256', $token), $userId, gmdate('Y-m-d H:i:s', time() + 30 * 86400)]);
    return $token;
}

function requireCareUser(PDO $pdo): int
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/', $header, $match)) {
        jsonResponse(['success' => false, 'message' => 'Please sign in again to sync companion care', 'code' => 'CARE_LOGIN'], 401);
    }
    $table = tableName('puffsqueeze_care_sessions');
    $stmt = $pdo->prepare("SELECT user_id FROM {$table} WHERE token_hash = ? AND expires_at > ?");
    $stmt->execute([hash('sha256', $match[1]), gmdate('Y-m-d H:i:s')]);
    $id = $stmt->fetchColumn();
    if (!$id) jsonResponse(['success' => false, 'message' => 'Care session expired. Please sign in again', 'code' => 'CARE_LOGIN'], 401);
    return (int)$id;
}

function carePet(PDO $pdo, int $petId, int $userId, bool $lock = false): array
{
    $pets = tableName('puffsqueeze_pets');
    $members = tableName('puffsqueeze_pet_members');
    $stmt = $pdo->prepare("SELECT p.* FROM {$pets} p INNER JOIN {$members} m ON m.pet_id=p.id WHERE p.id=? AND m.user_id=?" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$petId, $userId]);
    $pet = $stmt->fetch();
    if (!$pet) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'You are not a member of this companion home', 'code' => 'CARE_MEMBER'], 403);
    }
    return $pet;
}

function careNeeds(array $pet): array
{
    $minutes = min(720, max(0, (time() - strtotime($pet['updated_at'] . ' UTC')) / 60));
    foreach (['fullness' => .03, 'happiness' => .015, 'cleanliness' => .02, 'energy' => .02] as $key => $rate) {
        $pet[$key] = round(max(25, min(100, (float)$pet[$key] - $minutes * $rate)), 1);
    }
    return $pet;
}

function carePetData(PDO $pdo, array $pet): array
{
    $pet = careNeeds($pet);
    $members = tableName('puffsqueeze_pet_members');
    $users = tableName('puffsqueeze_users');
    $events = tableName('puffsqueeze_pet_events');
    $stmt = $pdo->prepare("SELECT u.id, u.display_name FROM {$members} m JOIN {$users} u ON u.id=m.user_id WHERE m.pet_id=? ORDER BY m.joined_at, u.id");
    $stmt->execute([$pet['id']]);
    $people = array_map(fn($m) => ['id' => (int)$m['id'], 'name' => $m['display_name']], $stmt->fetchAll());
    $stmt = $pdo->prepare("SELECT e.id, e.user_id, e.action, e.food, e.xp_gain, e.created_at, u.display_name FROM {$events} e JOIN {$users} u ON u.id=e.user_id WHERE e.pet_id=? ORDER BY e.id DESC LIMIT 20");
    $stmt->execute([$pet['id']]);
    $history = array_map(fn($e) => ['id' => (string)$e['id'], 'user_id' => (int)$e['user_id'], 'name' => $e['display_name'], 'action' => $e['action'], 'food' => $e['food'], 'xp_gain' => (int)$e['xp_gain'], 'created_at' => str_replace(' ', 'T', $e['created_at']) . 'Z'], $stmt->fetchAll());
    return ['id' => (string)$pet['id'], 'owner_id' => (int)$pet['owner_id'], 'kind' => $pet['kind'], 'name' => $pet['name'], 'xp' => (int)$pet['xp'], 'happiness' => $pet['happiness'], 'fullness' => $pet['fullness'], 'cleanliness' => $pet['cleanliness'], 'energy' => $pet['energy'], 'members' => $people, 'events' => $history, 'updated_at' => str_replace(' ', 'T', $pet['updated_at']) . 'Z'];
}
