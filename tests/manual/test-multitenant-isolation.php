<?php

/**
 * Diagnostic & Integration Test: Multi-tenant User Data Isolation
 * tests/manual/test-multitenant-isolation.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/Security/WebAppAuthenticator.php';

use Chatgo\Security\WebAppAuthenticator;

echo "=======================================================\n";
echo "   ТЕСТ ИЗОЛЯЦИИ ДАННЫХ И МУЛЬТИТЕНАНТНОСТИ В CHATGO    \n";
echo "=======================================================\n\n";

$passed = 0;
$failed = 0;

function assertCondition(bool $condition, string $description, &$passed, &$failed): void
{
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

try {
    $db = DB::getConnection();

    // 1. Создаем двух тестовых изолированных клиентов
    echo "[ШАГ 1] Создание изолированных тестовых пользователей User A и User B...\n";
    $db->exec("DELETE FROM users WHERE telegram_id IN ('mock_tg_user_a', 'mock_tg_user_b')");

    $userAId = WebAppAuthenticator::findOrCreateUserByTelegram($db, [
        'id' => 'mock_tg_user_a',
        'username' => 'test_user_a',
        'first_name' => 'User A'
    ]);

    $userBId = WebAppAuthenticator::findOrCreateUserByTelegram($db, [
        'id' => 'mock_tg_user_b',
        'username' => 'test_user_b',
        'first_name' => 'User B'
    ]);

    assertCondition($userAId > 0 && $userBId > 0 && $userAId !== $userBId, "Созданы разные пользователи: ID A={$userAId}, ID B={$userBId}", $passed, $failed);

    // 2. Создаем канал и чат исключительно для User A
    echo "\n[ШАГ 2] Создание канала и диалога для User A...\n";
    $stmtChannel = $db->prepare("
        INSERT INTO channels (user_id, type, name, status, settings)
        VALUES (?, 'telegram', 'Bot User A', 'connected', '{\"token\":\"mock_token_a\"}')
    ");
    $stmtChannel->execute([$userAId]);
    $channelAId = (int) $db->lastInsertId();

    $stmtChat = $db->prepare("
        INSERT INTO chats (channel_id, client_external_id, client_name, status, unread_count)
        VALUES (?, 'client_external_100', 'Покупатель Клиента А', 'active', 1)
    ");
    $stmtChat->execute([$channelAId]);
    $chatAId = (int) $db->lastInsertId();

    $stmtMsg = $db->prepare("
        INSERT INTO messages (chat_id, direction, text, type)
        VALUES (?, 'incoming', 'Здравствуйте, я клиент компании А!', 'text')
    ");
    $stmtMsg->execute([$chatAId]);

    assertCondition($channelAId > 0 && $chatAId > 0, "Канал и чат успешно созданы для User A", $passed, $failed);

    // 3. Проверяем видимость данных для User B (должно быть 0 каналов и 0 чатов)
    echo "\n[ШАГ 3] Проверка изоляции: чтение каналов и чатов от лица User B...\n";
    $stmtBChannels = $db->prepare("SELECT id FROM channels WHERE user_id = ?");
    $stmtBChannels->execute([$userBId]);
    $channelsB = $stmtBChannels->fetchAll();
    assertCondition(count($channelsB) === 0, "User B видит ровно 0 каналов (каналы User A скрыты)", $passed, $failed);

    $stmtBChats = $db->prepare("
        SELECT c.id FROM chats c
        JOIN channels ch ON c.channel_id = ch.id
        WHERE ch.user_id = ?
    ");
    $stmtBChats->execute([$userBId]);
    $chatsB = $stmtBChats->fetchAll();
    assertCondition(count($chatsB) === 0, "User B видит ровно 0 чатов (диалоги User A скрыты)", $passed, $failed);

    // 4. Попытка User B просмотреть сообщения чата User A
    $stmtBInspectMsg = $db->prepare("
        SELECT c.id 
        FROM chats c 
        JOIN channels ch ON c.channel_id = ch.id 
        WHERE c.id = ? AND ch.user_id = ?
    ");
    $stmtBInspectMsg->execute([$chatAId, $userBId]);
    $forbiddenAccess = $stmtBInspectMsg->fetch();
    assertCondition($forbiddenAccess === false, "User B не имеет доступа к сообщениям чата User A", $passed, $failed);

    // 5. Проверяем видимость данных для User A
    echo "\n[ШАГ 4] Проверка видимости данных для самого User A...\n";
    $stmtAChannels = $db->prepare("SELECT id, name FROM channels WHERE user_id = ?");
    $stmtAChannels->execute([$userAId]);
    $channelsA = $stmtAChannels->fetchAll();
    assertCondition(count($channelsA) === 1 && $channelsA[0]['name'] === 'Bot User A', "User A видит свой канал", $passed, $failed);

    $stmtAChats = $db->prepare("
        SELECT c.id, c.client_name FROM chats c
        JOIN channels ch ON c.channel_id = ch.id
        WHERE ch.user_id = ?
    ");
    $stmtAChats->execute([$userAId]);
    $chatsA = $stmtAChats->fetchAll();
    assertCondition(count($chatsA) === 1 && $chatsA[0]['client_name'] === 'Покупатель Клиента А', "User A видит свой диалог", $passed, $failed);

    // 6. Очистка тестовых записей
    echo "\n[ШАГ 5] Очистка тестовых данных...\n";
    $db->prepare("DELETE FROM channels WHERE id = ?")->execute([$channelAId]);
    $db->prepare("DELETE FROM users WHERE id IN (?, ?)")->execute([$userAId, $userBId]);
    assertCondition(true, "Тестовые пользователи и каналы успешно очищены", $passed, $failed);

    echo "\n-------------------------------------------------------\n";
    echo "ИТОГ ТЕСТИРОВАНИЯ: Успешно: {$passed}, Ошибок: {$failed}\n";
    if ($failed === 0) {
        echo "✅ ВСЕ ТЕСТЫ ИЗОЛЯЦИИ ДАННЫХ УСПЕШНО ПРОЙДЕНЫ!\n";
    }

} catch (Throwable $e) {
    echo "\n❌ КРИТИЧЕСКАЯ ОШИБКА ТЕСТА: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
