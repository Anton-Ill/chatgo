<?php

namespace Chatgo\Contracts;

interface ChannelInterface
{
    /**
     * Отправка сообщения во внешний канал (клиенту).
     *
     * @param string $clientExternalId Внешний ID клиента в мессенджере
     * @param string $text Текст сообщения
     * @param array $options Дополнительные параметры
     * @return bool Успешно ли отправлено сообщение
     */
    public function sendMessage(string $clientExternalId, string $text, array $options = []): bool;

    /**
     * Парсинг входящего вебхук-запроса от мессенджера в унифицированный формат.
     *
     * @param array $payload Входящий JSON/массив данных от вебхука
     * @return array{external_id: string, client_external_id: string, client_name: string, text: string, type: string}|null
     */
    public function parseWebhookPayload(array $payload): ?array;
}
