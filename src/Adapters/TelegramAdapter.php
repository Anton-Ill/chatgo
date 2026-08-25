<?php

namespace Chatgo\Adapters;

use Chatgo\Contracts\ChannelInterface;

class TelegramAdapter implements ChannelInterface
{
    public function __construct(
        private string $botToken,
        private string $apiUrl,
        private string $secret
    ) {
    }

    /**
     * Отправка сообщения клиенту через прокси-шлюз tg-api.
     */
    public function sendMessage(string $clientExternalId, string $text, array $options = []): bool
    {
        // Формируем URL к нашему прокси-шлюзу
        $url = rtrim($this->apiUrl, '/') . '/bot' . $this->botToken . '/sendMessage';

        $payload = [
            'chat_id' => $clientExternalId,
            'text'    => $text,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Chatgo-Secret: ' . $this->secret
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return false;
        }

        $result = json_decode($response, true);
        return isset($result['ok']) && $result['ok'] === true;
    }

    /**
     * Парсинг входящего вебхук-запроса от Telegram.
     */
    public function parseWebhookPayload(array $payload): ?array
    {
        if (!isset($payload['message']['text'])) {
            return null;
        }

        $message = $payload['message'];
        $chat = $message['chat'];
        $from = $message['from'] ?? [];

        // Объединяем имя и фамилию
        $firstName = $from['first_name'] ?? '';
        $lastName = $from['last_name'] ?? '';
        $clientName = trim($firstName . ' ' . $lastName);
        if (empty($clientName)) {
            $clientName = $from['username'] ?? 'Telegram Client';
        }

        return [
            'external_id'        => (string) $message['message_id'],
            'client_external_id' => (string) $chat['id'],
            'client_name'        => $clientName,
            'text'               => $message['text'],
            'type'               => 'text'
        ];
    }
}
