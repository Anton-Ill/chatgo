<?php

/**
 * REST API: Delete channel and revoke webhooks
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

use Chatgo\Security\WebAppAuthenticator;

header('Content-Type: application/json');

try {
    $db = DB::getConnection();

    // 1. Проверка авторизации
    if (!WebAppAuthenticator::authenticate($db)) {
        echo json_encode([
            'ok' => false,
            'error' => 'Доступ запрещен: Не авторизован'
        ]);
        exit;
    }

    // 2. Читаем JSON POST запрос
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    $channelId = isset($payload['channel_id']) ? (int) $payload['channel_id'] : null;

    if (!$channelId) {
        throw new Exception("Параметр channel_id обязателен.");
    }

    // 3. Получаем тип и настройки канала перед удалением
    $stmt = $db->prepare('SELECT type, settings FROM channels WHERE id = ?');
    $stmt->execute([$channelId]);
    $channel = $stmt->fetch();

    if (!$channel) {
        throw new Exception("Канал с ID {$channelId} не найден.");
    }

    $type = $channel['type'];
    $settings = json_decode($channel['settings'] ?? '{}', true);

    // Проверяем, mock-режим ли это
    $isMock = false;
    foreach ($settings as $val) {
        if (is_string($val) && str_contains($val, 'MOCK')) {
            $isMock = true;
            break;
        }
    }

    // 4. Отзыв вебхук-подписок для Telegram и MAX
    if (!$isMock) {
        if ($type === 'telegram') {
            $token = $settings['token'] ?? '';
            if ($token !== '') {
                $url = "https://api.telegram.org/bot{$token}/deleteWebhook";
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_exec($ch);
                curl_close($ch);
            }
        } elseif ($type === 'max') {
            $accessToken = $settings['access_token'] ?? '';
            if ($accessToken !== '') {
                $ch = curl_init();
                // Для MAX API отзыв вебхука происходит через удаление подписки.
                // Обычно отправляется DELETE запрос на /subscriptions
                curl_setopt($ch, CURLOPT_URL, 'https://platform-api2.max.ru/subscriptions');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $accessToken
                ]);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_exec($ch);
                curl_close($ch);
            }
        }
    }

    // 5. Удаляем канал из базы данных (связанные чаты и сообщения будут удалены каскадно по внешнему ключу)
    $stmt = $db->prepare('DELETE FROM channels WHERE id = ?');
    $stmt->execute([$channelId]);

    echo json_encode([
        'ok' => true
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
