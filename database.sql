CREATE DATABASE IF NOT EXISTS `chatgo` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `chatgo`;

-- Таблица пользователей (с заделом на будущее)
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `telegram_id` VARCHAR(50) NULL UNIQUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица токенов беспарольного входа (Magic Link)
CREATE TABLE IF NOT EXISTS `auth_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `expires_at` TIMESTAMP NOT NULL,
  `used` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица каналов связи (Telegram, WhatsApp, Instagram, VK, MAX)
CREATE TABLE IF NOT EXISTS `channels` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `type` VARCHAR(50) NOT NULL, -- telegram, whatsapp, vk, instagram, max
  `name` VARCHAR(255) NOT NULL, -- Название или логин бота, номер телефона
  `status` VARCHAR(50) DEFAULT 'disconnected', -- connected, disconnected, connecting
  `settings` TEXT NULL, -- JSON для хранения токенов, ключей сессий, QR и прочего
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица чатов с клиентами
CREATE TABLE IF NOT EXISTS `chats` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `channel_id` INT NOT NULL,
  `client_external_id` VARCHAR(255) NOT NULL, -- уникальный ID в мессенджере (например chat_id или телефон)
  `client_name` VARCHAR(255) NOT NULL,
  `client_phone` VARCHAR(50) NULL,
  `client_email` VARCHAR(255) NULL,
  `notes` TEXT NULL, -- заметки оператора
  `status` VARCHAR(50) DEFAULT 'new', -- new, active, archived
  `unread_count` INT DEFAULT 0,
  `last_message_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `channel_client` (`channel_id`, `client_external_id`),
  FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица сообщений
CREATE TABLE IF NOT EXISTS `messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `chat_id` INT NOT NULL,
  `direction` VARCHAR(20) NOT NULL, -- incoming, outgoing
  `text` TEXT NULL,
  `type` VARCHAR(50) DEFAULT 'text', -- text, image, file
  `attachment_url` VARCHAR(500) NULL,
  `external_id` VARCHAR(255) NULL, -- ID сообщения во внешней платформе (например message_id)
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`chat_id`) REFERENCES `chats` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
