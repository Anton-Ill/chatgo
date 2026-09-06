<?php

namespace Chatgo\Security;

use PDO;

class WebAppAuthenticator
{
    /**
     * Проверяет, авторизован ли разработчик/тестировщик через секретный ключ CHATGO_SECRET.
     */
    public static function isDevAuthorized(): bool
    {
        $secret = defined('CHATGO_SECRET') ? (string) CHATGO_SECRET : '';
        if ($secret === '') {
            return false;
        }

        // Проверка флага сессии
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['chatgo_dev_auth'])) {
            return true;
        }

        // Проверка ключа в заголовке, cookie, GET или POST
        $key = $_SERVER['HTTP_X_DEV_KEY'] ?? $_COOKIE['chatgo_dev_key'] ?? $_GET['dev_key'] ?? $_POST['dev_key'] ?? '';
        if ($key !== '' && hash_equals($secret, (string) $key)) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['chatgo_dev_auth'] = true;
            }
            return true;
        }

        return false;
    }

    /**
     * Выполняет проверку подписи initData и авторизацию оператора.
     */
    public static function authenticate(PDO $db): bool
    {
        // 0. Проверка индивидуального тестового доступа по CHATGO_SECRET
        if (self::isDevAuthorized()) {
            return true;
        }

        // 1. Получаем initData из заголовков или GET/POST параметров
        $initData = '';
        if (isset($_SERVER['HTTP_X_TG_INIT_DATA'])) {
            $initData = $_SERVER['HTTP_X_TG_INIT_DATA'];
        } elseif (isset($_GET['tg_init_data'])) {
            $initData = $_GET['tg_init_data'];
        } elseif (isset($_POST['tg_init_data'])) {
            $initData = $_POST['tg_init_data'];
        }

        // 2. В режиме локальной разработки разрешаем обход авторизации при отсутствии initData
        if (empty($initData)) {
            $baseUrl = defined('BASE_URL') ? BASE_URL : '';
            if (str_contains($baseUrl, 'local') || str_contains($baseUrl, 'localhost')) {
                return true;
            }
            return false;
        }

        // 3. Получаем токен Telegram бота из базы данных
        $stmt = $db->query("SELECT settings FROM channels WHERE type = 'telegram' LIMIT 1");
        $channel = $stmt->fetch();
        if (!$channel) {
            return false;
        }

        $settings = json_decode($channel['settings'] ?? '{}', true);
        $botToken = $settings['token'] ?? '';

        if ($botToken === '') {
            return false;
        }

        // 4. Проверяем подпись Telegram WebApp
        $userData = self::verify($initData, $botToken);
        if (!$userData) {
            return false;
        }

        // 5. Проверяем, привязан ли Telegram ID оператора в БД
        $tgUserId = (string) ($userData['id'] ?? '');
        if ($tgUserId === '') {
            return false;
        }

        $userStmt = $db->prepare("SELECT id FROM users WHERE telegram_id = ? LIMIT 1");
        $userStmt->execute([$tgUserId]);
        if ($userStmt->fetch() !== false) {
            return true;
        }

        // Автопривязка первого оператора: если в системе еще нет ни одного привязанного Telegram ID,
        // автоматически связываем первого пользователя с валидной криптографической подписью бота
        $hasBoundOperator = (bool) $db->query("SELECT id FROM users WHERE telegram_id IS NOT NULL LIMIT 1")->fetchColumn();
        if (!$hasBoundOperator) {
            $firstUserStmt = $db->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
            $firstUserId = $firstUserStmt->fetchColumn();
            if ($firstUserId) {
                $bindStmt = $db->prepare("UPDATE users SET telegram_id = ? WHERE id = ?");
                $bindStmt->execute([$tgUserId, $firstUserId]);
                return true;
            }
        }

        // Fallback: проверка по OPERATOR_TELEGRAM_ID константе
        $allowedOperatorId = defined('OPERATOR_TELEGRAM_ID') ? (string) OPERATOR_TELEGRAM_ID : '';
        return $allowedOperatorId !== '' && $tgUserId === $allowedOperatorId;
    }

    /**
     * Валидация подписи initData Telegram с помощью Bot Token.
     */
    public static function verify(string $initData, string $botToken): ?array
    {
        // Разбираем query string
        $params = [];
        parse_str($initData, $params);

        if (!isset($params['hash'])) {
            return null;
        }

        $hash = $params['hash'];
        unset($params['hash']);

        // Сортируем параметры по алфавиту
        ksort($params);

        // Формируем проверочную строку
        $dataCheckArr = [];
        foreach ($params as $key => $value) {
            $dataCheckArr[] = "$key=$value";
        }
        $dataCheckString = implode("\n", $dataCheckArr);

        // Вычисляем секретный ключ
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);

        // Вычисляем проверочный хеш
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        // Сравниваем хеши
        if (!hash_equals($hash, $calculatedHash)) {
            return null;
        }

        // Парсим JSON пользователя
        if (isset($params['user'])) {
            return json_decode($params['user'], true);
        }

        return null;
    }
}
