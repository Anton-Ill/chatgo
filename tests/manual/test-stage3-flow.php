<?php

/**
 * Diagnostic test script for Stage 3 REST API endpoints (list, messages, send)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

echo "[INFO] Starting Stage 3 REST API tests...\n";

try {
    $db = DB::getConnection();

    // 1. Тестируем эндпоинт list.php
    $listUrl = BASE_URL . '/api/chats/list.php';
    echo "[INFO] Fetching chats list from: {$listUrl}...\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $listUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] list.php Response Code: {$httpCode}\n";
    if ($httpCode !== 200) {
        throw new Exception("Неверный код ответа от list.php: {$httpCode}. Ответ: {$response}");
    }

    $listData = json_decode($response, true);
    if (!isset($listData['ok']) || !$listData['ok']) {
        throw new Exception("Неудачный статус ответа от list.php. Ответ: {$response}");
    }

    $chats = $listData['chats'] ?? [];
    echo "[PASS] Successfully fetched " . count($chats) . " chats\n";

    if (empty($chats)) {
        throw new Exception("Список чатов пуст. Запустите сначала предыдущие тесты.");
    }

    // Берём первый чат для проверки детальных сообщений
    $testChat = $chats[0];
    $chatId = (int) $testChat['id'];
    echo "[INFO] Using Chat ID {$chatId} for message tests\n";

    // Искусственно устанавливаем unread_count = 5 для проверки автосброса
    $stmt = $db->prepare("UPDATE chats SET unread_count = 5 WHERE id = ?");
    $stmt->execute([$chatId]);

    // 2. Тестируем эндпоинт messages.php
    $msgUrl = BASE_URL . '/api/chats/messages.php?chat_id=' . $chatId;
    echo "[INFO] Fetching chat messages from: {$msgUrl}...\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $msgUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] messages.php Response Code: {$httpCode}\n";
    if ($httpCode !== 200) {
        throw new Exception("Неверный код ответа от messages.php: {$httpCode}. Ответ: {$response}");
    }

    $msgData = json_decode($response, true);
    if (!isset($msgData['ok']) || !$msgData['ok']) {
        throw new Exception("Неудачный статус ответа от messages.php. Ответ: {$response}");
    }

    // Проверяем сброс unread_count в базе
    $stmt = $db->prepare("SELECT unread_count FROM chats WHERE id = ?");
    $stmt->execute([$chatId]);
    $unreadCount = (int) $stmt->fetchColumn();
    if ($unreadCount !== 0) {
        throw new Exception("Счетчик unread_count не сбросился в 0, получено: {$unreadCount}");
    }
    echo "[PASS] unread_count was correctly reset to 0 upon fetching messages\n";

    // 3. Тестируем эндпоинт send.php (отправка ответа оператора)
    $sendUrl = BASE_URL . '/api/send_message.php';
    $sendText = 'Hello client from automated Stage 3 test!';
    echo "[INFO] Sending reply to send.php: {$sendUrl}...\n";

    $payload = [
        'chat_id' => $chatId,
        'text' => $sendText
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $sendUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] send.php Response Code: {$httpCode}\n";
    echo "[INFO] send.php Response Body: {$response}\n";

    if ($httpCode !== 200) {
        throw new Exception("Неверный код ответа от send.php: {$httpCode}. Ответ: {$response}");
    }

    $sendData = json_decode($response, true);
    if (!isset($sendData['ok']) || !$sendData['ok']) {
        throw new Exception("Неудачный статус ответа от send.php. Ответ: {$response}");
    }

    $newMsgId = $sendData['message_id'];
    echo "[PASS] Message successfully sent. Message ID in DB: {$newMsgId}\n";

    // 4. Проверяем запись в БД
    $stmt = $db->prepare("SELECT text, direction FROM messages WHERE id = ?");
    $stmt->execute([$newMsgId]);
    $msgDb = $stmt->fetch();

    if (!$msgDb) {
        throw new Exception("Сообщение с ID {$newMsgId} не найдено в БД.");
    }

    if ($msgDb['text'] !== $sendText) {
        throw new Exception("Текст в БД не совпадает! Ожидалось: '{$sendText}', получено: '{$msgDb['text']}'");
    }

    if ($msgDb['direction'] !== 'outgoing') {
        throw new Exception("Неверное направление сообщения, ожидалось 'outgoing', получено: '{$msgDb['direction']}'");
    }
    echo "[PASS] Verified outgoing message registered in DB\n";

    echo "\n[SUCCESS] Stage 3 REST API tests completed successfully!\n";

} catch (Throwable $e) {
    echo "\n[FAIL] Test failed with error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
