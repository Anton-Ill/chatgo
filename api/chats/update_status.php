<?php

/**
 * REST API: Update chat status (e.g. approve or reject client from dashboard)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

use Chatgo\Security\WebAppAuthenticator;
use Chatgo\Services\ChatService;
use Chatgo\Adapters\TelegramAdapter;

header('Content-Type: application/json');

try {
    $db = DB::getConnection();

    // 1. Проверка авторизации
    $userId = WebAppAuthenticator::getAuthenticatedUserId($db);
    if ($userId === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'Доступ запрещен: Не авторизован'
        ]);
        exit;
    }

    // 2. Читаем JSON входные данные
    $rawInput = file_get_contents('php://input');
    $payload = json_decode((string) $rawInput, true);

    $chatId = isset($payload['chat_id']) ? (int) $payload['chat_id'] : 0;
    $status = isset($payload['status']) ? trim((string) $payload['status']) : '';

    if ($chatId <= 0 || !in_array($status, ['active', 'rejected', 'archived'], true)) {
        throw new Exception('Некорректные параметры chat_id или status.');
    }

    // Проверяем принадлежность чата текущему пользователю
    $checkStmt = $db->prepare('
        SELECT c.id 
        FROM chats c 
        JOIN channels ch ON c.channel_id = ch.id 
        WHERE c.id = ? AND ch.user_id = ? 
        LIMIT 1
    ');
    $checkStmt->execute([$chatId, $userId]);
    if ($checkStmt->fetchColumn() === false) {
        throw new Exception("Чат #{$chatId} не найден или у вас нет прав на его изменение.");
    }

    $chatService = new ChatService($db);
    $chat = $chatService->getChatById($chatId);
    if (!$chat) {
        throw new Exception("Чат #{$chatId} не найден.");
    }

    $chatService->updateStatus($chatId, $status);

    // 3. Если это Telegram канал — отправляем уведомление клиенту
    $stmtChannel = $db->prepare('SELECT type, settings FROM channels WHERE id = ? LIMIT 1');
    $stmtChannel->execute([$chat['channel_id']]);
    $channel = $stmtChannel->fetch();

    if ($channel && $channel['type'] === 'telegram') {
        $settings = json_decode((string) ($channel['settings'] ?? '{}'), true);
        $token = $settings['token'] ?? '';
        if ($token !== '') {
            $adapter = new TelegramAdapter($token, TELEGRAM_API_URL, CHATGO_SECRET);
            if ($status === 'active') {
                $adapter->sendMessage(
                    $chat['client_external_id'],
                    '✅ Ваш запрос на диалог одобрен! Оператор на связи, вы можете писать сообщения.'
                );
            } elseif ($status === 'rejected') {
                $adapter->sendMessage(
                    $chat['client_external_id'],
                    'К сожалению, ваш запрос отклонен администратором.'
                );
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'chat_id' => $chatId,
        'status' => $status
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
