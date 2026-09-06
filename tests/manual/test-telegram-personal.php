<?php

declare(strict_types=1);

/**
 * Диагностика и проверка шлюза личного Telegram (Personal MTProto)
 * Шаблон имени: tests/manual/test-<название>.php
 */

require_once __DIR__ . '/../../config/db.php';

use Chatgo\Adapters\TelegramPersonalAdapter;
use Chatgo\Services\ChatService;
use Chatgo\Services\MessageService;

echo "========================================================\n";
echo "       Chatgo: Диагностика Личного Telegram             \n";
echo "========================================================\n\n";

// 1. Проверка конфигурации
echo "1. Проверка окружения:\n";
echo "   - TELEGRAM_PERSONAL_SERVICE_URL: " . (defined('TELEGRAM_PERSONAL_SERVICE_URL') ? TELEGRAM_PERSONAL_SERVICE_URL : 'НЕ ОПРЕДЕЛЕНО') . "\n";
echo "   - CHATGO_SECRET: " . (defined('CHATGO_SECRET') ? substr(CHATGO_SECRET, 0, 5) . '***' : 'НЕ ОПРЕДЕЛЕНО') . "\n\n";

// 2. Проверка адаптера
echo "2. Проверка TelegramPersonalAdapter:\n";
try {
    $serviceUrl = defined('TELEGRAM_PERSONAL_SERVICE_URL') ? TELEGRAM_PERSONAL_SERVICE_URL : 'http://127.0.0.1:3005';
    $secret = defined('CHATGO_SECRET') ? CHATGO_SECRET : '';
    $adapter = new TelegramPersonalAdapter($serviceUrl, $secret);
    echo "   [OK] Класс TelegramPersonalAdapter успешно инстанциирован.\n";

    // Тест парсинга входящего вебхука
    $mockPayload = [
        'event' => 'message',
        'direction' => 'incoming',
        'message_id' => '9999',
        'peer_id' => '123456789',
        'client_name' => 'Иван Тестовый',
        'client_username' => 'ivan_test',
        'client_phone' => '+79990001122',
        'text' => 'Привет, это тестовое сообщение в личку!',
    ];
    $parsed = $adapter->parseWebhookPayload($mockPayload);
    if ($parsed && $parsed['client_external_id'] === '123456789' && $parsed['text'] === $mockPayload['text']) {
        echo "   [OK] parseWebhookPayload корректно разбирает структуру.\n";
    } else {
        echo "   [FAIL] Ошибка в parseWebhookPayload.\n";
    }
} catch (Throwable $e) {
    echo "   [ERROR] Исключение: " . $e->getMessage() . "\n";
}
echo "\n";

// 3. Проверка соединения с базой данных
echo "3. Проверка подключения к БД:\n";
try {
    $db = DB::getConnection();
    echo "   [OK] Успешное подключение к MySQL.\n";

    // Проверяем таблицу channels
    $stmt = $db->query("SELECT id, type, name, status, settings FROM channels WHERE type = 'telegram' LIMIT 1");
    $channel = $stmt->fetch();
    if ($channel) {
        echo "   [INFO] Канал Telegram найден (ID: {$channel['id']}, Статус: {$channel['status']}, Название: {$channel['name']})\n";
    } else {
        echo "   [INFO] Канал Telegram пока не создан в БД (будет создан автоматически при авторизации).\n";
    }
} catch (Throwable $e) {
    echo "   [FAIL] Ошибка БД: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. Проверка доступности Node.js микросервиса
echo "4. Проверка доступности Node.js шлюза (127.0.0.1:3005):\n";
$ch = curl_init($serviceUrl . '/api/status');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($res !== false && $httpCode === 200) {
    $data = json_decode((string) $res, true);
    echo "   [OK] Шлюз активен (HTTP 200)!\n";
    echo "   - Подключен аккаунт: " . (!empty($data['connected']) ? 'ДА' : 'НЕТ (требуется вход)') . "\n";
    if (!empty($data['user'])) {
        echo "   - Пользователь: {$data['user']['fullName']} (@{$data['user']['username']}) ID: {$data['user']['id']}\n";
    }
} else {
    echo "   [WARNING] Шлюз сейчас не отвечает локально ({$curlErr}).\n";
    echo "   (Это нормально при локальной проверке до запуска `node server.js` или на боевом сервере через systemd).\n";
}

echo "\n========================================================\n";
echo "              Диагностика завершена!                    \n";
echo "========================================================\n";
