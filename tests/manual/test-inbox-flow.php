<?php

declare(strict_types=1);

/**
 * Диагностика: почему входящее сообщение не появляется в дашборде.
 * Путь: tests/manual/test-inbox-flow.php
 *
 * Использование (локально):
 *   php tests/manual/test-inbox-flow.php
 *
 * Использование (на сервере):
 *   php /www/wwwroot/chatgo.ru/tests/manual/test-inbox-flow.php
 *
 * Что проверяется (по этапам):
 *   Шаг 1. Конфигурация (.env / config.php)
 *   Шаг 2. Подключение к БД + структура таблиц
 *   Шаг 3. Каналы в БД — тип, статус, привязка к user_id
 *   Шаг 4. Чаты — последние 10 записей + статусы (pending/active/rejected)
 *   Шаг 5. Сообщения — последние 10 входящих
 *   Шаг 6. Симуляция вебхука: POST /api/webhook/telegram_personal и /api/webhook/telegram
 *   Шаг 7. Проверка появления нового чата/сообщения в БД после симуляции
 *   Шаг 8. Проверка API дашборда (GET /api/chats)
 */

require_once __DIR__ . '/../../config/db.php';

// ─────────────────────── helpers ───────────────────────

function ok(string $msg): void
{
    echo "   \033[32m[OK]\033[0m {$msg}\n";
}

function fail(string $msg): void
{
    echo "   \033[31m[FAIL]\033[0m {$msg}\n";
}

function info(string $msg): void
{
    echo "   \033[36m[INFO]\033[0m {$msg}\n";
}

function warn(string $msg): void
{
    echo "   \033[33m[WARN]\033[0m {$msg}\n";
}

function header_line(string $title): void
{
    echo "\n" . str_repeat('-', 60) . "\n";
    echo "  {$title}\n";
    echo str_repeat('-', 60) . "\n";
}

function curlPost(string $url, array $data, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => $body, 'error' => $error];
}

function curlGet(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => $body, 'error' => $error];
}

// ─────────────────────── config ────────────────────────

// URL сервера (менять при необходимости)
$BASE_URL = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://chatgo.ru';

// Фиктивный клиент для тестового сообщения
$TEST_PEER_ID   = '999888777001';          // уникальный peer_id, чтобы не конфликтовать с реальными
$TEST_CLIENT    = 'DiagnosticClient';
$TEST_TEXT      = 'ДИАГНОСТИКА inbox-flow ' . date('H:i:s');

// ============================================================
echo "\nChatgo - Диагностика пути входящего сообщения\n";
echo date('Y-m-d H:i:s') . "\n";

// ────────────────────────────────────────────────────────────
header_line('Шаг 1. Конфигурация');

$requiredConsts = [
    'DB_HOST', 'DB_NAME', 'DB_USER',
    'CHATGO_SECRET',
    'TELEGRAM_BOT_TOKEN',
];
$configOk = true;
foreach ($requiredConsts as $c) {
    if (defined($c)) {
        $val = ($c === 'CHATGO_SECRET' || $c === 'DB_PASS' || $c === 'TELEGRAM_BOT_TOKEN')
            ? substr(constant($c), 0, 6) . '***'
            : constant($c);
        ok("{$c} = {$val}");
    } else {
        fail("{$c} — НЕ ОПРЕДЕЛЕНА");
        $configOk = false;
    }
}

if (defined('APP_URL')) {
    info("APP_URL = " . APP_URL);
} else {
    warn("APP_URL не определена, используем заглушку: {$BASE_URL}");
}

if (defined('TELEGRAM_PERSONAL_SERVICE_URL')) {
    info("TELEGRAM_PERSONAL_SERVICE_URL = " . TELEGRAM_PERSONAL_SERVICE_URL);
} else {
    warn("TELEGRAM_PERSONAL_SERVICE_URL не определена (личный MTProto не настроен)");
}

// ────────────────────────────────────────────────────────────
header_line('Шаг 2. Подключение к БД и структура таблиц');

