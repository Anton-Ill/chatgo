<?php

/**
 * Database Connection Helper (PDO)
 * PHP Version 8.x
 */

require_once __DIR__ . '/config.php';

class DB
{
    private static ?PDO $instance = null;

    /**
     * Возвращает экземпляр подключения к БД (Singleton)
     *
     * @return PDO
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                try {
                    @self::$instance->exec("ALTER TABLE `auth_tokens` DROP FOREIGN KEY `auth_tokens_ibfk_1`");
                } catch (\Throwable $e) {}
                try {
                    @self::$instance->exec("ALTER TABLE `auth_tokens` MODIFY `user_id` INT NULL DEFAULT NULL");
                } catch (\Throwable $e) {}
                try {
                    @self::$instance->exec("ALTER TABLE `users` MODIFY `email` VARCHAR(255) NULL DEFAULT NULL");
                } catch (\Throwable $e) {}
            } catch (PDOException $e) {
                // В реальном продакшене лучше логировать ошибку, а не выводить на экран
                die("Ошибка подключения к базе данных: " . $e->getMessage());
            }
        }
        return self::$instance;
    }
}
