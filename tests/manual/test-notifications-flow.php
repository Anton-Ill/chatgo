<?php

/**
 * REST API & Webhook Test: Operator Telegram Notifications Bind Flow (Mock Mode)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

echo "[INFO] Starting Telegram notifications integration flow test (Mock mode)...\n";

try {
    $db = DB::getConnection();

    // 1. Убеждаемся, что колонка telegram_id присутствует в users
    $columns = $db->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('telegram_id', $columns)) {
        $db->exec("ALTER TABLE users ADD COLUMN telegram_id VARCHAR(50) NULL UNIQUE");
        echo "[INFO] Applied migration: Added telegram_id column to users table.\n";
    }

    // Подготовим тестового пользователя, если нет
    $userId = $db->query("SELECT id FROM users LIMIT 1")->fetchColumn();
    if (!$userId) {
        $db->exec("INSERT INTO users (email) VALUES ('operator@chatgo.ru')");
        $userId = (int) $db->lastInsertId();
        echo "[INFO] Created test operator user with ID: {$userId}\n";
    }

    // Очистим telegram_id у этого пользователя для чистоты теста
    $db->prepare("UPDATE users SET telegram_id = NULL WHERE id = ?")->execute([$userId]);

    // Подготовим тестовый Telegram канал в БД, чтобы add_bind.php не ругался
    $db->exec("DELETE FROM channels WHERE type = 'telegram'");
    $stmtChannel = $db->prepare("
        INSERT INTO channels (user_id, name, type, settings, status) 
        VALUES (?, 'TestNotificationBot', 'telegram', '{\"token\":\"MOCK_BOT_TOKEN\"}', 'connected')
    ");
    $stmtChannel->execute([$userId]);
    $channelId = (int) $db->lastInsertId();
    echo "[INFO] Created mock Telegram channel with ID: {$channelId}\n";

    $statusUrl = BASE_URL . '/api/channels/get_bind_status.php';
    $addBindUrl = BASE_URL . '/api/channels/add_bind.php';
    $webhookUrl = BASE_URL . '/api/webhook/telegram.php?channel_id=' . $channelId;

    // 2. Тест: Проверка статуса (ожидаем false)
    echo "[INFO] Testing GET bind status from: {$statusUrl}...\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $statusUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] Status response: Code = {$httpCode}, Body = '{$response}'\n";
    if ($httpCode !== 200) {
        throw new Exception("Status API returned HTTP {$httpCode}");
    }

    $resData = json_decode($response, true);
    if (!isset($resData['ok']) || !$resData['ok']) {
        throw new Exception("Status API failed: " . ($resData['error'] ?? 'Unknown error'));
    }

    if ($resData['is_bound'] !== false) {
        throw new Exception("Operator should NOT be bound initially!");
    }
    echo "[PASS] Verified initial status is: NOT BOUND.\n";

    // 3. Тест: Генерация ссылки привязки
    echo "[INFO] Testing POST/GET add bind URL from: {$addBindUrl}...\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $addBindUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] Add bind response: Code = {$httpCode}, Body = '{$response}'\n";
    if ($httpCode !== 200) {
        throw new Exception("Add bind API returned HTTP {$httpCode}");
    }

    $resData = json_decode($response, true);
    if (!isset($resData['ok']) || !$resData['ok']) {
        throw new Exception("Add bind failed: " . ($resData['error'] ?? 'Unknown error'));
    }

    $bindUrl = $resData['bind_url'] ?? '';
    if (empty($bindUrl)) {
        throw new Exception("Bind URL is empty!");
    }

    // Вытаскиваем токен привязки из ссылки (после start=bind_)
    $parts = parse_url($bindUrl);
    parse_str($parts['query'] ?? '', $query);
    $startParam = $query['start'] ?? '';
    if (!str_starts_with($startParam, 'bind_')) {
        throw new Exception("Invalid start parameter format in link: {$startParam}");
    }

    $bindToken = substr($startParam, 5);
    echo "[PASS] Generated bind token successfully: {$bindToken}\n";

    // 4. Тест: Симуляция команды /start bind_<token> вебхуком Telegram
    echo "[INFO] Simulating Telegram webhook call to: {$webhookUrl}...\n";

    $webhookPayload = [
        'update_id' => 999999,
        'message' => [
            'message_id' => 888,
            'from' => [
                'id' => 777123456,
                'is_bot' => false,
                'first_name' => 'OperatorTester'
            ],
            'chat' => [
                'id' => 777123456,
                'first_name' => 'OperatorTester',
                'type' => 'private'
            ],
            'date' => time(),
            'text' => "/start bind_{$bindToken}"
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $webhookUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($webhookPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] Webhook response: Code = {$httpCode}, Body = '{$response}'\n";
    if ($httpCode !== 200) {
        throw new Exception("Webhook endpoint returned HTTP {$httpCode}: {$response}");
    }

    $resData = json_decode($response, true);
    if (!isset($resData['ok']) || !$resData['ok']) {
        throw new Exception("Webhook processing failed: " . ($resData['error'] ?? 'Unknown error'));
    }
    echo "[PASS] Simulated Telegram webhook call processed successfully.\n";

    // 5. Проверка записи telegram_id в БД
    $currentTgId = $db->query("SELECT telegram_id FROM users LIMIT 1")->fetchColumn();
    if ($currentTgId !== '777123456') {
        throw new Exception("Operator telegram_id was not written to users! Found: '{$currentTgId}'");
    }
    echo "[PASS] Verified operator telegram_id in database: {$currentTgId}.\n";

    // 6. Тест: Проверка статуса (ожидаем true)
    echo "[INFO] Testing GET bind status (bound check) from: {$statusUrl}...\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $statusUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] Bound status response: Code = {$httpCode}, Body = '{$response}'\n";
    $resData = json_decode($response, true);
    if (!isset($resData['ok']) || !$resData['ok'] || !$resData['is_bound']) {
        throw new Exception("Status API did not report that user is bound!");
    }
    echo "[PASS] Verified status is: BOUND.\n";

    // Очистка тестовых данных
    $db->prepare("UPDATE users SET telegram_id = NULL WHERE id = ?")->execute([$userId]);
    $db->exec("DELETE FROM channels WHERE id = {$channelId}");
    echo "[INFO] Cleaned up test data.\n";

    echo "\n[SUCCESS] Telegram notifications bind flow test completed successfully!\n";

} catch (Throwable $e) {
    echo "\n[FAIL] Test failed with error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
