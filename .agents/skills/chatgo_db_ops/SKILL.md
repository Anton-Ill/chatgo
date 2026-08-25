---
name: chatgo_db_ops
description: >-
  Паттерны работы с БД через PDO (транзакции, вставка сообщений и обновление счетчиков чатов).
---

# Операции с базой данных (PDO)

## 1. Получение подключения
Использовать исключительно `DB::getConnection()` из [`config/db.php`](file:///e:/01_Chatgo/config/db.php).

## 2. Атомарность (Транзакции)
При регистрации входящего сообщения:
- Начать транзакцию: `$db->beginTransaction();`
- Найти или создать чат в таблице `chats` по `channel_id` и `client_external_id` (используя `INSERT INTO ... ON DUPLICATE KEY UPDATE` или `SELECT FOR UPDATE`).
- Вставить запись в таблицу `messages` с `direction = 'incoming'`.
- Обновить счетчик `unread_count` и `last_message_at` in `chats`.
- Зафиксировать транзакцию: `$db->commit();`
- При ошибке вызвать `$db->rollBack();`
