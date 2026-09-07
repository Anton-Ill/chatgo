<?php

/**
 * Script to configure Telegram Bot Menu Button (WebApp)
 * Usage: php tests/manual/test-set-menu-button.php [URL] [TEXT]
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/Adapters/TelegramAdapter.php';

use Chatgo\Adapters\TelegramAdapter;

echo "=======================================================\n";
echo "       НАСТРОЙКА КНОПКИ МЕНЮ БОТА (setChatMenuButton)   \n";
echo "=======================================================\n\n";

try {
    $db = DB::getConnection();

    // 1. Поиск токена бота (из аргументов CLI, .env или БД)
    $arg1 = $argv[1] ?? '';
    $arg2 = $argv[2] ?? '';
    $arg3 = $argv[3] ?? '';

    $botToken = '';
    $webAppUrl = '';
    $buttonText = 'Панель';

    if (str_contains($arg1, ':') && !str_starts_with($arg1, 'http')) {
        // Первый аргумент — токен бота
        $botToken = $arg1;
        $webAppUrl = $arg2;
        $buttonText = $arg3 !== '' ? $arg3 : 'Панель';
    } else {
        $webAppUrl = $arg1;
        $buttonText = $arg2 !== '' ? $arg2 : 'Панель';
    }

    $channelName = '';
    if ($botToken === '') {
        $botToken = defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN !== ''
            ? (string) TELEGRAM_BOT_TOKEN
            : '';
    }

    if ($botToken === '') {
        $stmt = $db->query("SELECT name, settings FROM channels WHERE type = 'telegram' AND settings LIKE '%token%' LIMIT 1");
        $channel = $stmt->fetch();
        if ($channel) {
            $settings = json_decode((string) ($channel['settings'] ?? '{}'), true) ?: [];
            $botToken = (string) ($settings['token'] ?? '');
            $channelName = (string) ($channel['name'] ?? '');
        }
    }

    if ($botToken === '') {
        throw new RuntimeException("Токен Telegram-бота не найден в настройках каналов или .env. Укажите его первым аргументом: php tests/manual/test-set-menu-button.php <ТОКЕН_БОТА> [URL] [ТЕКСТ]");
    }

    $maskedToken = substr($botToken, 0, 8) . '...' . substr($botToken, -5);
    echo "  [OK] Используется токен бота: {$maskedToken} (" . ($channelName ?: 'Default') . ")\n";

    // 2. Инициализация адаптера
    $adapter = new TelegramAdapter($botToken, TELEGRAM_API_URL, CHATGO_SECRET);

    // 3. Проверка текущей кнопки меню
    echo "  Запрос текущих настроек кнопки меню (getChatMenuButton)...\n";
    $currentMenu = $adapter->getChatMenuButton();
    if ($currentMenu && isset($currentMenu['result'])) {
        echo "  Текущая кнопка меню: " . json_encode($currentMenu['result'], JSON_UNESCAPED_UNICODE) . "\n";
    }

    // 4. Определение целевого WebApp URL
    if ($webAppUrl === '') {
        $baseUrl = defined('BASE_URL') ? (string) BASE_URL : '';
        if (str_starts_with(strtolower($baseUrl), 'https://')) {
            $webAppUrl = rtrim($baseUrl, '/');
        } else {
            $webAppUrl = 'https://chatgo.ru';
        }
    }

    echo "\n  Целевой URL для кнопки: {$webAppUrl}\n";
    echo "  Текст кнопки: \"{$buttonText}\"\n";

    if (!str_starts_with(strtolower($webAppUrl), 'https://')) {
        throw new RuntimeException("Telegram WebApp требует строго HTTPS URL! Получен: '{$webAppUrl}'.");
    }

    // 5. Вызов setChatMenuButton
    echo "\n  Отправка запроса setChatMenuButton...\n";
    $success = $adapter->setChatMenuButton($webAppUrl, $buttonText);

    if (!$success) {
        throw new RuntimeException("Telegram API вернул ошибку при вызове setChatMenuButton.");
    }

    echo "  [OK] Кнопка меню успешно установлена в Telegram API!\n";

    // 6. Контрольная проверка
    $verifyMenu = $adapter->getChatMenuButton();
    if ($verifyMenu && isset($verifyMenu['result'])) {
        echo "  [ПОДТВЕРЖДЕНО]: " . json_encode($verifyMenu['result'], JSON_UNESCAPED_UNICODE) . "\n";
    }

    echo "\n=======================================================\n";
    echo "  КНОПКА МЕНЮ УСПЕШНО НАСТРОЕНА И АКТИВНА ДЛЯ ВСЕХ     \n";
    echo "=======================================================\n";

} catch (Throwable $e) {
    echo "\n[ОШИБКА]: " . $e->getMessage() . "\n";
    exit(1);
}
