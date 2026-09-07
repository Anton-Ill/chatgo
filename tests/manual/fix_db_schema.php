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

    // 1. Делаем email в users необязательным (NULL)
    try {
        $db->exec("ALTER TABLE `users` MODIFY `email` VARCHAR(255) NULL DEFAULT NULL");
        echo "[OK] `users.email` изменен на NULL DEFAULT NULL\n";
    } catch (Throwable $e) {
        echo "[WARN] `users.email`: " . $e->getMessage() . "\n";
    }

    // 2. Делаем user_id в auth_tokens необязательным (NULL)
    try {
        $db->exec("ALTER TABLE `auth_tokens` MODIFY `user_id` INT NULL DEFAULT NULL");
        echo "[OK] `auth_tokens.user_id` изменен на NULL DEFAULT NULL\n";
    } catch (Throwable $e) {
        echo "[WARN] `auth_tokens.user_id`: " . $e->getMessage() . "\n";
    }

    echo "Успешно обработано.\n";

} catch (Throwable $e) {
    echo "Ошибка подключения к БД: " . $e->getMessage() . "\n";
}
