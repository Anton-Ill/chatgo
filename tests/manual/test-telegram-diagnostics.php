<?php

/**
 * Chatgo: Step-by-step Telegram diagnostic script
 * tests/manual/test-telegram-diagnostics.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/Adapters/TelegramAdapter.php';
require_once __DIR__ . '/../../src/Services/ChatService.php';

use Chatgo\Adapters\TelegramAdapter;

echo "=======================================================\n";
echo "       ПОШАГОВАЯ ДИАГНОСТИКА СВЯЗИ TELEGRAM -> CHATGO   \n";
echo "=======================================================\n\n";

try {
    // --- ШАГ 1: Проверка подключения к БД и конфигурации канала ---
    echo "[ШАГ 1] Проверка базы данных и конфигурации каналов...\n";
    $db = DB::getConnection();
    echo "  [OK] Соединение с MySQL (MariaDB) успешно установлено.\n";

    $stmt = $db->query("SELECT id, name, type, status, settings FROM channels WHERE type = 'telegram' LIMIT 1");
    $channel = $stmt->fetch();

    if (!$channel) {
        throw new RuntimeException("Канал с типом 'telegram' не найден в таблице channels.");
    }

    $settings = json_decode($channel['settings'] ?? '{}', true);
    $botToken = $settings['token'] ?? null;

    if (!$botToken) {
        throw new RuntimeException("Токен бота отсутствует в настройках канала ID {$channel['id']}.");
    }

    $maskedToken = substr($botToken, 0, 8) . '...' . substr($botToken, -5);
    echo "  [OK] Найден канал ID: {$channel['id']} ('{$channel['name']}'), статус: {$channel['status']}\n";
    echo "  [OK] Токен бота: {$maskedToken}\n";

    // Проверка оператора в БД
    $opStmt = $db->query("SELECT id, email, telegram_id, role FROM users WHERE telegram_id IS NOT NULL");
    $operators = $opStmt->fetchAll();
    echo "  [INFO] Привязанные операторы:\n";
    foreach ($operators as $op) {
        echo "    - User ID {$op['id']} ({$op['email']}): Telegram ID {$op['telegram_id']} (роль: {$op['role']})\n";
    }

    // --- ШАГ 2: Проверка связи с Telegram API через прокси-шлюз ---
    echo "\n[ШАГ 2] Проверка связи с Telegram API через шлюз (" . TELEGRAM_API_URL . ")...\n";
    $adapter = new TelegramAdapter($botToken, TELEGRAM_API_URL, CHATGO_SECRET);

    $ch = curl_init();
    $getMeUrl = rtrim(TELEGRAM_API_URL, '/') . '/bot' . $botToken . '/getMe';
    curl_setopt($ch, CURLOPT_URL, $getMeUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Chatgo-Secret: ' . CHATGO_SECRET
    ]);
    $getMeRaw = curl_exec($ch);
    $getMeHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($getMeRaw === false || $getMeHttpCode !== 200) {
        echo "  [ОШИБКА] Не удалось связаться с Telegram API: HTTP {$getMeHttpCode}, ошибка cURL: {$curlErr}\n";
    } else {
        $getMeData = json_decode((string) $getMeRaw, true);
        if ($getMeData['ok'] ?? false) {
            $botUser = $getMeData['result'];
            echo "  [OK] Бот активен: @{$botUser['username']} (ID: {$botUser['id']}, имя: '{$botUser['first_name']}')\n";
        } else {
            echo "  [ОШИБКА] Telegram API вернул ошибку: " . ($getMeData['description'] ?? 'неизвестно') . "\n";
        }
    }

    // --- ШАГ 3: Проверка статуса вебхука (должен быть отключен) ---
    echo "\n[ШАГ 3] Проверка статуса Webhook в Telegram...\n";
    $ch = curl_init();
    $webhookUrl = rtrim(TELEGRAM_API_URL, '/') . '/bot' . $botToken . '/getWebhookInfo';
    curl_setopt($ch, CURLOPT_URL, $webhookUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Chatgo-Secret: ' . CHATGO_SECRET
    ]);
    $whRaw = curl_exec($ch);
    curl_close($ch);

    $whData = json_decode((string) $whRaw, true);
    if ($whData['ok'] ?? false) {
        $wh = $whData['result'];
        echo "  [OK] Текущий Webhook URL: '" . ($wh['url'] ?? '') . "'\n";
        echo "  [INFO] Ожидающих обновлений в очереди (pending_update_count): " . ($wh['pending_update_count'] ?? 0) . "\n";
        if (!empty($wh['last_error_message'])) {
            echo "  [ВНИМАНИЕ] Последняя ошибка вебхука: {$wh['last_error_message']} (дата: " . date('Y-m-d H:i:s', $wh['last_error_date'] ?? 0) . ")\n";
        }
    } else {
        echo "  [ОШИБКА] Не удалось получить getWebhookInfo\n";
    }

    // --- ШАГ 4: Запрос актуальных обновлений из Telegram (getUpdates) ---
    echo "\n[ШАГ 4] Запрос очереди обновлений (getUpdates) из Telegram...\n";
    $ch = curl_init();
    $updatesUrl = rtrim(TELEGRAM_API_URL, '/') . '/bot' . $botToken . '/getUpdates?limit=5&timeout=2';
    curl_setopt($ch, CURLOPT_URL, $updatesUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Chatgo-Secret: ' . CHATGO_SECRET
    ]);
    $updRaw = curl_exec($ch);
    $updHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "  [HTTP {$updHttpCode}] Ответ от getUpdates:\n";
    $updData = json_decode((string) $updRaw, true);
    if ($updData['ok'] ?? false) {
        $results = $updData['result'] ?? [];
        if (empty($results)) {
            echo "  [INFO] В очереди Telegram сейчас нет новых необработанных сообщений (очередь пуста).\n";
            echo "  (Это означает, что либо воркер уже забрал сообщение, либо сообщение было отправлено не этому боту).\n";
        } else {
            echo "  [НАЙДЕНЫ ОБНОВЛЕНИЯ] Количество: " . count($results) . "\n";
            foreach ($results as $item) {
                $uId = $item['update_id'] ?? 0;
                $msg = $item['message'] ?? [];
                $from = $msg['from'] ?? [];
                $txt = $msg['text'] ?? ($item['callback_query']['data'] ?? '[не текст]');
                $fromName = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
                $fromId = $from['id'] ?? 'N/A';
                $fromUser = $from['username'] ?? '';
                echo "    -> Update #{$uId}: От {$fromName} (@{$fromUser}, ID: {$fromId}): \"{$txt}\"\n";
            }
        }
    } else {
        echo "  [ОШИБКА getUpdates]: " . ($updData['description'] ?? $updRaw) . "\n";
    }

    // --- ШАГ 5: Проверка базы данных chats и messages ---
    echo "\n[ШАГ 5] Последние записи в базе данных Chatgo...\n";
    echo "  --- Таблица chats (последние 3): ---\n";
    $chats = $db->query("SELECT id, channel_id, client_external_id, client_name, status, unread_count, last_message_at FROM chats ORDER BY id DESC LIMIT 3")->fetchAll();
    if (empty($chats)) {
        echo "    (Таблица chats пуста)\n";
    } else {
        foreach ($chats as $c) {
            echo "    ID: {$c['id']} | Клиент: {$c['client_name']} (ExtID: {$c['client_external_id']}) | Статус: {$c['status']} | Непрочитано: {$c['unread_count']} | Время: {$c['last_message_at']}\n";
        }
    }

    echo "  --- Таблица messages (последние 3): ---\n";
    $msgs = $db->query("SELECT id, chat_id, direction, text, created_at FROM messages ORDER BY id DESC LIMIT 3")->fetchAll();
    if (empty($msgs)) {
        echo "    (Таблица messages пуста)\n";
    } else {
        foreach ($msgs as $m) {
            echo "    ID: {$m['id']} | Чат ID: {$m['chat_id']} | Направление: {$m['direction']} | Текст: \"{$m['text']}\" | Дата: {$m['created_at']}\n";
        }
    }

    // --- ШАГ 6: Статус фонового демона systemd ---
    echo "\n[ШАГ 6] Статус службы chatgo-telegram...\n";
    $serviceStatus = shell_exec("systemctl is-active chatgo-telegram 2>&1");
    echo "  Состояние службы: " . trim((string) $serviceStatus) . "\n";

    $logFile = __DIR__ . '/../../storage/logs/telegram-worker.log';
    if (file_exists($logFile)) {
        echo "  Последние строки лога воркера (" . basename($logFile) . "):\n";
        $logLines = array_slice(file($logFile), -8);
        foreach ($logLines as $ll) {
            echo "    " . trim($ll) . "\n";
        }
    }

    echo "\n=======================================================\n";
    echo "                 ДИАГНОСТИКА ЗАВЕРШЕНА                 \n";
    echo "=======================================================\n";

} catch (Throwable $e) {
    echo "\n[КРИТИЧЕСКАЯ ОШИБКА ДИАГНОСТИКИ]: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
