<?php

/**
 * Diagnostic test script for WebAppAuthenticator signature verification
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

use Chatgo\Security\WebAppAuthenticator;

echo "[INFO] Starting WebApp signature validation tests...\n";

try {
    $db = DB::getConnection();

    // 1. Получаем токен Telegram бота из базы данных
    $stmt = $db->query("SELECT settings FROM channels WHERE type = 'telegram' LIMIT 1");
    $channel = $stmt->fetch();
    if (!$channel) {
        throw new Exception("Канал Telegram не найден. Запустите тесты предыдущих этапов.");
    }
    $settings = json_decode($channel['settings'] ?? '{}', true);
    $botToken = $settings['token'] ?? '';
    if (empty($botToken)) {
        throw new Exception("Токен бота Telegram не настроен.");
    }

    echo "[INFO] Found Telegram Bot Token in DB: " . substr($botToken, 0, 8) . "...\n";

    // 2. Генерируем корректный payload initData Telegram
    $tgUserId = 555555; // Наш тестовый оператор
    $userJson = json_encode([
        'id' => $tgUserId,
        'first_name' => 'Alice',
        'last_name' => 'Smith',
        'username' => 'alice_smith'
    ]);

    $authDate = time();
    $params = [
        'auth_date' => (string) $authDate,
        'query_id'  => 'AAHd754OAAAAAN3vng43bH8d',
        'user'      => $userJson
    ];

    // Сортируем параметры по алфавиту
    ksort($params);

    $dataCheckArr = [];
    foreach ($params as $key => $value) {
        $dataCheckArr[] = "$key=$value";
    }
    $dataCheckString = implode("\n", $dataCheckArr);

    // Вычисляем валидную подпись Telegram
    $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

    // Строим настоящую query string, разделенную &
    $queryStringArr = [];
    foreach ($params as $key => $value) {
        $queryStringArr[] = "$key=" . rawurlencode($value);
    }
    $validInitData = implode('&', $queryStringArr) . '&hash=' . $calculatedHash;

    // 3. Тест 1: Проверка валидной подписи
    echo "[INFO] Testing WebAppAuthenticator::verify with VALID signature...\n";
    $result = WebAppAuthenticator::verify($validInitData, $botToken);

    if ($result === null) {
        throw new Exception("Авторизация не прошла для валидной подписи!");
    }

    if ($result['id'] !== $tgUserId) {
        throw new Exception("Возвращен неверный ID пользователя: " . ($result['id'] ?? 'null'));
    }
    echo "[PASS] WebAppAuthenticator::verify successfully validated authentic signature!\n";

    // 4. Тест 2: Проверка измененной/поддельной подписи
    echo "[INFO] Testing WebAppAuthenticator::verify with TAMPERED signature...\n";
    $invalidInitData = str_replace('query_id=AAHd754O', 'query_id=HACKED', $validInitData);
    $result = WebAppAuthenticator::verify($invalidInitData, $botToken);

    if ($result !== null) {
        throw new Exception("Система безопасности приняла поддельную подпись!");
    }
    echo "[PASS] WebAppAuthenticator::verify successfully rejected tampered signature!\n";

    // 5. Тест 3: Проверка аутентификации по заголовкам (authenticate)
    echo "[INFO] Testing WebAppAuthenticator::authenticate via server headers...\n";
    
    // Эмулируем заголовок запроса
    $_SERVER['HTTP_X_TG_INIT_DATA'] = $validInitData;

    // Проверяем, совпадает ли OPERATOR_TELEGRAM_ID
    // В .env настроен OPERATOR_TELEGRAM_ID=555555, который совпадает с $tgUserId
    $authenticated = WebAppAuthenticator::authenticate($db);

    if (!$authenticated) {
        throw new Exception("Аутентификация оператора с валидным ID отклонена!");
    }
    echo "[PASS] Authenticated operator successfully matched OPERATOR_TELEGRAM_ID!\n";

    // 6. Тест 4: Отклонение неавторизованного ID
    echo "[INFO] Testing WebAppAuthenticator::authenticate with UNAUTHORIZED ID...\n";
    
    // Генерируем подпись для другого пользователя Telegram (например, 999999)
    $unauthParams = $params;
    $unauthParams['user'] = json_encode(['id' => 999999, 'first_name' => 'Bob']);
    ksort($unauthParams);
    
    $unauthCheckArr = [];
    foreach ($unauthParams as $key => $value) {
        $unauthCheckArr[] = "$key=$value";
    }
    $unauthCheckString = implode("\n", $unauthCheckArr);
    $unauthHash = hash_hmac('sha256', $unauthCheckString, $secretKey);
    
    $unauthQueryArr = [];
    foreach ($unauthParams as $key => $value) {
        $unauthQueryArr[] = "$key=" . rawurlencode($value);
    }
    $unauthInitData = implode('&', $unauthQueryArr) . '&hash=' . $unauthHash;

    $_SERVER['HTTP_X_TG_INIT_DATA'] = $unauthInitData;
    $authenticated = WebAppAuthenticator::authenticate($db);

    if ($authenticated) {
        throw new Exception("Система авторизовала стороннего пользователя!");
    }
    echo "[PASS] Successfully rejected unauthorized operator Telegram ID!\n";

    // Очищаем эмулированный заголовок
    unset($_SERVER['HTTP_X_TG_INIT_DATA']);

    echo "\n[SUCCESS] WebApp cryptographical signature verification tests completed successfully!\n";

} catch (Throwable $e) {
    echo "\n[FAIL] Test failed with error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
