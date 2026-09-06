<?php

/**
 * Chatgo: Telegram Long Polling Background Worker
 */

declare(strict_types=1);

// Только запуск из командной строки
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script can only be run via CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Adapters/TelegramAdapter.php';
require_once __DIR__ . '/../src/Services/ChatService.php';
require_once __DIR__ . '/../src/Services/MessageService.php';
require_once __DIR__ . '/../src/Services/TelegramUpdateProcessor.php';

use Chatgo\Adapters\TelegramAdapter;
use Chatgo\Services\ChatService;
use Chatgo\Services\MessageService;
use Chatgo\Services\TelegramUpdateProcessor;

echo "[" . date('Y-m-d H:i:s') . "] [START] Запуск Telegram Long Polling воркера Chatgo...\n";

$running = true;

// Регистрация обработчиков сигналов остановки
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $signalHandler = function (int $signo) use (&$running): void {
        echo "\n[" . date('Y-m-d H:i:s') . "] [SIGNAL] Получен сигнал {$signo}, корректно завершаем работу...\n";
        $running = false;
    };
    pcntl_signal(SIGTERM, $signalHandler);
    pcntl_signal(SIGINT, $signalHandler);
}

$lastUpdateId = 0;
$db = null;
$channel = null;
$adapter = null;
$processor = null;

function initServices(): array
{
    $db = DB::getConnection();

    $stmt = $db->query("SELECT id, name, settings FROM channels WHERE type = 'telegram' LIMIT 1");
    $channel = $stmt->fetch();

    if (!$channel) {
        throw new RuntimeException("Канал с типом 'telegram' не найден в базе данных.");
    }

    $channelId = (int) $channel['id'];
    $settings = json_decode($channel['settings'] ?? '{}', true);
    $botToken = $settings['token'] ?? null;

    if (!$botToken) {
        throw new RuntimeException("Токен Telegram-бота не задан в настройках канала ID {$channelId}.");
    }

    $adapter = new TelegramAdapter($botToken, TELEGRAM_API_URL, CHATGO_SECRET);
    $chatService = new ChatService($db);
    $messageService = new MessageService($db);
    $processor = new TelegramUpdateProcessor($db, $adapter, $chatService, $messageService, $channelId);

    // Удаляем активный вебхук перед началом Long Polling
    $adapter->deleteWebhook();

    echo "[" . date('Y-m-d H:i:s') . "] [INIT] Подключен канал ID {$channelId} ('{$channel['name']}'). Webhook отключен, активен Long Polling.\n";

    return [$db, $channelId, $adapter, $processor];
}

while ($running) {
    try {
        if ($db === null || $adapter === null || $processor === null) {
            [$db, $channelId, $adapter, $processor] = initServices();
        }

        // Запрос к Telegram getUpdates (long polling с таймаутом 25 сек)
        $offset = $lastUpdateId > 0 ? $lastUpdateId + 1 : 0;
        $updates = $adapter->getUpdates($offset, 25, 50);

        if (!empty($updates)) {
            foreach ($updates as $update) {
                $updateId = (int) ($update['update_id'] ?? 0);
                if ($updateId > $lastUpdateId) {
                    $lastUpdateId = $updateId;
                }

                $result = $processor->process($update);
                echo "[" . date('Y-m-d H:i:s') . "] [UPDATE #{$updateId}] " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    } catch (Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] [ERROR] " . $e->getMessage() . "\n";
        // Сбрасываем ресурсы при сбое, чтобы переинициализировать подключение к БД
        $db = null;
        $adapter = null;
        $processor = null;
        sleep(3);
    }
}

echo "[" . date('Y-m-d H:i:s') . "] [STOP] Воркер остановлен.\n";
