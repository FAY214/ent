<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/auth_service.php';
require_once __DIR__ . '/../services/voice_service.php';
applyCors();
requireMethod('POST');
$userId = authenticatedUserId();
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2 * 1024 * 1024) {
    jsonResponse(['success' => false, 'code' => 'invalid_audio', 'message' => 'Recording exceeds 2 MB.'], 413);
}
$upload = $_FILES['audio'] ?? null;
$language = (string)($_POST['language'] ?? 'en');
if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])
    || ($upload['size'] ?? 0) < 100 || $upload['size'] > 2 * 1024 * 1024 || !in_array($language, ['en', 'zh'], true)) {
    jsonResponse(['success' => false, 'code' => 'invalid_audio', 'message' => 'Send a short audio recording.'], 422);
}
$signature = (string)file_get_contents($upload['tmp_name'], false, null, 0, 16);
if (substr($signature, 0, 4) !== "\x1a\x45\xdf\xa3" && substr($signature, 0, 4) !== 'OggS'
    && substr($signature, 4, 4) !== 'ftyp' && !(substr($signature, 0, 4) === 'RIFF' && substr($signature, 8, 4) === 'WAVE')) {
    jsonResponse(['success' => false, 'code' => 'invalid_audio', 'message' => 'Unsupported recording format.'], 422);
}
$model = envValue('VOICE_WHISPER_MODEL');
if (!$model || !is_file($model . '/model.bin')) voiceFailure('voice_not_configured');
// PHP removes this private upload automatically after the request.
$result = runVoiceWorker('voice_transcribe.py', ['audio' => $upload['tmp_name'], 'model' => $model, 'language' => $language], $userId, 'asr');
if (isset($result['error'])) voiceFailure($result['error']);
$decoded = json_decode($result['data'], true);
if (!is_array($decoded) || !is_string($decoded['text'] ?? null)) voiceFailure('voice_unavailable');
jsonResponse(['success' => true, 'data' => ['text' => mb_substr($decoded['text'], 0, 600)]]);
