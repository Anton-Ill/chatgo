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

    // Перехватываем команду /start bind_ от оператора
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

    // Регистрируем/получаем чат
    $chatId = $chatService->getOrCreateChat(
        $channelId,
        $parsed['client_external_id'],
        $parsed['client_name']
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

    // Отправляем уведомление оператору, если ID настроен и сообщение пришло от клиента
    if (defined('OPERATOR_TELEGRAM_ID') && OPERATOR_TELEGRAM_ID !== '' && $parsed['client_external_id'] !== OPERATOR_TELEGRAM_ID) {
        $notifyText = "🔔 Новое сообщение от {$parsed['client_name']}:\n\"{$parsed['text']}\"";
        $adapter->sendMessage(OPERATOR_TELEGRAM_ID, $notifyText);
    }

    echo json_encode([
        'ok' => true,
        'message_id' => $messageId,
        'chat_id' => $chatId
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
