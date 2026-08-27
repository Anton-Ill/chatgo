<?php

/**
 * REST API Test: Channel Connection Management (Mock Mode)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

echo "[INFO] Starting channels connection API test (Mock mode)...\n";

try {
    $db = DB::getConnection();

    // Перед тестом убедимся, что у нас есть тестовый пользователь
    $userId = $db->query("SELECT id FROM users LIMIT 1")->fetchColumn();
    if (!$userId) {
        $db->exec("INSERT INTO users (email, password_hash) VALUES ('test@chatgo.ru', 'mock_hash')");
        $userId = (int) $db->lastInsertId();
        echo "[INFO] Created test user with ID: {$userId}\n";
    }

    $addUrl = BASE_URL . '/api/channels/add.php';
    $listUrl = BASE_URL . '/api/channels/list.php';
    $deleteUrl = BASE_URL . '/api/channels/delete.php';

    // 1. Тест: Добавление Mock Telegram-канала
    echo "[INFO] Testing POST add channel to: {$addUrl}...\n";
    
    $addPayload = [
        'name' => 'Мой Тестовый Бот',
        'type' => 'telegram',
        'settings' => [
            'token' => '123456:BOT_MOCK_TOKEN_123'
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $addUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($addPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] Add response: Code = {$httpCode}, Body = '{$response}'\n";
    if ($httpCode !== 200) {
        throw new Exception("Add channel API returned HTTP {$httpCode}: {$response}");
    }

    $resData = json_decode($response, true);
    if (!isset($resData['ok']) || !$resData['ok']) {
        throw new Exception("Add channel failed: " . ($resData['error'] ?? 'Unknown error'));
    }

    $channelId = (int) $resData['channel_id'];
    echo "[PASS] Created Mock Channel successfully with ID: {$channelId}\n";

    // Проверяем запись в БД
    $stmt = $db->prepare("SELECT type, name, status FROM channels WHERE id = ?");
    $stmt->execute([$channelId]);
    $channelRecord = $stmt->fetch();

    if (!$channelRecord) {
        throw new Exception("Channel record not found in DB!");
    }

    if ($channelRecord['name'] !== 'Мой Тестовый Бот' || $channelRecord['status'] !== 'connected') {
        throw new Exception("Channel record values mismatch in DB!");
    }
    echo "[PASS] Verified channel connection state in DB.\n";

    // 2. Тест: Получение списка каналов
    echo "[INFO] Testing GET list channels from: {$listUrl}...\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $listUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] List response: Code = {$httpCode}\n";
    if ($httpCode !== 200) {
        throw new Exception("List channels API returned HTTP {$httpCode}");
    }

    $listData = json_decode($response, true);
    if (!isset($listData['ok']) || !$listData['ok']) {
        throw new Exception("List channels failed: " . ($listData['error'] ?? 'Unknown error'));
    }

    // Проверяем наличие нашего канала в списке
    $found = false;
    foreach ($listData['channels'] as $c) {
        if ((int)$c['id'] === $channelId) {
            $found = true;
            if (isset($c['settings'])) {
                throw new Exception("Security breach: channel settings were returned in list API!");
            }
            break;
        }
    }

    if (!$found) {
        throw new Exception("Created channel not found in list API response!");
    }
    echo "[PASS] Channels list API verified successfully (settings are masked).\n";

    // 3. Тест: Удаление канала
    echo "[INFO] Testing POST delete channel to: {$deleteUrl}...\n";

    $deletePayload = [
        'channel_id' => $channelId
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $deleteUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($deletePayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[INFO] Delete response: Code = {$httpCode}, Body = '{$response}'\n";
    if ($httpCode !== 200) {
        throw new Exception("Delete channel API returned HTTP {$httpCode}: {$response}");
    }

    $resData = json_decode($response, true);
    if (!isset($resData['ok']) || !$resData['ok']) {
        throw new Exception("Delete channel failed: " . ($resData['error'] ?? 'Unknown error'));
    }

    // Проверяем удаление из БД
    $stmt = $db->prepare("SELECT id FROM channels WHERE id = ?");
    $stmt->execute([$channelId]);
    if ($stmt->fetchColumn() !== false) {
        throw new Exception("Channel record still exists in DB after deletion!");
    }
    echo "[PASS] Channel deleted and verified in DB.\n";

    echo "\n[SUCCESS] Channels connection API test completed successfully!\n";

} catch (Throwable $e) {
    echo "\n[FAIL] Test failed with error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
