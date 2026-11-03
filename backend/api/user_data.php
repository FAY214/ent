<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/care_service.php';
require_once __DIR__ . '/../services/metrics_service.php';
applyCors();
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) requireMethod('POST');

try {
    $pdo = db();
    ensureCareSchema($pdo);
    $userId = requireCareUser($pdo);
    $prefix = (string)envValue('MOODLE_TABLE_PREFIX', '');
    if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) throw new RuntimeException('Invalid table prefix');
    $schema = file_get_contents(__DIR__ . '/../sql/account_records.sql');
    if ($schema === false) throw new RuntimeException('Account schema unavailable');
    $schema = preg_replace('/^\s*--.*$/m', '', $schema);
    foreach (explode(';', str_replace('puffsqueeze_', $prefix . 'puffsqueeze_', $schema)) as $sql) {
        if (trim($sql) !== '') $pdo->exec($sql);
    }
    $documents = tableName('puffsqueeze_account_documents');
    $records = tableName('puffsqueeze_account_records');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare("SELECT document_key, value_json FROM {$documents} WHERE user_id=?");
        $stmt->execute([$userId]);
        $values = [];
        foreach ($stmt->fetchAll() as $row) $values[$row['document_key']] = json_decode($row['value_json'], true, 128, JSON_THROW_ON_ERROR);
        jsonResponse(['success' => true, 'data' => ['documents' => (object)$values]]);
    }
    $batch = getJsonBody()['records'] ?? null;
    if (!is_array($batch) || count($batch) < 1 || count($batch) > 20) {
        jsonResponse(['success' => false, 'message' => 'Send 1 to 20 records'], 422);
    }
    $allowed = ['companion', 'companion_moods', 'daily_plans', 'poetry_cards', 'fishing_collection', 'care_local', 'ai_messages', 'care_visuals', 'voice_studio'];
    $validated = [];
    foreach ($batch as $item) {
        if (!is_array($item)) jsonResponse(['success' => false, 'message' => 'Invalid record'], 422);
        $id = (string)($item['id'] ?? '');
        $type = (string)($item['type'] ?? '');
        $key = (string)($item['key'] ?? '');
        $time = (string)($item['time'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || !in_array($type, ['document', 'activity'], true)
            || !preg_match('/^[a-z][a-z0-9_]{0,79}$/', $key) || strlen($time) > 35
            || !array_key_exists('value', $item) || ($type === 'document' && !in_array($key, $allowed, true))) {
            jsonResponse(['success' => false, 'message' => 'Invalid account record'], 422);
        }
        $json = json_encode($item['value'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > 8 * 1024 * 1024) jsonResponse(['success' => false, 'message' => 'Record exceeds 8 MB'], 413);
        $validated[] = [$id, $type, $key, $json, $time, ($item['seedOnly'] ?? false) === true];
    }
    $pdo->beginTransaction();
    // A dedicated lock avoids reversing the user/pet lock order used by online care.
    $locks = tableName('puffsqueeze_account_sync_locks');
    $lock = $pdo->prepare("INSERT IGNORE INTO {$locks} (user_id) VALUES (?)");
    $lock->execute([$userId]);
    $lock = $pdo->prepare("SELECT user_id FROM {$locks} WHERE user_id=? FOR UPDATE");
    $lock->execute([$userId]);
    $find = $pdo->prepare("SELECT id FROM {$records} WHERE user_id=? AND request_id=?");
    $insert = $pdo->prepare("INSERT INTO {$records} (user_id,request_id,record_type,record_key,value_json,client_time,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())");
    $save = $pdo->prepare("INSERT INTO {$documents} (user_id,document_key,value_json,updated_at) VALUES (?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=VALUES(updated_at)");
    $seed = $pdo->prepare("INSERT IGNORE INTO {$documents} (user_id,document_key,value_json,updated_at) VALUES (?,?,?,UTC_TIMESTAMP())");
    $accepted = [];
    foreach ($validated as [$id, $type, $key, $json, $time, $seedOnly]) {
        $find->execute([$userId, $id]);
        if ($find->fetchColumn() === false) {
            $insert->execute([$userId, $id, $type, $key, $json, $time]);
            if ($type === 'document') {
                $statement = $seedOnly ? $seed : $save;
                $statement->execute([$userId, $key, $json]);
                if ($key === 'care_local' && $seedOnly && $statement->rowCount() === 1) {
                    foreach ((array)json_decode($json, true) as $pet) {
                        if (is_array($pet) && in_array($pet['kind'] ?? '', CARE_KINDS, true)) importOfflineCareBaseline($pdo, $userId, $pet['kind'], (int)($pet['xp'] ?? 0));
                    }
                }
            }
            if ($type === 'activity') {
                $timestamp = strtotime($time);
                $eventTimestamp = $timestamp === false ? time() : min(time(), $timestamp);
                $eventTime = date('Y-m-d H:i:s', $eventTimestamp);
                $usage = tableName('puffsqueeze_usage_logs');
                $usageStmt = $pdo->prepare("INSERT INTO {$usage} (user_id,action_type,action_detail,created_at) VALUES (?,?,?,?)");
                $usageStmt->execute([$userId, substr($key, 0, 50), $json, $eventTime]);
                if ($key === 'offline_care_action') applyOfflineCareRecord($pdo, $userId, (array)json_decode($json, true), gmdate('Y-m-d H:i:s', $eventTimestamp));
                if ($key === 'squeeze') {
                    $detail = json_decode($json, true);
                    $value = $detail['strike_value'] ?? null;
                    if (!is_numeric($value) || !is_finite((float)$value) || $value < 0 || $value > 100) throw new InvalidArgumentException('Invalid squeeze reading');
                    $device = tableName('puffsqueeze_device_events');
                    $stmt = $pdo->prepare("INSERT INTO {$device} (user_id,device_id,strike_level,strike_value,raw_data_json,event_time,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())");
                    $stmt->execute([$userId, substr((string)($detail['device_id'] ?? 'browser'), 0, 64), strikeLevelFromValue((float)$value), $value, json_encode($detail['raw_data'] ?? [], JSON_UNESCAPED_UNICODE), $eventTime]);
                    $stress = tableName('puffsqueeze_stress_records');
                    $stmt = $pdo->prepare("INSERT INTO {$stress} (user_id,stress_index,source,event_time,created_at) VALUES (?,?,'squeeze',?,UTC_TIMESTAMP())");
                    $stmt->execute([$userId, computeRollingStressIndex($pdo, $userId), $eventTime]);
                }
            }
        }
        $accepted[] = $id;
    }
    $pdo->commit();
    jsonResponse(['success' => true, 'data' => ['accepted' => $accepted]]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Account records: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Account saving unavailable'], 500);
}
