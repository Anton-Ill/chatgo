<?php

/**
 * Webhook Simulator & REST API Test for VK (ВКонтакте) Channel
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

echo "[INFO] Starting VK webhook integration test...\n";

try {
    $db = DB::getConnection();

    // 1. Создаем или обновляем тестовый VK канал в БД
    $channelName = 'Test_VK_Community';
    $confirmationCode = 'vk_confirmation_12345';
    $secretKey = 'vk_secret_6789';
    $mockSettings = json_encode([
        'access_token'      => 'VK_MOCK_TOKEN_XYZ',
        'confirmation_code' => $confirmationCode,
        'secret_key'        => $secretKey
    ]);

    // Проверяем, существует ли канал
    $stmt = $db->prepare("SELECT id FROM channels WHERE name = ? AND type = 'vk'");
    $stmt->execute([$channelName]);
    $channelId = $stmt->fetchColumn();

    if ($channelId === false) {
        $userId = $db->query("SELECT id FROM users LIMIT 1")->fetchColumn();
        if (!$userId) {
            $db->exec("INSERT INTO users (email, password_hash) VALUES ('test@chatgo.ru', 'mock_hash')");
            $userId = $db->lastInsertId();
        }
        $stmt = $db->prepare("INSERT INTO channels (name, type, settings, user_id) VALUES (?, 'vk', ?, ?)");
        $stmt->execute([$channelName, $mockSettings, $userId]);
        $channelId = (int) $db->lastInsertId();
        echo "[PASS] Created new VK channel with ID: {$channelId}\n";
    } else {
        $channelId = (int) $channelId;
        $stmt = $db->prepare("UPDATE channels SET settings = ? WHERE id = ?");
        $stmt->execute([$mockSettings, $channelId]);
        echo "[PASS] Updated existing VK channel settings with ID: {$channelId}\n";
    }

    $webhookUrl = BASE_URL . '/api/webhook/vk.php?channel_id=' . $channelId;

    // 2. Тест 1: Запрос подтверждения сервера (confirmation)
    echo "[INFO] Testing confirmation request to: {$webhookUrl}...\n";
    $confPayload = [
        'type'     => 'confirmation',
        'group_id' => 999999
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $webhookUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($confPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] Confirmation response: Code = {$httpCode}, Body = '{$response}'\n";
    if ($httpCode !== 200 || $response !== $confirmationCode) {
        throw new Exception("Неверный ответ на confirmation: ожидалось '{$confirmationCode}' с кодом 200, получено '{$response}' с кодом {$httpCode}");
    }
    echo "[PASS] Confirmation code validation passed successfully!\n";

    // 3. Тест 2: Входящее сообщение от клиента (message_new)
    $clientExternalId = '777777';
    $messageText = 'Hello from VK client!';
    $vkMessageId = rand(1000, 9999);

    $msgPayload = [
        'type'     => 'message_new',
        'secret'   => $secretKey,
        'group_id' => 999999,
        'object'   => [
            'message' => [
                'id'      => $vkMessageId,
                'date'    => time(),
                'peer_id' => (int) $clientExternalId,
                'from_id' => (int) $clientExternalId,
                'text'    => $messageText
            ]
        ]
    ];

    echo "[INFO] Sending message_new webhook event...\n";
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
    if ($httpCode !== 200 || trim($response) !== 'ok') {
        throw new Exception("Неверный ответ на message_new: ожидалось 'ok' с кодом 200, получено '{$response}' с кодом {$httpCode}");
    }
    echo "[PASS] Webhook processed successfully and returned 'ok'\n";

    // Проверяем запись в БД
    $stmt = $db->prepare("
        SELECT c.id AS chat_id, m.text, m.direction, m.external_id 
        FROM chats c
        JOIN messages m ON m.chat_id = c.id
        WHERE c.channel_id = ? AND c.client_external_id = ?
        ORDER BY m.id DESC LIMIT 1
    ");
    $stmt->execute([$channelId, $clientExternalId]);
    $msgRecord = $stmt->fetch();

    if (!$msgRecord) {
        throw new Exception("Запись входящего сообщения не найдена в базе данных!");
    }

    if ($msgRecord['text'] !== $messageText) {
        throw new Exception("Текст в БД не совпадает! Ожидалось '{$messageText}', получено '{$msgRecord['text']}'");
    }

    if ($msgRecord['direction'] !== 'incoming') {
        throw new Exception("Неверное направление сообщения: ожидалось 'incoming', получено '{$msgRecord['direction']}'");
    }

    if ($msgRecord['external_id'] !== (string) $vkMessageId) {
        throw new Exception("Неверный ID входящего сообщения: ожидалось '{$vkMessageId}', получено '{$msgRecord['external_id']}'");
    }
    
    $chatId = (int) $msgRecord['chat_id'];
    echo "[PASS] Verified incoming message in DB: '{$msgRecord['text']}' (Chat ID = {$chatId})\n";

    // 4. Тест 3: Отправка ответа оператора через api/send_message.php
    $sendUrl = BASE_URL . '/api/send_message.php';
    $replyText = 'Hello from VK operator!';
    
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
        throw new Exception("Ошибка при отправке ответа оператора: HTTP {$httpCode}. Ответ: {$response}");
    }

    $resData = json_decode($response, true);
    if (!isset($resData['ok']) || !$resData['ok']) {
        throw new Exception("Ответ API содержит ошибку: " . ($resData['error'] ?? 'Unknown error'));
    }

    $newMsgId = $resData['message_id'];

    // Проверяем запись исходящего сообщения в БД
    $stmt = $db->prepare("SELECT text, direction FROM messages WHERE id = ?");
    $stmt->execute([$newMsgId]);
    $outMsg = $stmt->fetch();

    if (!$outMsg) {
        throw new Exception("Запись исходящего сообщения не найдена в БД!");
    }

    if ($outMsg['text'] !== $replyText) {
        throw new Exception("Текст исходящего сообщения в БД не совпадает! Ожидалось '{$replyText}', получено '{$outMsg['text']}'");
    }

    if ($outMsg['direction'] !== 'outgoing') {
        throw new Exception("Неверное направление исходящего сообщения в БД: ожидалось 'outgoing', получено '{$outMsg['direction']}'");
    }
    echo "[PASS] Verified outgoing message in DB: '{$outMsg['text']}'\n";

    echo "\n[SUCCESS] VK integration test completed successfully!\n";

} catch (Throwable $e) {
    echo "\n[FAIL] Test failed with error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
