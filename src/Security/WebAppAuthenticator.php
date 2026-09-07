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
     * Возвращает ID аутентифицированного пользователя или null.
     */
    public static function getAuthenticatedUserId(PDO $db): ?int
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        // 1. Проверяем активную PHP сессию
        if (!empty($_SESSION['chatgo_user_id'])) {
            $uid = (int) $_SESSION['chatgo_user_id'];
            $stmt = $db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$uid]);
            if ($stmt->fetchColumn() !== false) {
                return $uid;
            }
            unset($_SESSION['chatgo_user_id']);
        }

        // 2. Индивидуальный тестовый доступ разработчика по CHATGO_SECRET
        if (self::isDevAuthorized()) {
            $devUserId = $db->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
            if (!$devUserId) {
                $db->exec("INSERT INTO users (email, created_at) VALUES ('admin@chatgo.ru', NOW())");
                $devUserId = $db->lastInsertId();
            }
            $uid = (int) $devUserId;
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['chatgo_user_id'] = $uid;
            }
            return $uid;
        }

        // 3. Проверка одноразового токена беспарольного входа (?token=... или ?auth_token=...)
        $token = $_GET['token'] ?? $_GET['auth_token'] ?? '';
        if ($token !== '') {
            $stmt = $db->prepare('SELECT user_id, expires_at, used FROM auth_tokens WHERE token = ? LIMIT 1');
            $stmt->execute([$token]);
            $tokenData = $stmt->fetch();
            if ($tokenData && !empty($tokenData['user_id']) && strtotime($tokenData['expires_at']) > time()) {
                $uid = (int) $tokenData['user_id'];
                $db->prepare('UPDATE auth_tokens SET used = 1 WHERE token = ?')->execute([$token]);
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['chatgo_user_id'] = $uid;
                }
                return $uid;
            }
        }

        // 4. Проверка криптографической подписи initData из Telegram WebApp
        $initData = $_SERVER['HTTP_X_TG_INIT_DATA'] ?? $_GET['tg_init_data'] ?? $_POST['tg_init_data'] ?? '';
        if ($initData !== '') {
            $botToken = defined('TELEGRAM_BOT_TOKEN') ? (string) TELEGRAM_BOT_TOKEN : '';

            $userData = self::verify($initData, $botToken);

            // Fallback: если токен системного бота не задан или не подошел, проверяем токены каналов
            if (!$userData) {
                try {
                    $channelsStmt = $db->query("SELECT settings FROM channels WHERE type = 'telegram'");
                    while ($row = $channelsStmt->fetch()) {
                        $settings = json_decode($row['settings'] ?? '{}', true);
                        $channelToken = $settings['token'] ?? '';
                        if (!empty($channelToken) && $channelToken !== $botToken) {
                            $userData = self::verify($initData, $channelToken);
                            if ($userData) {
                                break;
                            }
                        }
                    }
                } catch (\Throwable $e) {}
            }

            if ($userData && !empty($userData['id'])) {
                $uid = self::findOrCreateUserByTelegram($db, $userData);
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['chatgo_user_id'] = $uid;
                }
                return $uid;
            }
        }

        // 5. Режим локальной разработки (fallback для localhost/chatgo.local)
        $baseUrl = defined('BASE_URL') ? (string) BASE_URL : '';
        if (str_contains($baseUrl, 'local') || str_contains($baseUrl, 'localhost')) {
            $localUserId = $db->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
            if ($localUserId) {
                $uid = (int) $localUserId;
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['chatgo_user_id'] = $uid;
                }
                return $uid;
            }
        }

        return null;
    }

    /**
     * Поиск или создание пользователя по Telegram-данным.
     */
    public static function findOrCreateUserByTelegram(PDO $db, array $tgUser): int
    {
        // Авто-миграция структуры таблицы users
        try {
            @$db->exec("ALTER TABLE `users` ADD COLUMN `telegram_id` VARCHAR(50) NULL");
            @$db->exec("ALTER TABLE `users` ADD COLUMN `username` VARCHAR(255) NULL");
            @$db->exec("ALTER TABLE `users` ADD COLUMN `first_name` VARCHAR(255) NULL");
        } catch (\Throwable $e) {}

        $tgUserId = (string) ($tgUser['id'] ?? '');
        $username = !empty($tgUser['username']) ? (string) $tgUser['username'] : null;
        $firstName = !empty($tgUser['first_name']) ? (string) $tgUser['first_name'] : null;

        // 1. Поиск по существующему telegram_id
        $stmt = $db->prepare('SELECT id FROM users WHERE telegram_id = ? LIMIT 1');
        $stmt->execute([$tgUserId]);
        $existingId = $stmt->fetchColumn();

        if ($existingId !== false) {
            $uid = (int) $existingId;
            $updateStmt = $db->prepare('
                UPDATE users 
                SET username = COALESCE(?, username), first_name = COALESCE(?, first_name) 
                WHERE id = ?
            ');
            $updateStmt->execute([$username, $firstName, $uid]);
            return $uid;
        }

        // 2. Если у главного администратора еще не привязан telegram_id (первый вход владельца)
        $firstUser = $db->query('SELECT id, telegram_id FROM users ORDER BY id ASC LIMIT 1')->fetch();
        if ($firstUser && empty($firstUser['telegram_id'])) {
            $uid = (int) $firstUser['id'];
            $bindStmt = $db->prepare('
                UPDATE users 
                SET telegram_id = ?, username = COALESCE(?, username), first_name = COALESCE(?, first_name)
                WHERE id = ?
            ');
            $bindStmt->execute([$tgUserId, $username, $firstName, $uid]);
            return $uid;
        }

        // 3. Для новых клиентов сервиса — создаем отдельный изолированный аккаунт
        $insertStmt = $db->prepare('
            INSERT INTO users (telegram_id, username, first_name, created_at)
            VALUES (?, ?, ?, ?)
        ');
        $insertStmt->execute([$tgUserId, $username, $firstName, date('Y-m-d H:i:s')]);
        return (int) $db->lastInsertId();
    }

    /**
     * Выполняет проверку авторизации оператора.
     */
    public static function authenticate(PDO $db): bool
    {
        return self::getAuthenticatedUserId($db) !== null;
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

        // Сортируем параметры по алфавиту (строковая сортировка)
        ksort($params, SORT_STRING);

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
