<?php

namespace Chatgo\Services;

use PDO;
use Exception;

class MessageService
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Записать сообщение в базу данных в рамках транзакции.
     *
     * @param int $chatId
     * @param string $direction 'incoming' или 'outgoing'
     * @param string|null $text
     * @param string $type 'text', 'image', 'file' и т.д.
     * @param string|null $attachmentUrl
     * @param string|null $externalId
     * @return int ID созданного сообщения в БД
     * @throws Exception
     */
    public function recordMessage(
        int $chatId,
        string $direction,
        ?string $text,
        string $type = 'text',
        ?string $attachmentUrl = null,
        ?string $externalId = null
    ): int {
        if (!in_array($direction, ['incoming', 'outgoing'])) {
            throw new Exception("Неверное направление сообщения: {$direction}");
        }

        try {
            $this->db->beginTransaction();

            // Вставляем новое сообщение в messages
            $stmt = $this->db->prepare(
                'INSERT INTO messages (chat_id, direction, text, type, attachment_url, external_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $chatId,
                $direction,
                $text,
                $type,
                $attachmentUrl,
                $externalId
            ]);
            $messageId = (int) $this->db->lastInsertId();

            // Обновляем чат: last_message_at и при необходимости unread_count
            if ($direction === 'incoming') {
                $stmt = $this->db->prepare(
                    'UPDATE chats 
                     SET unread_count = unread_count + 1, last_message_at = NOW() 
                     WHERE id = ?'
                );
            } else {
                // При отправке исходящего сообщения оператором сбрасываем unread_count в 0
                $stmt = $this->db->prepare(
                    'UPDATE chats 
                     SET unread_count = 0, last_message_at = NOW() 
                     WHERE id = ?'
                );
            }
            $stmt->execute([$chatId]);

            $this->db->commit();
            return $messageId;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
