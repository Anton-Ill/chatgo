<?php

/**
 * REST API: Send message from operator to client
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

use Chatgo\Services\MessageService;
use Chatgo\Adapters\TelegramAdapter;
use Chatgo\Adapters\VkAdapter;

use Chatgo\Security\WebAppAuthenticator;

header('Content-Type: application/json');

try {
    $db = DB::getConnection();

    // Проверка авторизации
    if (!WebAppAuthenticator::authenticate($db)) {
        echo json_encode([
            'ok' => false,
            'error' => 'Доступ запрещен: Не авторизован'
        ]);
        exit;
    }

    // Читаем JSON POST запрос
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    $chatId = isset($payload['chat_id']) ? (int) $payload['chat_id'] : null;
    $text = isset($payload['text']) ? trim($payload['text']) : '';

    if (!$chatId || empty($text)) {
        throw new Exception("Параметры chat_id и text обязательны.");
    }

    // 1. Получаем информацию о чате и клиенте
    $stmt = $db->prepare('
        SELECT c.client_external_id, c.channel_id, ch.type AS channel_type, ch.settings AS channel_settings
        FROM chats c
        JOIN channels ch ON c.channel_id = ch.id
        WHERE c.id = ?
    ');
    $stmt->execute([$chatId]);
    $chatData = $stmt->fetch();

    if (!$chatData) {
        throw new Exception("Чат с ID {$chatId} не найден.");
    }

    $channelType = $chatData['channel_type'];
    $clientExternalId = $chatData['client_external_id'];
    $settings = json_decode($chatData['channel_settings'] ?? '{}', true);

    // 2. Выбираем нужный адаптер и отправляем
    $sent = false;
    if ($channelType === 'telegram') {
        $botToken = $settings['token'] ?? null;
        if (!$botToken) {
            throw new Exception("Токен бота не настроен для данного канала.");
        }
        
        $adapter = new TelegramAdapter($botToken, TELEGRAM_API_URL, CHATGO_SECRET);
        $sent = $adapter->sendMessage($clientExternalId, $text);
    } elseif ($channelType === 'vk') {
        $accessToken = $settings['access_token'] ?? null;
        if (!$accessToken) {
            throw new Exception("Токен доступа VK не настроен для данного канала.");
        }
        
        $adapter = new VkAdapter($accessToken);
        $sent = $adapter->sendMessage($clientExternalId, $text);
    } else {
        throw new Exception("Тип канала '{$channelType}' пока не поддерживается для отправки.");
    }

    if (!$sent) {
        throw new Exception("Не удалось отправить сообщение во внешнюю систему.");
    }

    // 3. Записываем исходящее сообщение в БД
    $messageService = new MessageService($db);
    $messageId = $messageService->recordMessage($chatId, 'outgoing', $text, 'text');

    echo json_encode([
        'ok' => true,
        'message_id' => $messageId
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
