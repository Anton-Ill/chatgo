<?php

declare(strict_types=1);

namespace Chatgo\Services;

use PDO;
use Chatgo\Adapters\TelegramAdapter;

class NotificationService
{
    private PDO $db;
    private ?TelegramAdapter $systemBotAdapter;

    public function __construct(PDO $db, ?TelegramAdapter $systemBotAdapter = null)
    {
        $this->db = $db;
        $this->systemBotAdapter = $systemBotAdapter;
    }

    /**
     * Получить Telegram ID оператора, привязанного к каналу данного чата.
     *
     * @param int $chatId
     * @return string|null
     */
    public function getOperatorTelegramId(int $chatId): ?string
    {
        $stmt = $this->db->prepare('
            SELECT u.telegram_id 
            FROM chats c
            JOIN channels ch ON c.channel_id = ch.id
            JOIN users u ON ch.user_id = u.id
            WHERE c.id = ? AND u.telegram_id IS NOT NULL AND u.telegram_id != ""
            LIMIT 1
        ');
        $stmt->execute([$chatId]);
        $operatorTgId = $stmt->fetchColumn();

        if (!$operatorTgId && defined('OPERATOR_TELEGRAM_ID') && OPERATOR_TELEGRAM_ID !== '') {
            $operatorTgId = (string) OPERATOR_TELEGRAM_ID;
        }

        return $operatorTgId ? (string) $operatorTgId : null;
    }

    /**
     * Получить или инициализировать TelegramAdapter для системного бота.
     *
     * @return TelegramAdapter|null
     */
    public function getBotAdapter(): ?TelegramAdapter
    {
        if ($this->systemBotAdapter !== null) {
            return $this->systemBotAdapter;
        }

        $botToken = defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN !== ''
            ? (string) TELEGRAM_BOT_TOKEN
            : '';

        if ($botToken === '') {
            // Ищем токен подключенного Telegram-канала в БД
            $stmt = $this->db->query("
                SELECT settings 
                FROM channels 
                WHERE type = 'telegram' AND settings LIKE '%token%' 
                ORDER BY id ASC 
                LIMIT 1
            ");
            $channel = $stmt->fetch();
            if ($channel) {
                $settings = json_decode((string) ($channel['settings'] ?? '{}'), true) ?: [];
                $botToken = (string) ($settings['token'] ?? '');
            }
        }

        if ($botToken === '') {
            return null;
        }

        $apiUrl = defined('TELEGRAM_API_URL') ? (string) TELEGRAM_API_URL : '';
        $secret = defined('CHATGO_SECRET') ? (string) CHATGO_SECRET : '';

        $this->systemBotAdapter = new TelegramAdapter($botToken, $apiUrl, $secret);
        return $this->systemBotAdapter;
    }

    /**
     * Отправить оператору уведомление о новом входящем сообщении.
     *
     * @param int $chatId
     * @param string $text
     * @param string $channelType
     * @param string $clientName
     * @param array $options ['is_pending' => bool, 'client_external_id' => string]
     * @return bool
     */
    public function notifyNewMessage(
        int $chatId,
        string $text,
        string $channelType,
        string $clientName,
        array $options = []
    ): bool {
        $operatorTgId = $this->getOperatorTelegramId($chatId);
        if (!$operatorTgId) {
            return false;
        }

        // Не отправляем эхо, если оператор сам является клиентом в этом чате
        $clientExtId = (string) ($options['client_external_id'] ?? '');
        if ($clientExtId !== '' && $clientExtId === $operatorTgId) {
            return false;
        }

        $adapter = $this->getBotAdapter();
        if (!$adapter) {
            return false;
        }

        // Значок платформы
        $channelIcons = [
            'telegram'          => '✈️ [Telegram]',
            'telegram_personal' => '👤 [TG Личный]',
            'whatsapp'          => '🟢 [WhatsApp]',
            'vk'                => '🔵 [ВКонтакте]',
            'instagram'         => '📸 [Instagram]',
            'max'               => '🟣 [MAX]',
        ];
        $icon = $channelIcons[$channelType] ?? ('💬 [' . ucfirst($channelType) . ']');

        // Формирование текста
        $isPending = !empty($options['is_pending']);
        if ($isPending) {
            $notifyText = "🔔 Новый запрос на диалог!\n"
                . "Канал: {$icon}\n"
                . "Клиент: {$clientName}\n"
                . "Сообщение: \"{$text}\"\n\n"
                . "↩️ Ответьте на это сообщение (Reply), чтобы сразу написать клиенту.";
        } else {
            $notifyText = "{$icon} {$clientName}:\n\"{$text}\"\n\n"
                . "↩️ Ответьте на это сообщение (Reply), чтобы написать ответ.";
        }

        // Формирование inline-клавиатуры с кнопкой перехода в чат
        $baseUrl = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : 'http://localhost';
        $chatUrl = $baseUrl . '/?chat_id=' . $chatId;

        $openBtn = ['text' => '💬 Открыть в дашборде'];
        if (str_starts_with(strtolower($baseUrl), 'https://')) {
            $openBtn['web_app'] = ['url' => $chatUrl];
        } else {
            $openBtn['url'] = $chatUrl;
        }

        $keyboard = [[$openBtn]];

        if ($isPending) {
            $keyboard[] = [
                ['text' => '✅ Одобрить', 'callback_data' => "approve_{$chatId}"],
                ['text' => '❌ Отклонить', 'callback_data' => "reject_{$chatId}"]
            ];
        }

        $res = $adapter->sendMessageWithResult(
            $operatorTgId,
            $notifyText,
            ['reply_markup' => ['inline_keyboard' => $keyboard]]
        );

        if ($res && !empty($res['result']['message_id'])) {
            $botMsgId = (int) $res['result']['message_id'];
            $this->recordBotNotification($botMsgId, $operatorTgId, $chatId);
            return true;
        }

        return $res !== null;
    }

    /**
     * Сохранить привязку ID сообщения бота к chat_id.
     *
     * @param int $botMessageId
     * @param string $operatorTgId
     * @param int $chatId
     * @return void
     */
    public function recordBotNotification(int $botMessageId, string $operatorTgId, int $chatId): void
    {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO bot_notifications (bot_message_id, operator_telegram_id, chat_id, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE chat_id = VALUES(chat_id), created_at = NOW()
            ');
            $stmt->execute([$botMessageId, $operatorTgId, $chatId]);
        } catch (\Throwable $e) {
            // Игнорируем ошибки логирования уведомления
        }
    }

    /**
     * Получить chat_id по ID сообщения бота, на которое ответил оператор.
     *
     * @param int $botMessageId
     * @param string $operatorTgId
     * @return int|null
     */
    public function getChatIdByReplyMessageId(int $botMessageId, string $operatorTgId): ?int
    {
        // 1. Точный поиск по bot_message_id и operator_telegram_id
        $stmt = $this->db->prepare('
            SELECT chat_id 
            FROM bot_notifications 
            WHERE bot_message_id = ? AND operator_telegram_id = ? 
            LIMIT 1
        ');
        $stmt->execute([$botMessageId, $operatorTgId]);
        $chatId = $stmt->fetchColumn();

        if ($chatId !== false) {
            return (int) $chatId;
        }

        // 2. Поиск только по bot_message_id (на случай расхождения операторского ID)
        $stmt2 = $this->db->prepare('
            SELECT chat_id 
            FROM bot_notifications 
            WHERE bot_message_id = ? 
            LIMIT 1
        ');
        $stmt2->execute([$botMessageId]);
        $chatId2 = $stmt2->fetchColumn();

        return ($chatId2 !== false) ? (int) $chatId2 : null;
    }
}
