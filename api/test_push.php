<?php
// api/test_push.php

// 1) วาง Channel Access Token (long-lived)
$ACCESS_TOKEN = (getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '');


// 2) วาง userId ที่ได้จาก log
$USER_ID = 'U7147e61fe81ee56c80f2f8e35b52845c';

$data = [
    "to" => $USER_ID,
    "messages" => [
        [
            "type" => "text",
            "text" => "📢 ทดสอบ Push Message จากระบบ AutoParkX สำเร็จแล้ว!"
        ]
    ]
];

$ch = curl_init("https://api.line.me/v2/bot/message/push");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $ACCESS_TOKEN
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

echo "Status: " . $httpCode . "<br>";
echo "Response: " . $result;
