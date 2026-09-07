<?php

declare(strict_types=1);

namespace Chatgo\Services;

use PDO;
use Chatgo\Adapters\TelegramAdapter;

class TelegramUpdateProcessor
{
    private PDO $db;
    private TelegramAdapter $adapter;
    private ChatService $chatService;
    private MessageService $messageService;
    private int $channelId;

    public function __construct(
        PDO $db,
        TelegramAdapter $adapter,
        ChatService $chatService,
        MessageService $messageService,
        int $channelId
    ) {
        $this->db = $db;
        $this->adapter = $adapter;
        $this->chatService = $chatService;
        $this->messageService = $messageService;
        $this->channelId = $channelId;
    }

    /**
     * Обработать одно обновление Telegram
     *
     * @param array $payload
     * @return array
     */
    public function process(array $payload): array
    {
        $parsed = $this->adapter->parseWebhookPayload($payload);
        if ($parsed === null) {
            return ['ok' => true, 'description' => 'Апдейт пропущен (не текстовый)'];
        }

        // 1. Обработка callback_query (нажатия inline-кнопок)
        if ($parsed['type'] === 'callback_query') {
            return $this->handleCallbackQuery($parsed);
        }

        // 2. Команда /start bind_ от оператора
        if (str_starts_with($parsed['text'], '/start bind_')) {
            return $this->handleBindCommand($parsed);
        }

        // 3. Команда /start
        if (trim($parsed['text']) === '/start') {
            $handled = $this->handleStartCommand($parsed);
            if ($handled !== null) {
                return $handled;
            }
        }

        // 4. Сообщение от клиента и модерация
        return $this->handleClientMessage($parsed);
    }

    private function handleCallbackQuery(array $parsed): array
    {
        $callbackId = $parsed['callback_query_id'];
        $operatorTgId = $parsed['client_external_id'];
        $data = $parsed['data'];

        // Проверка прав оператора
        $isOperator = false;
        $opStmt = $this->db->prepare('SELECT id FROM users WHERE telegram_id = ? LIMIT 1');
        $opStmt->execute([$operatorTgId]);
        if ($opStmt->fetchColumn() !== false || (defined('OPERATOR_TELEGRAM_ID') && $operatorTgId === (string) OPERATOR_TELEGRAM_ID)) {
            $isOperator = true;
        }

        if (!$isOperator) {
            $this->adapter->answerCallbackQuery($callbackId, 'У вас нет прав для этого действия', true);
            return ['ok' => false, 'description' => 'Недостаточно прав'];
        }

        if (str_starts_with($data, 'approve_')) {
            $targetChatId = (int) substr($data, 8);
            $targetChat = $this->chatService->getChatById($targetChatId);
            if ($targetChat) {
                $this->chatService->updateStatus($targetChatId, 'active');
                $this->adapter->answerCallbackQuery($callbackId, 'Запрос одобрен');
                $this->adapter->editMessageText(
                    $parsed['chat_id'],
                    $parsed['message_id'],
                    "✅ Клиент {$targetChat['client_name']} одобрен оператором."
                );
                $this->adapter->sendMessage(
                    $targetChat['client_external_id'],
                    "✅ Ваш запрос на диалог одобрен! Оператор на связи, вы можете писать сообщения."
                );
            }
            return ['ok' => true, 'description' => 'Клиент одобрен'];
        }

        if (str_starts_with($data, 'reject_')) {
            $targetChatId = (int) substr($data, 7);
            $targetChat = $this->chatService->getChatById($targetChatId);
            if ($targetChat) {
                $this->chatService->updateStatus($targetChatId, 'rejected');
                $this->adapter->answerCallbackQuery($callbackId, 'Запрос отклонен');
                $this->adapter->editMessageText(
                    $parsed['chat_id'],
                    $parsed['message_id'],
                    "❌ Запрос от клиента {$targetChat['client_name']} отклонен."
                );
                $this->adapter->sendMessage(
                    $targetChat['client_external_id'],
                    "К сожалению, ваш запрос отклонен администратором."
                );
            }
            return ['ok' => true, 'description' => 'Клиент отклонен'];
        }

        $this->adapter->answerCallbackQuery($callbackId);
        return ['ok' => true];
    }

