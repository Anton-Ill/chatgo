<?php

/**
 * Chatgo Configuration Loader
 * PHP Version 8.x
 */

// Включаем отображение ошибок для разработки
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
if (function_exists('opcache_reset')) {
    @opcache_reset();
}
ini_set('opcache.enable', '0');

// Установка временной зоны
date_default_timezone_set('Europe/Moscow');

// Загрузка переменных окружения из .env
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Авто-исправление структуры БД до session_start()
try {
    $dbHost = getenv('DB_HOST') ?: 'localhost';
    $dbName = getenv('DB_NAME') ?: 'chatgo';
    $dbUser = getenv('DB_USER') ?: 'root';
    $dbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
    $rawPdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT
    ]);
    $rawPdo->exec("ALTER TABLE `auth_tokens` DROP FOREIGN KEY `auth_tokens_ibfk_1`");
    $rawPdo->exec("ALTER TABLE `auth_tokens` MODIFY `user_id` INT NULL DEFAULT NULL");
    $rawPdo->exec("ALTER TABLE `channels` MODIFY `user_id` INT NULL DEFAULT NULL");
    $rawPdo->exec("ALTER TABLE `users` MODIFY `email` VARCHAR(255) NULL DEFAULT NULL");
} catch (Throwable $e) {}

// Запуск сессии, если она не запущена
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Загрузка переменных окружения из .env
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Константы базы данных
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'chatgo');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

// Константы API и безопасности
define('TELEGRAM_API_URL', getenv('TELEGRAM_API_URL') ?: 'https://client.chatgo.ru/tg-api');
define('TELEGRAM_PERSONAL_SERVICE_URL', getenv('TELEGRAM_PERSONAL_SERVICE_URL') ?: 'http://127.0.0.1:3005');
define('CHATGO_SECRET', getenv('CHATGO_SECRET') ?: 'CG_Secret_Gate_2026_Secure');
define('OPERATOR_TELEGRAM_ID', getenv('OPERATOR_TELEGRAM_ID') ?: '');
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '8530564668:AAH2PqpJpVHnWSSws4KacbV1S2YDNP1GeQg');
define('TELEGRAM_BOT_USERNAME', getenv('TELEGRAM_BOT_USERNAME') ?: 'chatgoservice_bot');
define('BASE_URL', getenv('BASE_URL') ?: 'http://localhost/01_Chatgo');


// Настройки SMTP
define('SMTP_HOST', getenv('SMTP_HOST') ?: '');
define('SMTP_PORT', getenv('SMTP_PORT') ?: '587');
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_FROM', getenv('SMTP_FROM') ?: 'noreply@chatgo.ru');

// Простой PSR-4 автозагрузчик классов
spl_autoload_register(function ($class) {
    $prefix = 'Chatgo\\';
    $baseDir = dirname(__DIR__) . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