try {
    $db = DB::getConnection();
    ok("Подключение к MySQL успешно (" . DB_HOST . "/" . DB_NAME . ")");

    $tables = ['users', 'channels', 'chats', 'messages', 'auth_tokens'];
    foreach ($tables as $t) {
        $cnt = (int) $db->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        ok("Таблица `{$t}` существует ({$cnt} записей)");
        $cols = $db->query("SHOW COLUMNS FROM `{$t}`")->fetchAll();
        $colNames = array_column($cols, 'Field');
        info("  Колонки `{$t}`: " . implode(', ', $colNames));
    }
} catch (Throwable $e) {
    fail("БД недоступна: " . $e->getMessage());
    echo "\nПроверка прервана - без БД дальнейшая диагностика невозможна.\n";
    exit(1);
}

// ────────────────────────────────────────────────────────────
header_line('Шаг 3. Каналы в БД');

$channels = $db->query(
    "SELECT id, user_id, type, name, status, settings FROM channels ORDER BY id DESC LIMIT 10"
)->fetchAll();

if (empty($channels)) {
    fail("Ни одного канала не найдено в таблице channels!");
    warn("-> Пользователь должен добавить хотя бы один канал через дашборд.");
} else {
    foreach ($channels as $ch) {
        $settings = @json_decode($ch['settings'] ?? '{}', true) ?? [];
        $acctType = $settings['account_type'] ?? '-';
        echo "   ID={$ch['id']} | type={$ch['type']} | name={$ch['name']}"
            . " | user_id={$ch['user_id']} | status={$ch['status']} | account_type={$acctType}\n";

        if ($ch['status'] !== 'connected') {
            warn("Канал ID={$ch['id']} имеет статус '{$ch['status']}' - не 'connected'.");
            warn("-> Webhook-хендлер может не найти канал.");
        }
        if (empty($ch['user_id'])) {
            fail("Канал ID={$ch['id']} не привязан к пользователю (user_id пустой)!");
        }
    }
}

// Проверяем: есть ли у пользователя привязанный telegram_id для уведомлений
$users = $db->query("SELECT * FROM users LIMIT 10")->fetchAll();
echo "\n   Пользователи системы:\n";
foreach ($users as $u) {
    $name = $u['first_name'] ?? $u['name'] ?? $u['username'] ?? 'User';
    $tgId = !empty($u['telegram_id']) ? $u['telegram_id'] : '[НЕ ПРИВЯЗАН]';
    echo "   User ID={$u['id']} | name={$name} | telegram_id={$tgId}\n";
    if (empty($u['telegram_id'])) {
        warn("User ID={$u['id']}: telegram_id не задан -> уведомления оператору не придут.");
    }
}

// ────────────────────────────────────────────────────────────
header_line('Шаг 4. Чаты в БД (последние 10)');

$chats = $db->query(
    "SELECT c.id, c.channel_id, c.client_name, c.client_external_id,
            c.status, c.unread_count, c.created_at,
            ch.type AS channel_type
     FROM chats c
     LEFT JOIN channels ch ON ch.id = c.channel_id
     ORDER BY c.created_at DESC
     LIMIT 10"
)->fetchAll();

if (empty($chats)) {
    warn("Ни одного чата в таблице chats. Значит ни одно сообщение ещё не обработалось.");
} else {
    foreach ($chats as $c) {
        echo "   Chat ID={$c['id']} | channel={$c['channel_type']}(id={$c['channel_id']})"
            . " | client={$c['client_name']}({$c['client_external_id']})"
            . " | status={$c['status']}"
            . " | unread={$c['unread_count']}"
            . " | created={$c['created_at']}\n";

        if ($c['status'] === 'pending') {
            warn("Чат ID={$c['id']} в статусе pending - в дашборде он может быть скрыт,");
            warn("  если фильтр показывает только status=active.");
        }
    }
}

// ────────────────────────────────────────────────────────────
header_line('Шаг 5. Входящие сообщения (последние 10)');

$messages = $db->query(
    "SELECT m.id, m.chat_id, m.direction, m.text, m.type, m.created_at,
            c.client_name, ch.type AS channel_type
     FROM messages m
     LEFT JOIN chats c ON c.id = m.chat_id
     LEFT JOIN channels ch ON ch.id = c.channel_id
     WHERE m.direction = 'incoming'
     ORDER BY m.created_at DESC
     LIMIT 10"
)->fetchAll();

