<?php

/**
 * Manual test: NotificationService, bot_notifications mapping, and Telegram Reply bridge
 * tests/manual/test-notification-bridge.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/Adapters/TelegramAdapter.php';
require_once __DIR__ . '/../../src/Services/ChatService.php';
require_once __DIR__ . '/../../src/Services/MessageService.php';
require_once __DIR__ . '/../../src/Services/NotificationService.php';
require_once __DIR__ . '/../../src/Services/MessageSenderService.php';
require_once __DIR__ . '/../../src/Services/TelegramUpdateProcessor.php';

use Chatgo\Adapters\TelegramAdapter;
use Chatgo\Services\ChatService;
use Chatgo\Services\MessageService;
use Chatgo\Services\NotificationService;
use Chatgo\Services\MessageSenderService;
use Chatgo\Services\TelegramUpdateProcessor;

echo "=======================================================\n";
echo "   ТЕСТ: NotificationService и Reply-Bridge в Telegram \n";
echo "=======================================================\n\n";

try {
    $db = DB::getConnection();
    echo "[ШАГ 1] Проверка подключения к БД и таблицы bot_notifications...\n";

    $tblStmt = $db->query("SHOW TABLES LIKE 'bot_notifications'");
    if (!$tblStmt->fetch()) {
        throw new RuntimeException("Таблица 'bot_notifications' не существует!");
    }
    echo "  [OK] Таблица 'bot_notifications' создана и доступна.\n";

    // Создаем тестового пользователя-оператора
    $testOpTgId = (string) (900000000 + (time() % 100000000));
    $stmtUser = $db->prepare("INSERT INTO users (email, telegram_id) VALUES (?, ?)");
    $testEmail = 'op_' . time() . '@chatgo.test';
    $stmtUser->execute([$testEmail, $testOpTgId]);
    $testUserId = (int) $db->lastInsertId();
    echo "  [OK] Создан тестовый оператор ID {$testUserId} с Telegram ID: {$testOpTgId}\n";

    // Создаем тестовый канал
    $stmtCh = $db->prepare("
        INSERT INTO channels (user_id, type, name, status, settings)
        VALUES (?, 'whatsapp', 'Test WA Channel', 'connected', '{\"access_token\":\"dummy\",\"phone_number_id\":\"12345\"}')
    ");
    $stmtCh->execute([$testUserId]);
    $channelId = (int) $db->lastInsertId();
    echo "  [OK] Создан тестовый канал ID {$channelId} (WhatsApp)\n";

    // Создаем тестовый чат
    $chatService = new ChatService($db);
    $messageService = new MessageService($db);
    $testClientExtId = 'client_' . time();
    $chatId = $chatService->getOrCreateChat($channelId, $testClientExtId, 'Иван Клиент', null, null, 'active');
    echo "  [OK] Создан тестовый диалог ID {$chatId}\n";

    // --- ШАГ 2: Проверка работы NotificationService ---
    echo "\n[ШАГ 2] Проверка NotificationService::recordBotNotification и getChatIdByReplyMessageId...\n";
    $notificationService = new NotificationService($db);

    $testBotMsgId = 987654321;
    $notificationService->recordBotNotification($testBotMsgId, $testOpTgId, $chatId);

    $resolvedChatId = $notificationService->getChatIdByReplyMessageId($testBotMsgId, $testOpTgId);
    if ($resolvedChatId !== $chatId) {
        throw new RuntimeException("Не удалось найти chat_id по bot_message_id! Ожидался {$chatId}, получен " . var_export($resolvedChatId, true));
    }
    echo "  [OK] Связка bot_message_id ({$testBotMsgId}) ⇄ chat_id ({$chatId}) успешно сохранена и найдена.\n";

    // --- ШАГ 3: Проверка маппинга оператора канала ---
    echo "\n[ШАГ 3] Проверка NotificationService::getOperatorTelegramId...\n";
    $opTgIdFound = $notificationService->getOperatorTelegramId($chatId);
    if ($opTgIdFound !== $testOpTgId) {
        throw new RuntimeException("Оператор канала определен неверно: '{$opTgIdFound}' вместо '{$testOpTgId}'");
    }
    echo "  [OK] Владелец канала определен корректно: {$opTgIdFound}\n";

    // --- ШАГ 4: Эмуляция ответа оператора через TelegramUpdateProcessor (Reply Bridge) ---
    echo "\n[ШАГ 4] Эмуляция ответа оператора через цитирование (Reply) в Telegram...\n";

    // Создаем Mock TelegramAdapter для предотвращения реальных внешних сетевых вызовов
    $mockAdapter = new class('fake_token', 'http://fake.api', 'fake_secret') extends TelegramAdapter {
        public array $sentMessages = [];

        public function sendMessage(string $clientExternalId, string $text, array $options = []): bool
        {
            $this->sentMessages[] = [
                'to' => $clientExternalId,
                'text' => $text,
                'options' => $options
            ];
            return true;
        }

        public function sendMessageWithResult(string $clientExternalId, string $text, array $options = []): ?array
        {
            $this->sentMessages[] = [
                'to' => $clientExternalId,
                'text' => $text,
                'options' => $options
            ];
            return [
                'ok' => true,
                'result' => [
                    'message_id' => 555001,
                    'chat' => ['id' => $clientExternalId]
                ]
            ];
        }
    };

    // Создаем Mock MessageSenderService, чтобы проверить вызов
    $mockSenderService = new class($db, $messageService) extends MessageSenderService {
        public array $dispatched = [];

        public function send(int $chatId, string $text): array
        {
            $this->dispatched[] = ['chat_id' => $chatId, 'text' => $text];
            // Вызываем реальную запись сообщения в БД
            $msgId = (new MessageService(DB::getConnection()))->recordMessage($chatId, 'outgoing', $text);
            return [
                'ok' => true,
                'message_id' => $msgId,
                'chat_data' => [
                    'channel_type' => 'whatsapp',
                    'client_name' => 'Иван Клиент'
                ]
            ];
        }
    };

    $processor = new TelegramUpdateProcessor(
        $db,
        $mockAdapter,
        $chatService,
        $messageService,
        $channelId,
        $notificationService,
        $mockSenderService
    );

    // Подготавливаем payload вебхука от оператора с reply_to_message
    $operatorReplyPayload = [
        'update_id' => 10001,
        'message' => [
            'message_id' => 7771,
            'from' => [
                'id' => (int) filter_var($testOpTgId, FILTER_SANITIZE_NUMBER_INT),
                'first_name' => 'Test',
                'username' => 'testop'
            ],
            'chat' => [
                'id' => (int) filter_var($testOpTgId, FILTER_SANITIZE_NUMBER_INT)
            ],
            'text' => 'Здравствуйте, ваш заказ уже в пути!',
            'reply_to_message' => [
                'message_id' => $testBotMsgId,
                'from' => [
                    'id' => 999999,
                    'is_bot' => true,
                    'first_name' => 'Chatgo Bot'
                ],
                'text' => '🟢 [WhatsApp] Иван Клиент: "Где заказ?"'
            ]
        ]
    ];

    // Вызываем обработку апдейта
    $replyResult = $processor->process($operatorReplyPayload);
    echo "  Результат обработки реплая: " . json_encode($replyResult, JSON_UNESCAPED_UNICODE) . "\n";

    if (!($replyResult['ok'] ?? false)) {
        throw new RuntimeException("Ошибка при обработке реплая: " . ($replyResult['description'] ?? ''));
    }

    if (empty($mockSenderService->dispatched)) {
        throw new RuntimeException("MessageSenderService не получил вызов отправки!");
    }
    echo "  [OK] MessageSenderService успешно вызван для chat_id {$chatId} с текстом ответа.\n";

    // Проверяем запись сообщения в БД
    $lastMsg = $db->query("SELECT id, chat_id, direction, text FROM messages WHERE chat_id = {$chatId} ORDER BY id DESC LIMIT 1")->fetch();
    if (!$lastMsg || $lastMsg['direction'] !== 'outgoing') {
        throw new RuntimeException("Исходящее сообщение не зафиксировано в таблице messages!");
    }
    echo "  [OK] Исходящее сообщение записано в БД: ID {$lastMsg['id']} | Направление: {$lastMsg['direction']} | Текст: \"{$lastMsg['text']}\"\n";

    // Проверяем, что оператор получил подтверждение об отправке
    $lastSentToOp = end($mockAdapter->sentMessages);
    echo "  [OK] Оператор получил подтверждение: \"{$lastSentToOp['text']}\"\n";

    // --- ОЧИСТКА ТЕСТОВЫХ ДАННЫХ ---
    echo "\n[ШАГ 5] Очистка тестовых данных...\n";
    $db->prepare("DELETE FROM bot_notifications WHERE bot_message_id = ?")->execute([$testBotMsgId]);
    $db->prepare("DELETE FROM messages WHERE chat_id = ?")->execute([$chatId]);
    $db->prepare("DELETE FROM chats WHERE id = ?")->execute([$chatId]);
    $db->prepare("DELETE FROM channels WHERE id = ?")->execute([$channelId]);
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$testUserId]);
    echo "  [OK] Тестовые данные успешно удалены.\n";

    echo "\n=======================================================\n";
    echo "        ВСЕ ТЕСТЫ NOTIFICATION BRIDGE ПРОЙДЕНЫ!         \n";
    echo "=======================================================\n";

} catch (Throwable $e) {
    echo "\n[ОШИБКА ТЕСТА]: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
