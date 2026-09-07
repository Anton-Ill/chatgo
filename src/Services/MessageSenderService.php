<?php

declare(strict_types=1);

namespace Chatgo\Services;

use PDO;
use Exception;
use Chatgo\Adapters\TelegramAdapter;
use Chatgo\Adapters\TelegramPersonalAdapter;
use Chatgo\Adapters\VkAdapter;
use Chatgo\Adapters\WhatsAppAdapter;
use Chatgo\Adapters\InstagramAdapter;
use Chatgo\Adapters\MaxAdapter;

class MessageSenderService
{
    private PDO $db;
    private MessageService $messageService;

    public function __construct(PDO $db, ?MessageService $messageService = null)
    {
        $this->db = $db;
        $this->messageService = $messageService ?? new MessageService($db);
    }

    /**
     * Отправить сообщение клиенту во внешний канал и зафиксировать в БД.
     *
     * @param int $chatId
     * @param string $text
     * @return array
     * @throws Exception
     */
    public function send(int $chatId, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new Exception("Текст сообщения не может быть пустым.");
        }

        // 1. Получаем информацию о чате и канале
        $stmt = $this->db->prepare('
            SELECT c.client_external_id, c.client_name, c.channel_id, ch.type AS channel_type, ch.settings AS channel_settings
            FROM chats c
            JOIN channels ch ON c.channel_id = ch.id
            WHERE c.id = ?
            LIMIT 1
        ');
        $stmt->execute([$chatId]);
        $chatData = $stmt->fetch();

        if (!$chatData) {
            throw new Exception("Чат с ID {$chatId} не найден.");
        }

        $channelType = (string) $chatData['channel_type'];
        $clientExternalId = (string) $chatData['client_external_id'];
        $settings = json_decode($chatData['channel_settings'] ?? '{}', true) ?: [];

        // 2. Отправка во внешний адаптер
        $sent = false;
        if ($channelType === 'telegram') {
            $botToken = $settings['token'] ?? null;
            $isPersonal = ($settings['account_type'] ?? '') === 'personal' || empty($botToken);

            if ($isPersonal) {
                $serviceUrl = defined('TELEGRAM_PERSONAL_SERVICE_URL') ? TELEGRAM_PERSONAL_SERVICE_URL : 'http://127.0.0.1:3005';
                $secret = defined('CHATGO_SECRET') ? CHATGO_SECRET : '';
                $adapter = new TelegramPersonalAdapter($serviceUrl, $secret);
                $sent = $adapter->sendMessage($clientExternalId, $text);
            } else {
                $apiUrl = defined('TELEGRAM_API_URL') ? TELEGRAM_API_URL : '';
                $secret = defined('CHATGO_SECRET') ? CHATGO_SECRET : '';
                $adapter = new TelegramAdapter($botToken, $apiUrl, $secret);
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

        // 3. Фиксируем исходящее сообщение в БД
        $messageId = $this->messageService->recordMessage($chatId, 'outgoing', $text, 'text');

        // 4. Если чат был на модерации (pending) — переводим в active
        $stmtStatus = $this->db->prepare("UPDATE chats SET status = 'active' WHERE id = ? AND status = 'pending'");
        $stmtStatus->execute([$chatId]);

        return [
            'ok' => true,
            'message_id' => $messageId,
            'chat_data' => $chatData
        ];
    }
}
