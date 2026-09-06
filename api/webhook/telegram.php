<?php

/**
 * Webhook handler for Telegram Bot API
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

use Chatgo\Services\ChatService;
use Chatgo\Services\MessageService;
use Chatgo\Adapters\TelegramAdapter;

header('Content-Type: application/json');

try {
    $db = DB::getConnection();

    // Получаем channel_id из GET-параметра
    $channelId = isset($_GET['channel_id']) ? (int) $_GET['channel_id'] : null;

    if (!$channelId) {
        // Поиск первого доступного Telegram канала в БД, если ID не передан
        $stmt = $db->query("SELECT id, settings FROM channels WHERE type = 'telegram' LIMIT 1");
        $channel = $stmt->fetch();
        if (!$channel) {
            throw new Exception("Канал Telegram не настроен в базе данных.");
        }
        $channelId = (int) $channel['id'];
        $settings = json_decode($channel['settings'] ?? '{}', true);
    } else {
        // Выбираем переданный канал
        $stmt = $db->prepare("SELECT settings FROM channels WHERE id = ? AND type = 'telegram'");
        $stmt->execute([$channelId]);
        $channelSettings = $stmt->fetchColumn();
        if ($channelSettings === false) {
            throw new Exception("Канал с ID {$channelId} не найден или не является Telegram.");
        }
        $settings = json_decode($channelSettings ?? '{}', true);
    }

    $botToken = $settings['token'] ?? null;
    if (!$botToken) {
        throw new Exception("Токен бота не найден в настройках канала.");
    }

    // Читаем входящий запрос
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (empty($payload)) {
        throw new Exception("Пустой запрос вебхука.");
    }

    // Инициализируем компоненты
    $adapter = new TelegramAdapter($botToken, TELEGRAM_API_URL, CHATGO_SECRET);
    $chatService = new ChatService($db);
    $messageService = new MessageService($db);

    // Парсим payload
    $parsed = $adapter->parseWebhookPayload($payload);
    if ($parsed === null) {
        echo json_encode(['ok' => true, 'description' => 'Апдейт пропущен (не текстовый)']);
        exit;
    }

    // 1. Обработка callback_query (нажатия inline-кнопок оператором)
    if ($parsed['type'] === 'callback_query') {
        $callbackId = $parsed['callback_query_id'];
        $operatorTgId = $parsed['client_external_id'];
        $data = $parsed['data'];

        // Проверка прав оператора
        $isOperator = false;
        $opStmt = $db->prepare('SELECT id FROM users WHERE telegram_id = ? LIMIT 1');
        $opStmt->execute([$operatorTgId]);
        if ($opStmt->fetchColumn() !== false || (defined('OPERATOR_TELEGRAM_ID') && $operatorTgId === (string) OPERATOR_TELEGRAM_ID)) {
            $isOperator = true;
        }

        if (!$isOperator) {
            $adapter->answerCallbackQuery($callbackId, 'У вас нет прав для этого действия', true);
            echo json_encode(['ok' => false, 'description' => 'Недостаточно прав']);
            exit;
        }

        if (str_starts_with($data, 'approve_')) {
            $targetChatId = (int) substr($data, 8);
            $targetChat = $chatService->getChatById($targetChatId);
            if ($targetChat) {
                $chatService->updateStatus($targetChatId, 'active');
                $adapter->answerCallbackQuery($callbackId, 'Запрос одобрен');
                $adapter->editMessageText(
                    $parsed['chat_id'],
                    $parsed['message_id'],
                    "✅ Клиент {$targetChat['client_name']} одобрен оператором."
                );
                // Уведомляем клиента
                $adapter->sendMessage(
                    $targetChat['client_external_id'],
                    "✅ Ваш запрос на диалог одобрен! Оператор на связи, вы можете писать сообщения."
                );
            }
            echo json_encode(['ok' => true, 'description' => 'Клиент одобрен']);
            exit;
        }

        if (str_starts_with($data, 'reject_')) {
            $targetChatId = (int) substr($data, 7);
            $targetChat = $chatService->getChatById($targetChatId);
            if ($targetChat) {
                $chatService->updateStatus($targetChatId, 'rejected');
                $adapter->answerCallbackQuery($callbackId, 'Запрос отклонен');
                $adapter->editMessageText(
                    $parsed['chat_id'],
                    $parsed['message_id'],
                    "❌ Запрос от клиента {$targetChat['client_name']} отклонен."
                );
                // Уведомляем клиента
                $adapter->sendMessage(
                    $targetChat['client_external_id'],
                    "К сожалению, ваш запрос отклонен администратором."
                );
            }
            echo json_encode(['ok' => true, 'description' => 'Клиент отклонен']);
            exit;
        }

        $adapter->answerCallbackQuery($callbackId);
        echo json_encode(['ok' => true]);
        exit;
    }

    // 2. Перехватываем команду /start bind_ от оператора
    if (str_starts_with($parsed['text'], '/start bind_')) {
        $bindToken = substr($parsed['text'], 12);
        
        $stmtToken = $db->prepare('
            SELECT user_id FROM auth_tokens 
            WHERE token = ? AND used = 0 AND expires_at > ? 
            LIMIT 1
        ');
        $stmtToken->execute([$bindToken, date('Y-m-d H:i:s')]);
        $bindData = $stmtToken->fetch();
        
        if ($bindData) {
            $userIdToBind = (int) $bindData['user_id'];
            
            // Привязываем Telegram ID к пользователю в БД
            $stmtUpdate = $db->prepare('UPDATE users SET telegram_id = ? WHERE id = ?');
            $stmtUpdate->execute([$parsed['client_external_id'], $userIdToBind]);
            
            // Помечаем токен как использованный
            $stmtUseToken = $db->prepare('UPDATE auth_tokens SET used = 1 WHERE token = ?');
            $stmtUseToken->execute([$bindToken]);
            
            $adapter->sendMessage(
                $parsed['client_external_id'],
                "🎉 Уведомления Telegram успешно подключены!\nТеперь вы будете мгновенно получать сюда сообщения от клиентов."
            );
        } else {
            $adapter->sendMessage(
                $parsed['client_external_id'],
                "❌ Ошибка подключения: Ссылка привязки недействительна или устарела. Сгенерируйте новую ссылку в панели настроек."
            );
        }
        
        echo json_encode(['ok' => true, 'description' => 'Команда привязки обработана']);
        exit;
    }

    // 3. Обработка команды /start
    if (trim($parsed['text']) === '/start') {
        $hasBoundOperator = (bool) $db->query('SELECT id FROM users WHERE telegram_id IS NOT NULL LIMIT 1')->fetchColumn();
        if (!$hasBoundOperator) {
            $firstUserId = $db->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
            if ($firstUserId) {
                $stmtUpdate = $db->prepare('UPDATE users SET telegram_id = ? WHERE id = ?');
                $stmtUpdate->execute([$parsed['client_external_id'], $firstUserId]);
                $adapter->sendMessage(
                    $parsed['client_external_id'],
                    "👋 Добро пожаловать! Ваш Telegram ID успешно привязан к аккаунту администратора Chatgo.\n\nНажмите кнопку «Панель» в левом нижнем углу для входа в дашборд."
                );
                echo json_encode(['ok' => true, 'description' => 'Первый оператор автоматически привязан']);
                exit;
            }
        } else {
            $checkStmt = $db->prepare('SELECT id FROM users WHERE telegram_id = ? LIMIT 1');
            $checkStmt->execute([$parsed['client_external_id']]);
            if ($checkStmt->fetchColumn() !== false) {
                $adapter->sendMessage(
                    $parsed['client_external_id'],
                    "👋 Здравствуйте! Вы авторизованы как оператор Chatgo.\n\nНажмите кнопку «Панель» в левом нижнем углу для открытия дашборда."
                );
                echo json_encode(['ok' => true, 'description' => 'Приветствие оператора']);
                exit;
            }
        }
    }

    // 4. Проверяем существование чата с клиентом
    $checkChatStmt = $db->prepare('SELECT id, status FROM chats WHERE channel_id = ? AND client_external_id = ? LIMIT 1');
    $checkChatStmt->execute([$channelId, $parsed['client_external_id']]);
    $existingChat = $checkChatStmt->fetch();

    $isFirstMessage = ($existingChat === false);
    $currentStatus = $existingChat ? $existingChat['status'] : 'pending';

    // Если чат отклонен оператором — игнорируем
    if ($currentStatus === 'rejected') {
        echo json_encode(['ok' => true, 'description' => 'Сообщение отклоненного клиента проигнорировано']);
        exit;
    }

    // Регистрируем/получаем чат (новые чаты создаются со статусом pending)
    $chatId = $chatService->getOrCreateChat(
        $channelId,
        $parsed['client_external_id'],
        $parsed['client_name'],
        null,
        null,
        'pending'
    );

    // Записываем входящее сообщение
    $messageId = $messageService->recordMessage(
        $chatId,
        'incoming',
        $parsed['text'],
        $parsed['type'],
        null,
        $parsed['external_id']
    );

    // Получаем Telegram ID оператора для уведомления
    $operatorTelegramId = $db->query('SELECT telegram_id FROM users WHERE telegram_id IS NOT NULL LIMIT 1')->fetchColumn();
    if (!$operatorTelegramId && defined('OPERATOR_TELEGRAM_ID')) {
        $operatorTelegramId = OPERATOR_TELEGRAM_ID;
    }

    // Если чат на модерации (pending)
    if ($isFirstMessage || $currentStatus === 'pending') {
        if ($isFirstMessage) {
            // Клиенту отправляем сообщение об ожидании подтверждения
            $adapter->sendMessage(
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

            $adapter->sendMessage(
                (string) $operatorTelegramId,
                $notifyText,
                ['reply_markup' => $inlineKeyboard]
            );
        }
    } elseif ($currentStatus === 'active') {
        // Обычное сообщение от подтвержденного клиента
        if ($operatorTelegramId && (string) $parsed['client_external_id'] !== (string) $operatorTelegramId) {
            $notifyText = "💬 {$parsed['client_name']}:\n\"{$parsed['text']}\"";
            $adapter->sendMessage((string) $operatorTelegramId, $notifyText);
        }
    }

    echo json_encode([
        'ok' => true,
        'message_id' => $messageId,
        'chat_id' => $chatId,
        'status' => $currentStatus
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
