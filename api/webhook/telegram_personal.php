<?php

declare(strict_types=1);

/**
 * Webhook handler for Personal Telegram MTProto Service
 */

require_once __DIR__ . '/../../config/db.php';

use Chatgo\Services\ChatService;
use Chatgo\Services\MessageService;

header('Content-Type: application/json');

try {
    // 1. Проверка безопасности по секретному ключу
    $headers = getallheaders();
    $secretHeader = $headers['X-Chatgo-Secret'] ?? $headers['x-chatgo-secret'] ?? '';

    if (!defined('CHATGO_SECRET') || $secretHeader !== CHATGO_SECRET) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Доступ запрещен: Неверный секретный ключ']);
        exit;
    }

    // 2. Чтение входящих данных
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (empty($payload) || empty($payload['peer_id'])) {
        throw new Exception('Некорректный запрос: отсутствует peer_id');
    }

    $db = DB::getConnection();

    // 3. Поиск или создание канала Telegram в базе данных
    $stmt = $db->query("SELECT id, name FROM channels WHERE type = 'telegram' LIMIT 1");
    $channel = $stmt->fetch();

    if (!$channel) {
        // Получаем ID первого пользователя
        $userId = (int) ($db->query('SELECT id FROM users LIMIT 1')->fetchColumn() ?: 1);
        $stmtInsert = $db->prepare("
            INSERT INTO channels (user_id, type, name, status, settings, created_at)
            VALUES (?, 'telegram', 'Telegram (Личный)', 'connected', '{\"account_type\":\"personal\"}', NOW())
        ");
        $stmtInsert->execute([$userId]);
        $channelId = (int) $db->lastInsertId();
    } else {
        $channelId = (int) $channel['id'];
        // Убедимся, что статус канала connected
        $db->prepare("UPDATE channels SET status = 'connected' WHERE id = ?")->execute([$channelId]);
    }

    // 4. Извлечение параметров сообщения
    $clientExternalId = (string) $payload['peer_id'];
    $clientName = trim((string) ($payload['client_name'] ?? ''));
    if ($clientName === '') {
        $clientName = !empty($payload['client_username']) ? '@' . $payload['client_username'] : 'ID ' . $clientExternalId;
    }
    $clientPhone = !empty($payload['client_phone']) ? (string) $payload['client_phone'] : null;
    $text = (string) ($payload['text'] ?? '');
    $direction = ($payload['direction'] ?? 'incoming') === 'outgoing' ? 'outgoing' : 'incoming';
    $externalId = !empty($payload['message_id']) ? (string) $payload['message_id'] : null;

    // 5. Поиск или создание чата
    $chatService = new ChatService($db);
    $chatId = $chatService->getOrCreateChat(
        $channelId,
        $clientExternalId,
        $clientName,
        $clientPhone,
        null,
        'active'
    );

    // 6. Запись сообщения в БД
    $messageService = new MessageService($db);
    $messageId = $messageService->recordMessage(
        $chatId,
        $direction,
        $text,
        'text',
        null,
        $externalId
    );

    // 7. Уведомление оператора в Telegram при входящем сообщении
    if ($direction === 'incoming') {
        $notificationService = new \Chatgo\Services\NotificationService($db);
        $notificationService->notifyNewMessage(
            $chatId,
            $text,
            'telegram_personal',
            $clientName,
            ['client_external_id' => $clientExternalId]
        );
    }

    echo json_encode([
        'ok'         => true,
        'chat_id'    => $chatId,
        'message_id' => $messageId,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ]);
}
