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
        ?string $clientEmail = null,
        string $initialStatus = 'new'
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
                 VALUES (?, ?, ?, ?, ?, ?, 0, NOW())'
            );
            $stmt->execute([
                $channelId,
                $clientExternalId,
                $clientName,
                $clientPhone,
                $clientEmail,
                $initialStatus
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

    /**
     * Получить данные чата по ID.
     */
    public function getChatById(int $chatId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM chats WHERE id = ? LIMIT 1');
        $stmt->execute([$chatId]);
        $chat = $stmt->fetch();
        return $chat ?: null;
    }

    /**
     * Обновить статус чата (например, active, pending, rejected, archived).
     */
    public function updateStatus(int $chatId, string $status): bool
    {
        $stmt = $this->db->prepare('UPDATE chats SET status = ? WHERE id = ?');
        return $stmt->execute([$status, $chatId]);
    }
}
