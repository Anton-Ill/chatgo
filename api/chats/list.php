<?php

/**
 * REST API: List active chats
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

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

    $stmt = $db->prepare('
        SELECT 
            c.id, 
            c.client_external_id, 
            c.client_name, 
            c.status, 
            c.unread_count, 
            c.last_message_at,
            ch.type AS channel_type,
            ch.name AS channel_name,
            (SELECT text FROM messages WHERE chat_id = c.id ORDER BY id DESC LIMIT 1) AS last_message_text
        FROM chats c
        JOIN channels ch ON c.channel_id = ch.id
        WHERE ch.user_id = ?
        ORDER BY COALESCE(c.last_message_at, c.created_at) DESC, c.id DESC
    ');
    $stmt->execute([$userId]);
    $chats = $stmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'chats' => $chats
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
