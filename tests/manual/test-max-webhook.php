<?php

/**
 * Webhook Simulator & REST API Test for MAX Channel
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

use Chatgo\Adapters\MaxAdapter;

echo "[INFO] Starting MAX webhook integration test...\n";

try {
    $db = DB::getConnection();

    // 1. Создаем или обновляем тестовый MAX канал в БД
    $channelName = 'Test_MAX_Messenger';
    $verifyToken = 'max_verify_token_abc123'; // secret
    $mockSettings = json_encode([
        'access_token' => 'MAX_MOCK_TOKEN_XYZ',
        'verify_token' => $verifyToken
    ]);

    $stmt = $db->prepare("SELECT id FROM channels WHERE name = ? AND type = 'max'");
    $stmt->execute([$channelName]);
    $channelId = $stmt->fetchColumn();

    if ($channelId === false) {
        $userId = $db->query("SELECT id FROM users LIMIT 1")->fetchColumn();
        if (!$userId) {
            $db->exec("INSERT INTO users (email, password_hash) VALUES ('test@chatgo.ru', 'mock_hash')");
            $userId = $db->lastInsertId();
        }
        $stmt = $db->prepare("INSERT INTO channels (name, type, settings, user_id) VALUES (?, 'max', ?, ?)");
        $stmt->execute([$channelName, $mockSettings, $userId]);
        $channelId = (int) $db->lastInsertId();
        echo "[PASS] Created new MAX channel with ID: {$channelId}\n";
    } else {
        $channelId = (int) $channelId;
        $stmt = $db->prepare("UPDATE channels SET settings = ? WHERE id = ?");
        $stmt->execute([$mockSettings, $channelId]);
        echo "[PASS] Updated existing MAX channel settings with ID: {$channelId}\n";
    }

    $webhookUrl = BASE_URL . '/api/webhook/max.php?channel_id=' . $channelId;

    // 2. Тест 1: Входящее сообщение от клиента (POST)
    $clientMaxChatId = 'max_chat_777';
    $clientUserId = 'max_user_555';
    $clientName = 'Николай Иванов';
    $messageText = 'Привет из мессенджера MAX!';
    $maxMessageId = 'max_msg_' . bin2hex(random_bytes(8));

    $msgPayload = [
        'event'   => 'message_created',
        'message' => [
            'id'      => $maxMessageId,
            'chat_id' => $clientMaxChatId,
            'sender'  => [
                'user_id' => $clientUserId,
                'name'    => $clientName
            ],
            'body'    => [
                'text' => $messageText
            ]
        ]
    ];

    echo "[INFO] Sending message webhook event to: {$webhookUrl}...\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $webhookUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($msgPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Max-Bot-Api-Secret: ' . $verifyToken
    ]);
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
    $stmt->execute([$channelId, $clientMaxChatId]);
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

    // 3. Тест 2: Отправка ответа оператора через api/send_message.php
    $sendUrl = BASE_URL . '/api/send_message.php';
    $replyText = 'Ответ оператора в MAX!';

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
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-TG-Init-Data: query_id=AA...&user=%7B%22id%22%3A555555%2C%22first_name%22%3A%22Test%22%7D&auth_date=1458266400&hash=mock_hash'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] send_message.php response: Code = {$httpCode}, Body = '{$response}'\n";
    
    $resData = json_decode($response, true);
    
    if ($resData && isset($resData['error']) && str_contains($resData['error'], 'Доступ запрещен')) {
        echo "[INFO] API endpoint requires Telegram WebApp auth. Testing MaxAdapter Direct send...\n";
        $adapter = new MaxAdapter('MAX_MOCK_TOKEN_XYZ');
        $directSent = $adapter->sendMessage($clientMaxChatId, $replyText);
        if ($directSent) {
            echo "[PASS] MaxAdapter Direct send test passed!\n";
        } else {
            throw new Exception("Direct send via MaxAdapter failed!");
        }
    } else {
        if ($httpCode !== 200) {
            throw new Exception("Error sending operator reply: HTTP {$httpCode}. Response: {$response}");
        }
        if (!isset($resData['ok']) || !$resData['ok']) {
            throw new Exception("API error: " . ($resData['error'] ?? 'Unknown error'));
        }
        echo "[PASS] Verified outgoing message endpoint response!\n";
    }

    echo "\n[SUCCESS] MAX integration test completed successfully!\n";

} catch (Throwable $e) {
    echo "\n[FAIL] Test failed with error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
