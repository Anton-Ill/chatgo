<?php

/**
 * REST API: Add new channel with token validation and webhook registration
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

    // 2. Читаем JSON POST запрос
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    $name = isset($payload['name']) ? trim($payload['name']) : '';
    $type = isset($payload['type']) ? trim($payload['type']) : '';
    $settings = isset($payload['settings']) ? $payload['settings'] : [];

    if ($name === '' || $type === '' || empty($settings)) {
        throw new Exception("Все поля формы (название, тип мессенджера, настройки) обязательны.");
    }

    // Проверяем корректность типа канала
    $allowedTypes = ['telegram', 'whatsapp', 'vk', 'instagram', 'max'];
    if (!in_array($type, $allowedTypes, true)) {
        throw new Exception("Неподдерживаемый тип канала: {$type}");
    }

    // 3. Валидация токенов через внешние API мессенджеров
    $isMock = false;
    foreach ($settings as $val) {
        if (is_string($val) && str_contains($val, 'MOCK')) {
            $isMock = true;
            break;
        }
    }

    if (!$isMock) {
        if ($type === 'telegram') {
            $token = $settings['token'] ?? '';
            if ($token === '') {
                throw new Exception("Токен бота обязателен.");
            }
            // Проверка getMe через шлюз
            $url = rtrim(TELEGRAM_API_URL, '/') . '/bot' . $token . '/getMe';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-Chatgo-Secret: ' . CHATGO_SECRET
            ]);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $resData = json_decode((string) $res, true);
            if ($httpCode !== 200 || !($resData['ok'] ?? false)) {
                throw new Exception("Неверный токен Telegram бота. Ошибка: " . ($resData['description'] ?? 'неизвестно'));
            }
        } elseif ($type === 'vk') {
            $accessToken = $settings['access_token'] ?? '';
            if ($accessToken === '') {
                throw new Exception("Токен доступа VK обязателен.");
            }
            $url = "https://api.vk.com/method/groups.getById?v=5.131&access_token=" . urlencode($accessToken);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $resData = json_decode((string) $res, true);
            if ($httpCode !== 200 || isset($resData['error'])) {
                throw new Exception("Неверный токен группы VK. Ошибка: " . ($resData['error']['error_msg'] ?? 'неизвестно'));
            }
        } elseif ($type === 'whatsapp') {
            $accessToken = $settings['access_token'] ?? '';
            $phoneNumberId = $settings['phone_number_id'] ?? '';
            if ($accessToken === '' || $phoneNumberId === '') {
                throw new Exception("Токен доступа Meta и Phone Number ID обязательны.");
            }
            $url = "https://graph.facebook.com/v17.0/" . urlencode($phoneNumberId) . "?access_token=" . urlencode($accessToken);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $resData = json_decode((string) $res, true);
            if ($httpCode !== 200 || isset($resData['error'])) {
                throw new Exception("Неверные настройки WhatsApp. Ошибка: " . ($resData['error']['message'] ?? 'неизвестно'));
            }
        } elseif ($type === 'instagram') {
            $accessToken = $settings['access_token'] ?? '';
            $instagramAccountId = $settings['instagram_account_id'] ?? '';
            if ($accessToken === '' || $instagramAccountId === '') {
                throw new Exception("Токен доступа Meta и Instagram Account ID обязательны.");
            }
            $url = "https://graph.facebook.com/v17.0/" . urlencode($instagramAccountId) . "?access_token=" . urlencode($accessToken);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $resData = json_decode((string) $res, true);
            if ($httpCode !== 200 || isset($resData['error'])) {
                throw new Exception("Неверные настройки Instagram. Ошибка: " . ($resData['error']['message'] ?? 'неизвестно'));
            }
        } elseif ($type === 'max') {
            $accessToken = $settings['access_token'] ?? '';
            if ($accessToken === '') {
                throw new Exception("Токен доступа MAX обязателен.");
            }
            // Проверка подписок бота
            $url = "https://platform-api2.max.ru/subscriptions";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("Неверный токен бота MAX (HTTP {$httpCode}).");
            }
        }
    }

    // 4. Сохраняем канал в базу со статусом connected
    $stmt = $db->prepare('
        INSERT INTO channels (user_id, type, name, status, settings)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $userId,
        $type,
        $name,
        'connected',
        json_encode($settings)
    ]);
    $channelId = (int) $db->lastInsertId();

    // 5. Автоматическая настройка вебхуков для поддерживаемых платформ
    $webhookResponseText = '';
    $requiresManualSetup = in_array($type, ['vk', 'whatsapp', 'instagram'], true);

    if (!$isMock) {
        if ($type === 'telegram') {
            $token = $settings['token'] ?? '';
            $webhookUrl = BASE_URL . '/api/webhook/telegram.php?channel_id=' . $channelId;
            $url = rtrim(TELEGRAM_API_URL, '/') . '/bot' . $token . '/setWebhook?url=' . urlencode($webhookUrl);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-Chatgo-Secret: ' . CHATGO_SECRET
            ]);
            $res = curl_exec($ch);
            $resData = json_decode((string) $res, true);
            curl_close($ch);

            if (!($resData['ok'] ?? false)) {
                throw new Exception("Канал сохранен, но не удалось зарегистрировать вебхук в Telegram: " . ($resData['description'] ?? 'неизвестно'));
            }
        } elseif ($type === 'max') {
            $accessToken = $settings['access_token'] ?? '';
            $verifyToken = $settings['verify_token'] ?? ''; // secret
            $webhookUrl = BASE_URL . '/api/webhook/max.php?channel_id=' . $channelId;

            $payloadMax = [
                'url' => $webhookUrl
            ];
            if ($verifyToken !== '') {
                $payloadMax['secret'] = $verifyToken;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://platform-api2.max.ru/subscriptions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadMax));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("Канал сохранен, но не удалось зарегистрировать вебхук в MAX (HTTP {$httpCode}).");
            }
        }
    }

    // Для ручной настройки формируем URL
    $manualWebhookUrl = '';
    if ($requiresManualSetup) {
        $manualWebhookUrl = BASE_URL . "/api/webhook/{$type}.php?channel_id={$channelId}";
    }

    echo json_encode([
        'ok' => true,
        'channel_id' => $channelId,
        'requires_manual_webhook' => $requiresManualSetup,
        'webhook_url' => $manualWebhookUrl,
        'verify_token' => $settings['verify_token'] ?? ''
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
