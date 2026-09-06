<?php

declare(strict_types=1);

namespace Chatgo\Adapters;

use Chatgo\Contracts\ChannelInterface;
use Throwable;

class TelegramPersonalAdapter implements ChannelInterface
{
    public function __construct(
        private string $serviceUrl,
        private string $secret
    ) {
    }

    /**
     * Отправка сообщения клиенту через локальный микросервис GramJS (MTProto).
     *
     * @param string $clientExternalId Telegram user ID / peer ID
     * @param string $text Текст сообщения
     * @param array $options Дополнительные параметры
     * @return bool
     */
    public function sendMessage(string $clientExternalId, string $text, array $options = []): bool
    {
        $url = rtrim($this->serviceUrl, '/') . '/api/send_message';

        $payload = [
            'peer_id' => $clientExternalId,
            'text'    => $text,
        ];

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Chatgo-Secret: ' . $this->secret,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                return false;
            }

            $result = json_decode((string) $response, true);
            return isset($result['ok']) && $result['ok'] === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Парсинг входящего вебхук-запроса от личного Telegram сервиса.
     *
     * @param array $payload
     * @return array{external_id: string, client_external_id: string, client_name: string, text: string, type: string}|null
     */
    public function parseWebhookPayload(array $payload): ?array
    {
        if (empty($payload['peer_id']) || !isset($payload['text'])) {
            return null;
        }

        $clientName = trim($payload['client_name'] ?? '');
        if ($clientName === '') {
            $clientName = $payload['client_username'] ? '@' . $payload['client_username'] : 'Telegram User';
        }

        return [
            'type'               => 'text',
            'external_id'        => (string) ($payload['message_id'] ?? ''),
            'client_external_id' => (string) $payload['peer_id'],
            'client_name'        => $clientName,
            'client_phone'       => $payload['client_phone'] ?? null,
            'text'               => (string) $payload['text'],
            'direction'          => $payload['direction'] ?? 'incoming',
        ];
    }
}
