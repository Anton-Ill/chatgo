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
    if (!WebAppAuthenticator::authenticate($db)) {
        echo json_encode([
            'ok' => false,
            'error' => 'Доступ запрещен: Не авторизован'
        ]);
        exit;
    }

    // Выбираем каналы, маскируя конфиденциальные настройки settings
    $stmt = $db->query('
        SELECT id, type, name, status, created_at 
        FROM channels 
        ORDER BY id DESC
    ');
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
