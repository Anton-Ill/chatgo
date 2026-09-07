<?php

/**
 * REST API: Send message from operator to client
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

use Chatgo\Services\MessageService;
use Chatgo\Adapters\TelegramAdapter;
use Chatgo\Adapters\TelegramPersonalAdapter;
use Chatgo\Adapters\VkAdapter;
use Chatgo\Adapters\WhatsAppAdapter;
use Chatgo\Adapters\InstagramAdapter;
use Chatgo\Adapters\MaxAdapter;

use Chatgo\Security\WebAppAuthenticator;

header('Content-Type: application/json');

try {
    $db = DB::getConnection();

    // Проверка авторизации
    $userId = WebAppAuthenticator::getAuthenticatedUserId($db);
    if ($userId === null) {
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

    // 1. Проверяем права пользователя на чат
    $stmt = $db->prepare('
        SELECT c.id
        FROM chats c
        JOIN channels ch ON c.channel_id = ch.id
        WHERE c.id = ? AND ch.user_id = ?
    ');
    $stmt->execute([$chatId, $userId]);
    if (!$stmt->fetchColumn()) {
        throw new Exception("Чат с ID {$chatId} не найден или у вас нет прав на отправку.");
    }

    // 2. Отправка через единый MessageSenderService
    $senderService = new \Chatgo\Services\MessageSenderService($db);
    $sendResult = $senderService->send($chatId, $text);

    echo json_encode([
        'ok' => true,
        'message_id' => $sendResult['message_id']
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}

