<?php

declare(strict_types=1);

/**
 * Исправление ограничения NOT NULL в БД на продакшне и локально
 * Путь: tests/manual/fix_db_schema.php
 */

require_once __DIR__ . '/../../config/db.php';

try {
    $db = DB::getConnection();
    echo "=== Модификация колонок БД ===\n";

    // 1. Делаем email и user_id в users необязательным (NULL)
    try {
        $db->exec("ALTER TABLE `users` MODIFY `email` VARCHAR(255) NULL DEFAULT NULL");
        echo "[OK] `users.email` изменен на NULL DEFAULT NULL\n";
    } catch (Throwable $e) {
        echo "[WARN] `users.email`: " . $e->getMessage() . "\n";
    }
    try {
        $db->exec("ALTER TABLE `users` MODIFY `user_id` INT NULL DEFAULT NULL");
        echo "[OK] `users.user_id` изменен на NULL DEFAULT NULL\n";
    } catch (Throwable $e) {
        echo "[INFO] `users.user_id`: " . $e->getMessage() . "\n";
    }

    // 2. Сбрасываем FK auth_tokens_ibfk_1 если существует
    try {
        $db->exec("ALTER TABLE `auth_tokens` DROP FOREIGN KEY `auth_tokens_ibfk_1`");
        echo "[OK] FK auth_tokens_ibfk_1 удален\n";
    } catch (Throwable $e) {
        echo "[INFO] FK auth_tokens_ibfk_1: " . $e->getMessage() . "\n";
    }

    // 3. Делаем user_id в auth_tokens необязательным (NULL)
    try {
        $db->exec("ALTER TABLE `auth_tokens` MODIFY `user_id` INT NULL DEFAULT NULL");
        echo "[OK] `auth_tokens.user_id` изменен на NULL DEFAULT NULL\n";
    } catch (Throwable $e) {
        echo "[WARN] `auth_tokens.user_id`: " . $e->getMessage() . "\n";
    }

    // 4. Делаем user_id в channels необязательным (NULL)
    try {
        $db->exec("ALTER TABLE `channels` DROP FOREIGN KEY `channels_ibfk_1`");
    } catch (Throwable $e) {}
    try {
        $db->exec("ALTER TABLE `channels` MODIFY `user_id` INT NULL DEFAULT NULL");
        echo "[OK] `channels.user_id` изменен на NULL DEFAULT NULL\n";
    } catch (Throwable $e) {
        echo "[WARN] `channels.user_id`: " . $e->getMessage() . "\n";
    }

    echo "Успешно обработано.\n";

} catch (Throwable $e) {
    echo "Ошибка подключения к БД: " . $e->getMessage() . "\n";
}
