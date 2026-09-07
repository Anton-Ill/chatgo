<?php

/**
 * REST API: Generate Telegram deep linking URL to bind operator's Telegram ID
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

use Chatgo\Security\WebAppAuthenticator;

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

    // 2. Ищем имя бота Telegram
    $botUsername = defined('TELEGRAM_BOT_USERNAME') && TELEGRAM_BOT_USERNAME !== ''
        ? (string) TELEGRAM_BOT_USERNAME
        : '';

    if ($botUsername === '') {
        $stmt = $db->query("SELECT name FROM channels WHERE type = 'telegram' LIMIT 1");
        $chName = (string) ($stmt->fetchColumn() ?: '');
        $botUsername = ltrim($chName, '@');
    }

    if ($botUsername === '') {
        throw new Exception("Канал Telegram бота не настроен на сервере.");
    }

    // 4. Генерируем случайный токен привязки
    $bindToken = bin2hex(random_bytes(16)); // 32 символа

    // Записываем токен в таблицу auth_tokens на 15 минут
    $expiresAt = date('Y-m-d H:i:s', time() + 900); // +15 минут
    
    $stmtToken = $db->prepare('
        INSERT INTO auth_tokens (user_id, token, expires_at, used)
        VALUES (?, ?, ?, 0)
    ');
    $stmtToken->execute([$userId, $bindToken, $expiresAt]);

    // 5. Формируем deep linking URL
    $botLink = "https://t.me/{$botUsername}?start=bind_{$bindToken}";

    echo json_encode([
        'ok' => true,
        'bind_url' => $botLink
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
