<?php

declare(strict_types=1);

namespace Chatgo\Adapters;

use Chatgo\Contracts\ChannelInterface;

class MaxAdapter implements ChannelInterface
{
    public function __construct(
        private string $accessToken
    ) {
    }

    /**
     * Отправка сообщения клиенту в MAX Messenger через Bot API.
     */
    public function sendMessage(string $clientExternalId, string $text, array $options = []): bool
    {
        // Режим песочницы для офлайн тестирования
        if (str_contains($this->accessToken, 'MOCK')) {
            return true;
        }

        $url = 'https://platform-api2.max.ru/messages';

        $payload = [
            'chat_id' => $clientExternalId,
            'text'    => $text
        ];

        // Поддержка Bearer токена
        $authHeader = str_starts_with($this->accessToken, 'Bearer ') 
            ? $this->accessToken 
            : 'Bearer ' . $this->accessToken;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: ' . $authHeader
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return false;
        }

        $result = json_decode($response, true);

        // MAX API возвращает message_id или id созданного сообщения при успехе
        return isset($result['message_id']) || isset($result['id']);
    }

    /**
     * Парсинг входящего вебхук-запроса от MAX Bot API.
     */
    public function parseWebhookPayload(array $payload): ?array
    {
        // Проверяем, что событие связано с созданием сообщения
        $event = $payload['event'] ?? '';
        if ($event !== 'message_created') {
            return null;
        }

        $message = $payload['message'] ?? null;
        if (!$message) {
            return null;
        }

        $chatId = $message['chat_id'] ?? null;
        if (!$chatId) {
            return null;
        }

        $sender = $message['sender'] ?? [];
        $senderId = $sender['user_id'] ?? '';
        $senderName = $sender['name'] ?? 'MAX User ' . $senderId;

        $body = $message['body'] ?? [];
        $text = $body['text'] ?? '';

        return [
            'external_id'        => (string) ($message['id'] ?? ''),
            'client_external_id' => (string) $chatId,
            'client_name'        => $senderName,
            'text'               => $text,
            'type'               => 'text'
        ];
    }
}
