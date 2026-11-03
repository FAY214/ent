<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function strikePromptHint(string $level): string
{
    if ($level === 'low') {
        return 'User strike intensity is LOW. Respond softly and encourage calm breathing.';
    }
    if ($level === 'medium') {
        return 'User strike intensity is MEDIUM. Respond with grounding advice and structured steps.';
    }
    if ($level === 'high') {
        return 'User strike intensity is HIGH. Prioritize emotional de-escalation and short, reassuring instructions.';
    }
    return 'User strike intensity is unknown. Keep response gentle and supportive.';
}

function sanitizeAiText(string $text): string
{
    // Remove common markdown formatting markers to keep plain text output.
    $text = str_replace(['**', '*', '`', '#'], '', $text);
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    return trim($text);
}

function geminiChat(array $history, string $message, string $strikeLevel, array $deviceData = []): string
{
    $apiKey = envValue('GEMINI_API_KEY');
    if (!$apiKey) {
        throw new RuntimeException('GEMINI_API_KEY is not configured.');
    }

    $systemInstruction = implode("\n", [
        "You are Healing AI in PuffSqueeze.",
        "Tone: gentle, practical, and concise.",
        "Output plain text only. Do not use markdown or symbols like *, **, #, -, or numbered markdown formatting.",
        strikePromptHint($strikeLevel),
    ]);

    $contents = [];
    foreach (array_slice($history, -30) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $role = ($item['role'] ?? 'user') === 'model' ? 'model' : 'user';
        $text = '';
        if (isset($item['parts'][0]['text']) && is_string($item['parts'][0]['text'])) {
            $text = mb_substr($item['parts'][0]['text'], 0, 6000);
        }
        if ($text !== '') {
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $text]],
            ];
        }
    }

    $deviceSummary = $deviceData ? "\nDevice data: " . json_encode($deviceData, JSON_UNESCAPED_UNICODE) : '';
    $contents[] = [
        'role' => 'user',
        'parts' => [[
            'text' => "Current user message: {$message}{$deviceSummary}",
        ]],
    ];

    $payload = [
        'system_instruction' => [
            'parts' => [['text' => $systemInstruction]],
        ],
        'contents' => $contents,
    ];

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        throw new RuntimeException('Gemini request failed: ' . $curlError);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Gemini request failed with HTTP ' . $httpCode . ': ' . $response);
    }

    $json = json_decode($response, true);
    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!is_string($text) || trim($text) === '') {
        throw new RuntimeException('Gemini returned empty content.');
    }

    return sanitizeAiText($text);
}