if (empty($messages)) {
    fail("Входящих сообщений в таблице messages нет!");
    warn("-> Либо webhook не вызывается, либо падает до записи в БД.");
} else {
    foreach ($messages as $m) {
        $text = mb_substr($m['text'] ?? '', 0, 50);
        echo "   Msg ID={$m['id']} | chat_id={$m['chat_id']} | channel={$m['channel_type']}"
            . " | client={$m['client_name']} | text=\"{$text}\" | {$m['created_at']}\n";
    }
}

// ────────────────────────────────────────────────────────────
header_line('Шаг 6. Симуляция вебхука');

$secret = defined('CHATGO_SECRET') ? CHATGO_SECRET : '';

// Payload для telegram_personal
$personalPayload = [
    'event'           => 'message',
    'direction'       => 'incoming',
    'message_id'      => '88' . rand(10000, 99999),
    'peer_id'         => $TEST_PEER_ID,
    'client_name'     => $TEST_CLIENT,
    'client_username' => 'diagnostic_user',
    'client_phone'    => '+79990000001',
    'text'            => $TEST_TEXT,
];

// Payload для обычного Telegram Bot
$botPayload = [
    'update_id' => rand(100000000, 999999999),
    'message'   => [
        'message_id' => rand(1000, 9999),
        'from'       => [
            'id'         => (int) $TEST_PEER_ID,
            'is_bot'     => false,
            'first_name' => $TEST_CLIENT,
            'username'   => 'diagnostic_user',
        ],
        'chat' => [
            'id'         => (int) $TEST_PEER_ID,
            'first_name' => $TEST_CLIENT,
            'type'       => 'private',
        ],
        'date' => time(),
        'text' => $TEST_TEXT,
    ],
];

$webhookUrls = [
    'telegram_personal' => [
        'url'     => $BASE_URL . '/api/webhook/telegram_personal',
        'payload' => $personalPayload,
        'headers' => ["X-Chatgo-Secret: {$secret}"],
    ],
    'telegram_bot' => [
        'url'     => $BASE_URL . '/api/webhook/telegram',
        'payload' => $botPayload,
        'headers' => [],
    ],
];

$webhookHit = false;

foreach ($webhookUrls as $label => $cfg) {
    echo "\n   -> Тестируем {$label}: {$cfg['url']}\n";
    $resp = curlPost($cfg['url'], $cfg['payload'], $cfg['headers']);

    if ($resp['error']) {
        warn("cURL ошибка: {$resp['error']}");
        continue;
    }

    $decoded = @json_decode((string) $resp['body'], true);
    echo "   HTTP {$resp['code']} | Body: " . ($resp['body'] ?: '(пусто)') . "\n";

    if ($resp['code'] === 200 && !empty($decoded['ok'])) {
        ok("{$label}: webhook принял сообщение!");
        info("chat_id={$decoded['chat_id']}, message_id={$decoded['message_id']}");
        $webhookHit = true;
        break;
    } elseif ($resp['code'] === 403) {
        fail("{$label}: 403 Forbidden - неверный CHATGO_SECRET или не передан заголовок.");
    } elseif ($resp['code'] === 404) {
        warn("{$label}: 404 - файл не найден или роутинг не настроен.");
    } elseif ($resp['code'] === 0) {
        warn("{$label}: нет ответа - сервер недоступен или неверный BASE_URL ({$BASE_URL}).");
    } else {
        fail("{$label}: неожиданный ответ HTTP {$resp['code']}.");
        if (!empty($decoded['error'])) {
            fail("Ошибка: {$decoded['error']}");
        }
    }
}

// ────────────────────────────────────────────────────────────
header_line('Шаг 7. Проверка результата в БД после симуляции');

