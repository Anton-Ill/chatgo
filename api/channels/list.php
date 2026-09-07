<?php

/**
 * REST API: List all connected channels
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

    // Выбираем каналы только текущего пользователя
    $stmt = $db->prepare('
        SELECT id, type, name, status, created_at 
        FROM channels 
        WHERE user_id = ?
        ORDER BY id DESC
    ');
    $stmt->execute([$userId]);
    $channels = $stmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'channels' => $channels
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
