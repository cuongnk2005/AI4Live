<?php
// API endpoint to ask Gemini about selected text
header('Content-Type: application/json; charset=utf-8');
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

$config = require __DIR__ . '/config.php';
$GEMINI_KEY = $config['gemini_api_key'] ?? '';

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$context = $input['context'] ?? '';
$question = $input['question'] ?? '';

if (empty($context) || empty($question)) {
    echo json_encode(['error' => 'Missing context or question']);
    exit;
}

if (empty($GEMINI_KEY)) {
    echo json_encode(['error' => 'Gemini API key not configured']);
    exit;
}

// Prepare prompt for short answer (30-40 words)
$prompt = "Dựa vào đoạn văn sau:\n\n\"$context\"\n\nTrả lời ngắn gọn câu hỏi (30-40 từ): $question";

// Call Gemini API
$apiUrl = "https://generativelanguage.googleapis.com/v1/models/gemini-2.0-flash:generateContent?key=" . $GEMINI_KEY;

$requestData = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt]
            ]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.7,
        'maxOutputTokens' => 100, // Limit to ~30-40 words
        'topP' => 0.8,
        'topK' => 10
    ]
];

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    $errorData = json_decode($response, true);
    $errorMsg = $errorData['error']['message'] ?? 'Unknown error';
    echo json_encode(['error' => "Gemini API error ($httpCode): $errorMsg"]);
    exit;
}

$data = json_decode($response, true);

if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
    $answer = trim($data['candidates'][0]['content']['parts'][0]['text']);
    echo json_encode(['answer' => $answer]);
} else {
    echo json_encode(['error' => 'No response from Gemini']);
}
