<?php

/**
 * Webhook Endpoint: VK (ВКонтакте) Callback API
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

use Chatgo\Services\ChatService;
use Chatgo\Services\MessageService;
use Chatgo\Adapters\VkAdapter;
use Chatgo\Adapters\TelegramAdapter;

// VK ожидает обычную текстовую строку (Confirmation string или "ok")
header('Content-Type: text/plain; charset=utf-8');

try {
    $db = DB::getConnection();

    $channelId = isset($_GET['channel_id']) ? (int) $_GET['channel_id'] : null;

    if (!$channelId) {
        http_response_code(400);
        exit('Error: channel_id parameter is required');
    }

    // 1. Получаем настройки канала
    $stmt = $db->prepare('SELECT type, settings FROM channels WHERE id = ?');
    $stmt->execute([$channelId]);
    $channel = $stmt->fetch();

    if (!$channel || $channel['type'] !== 'vk') {
        http_response_code(404);
        exit('Error: VK channel not found');
    }

    $settings = json_decode($channel['settings'] ?? '{}', true);
    $secretKey = $settings['secret_key'] ?? '';
    $confirmationCode = $settings['confirmation_code'] ?? '';
    $accessToken = $settings['access_token'] ?? '';

    // 2. Получаем тело запроса
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (!$payload || !isset($payload['type'])) {
        http_response_code(400);
        exit('Error: Invalid JSON payload');
    }

    // 3. Обработка подтверждения адреса сервера (Confirmation)
    if ($payload['type'] === 'confirmation') {
        exit($confirmationCode);
    }

    // 4. Проверка секретного ключа (если он задан в настройках)
    if ($secretKey !== '' && ($payload['secret'] ?? '') !== $secretKey) {
        http_response_code(403);
        exit('Error: Invalid secret key');
    }

    // 5. Обработка входящего сообщения
    if ($payload['type'] === 'message_new') {
        $adapter = new VkAdapter($accessToken, $secretKey, $confirmationCode);
        $parsed = $adapter->parseWebhookPayload($payload);

        if ($parsed) {
            $chatService = new ChatService($db);
            $messageService = new MessageService($db);

            // Находим или создаем чат с клиентом
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

            // Отправляем уведомление оператору в Telegram через единый NotificationService
            $notificationService = new \Chatgo\Services\NotificationService($db);
            $notificationService->notifyNewMessage(
                $chatId,
                $parsed['text'],
                'vk',
                $parsed['client_name'],
                ['client_external_id' => $parsed['client_external_id']]
            );
        }
    }

    // VK всегда требует возвращать строку "ok" для всех запросов кроме confirmation
    exit('ok');

} catch (Throwable $e) {
    http_response_code(500);
    exit('Error: ' . $e->getMessage());
}
