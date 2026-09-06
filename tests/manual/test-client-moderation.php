<?php

/**
 * Manual test: Client moderation lifecycle (pending -> active / rejected)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/Services/ChatService.php';

use Chatgo\Services\ChatService;

echo "--- Начинаем тест логики модерации клиентов ---\n";

try {
    $db = DB::getConnection();
    $chatService = new ChatService($db);

    // 1. Поиск Telegram-канала
    $stmt = $db->query("SELECT id FROM channels WHERE type = 'telegram' LIMIT 1");
    $channelId = (int) $stmt->fetchColumn();

    if (!$channelId) {
        throw new RuntimeException("Не найден канал с типом 'telegram' в базе данных.");
    }
    echo "[OK] Найден канал Telegram ID: {$channelId}\n";

    $testClientId = 'test_mod_client_' . time();
    $testClientName = 'Тестовый Клиент Модерации';

    // 2. Создание чата со статусом pending
    $chatId = $chatService->getOrCreateChat($channelId, $testClientId, $testClientName, null, null, 'pending');
    echo "[OK] Чат создан ID: {$chatId}\n";

    // 3. Проверка getChatById
    $chat = $chatService->getChatById($chatId);
    if (!$chat || $chat['status'] !== 'pending') {
        throw new RuntimeException("Ошибка: ожидался статус 'pending', получено: " . ($chat['status'] ?? 'null'));
    }
    echo "[OK] getChatById подтвердил статус 'pending'\n";

    // 4. Одобрение чата (updateStatus -> active)
    $updateResult = $chatService->updateStatus($chatId, 'active');
    if (!$updateResult) {
        throw new RuntimeException("Не удалось обновить статус до 'active'.");
    }
    $freshChat = $chatService->getChatById($chatId);
    if ($freshChat['status'] !== 'active') {
        throw new RuntimeException("Ожидался статус 'active', но получен '{$freshChat['status']}'.");
    }
    echo "[OK] Чат успешно одобрен (статус 'active')\n";

    // 5. Отклонение чата (updateStatus -> rejected)
    $updateResult = $chatService->updateStatus($chatId, 'rejected');
    if (!$updateResult) {
        throw new RuntimeException("Не удалось обновить статус до 'rejected'.");
    }
    $freshChat = $chatService->getChatById($chatId);
    if ($freshChat['status'] !== 'rejected') {
        throw new RuntimeException("Ожидался статус 'rejected', но получен '{$freshChat['status']}'.");
    }
    echo "[OK] Чат успешно отклонен (статус 'rejected')\n";

    // 6. Очистка тестового чата
    $delStmt = $db->prepare("DELETE FROM chats WHERE id = :id");
    $delStmt->execute([':id' => $chatId]);
    echo "[OK] Тестовый чат ID {$chatId} успешно очищен\n";

    echo "\n=== ВСЕ ТЕСТЫ МОДЕРАЦИИ УСПЕШНО ПРОЙДЕНЫ ===\n";
} catch (Throwable $e) {
    echo "\n[ОШИБКА] " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
