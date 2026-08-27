<?php

declare(strict_types=1);

namespace Chatgo\Adapters;

use Chatgo\Contracts\ChannelInterface;

class InstagramAdapter implements ChannelInterface
{
    public function __construct(
        private string $accessToken,
        private string $instagramAccountId = ''
    ) {
    }

    /**
     * Отправка сообщения клиенту в Instagram Direct через Meta Graph API.
     */
    public function sendMessage(string $clientExternalId, string $text, array $options = []): bool
    {
        // Режим песочницы для офлайн тестирования
        if (str_contains($this->accessToken, 'MOCK')) {
            return true;
        }

        $url = 'https://graph.facebook.com/v17.0/me/messages';

        $payload = [
            'recipient' => [
                'id' => $clientExternalId
            ],
            'message' => [
                'text' => $text
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->accessToken
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

        // Meta Graph API возвращает recipient_id и message_id при успехе
        return isset($result['message_id']);
    }

    /**
     * Парсинг входящего вебхук-запроса от Instagram Graph API.
     */
    public function parseWebhookPayload(array $payload): ?array
    {
        // Проверяем, что это событие instagram
        if (($payload['object'] ?? '') !== 'instagram') {
            return null;
        }

        $entry = $payload['entry'][0] ?? null;
        if (!$entry) {
            return null;
        }

        $messaging = $entry['messaging'][0] ?? null;
        if (!$messaging) {
            return null;
        }

        $senderId = $messaging['sender']['id'] ?? null;
        if (!$senderId) {
            return null;
        }

        $message = $messaging['message'] ?? null;
        if (!$message) {
            return null;
        }

        // Поддерживаем только текстовые сообщения
        $text = $message['text'] ?? '';
        
        // В случае других типов сообщений (изображения, стикеры и др.)
        if (empty($text) && isset($message['attachments'])) {
            $text = '[attachment]';
        }

        return [
            'external_id'        => $message['mid'] ?? '',
            'client_external_id' => (string) $senderId,
            'client_name'        => 'Instagram ' . $senderId,
            'text'               => $text,
            'type'               => 'text'
        ];
    }
}