    private function handleBindCommand(array $parsed): array
    {
        $bindToken = substr($parsed['text'], 12);

        $stmtToken = $this->db->prepare('
            SELECT user_id FROM auth_tokens 
            WHERE token = ? AND used = 0 AND expires_at > ? 
            LIMIT 1
        ');
        $stmtToken->execute([$bindToken, date('Y-m-d H:i:s')]);
        $bindData = $stmtToken->fetch();

        if ($bindData) {
            $userIdToBind = (int) $bindData['user_id'];

            $stmtUpdate = $this->db->prepare('UPDATE users SET telegram_id = ? WHERE id = ?');
            $stmtUpdate->execute([$parsed['client_external_id'], $userIdToBind]);

            $stmtUseToken = $this->db->prepare('UPDATE auth_tokens SET used = 1 WHERE token = ?');
            $stmtUseToken->execute([$bindToken]);

            $this->adapter->sendMessage(
                $parsed['client_external_id'],
                "🎉 Уведомления Telegram успешно подключены!\nТеперь вы будете мгновенно получать сюда сообщения от клиентов."
            );
        } else {
            $this->adapter->sendMessage(
                $parsed['client_external_id'],
                "❌ Ошибка подключения: Ссылка привязки недействительна или устарела. Сгенерируйте новую ссылку в панели настроек."
            );
        }

        return ['ok' => true, 'description' => 'Команда привязки обработана'];
    }

    private function handleStartCommand(array $parsed): ?array
    {
        // 1. Проверка подтверждения входа через браузер: /start auth_<token>
        if (str_starts_with($parsed['text'], '/start auth_')) {
            $authToken = substr(trim($parsed['text']), 12);
            $stmtToken = $this->db->prepare('
                SELECT id, user_id, expires_at 
                FROM auth_tokens 
                WHERE token = ? AND expires_at > ? 
                LIMIT 1
            ');
            $stmtToken->execute([$authToken, date('Y-m-d H:i:s')]);
            $tokenData = $stmtToken->fetch();

            if ($tokenData) {
                $tgUserId = (string) $parsed['client_external_id'];
                $clientName = (string) ($parsed['client_name'] ?? '');

                $userStmt = $this->db->prepare('SELECT id FROM users WHERE telegram_id = ? LIMIT 1');
                $userStmt->execute([$tgUserId]);
                $userId = $userStmt->fetchColumn();

                if (!$userId) {
                    $insertUser = $this->db->prepare('
                        INSERT INTO users (telegram_id, first_name, created_at)
                        VALUES (?, ?, NOW())
                    ');
                    $insertUser->execute([$tgUserId, $clientName]);
                    $userId = $this->db->lastInsertId();
                }

                $updateToken = $this->db->prepare('UPDATE auth_tokens SET user_id = ?, used = 1 WHERE id = ?');
                $updateToken->execute([$userId, $tokenData['id']]);

                $this->adapter->sendMessage(
                    $parsed['client_external_id'],
                    "🎉 Вход в Chatgo успешно подтвержден!\n\nВернитесь на страницу сервиса в браузере — авторизация завершится автоматически."
                );
                return ['ok' => true, 'description' => 'Авторизация в браузере подтверждена'];
            } else {
                $this->adapter->sendMessage(
                    $parsed['client_external_id'],
                    "❌ Ссылка для входа устарела или недействительна. Запросите новую ссылку в браузере."
                );
                return ['ok' => false, 'description' => 'Недействительный токен входа'];
            }
        }

        $hasBoundOperator = (bool) $this->db->query('SELECT id FROM users WHERE telegram_id IS NOT NULL LIMIT 1')->fetchColumn();
        if (!$hasBoundOperator) {
            $firstUserId = $this->db->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
            if ($firstUserId) {
                $stmtUpdate = $this->db->prepare('UPDATE users SET telegram_id = ? WHERE id = ?');
                $stmtUpdate->execute([$parsed['client_external_id'], $firstUserId]);
                $this->adapter->sendMessage(
                    $parsed['client_external_id'],
                    "👋 Добро пожаловать! Ваш Telegram ID успешно привязан к аккаунту администратора Chatgo.\n\nНажмите кнопку «Панель» в левом нижнем углу для входа в дашборд."
                );
                return ['ok' => true, 'description' => 'Первый оператор автоматически привязан'];
            }
        } else {
            $checkStmt = $this->db->prepare('SELECT id FROM users WHERE telegram_id = ? LIMIT 1');
            $checkStmt->execute([$parsed['client_external_id']]);
            if ($checkStmt->fetchColumn() !== false) {
                $this->adapter->sendMessage(
                    $parsed['client_external_id'],
                    "👋 Здравствуйте! Вы авторизованы как оператор Chatgo.\n\nНажмите кнопку «Панель» в левом нижнем углу для открытия дашборда."
                );
                return ['ok' => true, 'description' => 'Приветствие оператора'];
            }
        }

        return null;
    }

    private function handleClientMessage(array $parsed): array
    {
        // Проверяем существование чата с клиентом
        $checkChatStmt = $this->db->prepare('SELECT id, status FROM chats WHERE channel_id = ? AND client_external_id = ? LIMIT 1');
        $checkChatStmt->execute([$this->channelId, $parsed['client_external_id']]);
        $existingChat = $checkChatStmt->fetch();

        $isFirstMessage = ($existingChat === false);
        $currentStatus = $existingChat ? $existingChat['status'] : 'pending';

        // Если чат отклонен оператором — игнорируем
        if ($currentStatus === 'rejected') {
            return ['ok' => true, 'description' => 'Сообщение отклоненного клиента проигнорировано'];
        }

        // Регистрируем/получаем чат (новые чаты создаются со статусом pending)
        $chatId = $this->chatService->getOrCreateChat(
            $this->channelId,
            $parsed['client_external_id'],
            $parsed['client_name'],
            null,
            null,
            'pending'
        );

        // Записываем входящее сообщение
        $messageId = $this->messageService->recordMessage(
            $chatId,
            'incoming',
            $parsed['text'],
            $parsed['type'],
            null,
            $parsed['external_id']
        );

        // Получаем Telegram ID владельца этого канала для уведомления
        $stmtOwner = $this->db->prepare('
            SELECT u.telegram_id 
            FROM channels ch
            JOIN users u ON ch.user_id = u.id
            WHERE ch.id = ? AND u.telegram_id IS NOT NULL AND u.telegram_id != ""
            LIMIT 1
        ');
        $stmtOwner->execute([$this->channelId]);
        $operatorTelegramId = $stmtOwner->fetchColumn();

        if (!$operatorTelegramId && defined('OPERATOR_TELEGRAM_ID')) {
            $operatorTelegramId = OPERATOR_TELEGRAM_ID;
        }

        // Если чат на модерации (pending)
        if ($isFirstMessage || $currentStatus === 'pending') {
            if ($isFirstMessage) {
                // Клиенту отправляем сообщение об ожидании подтверждения
                $this->adapter->sendMessage(
                    $parsed['client_external_id'],
                    "Ваш запрос на диалог передан оператору. Пожалуйста, ожидайте подтверждения."
                );
            }

            // Оператору отправляем запрос с кнопками «Одобрить» / «Отклонить»
            if ($operatorTelegramId && (string) $parsed['client_external_id'] !== (string) $operatorTelegramId) {
                $notifyText = "🔔 Новый запрос на диалог!\n"
                    . "От: {$parsed['client_name']} (ID: {$parsed['client_external_id']})\n"
                    . "Сообщение: \"{$parsed['text']}\"";

                $inlineKeyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '✅ Одобрить', 'callback_data' => "approve_{$chatId}"],
                            ['text' => '❌ Отклонить', 'callback_data' => "reject_{$chatId}"]
                        ]
                    ]
                ];

                $this->adapter->sendMessage(
                    (string) $operatorTelegramId,
                    $notifyText,
                    ['reply_markup' => $inlineKeyboard]
                );
            }
        } elseif ($currentStatus === 'active') {
            // Обычное сообщение от подтвержденного клиента
            if ($operatorTelegramId && (string) $parsed['client_external_id'] !== (string) $operatorTelegramId) {
                $notifyText = "💬 {$parsed['client_name']}:\n\"{$parsed['text']}\"";
                $this->adapter->sendMessage((string) $operatorTelegramId, $notifyText);
            }
        }

        return [
            'ok' => true,
            'message_id' => $messageId,
            'chat_id' => $chatId,
            'status' => $currentStatus
        ];
    }
}
