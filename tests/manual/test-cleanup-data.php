<?php

/**
 * Diagnostic & Cleanup Script: Очистка продакшн-БД от тестовых каналов, диалогов и сообщений
 * tests/manual/test-cleanup-data.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

echo "=======================================================\n";
echo "   ОЧИСТКА БАЗЫ ДАННЫХ CHATGO ОТ ТЕСТОВЫХ ЗАПИСЕЙ      \n";
echo "=======================================================\n\n";

try {
    $db = DB::getConnection();

    // 1. Находим все тестовые каналы
    $stmtFind = $db->query("
        SELECT id, name, type 
        FROM channels 
        WHERE name LIKE 'Test_%' 
           OR name LIKE 'Тест%' 
           OR settings LIKE '%MOCK%'
    ");
    $testChannels = $stmtFind->fetchAll();

    echo "Найдено тестовых каналов для удаления: " . count($testChannels) . "\n";
    foreach ($testChannels as $ch) {
        echo "  - [ID {$ch['id']}] [{$ch['type']}] {$ch['name']}\n";
    }

    // 2. Удаляем тестовые каналы (каскадно удалятся связанные chats и messages)
    $deletedChannels = 0;
    if (!empty($testChannels)) {
        $ids = array_map(fn($c) => (int)$c['id'], $testChannels);
        $inClause = implode(',', $ids);
        $deletedChannels = $db->exec("DELETE FROM channels WHERE id IN ({$inClause})");
    }
    echo "Удалено каналов: {$deletedChannels}\n";

    // 3. Удаляем тестовые диалоги (включая накопившиеся сервисные тесты)
    $deletedChats = $db->exec("
        DELETE FROM chats 
        WHERE client_name LIKE 'Test%' 
           OR client_name LIKE 'Тест%' 
           OR client_name LIKE '%Tester%'
           OR client_name IN ('RemoteTestUser', 'NewBotTester', 'TelegramTester', 'ProdClient')
           OR client_external_id LIKE 'mock_%'
           OR client_external_id LIKE 'test_%'
    ");
    echo "Удалено тестовых диалогов: {$deletedChats}\n";

    // 4. Очищаем сиротские сообщения (если остались без чата)
    $deletedMessages = $db->exec("
        DELETE FROM messages 
        WHERE chat_id NOT IN (SELECT id FROM chats)
    ");
    echo "Удалено остаточных сообщений: {$deletedMessages}\n";

    // 4. Проверяем оставшиеся каналы
    $remaining = $db->query("SELECT id, user_id, name, type, status FROM channels")->fetchAll();
    echo "\nОставшиеся каналы в базе данных:\n";
    if (empty($remaining)) {
        echo "  [База чиста: 0 каналов]\n";
    } else {
        foreach ($remaining as $r) {
            echo "  - [ID {$r['id']}] UserID: {$r['user_id']} | {$r['type']} | {$r['name']} ({$r['status']})\n";
        }
    }

    echo "\n✅ Очистка успешно завершена!\n";

} catch (Throwable $e) {
    echo "❌ Ошибка при очистке: " . $e->getMessage() . "\n";
}
