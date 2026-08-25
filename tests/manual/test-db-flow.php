<?php

/**
 * Diagnostic test script for DB services (ChatService, MessageService)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

use Chatgo\Services\ChatService;
use Chatgo\Services\MessageService;

echo "[INFO] Starting database services test...\n";

try {
    $db = DB::getConnection();
    
    // 1. Обеспечиваем наличие тестового пользователя
    $email = 'test-developer@chatgot.ru';
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $userId = $stmt->fetchColumn();
    
    if ($userId === false) {
        echo "[INFO] Creating test user...\n";
        $stmt = $db->prepare('INSERT INTO users (email) VALUES (?)');
        $stmt->execute([$email]);
        $userId = (int) $db->lastInsertId();
    } else {
        $userId = (int) $userId;
    }
    echo "[PASS] Test user ID: {$userId}\n";

    // 2. Обеспечиваем наличие тестового канала Telegram
    $channelType = 'telegram';
    $channelName = 'Test_Dev_Bot';
    $stmt = $db->prepare('SELECT id FROM channels WHERE user_id = ? AND type = ?');
    $stmt->execute([$userId, $channelType]);
    $channelId = $stmt->fetchColumn();
    
    if ($channelId === false) {
        echo "[INFO] Creating test channel...\n";
        $stmt = $db->prepare('INSERT INTO channels (user_id, type, name, status) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $channelType, $channelName, 'connected']);
        $channelId = (int) $db->lastInsertId();
    } else {
        $channelId = (int) $channelId;
    }
    echo "[PASS] Test channel ID: {$channelId}\n";

    // Инициализируем сервисы
    $chatService = new ChatService($db);
    $messageService = new MessageService($db);

    // 3. Тестируем ChatService::getOrCreateChat
    $clientExternalId = 'test_tg_user_' . time();
    $clientName = 'John Doe';
    
    echo "[INFO] Creating or retrieving chat for external ID: {$clientExternalId}...\n";
    $chatId = $chatService->getOrCreateChat($channelId, $clientExternalId, $clientName);
    
    if ($chatId <= 0) {
        throw new Exception("Неверный ID чата: {$chatId}");
    }
    echo "[PASS] Chat successfully created/retrieved. Chat ID: {$chatId}\n";

    // Проверяем начальные значения счетчиков
    $stmt = $db->prepare('SELECT unread_count, status FROM chats WHERE id = ?');
    $stmt->execute([$chatId]);
    $chatInfo = $stmt->fetch();
    
    if ($chatInfo['unread_count'] !== 0) {
        throw new Exception("Начальный счетчик unread_count должен быть 0, получено: " . $chatInfo['unread_count']);
    }
    echo "[PASS] Initial unread_count is 0\n";

    // 4. Тестируем MessageService::recordMessage для входящего сообщения
    echo "[INFO] Recording incoming message...\n";
    $incomingText = "Hello from client!";
    $msgId1 = $messageService->recordMessage($chatId, 'incoming', $incomingText, 'text');
    
    if ($msgId1 <= 0) {
        throw new Exception("Неверный ID сообщения: {$msgId1}");
    }
    echo "[PASS] Incoming message recorded. Message ID: {$msgId1}\n";

    // Проверяем обновление unread_count
    $stmt = $db->prepare('SELECT unread_count FROM chats WHERE id = ?');
    $stmt->execute([$chatId]);
    $unreadCount = (int) $stmt->fetchColumn();
    
    if ($unreadCount !== 1) {
        throw new Exception("Неверный счетчик unread_count после входящего сообщения, ожидалось 1, получено: {$unreadCount}");
    }
    echo "[PASS] unread_count correctly incremented to 1\n";

    // 5. Тестируем MessageService::recordMessage для исходящего сообщения
    echo "[INFO] Recording outgoing message...\n";
    $outgoingText = "Hello from operator!";
    $msgId2 = $messageService->recordMessage($chatId, 'outgoing', $outgoingText, 'text');
    
    if ($msgId2 <= 0) {
        throw new Exception("Неверный ID сообщения: {$msgId2}");
    }
    echo "[PASS] Outgoing message recorded. Message ID: {$msgId2}\n";

    // Проверяем сброс unread_count
    $stmt = $db->prepare('SELECT unread_count FROM chats WHERE id = ?');
    $stmt->execute([$chatId]);
    $unreadCount = (int) $stmt->fetchColumn();
    
    if ($unreadCount !== 0) {
        throw new Exception("Неверный счетчик unread_count после исходящего сообщения, ожидалось 0, получено: {$unreadCount}");
    }
    echo "[PASS] unread_count correctly reset to 0\n";

    echo "\n[SUCCESS] All database services tests completed successfully!\n";

} catch (Throwable $e) {
    echo "\n[FAIL] Test failed with error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
