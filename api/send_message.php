<?php

/**
 * REST API: Send message from operator to client
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

use Chatgo\Services\MessageService;
use Chatgo\Adapters\TelegramAdapter;
use Chatgo\Adapters\TelegramPersonalAdapter;
use Chatgo\Adapters\VkAdapter;
use Chatgo\Adapters\WhatsAppAdapter;
use Chatgo\Adapters\InstagramAdapter;
use Chatgo\Adapters\MaxAdapter;

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

    // Читаем JSON POST запрос
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    $chatId = isset($payload['chat_id']) ? (int) $payload['chat_id'] : null;
    $text = isset($payload['text']) ? trim($payload['text']) : '';

    if (!$chatId || empty($text)) {
        throw new Exception("Параметры chat_id и text обязательны.");
    }

    // 1. Получаем информацию о чате и клиенте с проверкой прав пользователя
    $stmt = $db->prepare('
        SELECT c.client_external_id, c.channel_id, ch.type AS channel_type, ch.settings AS channel_settings
        FROM chats c
        JOIN channels ch ON c.channel_id = ch.id
        WHERE c.id = ? AND ch.user_id = ?
    ');
    $stmt->execute([$chatId, $userId]);
    $chatData = $stmt->fetch();

    if (!$chatData) {
        throw new Exception("Чат с ID {$chatId} не найден или у вас нет прав на отправку.");
    }

    $channelType = $chatData['channel_type'];
    $clientExternalId = $chatData['client_external_id'];
    $settings = json_decode($chatData['channel_settings'] ?? '{}', true);

    // 2. Выбираем нужный адаптер и отправляем
    $sent = false;
    if ($channelType === 'telegram') {
        $botToken = $settings['token'] ?? null;
        $isPersonal = ($settings['account_type'] ?? '') === 'personal' || empty($botToken);

        if ($isPersonal) {
            $adapter = new TelegramPersonalAdapter(TELEGRAM_PERSONAL_SERVICE_URL, CHATGO_SECRET);
            $sent = $adapter->sendMessage($clientExternalId, $text);
        } else {
            $adapter = new TelegramAdapter($botToken, TELEGRAM_API_URL, CHATGO_SECRET);
            $sent = $adapter->sendMessage($clientExternalId, $text);
        }
    } elseif ($channelType === 'vk') {
        $accessToken = $settings['access_token'] ?? null;
        if (!$accessToken) {
            throw new Exception("Токен доступа VK не настроен для данного канала.");
        }
        
        $adapter = new VkAdapter($accessToken);
        $sent = $adapter->sendMessage($clientExternalId, $text);
    } elseif ($channelType === 'whatsapp') {
        $accessToken = $settings['access_token'] ?? null;
        $phoneNumberId = $settings['phone_number_id'] ?? null;
        if (!$accessToken || !$phoneNumberId) {
            throw new Exception("Токен или Phone Number ID WhatsApp не настроен для данного канала.");
        }
        
        $adapter = new WhatsAppAdapter($accessToken, $phoneNumberId);
        $sent = $adapter->sendMessage($clientExternalId, $text);
    } elseif ($channelType === 'instagram') {
        $accessToken = $settings['access_token'] ?? null;
        $instagramAccountId = $settings['instagram_account_id'] ?? null;
        if (!$accessToken) {
            throw new Exception("Токен доступа Instagram не настроен для данного канала.");
        }
        
        $adapter = new InstagramAdapter($accessToken, $instagramAccountId);
        $sent = $adapter->sendMessage($clientExternalId, $text);
    } elseif ($channelType === 'max') {
        $accessToken = $settings['access_token'] ?? null;
        if (!$accessToken) {
            throw new Exception("Токен доступа MAX не настроен для данного канала.");
        }
        
        $adapter = new MaxAdapter($accessToken);
        $sent = $adapter->sendMessage($clientExternalId, $text);
    } else {
        throw new Exception("Тип канала '{$channelType}' пока не поддерживается для отправки.");
    }

    if (!$sent) {
        throw new Exception("Не удалось отправить сообщение во внешнюю систему.");
    }

    // 3. Записываем исходящее сообщение в БД
    $messageService = new MessageService($db);
    $messageId = $messageService->recordMessage($chatId, 'outgoing', $text, 'text');

    echo json_encode([
        'ok' => true,
        'message_id' => $messageId
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
