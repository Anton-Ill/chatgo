<?php

/**
 * Webhook Simulator Test for Telegram Channel
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

echo "[INFO] Starting Telegram webhook simulator test...\n";

try {
    $db = DB::getConnection();

    // 1. Находим тестовый канал и настраиваем токен бота
    $stmt = $db->prepare("SELECT id FROM channels WHERE name = ? AND type = 'telegram'");
    $stmt->execute(['Test_Dev_Bot']);
    $channelId = $stmt->fetchColumn();

    if ($channelId === false) {
        throw new Exception("Тестовый канал 'Test_Dev_Bot' не найден. Сначала запустите test-db-flow.php.");
    }
    $channelId = (int) $channelId;

    // Устанавливаем корректные settings для канала
    $mockSettings = json_encode(['token' => '123456:ABC-MOCK-TOKEN-XYZ']);
    $stmt = $db->prepare("UPDATE channels SET settings = ? WHERE id = ?");
    $stmt->execute([$mockSettings, $channelId]);
    echo "[PASS] Setup bot token settings for channel ID: {$channelId}\n";

    // 2. Формируем тестовый payload от Telegram
    $simulatedClientExternalId = '555555';
    $simulatedText = 'Hello, this is a simulated webhook message!';
    $messageId = rand(1000, 9999);

    $payload = [
        'update_id' => rand(100000, 999999),
        'message' => [
            'message_id' => $messageId,
            'from' => [
                'id' => (int) $simulatedClientExternalId,
                'is_bot' => false,
                'first_name' => 'Alice',
                'last_name' => 'Smith',
                'username' => 'alice_smith'
            ],
            'chat' => [
                'id' => (int) $simulatedClientExternalId,
                'first_name' => 'Alice',
                'last_name' => 'Smith',
                'type' => 'private'
            ],
            'date' => time(),
            'text' => $simulatedText
        ]
    ];

    // 3. Выполняем HTTP POST запрос к нашему локальному сайту http://chatgo.local
    $webhookUrl = BASE_URL . '/api/webhook/telegram.php?channel_id=' . $channelId;
    echo "[INFO] Sending POST request to: {$webhookUrl}...\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $webhookUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] HTTP Response Code: {$httpCode}\n";
    echo "[INFO] Response Body: {$response}\n";

    if ($httpCode !== 200) {
        throw new Exception("Запрос к вебхуку завершился с кодом: {$httpCode}. Тело ответа: {$response}");
    }

    $resData = json_decode($response, true);
    if (!isset($resData['ok']) || !$resData['ok']) {
        throw new Exception("Вебхук вернул статус ошибки: " . ($resData['error'] ?? 'Unknown error'));
    }
    
    $chatId = $resData['chat_id'];
    echo "[PASS] Webhook successfully processed. Chat ID in DB: {$chatId}\n";

    // 4. Проверяем состояние базы данных
    $stmt = $db->prepare("SELECT text, direction, external_id FROM messages WHERE chat_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$chatId]);
    $lastMsg = $stmt->fetch();

    if (!$lastMsg) {
        throw new Exception("Сообщение не найдено в базе данных!");
    }

    if ($lastMsg['text'] !== $simulatedText) {
        throw new Exception("Текст сообщения в БД не совпадает! Ожидалось: '{$simulatedText}', получено: '{$lastMsg['text']}'");
    }
    echo "[PASS] Verified message text in DB: '{$lastMsg['text']}'\n";

    if ($lastMsg['direction'] !== 'incoming') {
        throw new Exception("Неверное направление сообщения, ожидалось 'incoming', получено: '{$lastMsg['direction']}'");
    }
    echo "[PASS] Verified message direction is 'incoming'\n";

    if ($lastMsg['external_id'] !== (string) $messageId) {
        throw new Exception("Неверный external_id сообщения, ожидалось '{$messageId}', получено: '{$lastMsg['external_id']}'");
    }
    echo "[PASS] Verified message external_id is '{$messageId}'\n";

    echo "\n[SUCCESS] Webhook integration test completed successfully!\n";

} catch (Throwable $e) {
    echo "\n[FAIL] Test failed with error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
