<?php

/**
 * REST API: Get Telegram notifications bind status for operator
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

    // Извлекаем telegram_id первого пользователя системы
    $stmt = $db->query('SELECT telegram_id FROM users LIMIT 1');
    $telegramId = $stmt->fetchColumn();

    echo json_encode([
        'ok' => true,
        'is_bound' => ($telegramId !== null && $telegramId !== ''),
        'telegram_id' => $telegramId ?: ''
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
