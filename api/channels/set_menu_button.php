<?php

/**
 * REST API: Configure Telegram Bot Menu Button (WebApp)
 * Path: api/channels/set_menu_button.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/Adapters/TelegramAdapter.php';

use Chatgo\Adapters\TelegramAdapter;
use Chatgo\Security\WebAppAuthenticator;

header('Content-Type: application/json');

try {
    $db = DB::getConnection();

    // 1. Проверка авторизации
    $userId = WebAppAuthenticator::getAuthenticatedUserId($db);
    $isDev = WebAppAuthenticator::isDevAuthorized();

    if ($userId === null && !$isDev) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Доступ запрещен: Не авторизован']);
        exit;
    }

    // Читаем параметры
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: [];

    $buttonText = trim((string) ($data['button_text'] ?? $_GET['button_text'] ?? 'Панель'));
    $customUrl = trim((string) ($data['url'] ?? $_GET['url'] ?? ''));
    $tokenParam = trim((string) ($data['token'] ?? $_GET['token'] ?? ''));

    // 2. Определение токена бота
    $botToken = $tokenParam;
    if ($botToken === '') {
        $stmt = $db->prepare("
            SELECT settings 
            FROM channels 
            WHERE type = 'telegram' AND settings LIKE '%token%'
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $channel = $stmt->fetch();
        if ($channel) {
            $settings = json_decode((string) ($channel['settings'] ?? '{}'), true) ?: [];
            $botToken = (string) ($settings['token'] ?? '');
        }
    }

    if ($botToken === '' && defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN !== '') {
        $botToken = (string) TELEGRAM_BOT_TOKEN;
    }

    if ($botToken === '') {
        throw new Exception("Канал Telegram бота не найден в системе. Подключите Telegram бота в панели каналов.");
    }

    // 3. Определение WebApp URL
    if ($customUrl !== '') {
        $webAppUrl = $customUrl;
    } else {
        $baseUrl = defined('BASE_URL') ? (string) BASE_URL : '';
        if (str_starts_with(strtolower($baseUrl), 'https://')) {
            $webAppUrl = rtrim($baseUrl, '/');
        } else {
            $webAppUrl = 'https://chatgo.ru';
        }
    }

    if (!str_starts_with(strtolower($webAppUrl), 'https://')) {
        throw new Exception("Telegram WebApp требует защищенный HTTPS URL (получен: {$webAppUrl}).");
    }

    // 4. Вызов Telegram API через адаптер
    $adapter = new TelegramAdapter($botToken, TELEGRAM_API_URL, CHATGO_SECRET);
    $ok = $adapter->setChatMenuButton($webAppUrl, $buttonText);

    if (!$ok) {
        throw new Exception("Не удалось обновить кнопку меню в Telegram API.");
    }

    $currentMenu = $adapter->getChatMenuButton();

    echo json_encode([
        'ok' => true,
        'message' => 'Кнопка меню бота успешно настроена',
        'url' => $webAppUrl,
        'button_text' => $buttonText,
        'menu_button' => $currentMenu['result'] ?? null
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
