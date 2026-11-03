<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

/** Run a server-chosen worker; private input never enters a shell. */
function runVoiceWorker(string $worker, array $payload, int $userId, string $kind): array
{
    set_time_limit(45);
    $python = envValue('VOICE_PYTHON');
    if (!$python || !is_file($python) || !is_callable('proc_open')) return ['error' => 'voice_not_configured'];
    $output = tempnam(sys_get_temp_dir(), 'ps-voice-');
    $errors = tempnam(sys_get_temp_dir(), 'ps-voice-');
    $process = null;
    $lock = null;
    try {
        if ($output === false || $errors === false) throw new RuntimeException('Temporary storage unavailable');
        $lock = fopen(sys_get_temp_dir() . '/ps-voice-' . $kind . '-' . $userId, 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['error' => 'voice_busy'];
        $last = (int)stream_get_contents($lock);
        if (time() - $last < 3) return ['error' => 'voice_busy'];
        ftruncate($lock, 0); rewind($lock); fwrite($lock, (string)time());
        $pipes = [];
        $process = proc_open([$python, __DIR__ . '/../scripts/' . $worker], [
            0 => ['pipe', 'r'], 1 => ['file', $output, 'wb'], 2 => ['file', $errors, 'wb'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Voice process unavailable');
        fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $deadline = microtime(true) + 40;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) break;
            usleep(100000);
        } while (microtime(true) < $deadline);
        if ($status['running']) { proc_terminate($process); return ['error' => 'voice_timeout']; }
        // Workers emit only a fixed error token, never audio text or private paths.
        if ($status['exitcode'] !== 0) {
            $token = trim((string)file_get_contents($errors));
            $known = ['sample_too_short','sample_too_long','sample_too_quiet','audio_decode_failed','asr_dependency_error'];
            error_log('Voice worker failed: ' . $kind . (in_array($token, $known, true) ? ' ['.$token.']' : ''));
            return ['error' => in_array($token, $known, true) ? $token : 'voice_unavailable'];
        }
        if (filesize($output) > 1024 * 1024) return ['error' => 'voice_unavailable'];
        $data = file_get_contents($output);
        return $data === false || $data === '' ? ['error' => 'voice_unavailable'] : ['data' => $data];
    } catch (Throwable $e) {
        error_log('Voice worker unavailable: ' . $kind);
        return ['error' => 'voice_unavailable'];
    } finally {
        if (is_resource($process)) proc_close($process);
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        if (is_string($output) && is_file($output)) unlink($output);
        if (is_string($errors) && is_file($errors)) unlink($errors);
    }
}

function voiceFailure(string $code): void
{
    $status = $code === 'voice_busy' ? 429 : ($code === 'voice_not_configured' ? 409 : 502);
    jsonResponse(['success' => false, 'code' => $code, 'message' => 'Voice service unavailable. Please try again.'], $status);
}
