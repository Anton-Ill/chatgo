<?php

/**
 * Webhook Endpoint: MAX Bot API
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

use Chatgo\Services\ChatService;
use Chatgo\Services\MessageService;
use Chatgo\Adapters\MaxAdapter;
use Chatgo\Adapters\TelegramAdapter;

// MAX Bot API ожидает JSON-ответы
header('Content-Type: application/json');

try {
    $db = DB::getConnection();

    $channelId = isset($_GET['channel_id']) ? (int) $_GET['channel_id'] : null;

    if (!$channelId) {
        http_response_code(400);
        echo json_encode(['error' => 'channel_id parameter is required']);
        exit;
    }

    // 1. Получаем настройки канала
    $stmt = $db->prepare('SELECT type, settings FROM channels WHERE id = ?');
    $stmt->execute([$channelId]);
    $channel = $stmt->fetch();

    if (!$channel || $channel['type'] !== 'max') {
        http_response_code(404);
        echo json_encode(['error' => 'MAX channel not found']);
        exit;
    }

    $settings = json_decode($channel['settings'] ?? '{}', true);
    $verifyToken = $settings['verify_token'] ?? ''; // Используется как secret
    $accessToken = $settings['access_token'] ?? '';

    // 2. Проверка безопасности вебхука (secret key)
    if ($verifyToken !== '') {
        // В PHP заголовки вида X-Max-Bot-Api-Secret преобразуются в HTTP_X_MAX_BOT_API_SECRET
        $receivedSecret = $_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? '';
        if ($receivedSecret !== $verifyToken) {
            http_response_code(403);
            echo json_encode(['error' => 'Secret verification failed']);
            exit;
        }
    }

    // 3. Обработка входящих сообщений (POST-запрос)
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (!$payload) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        exit;
    }

    // Проверяем, что это событие MAX
    $adapter = new MaxAdapter($accessToken);
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
            'max',
            $parsed['client_name'],
            ['client_external_id' => $parsed['client_external_id']]
        );
    }

    // MAX API ожидает HTTP 200 для подтверждения получения
    http_response_code(200);
    echo json_encode(['status' => 'ok']);

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['error' => $e->getMessage()]);
}
