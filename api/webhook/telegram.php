<?php

/**
 * Webhook handler for Telegram Bot API
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

use Chatgo\Services\ChatService;
use Chatgo\Services\MessageService;
use Chatgo\Adapters\TelegramAdapter;

header('Content-Type: application/json');

try {
    $db = DB::getConnection();

    // Получаем channel_id из GET-параметра
    $channelId = isset($_GET['channel_id']) ? (int) $_GET['channel_id'] : null;

    if (!$channelId) {
        // Поиск первого доступного Telegram канала в БД, если ID не передан
        $stmt = $db->query("SELECT id, settings FROM channels WHERE type = 'telegram' LIMIT 1");
        $channel = $stmt->fetch();
        if (!$channel) {
            throw new Exception("Канал Telegram не настроен в базе данных.");
        }
        $channelId = (int) $channel['id'];
        $settings = json_decode($channel['settings'] ?? '{}', true);
    } else {
        // Выбираем переданный канал
        $stmt = $db->prepare("SELECT settings FROM channels WHERE id = ? AND type = 'telegram'");
        $stmt->execute([$channelId]);
        $channelSettings = $stmt->fetchColumn();
        if ($channelSettings === false) {
            throw new Exception("Канал с ID {$channelId} не найден или не является Telegram.");
        }
        $settings = json_decode($channelSettings ?? '{}', true);
    }

    $botToken = $settings['token'] ?? null;
    if (!$botToken) {
        throw new Exception("Токен бота не найден в настройках канала.");
    }

    // Читаем входящий запрос
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (empty($payload)) {
        throw new Exception("Пустой запрос вебхука.");
    }

    // Инициализируем компоненты
    $adapter = new TelegramAdapter($botToken, TELEGRAM_API_URL, CHATGO_SECRET);
    $chatService = new ChatService($db);
    $messageService = new MessageService($db);

    $processor = new \Chatgo\Services\TelegramUpdateProcessor(
        $db,
        $adapter,
        $chatService,
        $messageService,
        $channelId
    );

    $result = $processor->process($payload);
    echo json_encode($result);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
