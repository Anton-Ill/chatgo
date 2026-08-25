<?php

namespace Chatgo\Services;

use PDO;

class ChatService
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Получить существующий чат или создать новый.
     *
     * @param int $channelId
     * @param string $clientExternalId
     * @param string $clientName
     * @param string|null $clientPhone
     * @param string|null $clientEmail
     * @return int ID чата в базе данных
     */
    public function getOrCreateChat(
        int $channelId,
        string $clientExternalId,
        string $clientName,
        ?string $clientPhone = null,
        ?string $clientEmail = null
    ): int {
        $stmt = $this->db->prepare(
            'SELECT id FROM chats WHERE channel_id = ? AND client_external_id = ?'
        );
        $stmt->execute([$channelId, $clientExternalId]);
        $chatId = $stmt->fetchColumn();

        if ($chatId !== false) {
            return (int) $chatId;
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO chats (channel_id, client_external_id, client_name, client_phone, client_email, status, unread_count, created_at)
                 VALUES (?, ?, ?, ?, ?, \'new\', 0, NOW())'
            );
            $stmt->execute([
                $channelId,
                $clientExternalId,
                $clientName,
                $clientPhone,
                $clientEmail
            ]);
            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            // Если произошла коллизия из-за параллельного запроса, делаем повторную выборку
            $stmt = $this->db->prepare(
                'SELECT id FROM chats WHERE channel_id = ? AND client_external_id = ?'
            );
            $stmt->execute([$channelId, $clientExternalId]);
            $chatId = $stmt->fetchColumn();
            if ($chatId !== false) {
                return (int) $chatId;
            }
            throw $e;
        }
    }
}
