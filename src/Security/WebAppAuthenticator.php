<?php

namespace Chatgo\Security;

use PDO;

class WebAppAuthenticator
{
    /**
     * Выполняет проверку подписи initData и авторизацию оператора.
     */
    public static function authenticate(PDO $db): bool
    {
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

        // 5. Проверяем, совпадает ли ID пользователя с настроенным OPERATOR_TELEGRAM_ID
        $tgUserId = (string) ($userData['id'] ?? '');
        $allowedOperatorId = defined('OPERATOR_TELEGRAM_ID') ? (string) OPERATOR_TELEGRAM_ID : '';

        return $tgUserId !== '' && $tgUserId === $allowedOperatorId;
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
