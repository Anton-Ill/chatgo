<?php

declare(strict_types=1);

/**
 * Diagnostic unit test for WebApp SSO and SaaS Multi-tenant Isolation
 * Path: tests/manual/test-webapp-sso.php
 */

if (!getenv('TELEGRAM_BOT_TOKEN')) {
    putenv('TELEGRAM_BOT_TOKEN=123456789:MOCK_TEST_BOT_TOKEN_FOR_SSO');
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/Security/WebAppAuthenticator.php';

use Chatgo\Security\WebAppAuthenticator;

echo "=== ТЕСТИРОВАНИЕ WEBAPP SSO И МУЛЬТИТЕНАНТНОСТИ ===\n";

$passed = 0;
$failed = 0;

function assertCondition(bool $cond, string $msg, int &$p, int &$f): void
{
    if ($cond) {
        echo "  [PASS] {$msg}\n";
        $p++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $f++;
    }
}

// 1. Создаем тестовую базу данных в SQLite памяти
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Создаем таблицы users и auth_tokens
$db->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT NULL,
        username TEXT NULL,
        first_name TEXT NULL,
        email TEXT NULL,
        created_at DATETIME
    );
    CREATE TABLE auth_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        token TEXT,
        expires_at DATETIME,
        used INTEGER DEFAULT 0
    );
");

// Симулируем существующий начальный аккаунт администратора без telegram_id
$db->exec("INSERT INTO users (email, created_at) VALUES ('admin@chatgo.ru', datetime('now'))");
$adminId = (int) $db->lastInsertId();
assertCondition($adminId === 1, "Создан начальный аккаунт администратора с ID: {$adminId}", $passed, $failed);

$botToken = (string) (defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN !== '' ? TELEGRAM_BOT_TOKEN : '123456789:MOCK_TEST_BOT_TOKEN_FOR_SSO');
$antonTgId = '11223344';
$antonUserJson = json_encode([
    'id' => (int) $antonTgId,
    'first_name' => 'Anton',
    'username' => 'anton_ill'
]);

$params = [
    'auth_date' => (string) time(),
    'query_id' => 'AAHd754OAAAAAN3vng43bH8d',
    'user' => $antonUserJson
];
ksort($params, SORT_STRING);

$checkArr = [];
foreach ($params as $k => $v) {
    $checkArr[] = "$k=$v";
}
$checkString = implode("\n", $checkArr);
$secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
$hash = hash_hmac('sha256', $checkString, $secretKey);

$queryArr = [];
foreach ($params as $k => $v) {
    $queryArr[] = "$k=" . rawurlencode($v);
}
$validInitData = implode('&', $queryArr) . '&hash=' . $hash;

// Проверка валидной подписи
$verifiedUser = WebAppAuthenticator::verify($validInitData, $botToken);
assertCondition(
    $verifiedUser !== null && (string)$verifiedUser['id'] === $antonTgId,
    "WebAppAuthenticator::verify успешно валидировал подпись системного бота",
    $passed,
    $failed
);

// Проверка отклонения поддельной подписи
$tamperedInitData = str_replace('query_id=AAHd754O', 'query_id=HACKED', $validInitData);
$rejectedUser = WebAppAuthenticator::verify($tamperedInitData, $botToken);
assertCondition($rejectedUser === null, "WebAppAuthenticator::verify успешно отклонил поддельную подпись", $passed, $failed);

// 3. Тестируем связывание администратора (первый вход владельца)
$resolvedAdminId = WebAppAuthenticator::findOrCreateUserByTelegram($db, $verifiedUser);
assertCondition($resolvedAdminId === 1, "Telegram владельца успешно привязался к существующему аккаунту ID 1", $passed, $failed);

$adminRecord = $db->query("SELECT * FROM users WHERE id = 1")->fetch();
assertCondition($adminRecord['telegram_id'] === $antonTgId, "Запись ID 1 теперь содержит telegram_id владельца", $passed, $failed);
assertCondition($adminRecord['username'] === 'anton_ill', "Запись ID 1 содержит username anton_ill", $passed, $failed);

// Повторный вход Антона -> возвращает тот же ID 1
$repeatAdminId = WebAppAuthenticator::findOrCreateUserByTelegram($db, $verifiedUser);
assertCondition($repeatAdminId === 1, "Повторный вход владельца возвращает тот же ID 1 без дубликатов", $passed, $failed);

// 4. Тестируем регистрацию нового клиента сервиса (клиент Tenant B)
$clientBTgId = '99887766';
$clientBUser = [
    'id' => (int) $clientBTgId,
    'first_name' => 'Client B',
    'username' => 'client_b_shop'
];

$clientBId = WebAppAuthenticator::findOrCreateUserByTelegram($db, $clientBUser);
assertCondition($clientBId === 2, "Новый клиент сервиса получил изолированный аккаунт с ID 2", $passed, $failed);

// Повторный вход клиента Tenant B -> возвращает ID 2
$repeatClientBId = WebAppAuthenticator::findOrCreateUserByTelegram($db, $clientBUser);
assertCondition($repeatClientBId === 2, "Повторный вход клиента B возвращает ID 2 без дубликатов", $passed, $failed);

$totalUsersCount = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
assertCondition($totalUsersCount === 2, "Всего в базе ровно 2 изолированных пользователя (администратор и клиент)", $passed, $failed);

// 5. Тестируем получение сессии через HTTP_X_TG_INIT_DATA
$_SERVER['HTTP_X_TG_INIT_DATA'] = $validInitData;
$authUserId = WebAppAuthenticator::getAuthenticatedUserId($db);
assertCondition($authUserId === 1, "getAuthenticatedUserId по заголовку X-TG-Init-Data вернул пользователя ID 1", $passed, $failed);
unset($_SERVER['HTTP_X_TG_INIT_DATA']);

echo "\nИТОГО ТЕСТОВ: Пройдено: {$passed}, Ошибок: {$failed}\n";
if ($failed > 0) {
    exit(1);
}
echo "[SUCCESS] Все тесты WebApp SSO и мультитенантности успешно пройдены!\n";
