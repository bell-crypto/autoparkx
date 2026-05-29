<?php
// api/line_helper.php

function line_push_text(string $userId, string $text): bool
{
    $ACCESS_TOKEN = (getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '');

    $body = [
        'to' => $userId,
        'messages' => [[
            'type' => 'text',
            'text' => $text,
        ]],
    ];

    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $ACCESS_TOKEN,
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);

    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // จะ echo log เพิ่ม หรือเขียนลงไฟล์ก็ได้
    return ($httpCode === 200);
}
