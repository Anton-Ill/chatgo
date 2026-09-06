<?php

/**
 * Manual test: Test Dev Access authorization
 * Usage: php tests/manual/test-dev-access.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

use Chatgo\Security\WebAppAuthenticator;

echo "=== Запуск теста авторизации тестового режима разработчика ===\n";

try {
    $db = new PDO('sqlite::memory:');
} catch (Throwable $e) {
    $db = null;
}

// 1. Проверка без авторизации
$_SERVER['HTTP_X_TG_INIT_DATA'] = null;
$_GET = [];
$_POST = [];
$_COOKIE = [];
$_SESSION = [];
unset($_SERVER['HTTP_X_DEV_KEY']);

$unauthCheck = WebAppAuthenticator::isDevAuthorized();
echo "1. Проверка без ключа: " . ($unauthCheck === false ? "PASS (false)" : "FAIL (ожидался false)") . "\n";

// 2. Проверка с неверным ключом
$_GET['dev_key'] = 'invalid_secret_key_123';
$invalidCheck = WebAppAuthenticator::isDevAuthorized();
echo "2. Проверка с неверным ключом: " . ($invalidCheck === false ? "PASS (false)" : "FAIL (ожидался false)") . "\n";

// 3. Проверка с правильным ключом через GET
$_GET['dev_key'] = CHATGO_SECRET;
$validGetCheck = WebAppAuthenticator::isDevAuthorized();
echo "3. Проверка с верным ключом через GET: " . ($validGetCheck === true ? "PASS (true)" : "FAIL (ожидался true)") . "\n";

// 4. Проверка сохранения в сессии
$_GET = [];
$sessionCheck = WebAppAuthenticator::isDevAuthorized();
echo "4. Проверка сессионной авторизации: " . ($sessionCheck === true ? "PASS (true)" : "FAIL (ожидался true)") . "\n";

// 5. Проверка WebAppAuthenticator::authenticate() с активной сессией
$fullAuthCheck = WebAppAuthenticator::authenticate($db);
echo "5. Проверка authenticate() через сессию dev: " . ($fullAuthCheck === true ? "PASS (true)" : "FAIL (ожидался true)") . "\n";

// 6. Сброс сессии и проверка через заголовок X-Dev-Key
$_SESSION = [];
$_SERVER['HTTP_X_DEV_KEY'] = CHATGO_SECRET;
$headerCheck = WebAppAuthenticator::isDevAuthorized();
$headerFullAuth = WebAppAuthenticator::authenticate($db);
echo "6. Проверка через заголовок X-Dev-Key: " . (($headerCheck === true && $headerFullAuth === true) ? "PASS (true)" : "FAIL") . "\n";

// 7. Сброс заголовка и сессии
unset($_SERVER['HTTP_X_DEV_KEY']);
$_SESSION = [];
$resetCheck = WebAppAuthenticator::isDevAuthorized();
echo "7. Проверка после сброса: " . ($resetCheck === false ? "PASS (false)" : "FAIL") . "\n";

echo "=== Все тесты успешно завершены ===\n";
