<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/auth_service.php';
require_once __DIR__ . '/../services/voice_service.php';
applyCors();
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET','POST'], true)) requireMethod('POST');
$userId = authenticatedUserId();
$pdo = db();
$table = tableName('puffsqueeze_voice_profiles');
$users = tableName('puffsqueeze_users');

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (
        id CHAR(32) NOT NULL PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(80) NOT NULL,
        reference_text TEXT NOT NULL,
        language VARCHAR(5) NOT NULL,
        audio_wav MEDIUMBLOB NOT NULL,
        duration_seconds DECIMAL(6,2) NOT NULL,
        state VARCHAR(32) NOT NULL DEFAULT 'sample_ready',
        official_voice_name VARCHAR(80) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_voice_owner (user_id, created_at),
        FOREIGN KEY (user_id) REFERENCES {$users}(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['audio'] ?? '') === '1') {
            $id = (string)($_GET['id'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/', $id)) jsonResponse(['success'=>false,'code'=>'invalid_profile'],422);
            $stmt = $pdo->prepare("SELECT audio_wav FROM {$table} WHERE id=? AND user_id=?");
            $stmt->execute([$id,$userId]);
            $audio = $stmt->fetchColumn();
            if ($audio === false) jsonResponse(['success'=>false,'code'=>'profile_not_found'],404);
            header('Content-Type: audio/wav');
            header('Content-Disposition: attachment; filename="PuffSqueeze-my-voice-'.$id.'.wav"');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: '.strlen($audio));
            echo $audio;
            exit;
        }
        $stmt = $pdo->prepare("SELECT id,name,reference_text,language,duration_seconds,state,official_voice_name,created_at FROM {$table} WHERE user_id=? ORDER BY created_at DESC,id DESC");
        $stmt->execute([$userId]);
        jsonResponse(['success'=>true,'data'=>['profiles'=>$stmt->fetchAll()]]);
    }

    $body = isset($_POST['action']) ? $_POST : getJsonBody();
    $action = (string)($body['action'] ?? '');
    if ($action === 'create') {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8*1024*1024) jsonResponse(['success'=>false,'code'=>'invalid_audio'],413);
        $name = trim((string)($body['name'] ?? ''));
        $text = trim((string)($body['reference_text'] ?? ''));
        $language = (string)($body['language'] ?? 'zh');
        $upload = $_FILES['audio'] ?? null;
        if ($name === '' || mb_strlen($name)>80 || mb_strlen($text)>600 || !in_array($language,['zh','en'],true)
            || !is_array($upload) || ($upload['error']??-1)!==UPLOAD_ERR_OK || !is_string($upload['tmp_name']??null)
            || !is_uploaded_file($upload['tmp_name']) || ($upload['size']??0)<100 || $upload['size']>8*1024*1024) {
            jsonResponse(['success'=>false,'code'=>'invalid_audio'],422);
        }
        $result = runVoiceWorker('voice_prepare_sample.py',['audio'=>$upload['tmp_name']],$userId,'reference');
        if (isset($result['error'])) voiceFailure($result['error']);
        $audio = $result['data'];
        if (substr($audio,0,4)!=='RIFF' || substr($audio,8,4)!=='WAVE') voiceFailure('audio_decode_failed');
        $duration = round((strlen($audio)-44)/32000,2);
        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT id FROM {$users} WHERE id=? FOR UPDATE"); $lock->execute([$userId]);
        $count = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id=?"); $count->execute([$userId]);
        if ((int)$count->fetchColumn()>=3) { $pdo->rollBack(); jsonResponse(['success'=>false,'code'=>'profile_limit'],409); }
        $id = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("INSERT INTO {$table} (id,user_id,name,reference_text,language,audio_wav,duration_seconds,created_at,updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())");
        $stmt->execute([$id,$userId,$name,$text,$language,$audio,$duration]);
        $pdo->commit();
        jsonResponse(['success'=>true,'data'=>['id'=>$id,'state'=>'sample_ready']]);
    }
    $id = (string)($body['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/',$id)) jsonResponse(['success'=>false,'code'=>'invalid_profile'],422);
    if ($action === 'delete') {
        $stmt=$pdo->prepare("DELETE FROM {$table} WHERE id=? AND user_id=?"); $stmt->execute([$id,$userId]);
    } elseif ($action === 'confirm_official_setup') {
        $label=trim((string)($body['official_voice_name']??''));
        if(mb_strlen($label)>80) jsonResponse(['success'=>false,'code'=>'invalid_profile'],422);
        // This records the user's report, not a verified remote API response.
        $stmt=$pdo->prepare("UPDATE {$table} SET state='user_confirmed',official_voice_name=?,updated_at=NOW() WHERE id=? AND user_id=?");
        $stmt->execute([$label,$id,$userId]);
    } else jsonResponse(['success'=>false,'code'=>'invalid_profile'],422);
    jsonResponse(['success'=>true]);
} catch(Throwable $e) {
    if($pdo->inTransaction()) $pdo->rollBack();
    error_log('Voice profile operation failed: '.get_class($e));
    jsonResponse(['success'=>false,'code'=>'profile_unavailable','message'=>'Voice profile operation unavailable'],500);
}
