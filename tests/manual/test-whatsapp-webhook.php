<?php

/**
 * Webhook Simulator & REST API Test for WhatsApp Channel
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

echo "[INFO] Starting WhatsApp webhook integration test...\n";

try {
    $db = DB::getConnection();

    // 1. Создаем или обновляем тестовый WhatsApp канал в БД
    $channelName = 'Test_WhatsApp_Business';
    $verifyToken = 'wa_verify_token_abc123';
    $mockSettings = json_encode([
        'access_token'    => 'WA_MOCK_TOKEN_XYZ',
        'verify_token'    => $verifyToken,
        'phone_number_id' => '1234567890'
    ]);

    $stmt = $db->prepare("SELECT id FROM channels WHERE name = ? AND type = 'whatsapp'");
    $stmt->execute([$channelName]);
    $channelId = $stmt->fetchColumn();

    if ($channelId === false) {
        $userId = $db->query("SELECT id FROM users LIMIT 1")->fetchColumn();
        if (!$userId) {
            $db->exec("INSERT INTO users (email, password_hash) VALUES ('test@chatgo.ru', 'mock_hash')");
            $userId = $db->lastInsertId();
        }
        $stmt = $db->prepare("INSERT INTO channels (name, type, settings, user_id) VALUES (?, 'whatsapp', ?, ?)");
        $stmt->execute([$channelName, $mockSettings, $userId]);
        $channelId = (int) $db->lastInsertId();
        echo "[PASS] Created new WhatsApp channel with ID: {$channelId}\n";
    } else {
        $channelId = (int) $channelId;
        $stmt = $db->prepare("UPDATE channels SET settings = ? WHERE id = ?");
        $stmt->execute([$mockSettings, $channelId]);
        echo "[PASS] Updated existing WhatsApp channel settings with ID: {$channelId}\n";
    }

    $webhookUrl = BASE_URL . '/api/webhook/whatsapp.php?channel_id=' . $channelId;

    // 2. Тест 1: Верификация вебхука (GET запрос)
    echo "[INFO] Testing webhook verification (GET) to: {$webhookUrl}...\n";
    $verifyUrl = $webhookUrl
        . '&hub_mode=subscribe'
        . '&hub_verify_token=' . urlencode($verifyToken)
        . '&hub_challenge=test_challenge_string_42';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $verifyUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] Verification response: Code = {$httpCode}, Body = '{$response}'\n";
    if ($httpCode !== 200 || $response !== 'test_challenge_string_42') {
        throw new Exception("Webhook verification failed: expected 'test_challenge_string_42', got '{$response}' (HTTP {$httpCode})");
    }
    echo "[PASS] Webhook verification passed successfully!\n";

    // 3. Тест 2: Входящее сообщение от клиента
    $clientPhone = '79001234567';
    $clientName = 'Иван Петров';
    $messageText = 'Привет из WhatsApp!';
    $waMessageId = 'wamid.' . bin2hex(random_bytes(8));

    $msgPayload = [
        'object' => 'whatsapp_business_account',
        'entry'  => [
            [
                'id'      => '999999',
                'changes' => [
                    [
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'metadata'          => [
                                'display_phone_number' => '15551234567',
                                'phone_number_id'      => '1234567890'
                            ],
                            'contacts' => [
                                [
                                    'profile' => ['name' => $clientName],
                                    'wa_id'   => $clientPhone
                                ]
                            ],
                            'messages' => [
                                [
                                    'from'      => $clientPhone,
                                    'id'        => $waMessageId,
                                    'timestamp' => (string) time(),
                                    'text'      => ['body' => $messageText],
                                    'type'      => 'text'
                                ]
                            ]
                        ],
                        'field' => 'messages'
                    ]
                ]
            ]
        ]
    ];

    echo "[INFO] Sending message webhook event...\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $webhookUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($msgPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] Webhook response: Code = {$httpCode}, Body = '{$response}'\n";
    if ($httpCode !== 200) {
        throw new Exception("Webhook returned HTTP {$httpCode}: {$response}");
    }
    echo "[PASS] Webhook processed successfully!\n";

    // Проверяем запись в БД
    $stmt = $db->prepare("
        SELECT c.id AS chat_id, m.text, m.direction, m.external_id
        FROM chats c
        JOIN messages m ON m.chat_id = c.id
        WHERE c.channel_id = ? AND c.client_external_id = ?
        ORDER BY m.id DESC LIMIT 1
    ");
    $stmt->execute([$channelId, $clientPhone]);
    $msgRecord = $stmt->fetch();

    if (!$msgRecord) {
        throw new Exception("Incoming message record not found in DB!");
    }

    if ($msgRecord['text'] !== $messageText) {
        throw new Exception("Text mismatch: expected '{$messageText}', got '{$msgRecord['text']}'");
    }

    if ($msgRecord['direction'] !== 'incoming') {
        throw new Exception("Direction mismatch: expected 'incoming', got '{$msgRecord['direction']}'");
    }

    $chatId = (int) $msgRecord['chat_id'];
    echo "[PASS] Verified incoming message in DB: '{$msgRecord['text']}' (Chat ID = {$chatId})\n";

    // 4. Тест 3: Отправка ответа оператора через api/send_message.php
    $sendUrl = BASE_URL . '/api/send_message.php';
    $replyText = 'Ответ оператора из WhatsApp!';

    echo "[INFO] Testing operator reply via: {$sendUrl}...\n";
    $sendPayload = [
        'chat_id' => $chatId,
        'text'    => $replyText
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $sendUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sendPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] send_message.php response: Code = {$httpCode}, Body = '{$response}'\n";
    if ($httpCode !== 200) {
        throw new Exception("Error sending operator reply: HTTP {$httpCode}. Response: {$response}");
    }

    $resData = json_decode($response, true);
    if (!isset($resData['ok']) || !$resData['ok']) {
        throw new Exception("API error: " . ($resData['error'] ?? 'Unknown error'));
    }

    $newMsgId = $resData['message_id'];

    // Проверяем запись исходящего сообщения в БД
    $stmt = $db->prepare("SELECT text, direction FROM messages WHERE id = ?");
    $stmt->execute([$newMsgId]);
    $outMsg = $stmt->fetch();

    if (!$outMsg) {
        throw new Exception("Outgoing message record not found in DB!");
    }

    if ($outMsg['text'] !== $replyText) {
        throw new Exception("Outgoing text mismatch: expected '{$replyText}', got '{$outMsg['text']}'");
    }

    if ($outMsg['direction'] !== 'outgoing') {
        throw new Exception("Outgoing direction mismatch: expected 'outgoing', got '{$outMsg['direction']}'");
    }
    echo "[PASS] Verified outgoing message in DB: '{$outMsg['text']}'\n";

    echo "\n[SUCCESS] WhatsApp integration test completed successfully!\n";

} catch (Throwable $e) {
    echo "\n[FAIL] Test failed with error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
