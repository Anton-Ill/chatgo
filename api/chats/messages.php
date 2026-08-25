<?php

/**
 * REST API: Get messages for a specific chat and reset unread count
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

try {
    $db = DB::getConnection();

    $chatId = isset($_GET['chat_id']) ? (int) $_GET['chat_id'] : null;

    if (!$chatId) {
        throw new Exception("Параметр chat_id обязателен.");
    }

    // Сбрасываем unread_count для данного чата при прочтении
    $stmt = $db->prepare('UPDATE chats SET unread_count = 0 WHERE id = ?');
    $stmt->execute([$chatId]);

    // Получаем историю сообщений
    $stmt = $db->prepare('
        SELECT id, direction, text, type, attachment_url, created_at
        FROM messages
        WHERE chat_id = ?
        ORDER BY id ASC
    ');
    $stmt->execute([$chatId]);
    $messages = $stmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'messages' => $messages
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
