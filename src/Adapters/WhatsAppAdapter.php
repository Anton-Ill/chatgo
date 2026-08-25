<?php

namespace Chatgo\Adapters;

use Chatgo\Contracts\ChannelInterface;

class WhatsAppAdapter implements ChannelInterface
{
    public function __construct(
        private string $accessToken,
        private string $phoneNumberId = '',
        private string $verifyToken = ''
    ) {
    }

    /**
     * Отправка текстового сообщения клиенту через WhatsApp Cloud API.
     */
    public function sendMessage(string $clientExternalId, string $text, array $options = []): bool
    {
        // Режим песочницы для офлайн тестирования
        if (str_contains($this->accessToken, 'MOCK')) {
            return true;
        }

        $url = "https://graph.facebook.com/v17.0/{$this->phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $clientExternalId,
            'type'              => 'text',
            'text'              => [
                'preview_url' => false,
                'body'        => $text
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

        // WhatsApp Cloud API возвращает messages[].id при успехе
        return isset($result['messages'][0]['id']);
    }

    /**
     * Парсинг входящего вебхук-запроса от WhatsApp Cloud API.
     */
    public function parseWebhookPayload(array $payload): ?array
    {
        $entry = $payload['entry'][0] ?? null;
        if (!$entry) {
            return null;
        }

        $changes = $entry['changes'][0] ?? null;
        if (!$changes || ($changes['field'] ?? '') !== 'messages') {
            return null;
        }

        $value = $changes['value'] ?? [];
        $messages = $value['messages'] ?? [];

        if (empty($messages)) {
            return null;
        }

        $message = $messages[0];
        $from = $message['from'] ?? null;

        if (!$from) {
            return null;
        }

        // Извлекаем имя контакта, если доступно
        $contacts = $value['contacts'] ?? [];
        $clientName = 'WhatsApp ' . $from;
        if (!empty($contacts[0]['profile']['name'])) {
            $clientName = $contacts[0]['profile']['name'];
        }

        // Поддерживаем только текстовые сообщения
        $text = '';
        $type = $message['type'] ?? 'text';
        if ($type === 'text') {
            $text = $message['text']['body'] ?? '';
        } else {
            $text = "[{$type}]";
        }

        return [
            'external_id'        => $message['id'] ?? '',
            'client_external_id' => (string) $from,
            'client_name'        => $clientName,
            'text'               => $text,
            'type'               => 'text'
        ];
    }
}
