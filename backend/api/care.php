<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/care_service.php';
applyCors();
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) requireMethod('POST');

try {
    $pdo = db();
    ensureCareSchema($pdo);
    $userId = requireCareUser($pdo);
    $pets = tableName('puffsqueeze_pets');
    $members = tableName('puffsqueeze_pet_members');
    $events = tableName('puffsqueeze_pet_events');
    $invites = tableName('puffsqueeze_pet_invites');
    $now = gmdate('Y-m-d H:i:s');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // One independently growing companion per species, plus joined friends' homes.
        $stmt = $pdo->prepare("INSERT IGNORE INTO {$pets} (owner_id, kind, updated_at, created_at) VALUES (?, ?, ?, ?)");
        foreach (CARE_KINDS as $kind) $stmt->execute([$userId, $kind, $now, $now]);
        $stmt = $pdo->prepare("INSERT IGNORE INTO {$members} (pet_id, user_id, joined_at) SELECT id, owner_id, created_at FROM {$pets} WHERE owner_id=?");
        $stmt->execute([$userId]);
        $stmt = $pdo->prepare("SELECT p.* FROM {$pets} p JOIN {$members} m ON m.pet_id=p.id WHERE m.user_id=? ORDER BY p.owner_id=?, p.id");
        $stmt->execute([$userId, $userId]);
        $list = array_map(fn($pet) => carePetData($pdo, $pet), $stmt->fetchAll());
        jsonResponse(['success' => true, 'data' => ['pets' => $list]]);
    }

    $body = getJsonBody();
    $op = (string)($body['op'] ?? '');
    if ($op === 'join') {
        $code = strtoupper(preg_replace('/[\s-]/', '', (string)($body['code'] ?? '')));
        if (!preg_match('/^[A-F0-9]{12}$/', $code)) jsonResponse(['success' => false, 'message' => 'Enter a valid 12-character invitation code', 'code' => 'CARE_CODE'], 422);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT p.* FROM {$pets} p JOIN {$invites} i ON i.pet_id=p.id WHERE i.code_hash=? AND i.expires_at>? FOR UPDATE");
        $stmt->execute([hash('sha256', $code), $now]);
        $pet = $stmt->fetch();
        if (!$pet) { $pdo->rollBack(); jsonResponse(['success' => false, 'message' => 'Invitation expired or not found', 'code' => 'CARE_CODE'], 404); }
        $stmt = $pdo->prepare("SELECT user_id FROM {$members} WHERE pet_id=?");
        $stmt->execute([$pet['id']]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (count($ids) >= 6 && !in_array($userId, $ids, true)) { $pdo->rollBack(); jsonResponse(['success' => false, 'message' => 'This home already has six caregivers', 'code' => 'CARE_FULL'], 409); }
        $stmt = $pdo->prepare("INSERT IGNORE INTO {$members} (pet_id, user_id, joined_at) VALUES (?, ?, ?)");
        $stmt->execute([$pet['id'], $userId, $now]);
        $pdo->commit();
        jsonResponse(['success' => true, 'data' => ['pet' => carePetData($pdo, $pet)]]);
    }

    $petId = (int)($body['pet_id'] ?? 0);
    $pdo->beginTransaction();
    $pet = carePet($pdo, $petId, $userId, true);
    $owner = (int)$pet['owner_id'] === $userId;

    if (in_array($op, ['invite', 'revoke', 'rename', 'remove'], true)) {
        if (!$owner) { $pdo->rollBack(); jsonResponse(['success' => false, 'message' => 'Only the home owner can do this', 'code' => 'CARE_OWNER'], 403); }
        if ($op === 'invite' || $op === 'revoke') {
            $stmt = $pdo->prepare("DELETE FROM {$invites} WHERE pet_id=?"); $stmt->execute([$petId]);
            $code = '';
            if ($op === 'invite') {
                $code = strtoupper(bin2hex(random_bytes(6)));
                $stmt = $pdo->prepare("INSERT INTO {$invites} (code_hash, pet_id, expires_at) VALUES (?, ?, ?)");
                $stmt->execute([hash('sha256', $code), $petId, gmdate('Y-m-d H:i:s', time() + 7 * 86400)]);
            }
            $pdo->commit();
            jsonResponse(['success' => true, 'data' => ['code' => $code]]);
        }
        if ($op === 'rename') {
            $name = trim((string)($body['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 30) { $pdo->rollBack(); jsonResponse(['success' => false, 'message' => 'Choose a name of 1–30 characters'], 422); }
            $stmt = $pdo->prepare("UPDATE {$pets} SET name=? WHERE id=?"); $stmt->execute([$name, $petId]);
            $pet['name'] = $name;
        } else {
            $removeId = (int)($body['member_id'] ?? 0);
            if ($removeId === $userId) { $pdo->rollBack(); jsonResponse(['success' => false, 'message' => 'Owner cannot leave their own home'], 422); }
            $stmt = $pdo->prepare("DELETE FROM {$members} WHERE pet_id=? AND user_id=?"); $stmt->execute([$petId, $removeId]);
            // Invalidates previous invitations so removed members cannot reuse them.
            $stmt = $pdo->prepare("DELETE FROM {$invites} WHERE pet_id=?"); $stmt->execute([$petId]);
        }
    } elseif ($op === 'leave') {
        if ($owner) { $pdo->rollBack(); jsonResponse(['success' => false, 'message' => 'Owner cannot leave their own home'], 422); }
        $stmt = $pdo->prepare("DELETE FROM {$members} WHERE pet_id=? AND user_id=?"); $stmt->execute([$petId, $userId]);
        $pdo->commit(); jsonResponse(['success' => true, 'data' => []]);
    } elseif ($op === 'action') {
        $action = (string)($body['action'] ?? '');
        $rewards = ['feed' => 10, 'pet' => 5, 'wash' => 8, 'play' => 12, 'rest' => 6, 'breathe' => 15];
        $requestId = (string)($body['request_id'] ?? '');
        if (!isset($rewards[$action]) || !preg_match('/^[a-zA-Z0-9-]{20,64}$/', $requestId)) { $pdo->rollBack(); jsonResponse(['success' => false, 'message' => 'Invalid care action'], 422); }
        $stmt = $pdo->prepare("SELECT pet_id FROM {$events} WHERE user_id=? AND request_id=?"); $stmt->execute([$userId, $requestId]);
        $duplicate = $stmt->fetchColumn();
        if ($duplicate !== false) {
            if ((int)$duplicate !== $petId) { $pdo->rollBack(); jsonResponse(['success' => false, 'message' => 'Care request already used'], 409); }
            $pdo->commit(); jsonResponse(['success' => true, 'data' => ['pet' => carePetData($pdo, $pet)]]);
        }
        $stmt = $pdo->prepare("SELECT created_at FROM {$events} WHERE pet_id=? AND user_id=? ORDER BY id DESC LIMIT 1"); $stmt->execute([$petId, $userId]);
        $last = $stmt->fetchColumn();
        if ($last && time() - strtotime($last . ' UTC') < 5) { $pdo->rollBack(); jsonResponse(['success' => false, 'message' => 'Give your companion a moment (5 seconds between actions)', 'code' => 'CARE_PAUSE'], 429); }
        $food = $action === 'feed' ? (string)($body['food'] ?? 'seeds') : '';
        if ($action === 'feed' && !in_array($food, ['seeds', 'berry', 'biscuit'], true)) { $pdo->rollBack(); jsonResponse(['success' => false, 'message' => 'Unknown food'], 422); }
        $pet = careNeeds($pet);
        $changes = ['feed' => ['fullness' => 18, 'happiness' => 4, 'cleanliness' => -2], 'pet' => ['happiness' => 12], 'wash' => ['cleanliness' => 20, 'happiness' => 4], 'play' => ['happiness' => 18, 'energy' => -6, 'fullness' => -3], 'rest' => ['energy' => 22, 'happiness' => 3], 'breathe' => ['happiness' => 15, 'energy' => 8]];
        foreach ($changes[$action] as $key => $change) $pet[$key] = max(25, min(100, $pet[$key] + $change));
        $pet['xp'] = min(1000000, (int)$pet['xp'] + $rewards[$action]);
        $pet['updated_at'] = $now;
        $stmt = $pdo->prepare("UPDATE {$pets} SET xp=?, happiness=?, fullness=?, cleanliness=?, energy=?, updated_at=? WHERE id=?");
        $stmt->execute([$pet['xp'], $pet['happiness'], $pet['fullness'], $pet['cleanliness'], $pet['energy'], $now, $petId]);
        $stmt = $pdo->prepare("INSERT INTO {$events} (pet_id, user_id, request_id, action, food, xp_gain, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$petId, $userId, $requestId, $action, $food, $rewards[$action], $now]);
        $usage = tableName('puffsqueeze_usage_logs');
        $usageTypes = ['feed' => 'mindful_feeding_time', 'pet' => 'mindful_gentle_stroke', 'wash' => 'mindful_cleaning', 'breathe' => 'mindful_deep_breathing', 'play' => 'mindful_play', 'rest' => 'mindful_rest'];
        $stmt = $pdo->prepare("INSERT INTO {$usage} (user_id, action_type, action_detail, created_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $usageTypes[$action], json_encode(['pet_id' => $petId, 'kind' => $pet['kind'], 'score_gain' => $rewards[$action], 'food' => $food, 'completed_cycles' => $action === 'breathe' ? 3 : null], JSON_UNESCAPED_UNICODE), $now]);
    } else {
        $pdo->rollBack(); jsonResponse(['success' => false, 'message' => 'Unknown care operation'], 422);
    }
    $pdo->commit();
    jsonResponse(['success' => true, 'data' => ['pet' => carePetData($pdo, $pet)]]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Companion care: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Companion sync unavailable. Please check the backend connection', 'code' => 'CARE_SERVER'], 500);
}
