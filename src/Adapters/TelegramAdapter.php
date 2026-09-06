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
        $url = rtrim($this->apiUrl, '/') . '/bot' . $this->botToken . '/sendMessage';

        $payload = [
            'chat_id' => $clientExternalId,
            'text'    => $text,
        ];

        if (isset($options['reply_markup'])) {
            $payload['reply_markup'] = $options['reply_markup'];
        }

        return $this->sendPostRequest($url, $payload);
    }

    /**
     * Редактирование текста сообщения (например, после нажатия inline кнопки).
     */
    public function editMessageText(string $chatId, int $messageId, string $text, array $options = []): bool
    {
        $url = rtrim($this->apiUrl, '/') . '/bot' . $this->botToken . '/editMessageText';

        $payload = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
        ];

        if (isset($options['reply_markup'])) {
            $payload['reply_markup'] = $options['reply_markup'];
        }

        return $this->sendPostRequest($url, $payload);
    }

    /**
     * Подтверждение получения callback_query (снятие индикатора загрузки с кнопки).
     */
    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): bool
    {
        $url = rtrim($this->apiUrl, '/') . '/bot' . $this->botToken . '/answerCallbackQuery';

        $payload = [
            'callback_query_id' => $callbackQueryId,
        ];

        if ($text !== '') {
            $payload['text'] = $text;
            $payload['show_alert'] = $showAlert;
        }

        return $this->sendPostRequest($url, $payload);
    }

    /**
     * Получить обновления через метод getUpdates (Long Polling).
     *
     * @param int $offset
     * @param int $timeout
     * @param int $limit
     * @return array
     */
    public function getUpdates(int $offset = 0, int $timeout = 25, int $limit = 50): array
    {
        $url = rtrim($this->apiUrl, '/') . '/bot' . $this->botToken . '/getUpdates';

        $payload = [
            'timeout' => $timeout,
            'limit' => $limit,
            'allowed_updates' => ['message', 'callback_query']
        ];
        if ($offset > 0) {
            $payload['offset'] = $offset;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout + 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Chatgo-Secret: ' . $this->secret
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return [];
        }

        $result = json_decode((string) $response, true);
        return (isset($result['ok']) && $result['ok'] === true && is_array($result['result']))
            ? $result['result']
            : [];
    }

    /**
     * Удалить вебхук Telegram бота.
     */
    public function deleteWebhook(): bool
    {
        $url = rtrim($this->apiUrl, '/') . '/bot' . $this->botToken . '/deleteWebhook';
        return $this->sendPostRequest($url, []);
    }

    /**
     * Выполнение POST запроса к Telegram API через прокси-шлюз.
     */
    private function sendPostRequest(string $url, array $payload): bool
    {
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

        $result = json_decode((string) $response, true);
        return isset($result['ok']) && $result['ok'] === true;
    }

    /**
     * Парсинг входящего вебхук-запроса от Telegram (текстовые сообщения и callback_query).
     */
    public function parseWebhookPayload(array $payload): ?array
    {
        // 1. Проверка callback_query (нажатия inline-кнопок)
        if (isset($payload['callback_query'])) {
            $cq = $payload['callback_query'];
            $from = $cq['from'] ?? [];
            $firstName = $from['first_name'] ?? '';
            $lastName = $from['last_name'] ?? '';
            $operatorName = trim($firstName . ' ' . $lastName);
            if (empty($operatorName)) {
                $operatorName = $from['username'] ?? 'Telegram Operator';
            }

            return [
                'type'               => 'callback_query',
                'callback_query_id'  => (string) ($cq['id'] ?? ''),
                'client_external_id' => (string) ($from['id'] ?? ''),
                'client_name'        => $operatorName,
                'message_id'         => (int) ($cq['message']['message_id'] ?? 0),
                'chat_id'            => (string) ($cq['message']['chat']['id'] ?? ''),
                'data'               => (string) ($cq['data'] ?? '')
            ];
        }

        // 2. Обычные текстовые сообщения
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
            'type'               => 'text',
            'external_id'        => (string) $message['message_id'],
            'client_external_id' => (string) $chat['id'],
            'client_name'        => $clientName,
            'text'               => $message['text']
        ];
    }
}
