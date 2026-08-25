<?php

namespace Chatgo\Adapters;

use Chatgo\Contracts\ChannelInterface;

class VkAdapter implements ChannelInterface
{
    public function __construct(
        private string $accessToken,
        private string $secretKey = '',
        private string $confirmationCode = ''
    ) {
    }

    /**
     * Отправка сообщения клиенту через VK Messages API.
     */
    public function sendMessage(string $clientExternalId, string $text, array $options = []): bool
    {
        // Режим песочницы для офлайн тестирования
        if (str_contains($this->accessToken, 'MOCK')) {
            return true;
        }

        $url = 'https://api.vk.com/method/messages.send';

        $payload = [
            'peer_id'      => $clientExternalId,
            'message'      => $text,
            'random_id'    => rand(1, 2147483647),
            'v'            => '5.131',
            'access_token' => $this->accessToken
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return false;
        }

        $result = json_decode($response, true);
        
        // VK API возвращает id отправленного сообщения при успехе или error
        return isset($result['response']) || isset($result['result']);
    }

    /**
     * Парсинг входящего вебхук-запроса от VK Callback API.
     */
    public function parseWebhookPayload(array $payload): ?array
    {
        if (($payload['type'] ?? '') !== 'message_new') {
            return null;
        }

        $message = $payload['object']['message'] ?? [];
        $fromId = $message['from_id'] ?? null;

        if (!$fromId) {
            return null;
        }

        return [
            'external_id'        => (string) ($message['id'] ?? ''),
            'client_external_id' => (string) $fromId,
            'client_name'        => 'VK User ' . $fromId,
            'text'               => $message['text'] ?? '',
            'type'               => 'text'
        ];
    }
}
