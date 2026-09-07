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
    $userId = WebAppAuthenticator::getAuthenticatedUserId($db);
    if ($userId === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'Доступ запрещен: Не авторизован'
        ]);
        exit;
    }

    // Извлекаем telegram_id текущего пользователя системы
    $stmt = $db->prepare('SELECT telegram_id FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $telegramId = $stmt->fetchColumn();

    echo json_encode([
        'ok' => true,
        'is_bound' => ($telegramId !== null && $telegramId !== false && $telegramId !== ''),
        'telegram_id' => $telegramId ?: ''
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
