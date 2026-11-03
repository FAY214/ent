<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/auth_service.php';
require_once __DIR__ . '/../services/voice_service.php';
applyCors();
requireMethod('POST');
$userId = authenticatedUserId();
$body = getJsonBody();
$text = trim((string)($body['text'] ?? ''));
$voice = (string)($body['voice'] ?? 'ana');
if ($text === '' || mb_strlen($text) > 600 || !in_array($voice, ['ana', 'xiaoxiao'], true)) {
    jsonResponse(['success' => false, 'code' => 'invalid_voice_input', 'message' => 'Use 1–600 characters and a supported voice.'], 422);
}
$result = runVoiceWorker('voice_synthesize.py', ['text' => $text, 'voice' => $voice], $userId, 'tts');
if (isset($result['error'])) voiceFailure($result['error']);
if (strlen($result['data']) < 100) voiceFailure('voice_unavailable');
jsonResponse(['success' => true, 'data' => ['audio_base64' => base64_encode($result['data']), 'mime_type' => 'audio/mpeg', 'voice' => $voice]]);