if ($webhookHit) {
    usleep(300000); // 300ms

    $newChat = $db->prepare(
        "SELECT c.id, c.status, c.unread_count,
                m.text, m.direction, m.created_at AS msg_at
         FROM chats c
         LEFT JOIN messages m ON m.chat_id = c.id
         WHERE c.client_external_id = ?
         ORDER BY m.created_at DESC
         LIMIT 1"
    );
    $newChat->execute([$TEST_PEER_ID]);
    $row = $newChat->fetch();

    if ($row) {
        ok("Чат создан в БД (ID={$row['id']}, status={$row['status']}, unread={$row['unread_count']})");
        ok("Сообщение записано: \"{$row['text']}\" ({$row['direction']}) в {$row['msg_at']}");

        if ($row['status'] === 'pending') {
            warn("Чат создан со статусом 'pending'.");
            warn("-> Проверьте: дашборд фильтрует чаты и может не показывать 'pending'.");
            warn("-> Решение: убедитесь, что API /api/chats возвращает чаты со статусом pending,");
            warn("   или что интерфейс не применяет фильтр status=active.");
        }
    } else {
        fail("Чат/сообщение с peer_id={$TEST_PEER_ID} не найдено в БД после webhook!");
        fail("-> Webhook вернул ok=true, но в БД данных нет. Проверьте исключения внутри handler.");
    }
} else {
    warn("Webhook не был успешно вызван - проверка БД пропущена.");
    warn("-> Исправьте проблему на Шаге 6 и запустите скрипт снова.");
}

// ────────────────────────────────────────────────────────────
header_line('Шаг 8. Проверка API дашборда: GET /api/chats');

$resp = curlGet($BASE_URL . '/api/chats');
echo "   HTTP {$resp['code']} | ";

if ($resp['code'] === 200) {
    $data = @json_decode((string) $resp['body'], true);
    if (is_array($data)) {
        $total = count($data);
        ok("API вернул {$total} чат(ов)");
        if ($total === 0) {
            warn("-> Список пуст. Либо нет чатов, либо API применяет жёсткий фильтр по статусу.");
        } else {
            // Ищем наш тестовый чат
            $found = array_filter($data, fn($c) => ($c['client_external_id'] ?? '') === $TEST_PEER_ID);
            if (!empty($found)) {
                ok("Тестовый чат ({$TEST_PEER_ID}) ВИДЕН в ответе API.");
            } else {
                fail("Тестовый чат ({$TEST_PEER_ID}) НЕ виден в ответе API (хотя в БД есть)!");
                warn("-> API применяет фильтр. Проверьте /api/chats - какой WHERE он использует.");
            }
        }
    } else {
        warn("Ответ не является JSON-массивом: " . mb_substr((string) $resp['body'], 0, 200));
    }
} elseif ($resp['code'] === 401 || $resp['code'] === 403) {
    warn("API вернул {$resp['code']} - требуется авторизация (cookie/session).");
    info("-> Нормально при запуске из CLI. Проверьте вручную в браузере: {$BASE_URL}/api/chats");
} elseif ($resp['code'] === 0) {
    warn("Сервер недоступен ({$resp['error']}). Проверьте BASE_URL: {$BASE_URL}");
} else {
    fail("API вернул HTTP {$resp['code']}");
    echo "   Body: " . mb_substr((string) $resp['body'], 0, 300) . "\n";
}

// ─────────────────────── итог ──────────────────────────

echo "\n" . str_repeat('=', 60) . "\n";
echo "  ДИАГНОСТИКА ЗАВЕРШЕНА\n";
echo str_repeat('=', 60) . "\n";
echo "\nЧек-лист возможных причин проблемы:\n";
echo "  [ ] Канал отсутствует в channels или статус != connected\n";
echo "  [ ] Чат создаётся со статусом 'pending' - скрыт фильтром\n";
echo "  [ ] API /api/chats не возвращает 'pending'-чаты\n";
echo "  [ ] Webhook-файл не вызывается (неверный роутинг)\n";
echo "  [ ] Webhook падает на 403 (неверный CHATGO_SECRET)\n";
echo "  [ ] Исключение внутри handler не даёт записать в БД\n";
echo "  [ ] telegram_id оператора не привязан -> нет уведомлений\n";
echo "  [ ] service-telegram (Node.js) не запущен или не пингует webhook\n";
echo "\n";
