<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = DB::getConnection();
    echo "1. DB Connected\n";

    echo "2. Checking botUsername...\n";
    $botUsername = defined('TELEGRAM_BOT_USERNAME') && TELEGRAM_BOT_USERNAME !== ''
        ? (string) TELEGRAM_BOT_USERNAME
        : '';
    if ($botUsername === '') {
        $stmt = $db->query("SELECT name FROM channels WHERE type = 'telegram' LIMIT 1");
        $chName = (string) ($stmt->fetchColumn() ?: '');
        $botUsername = ltrim($chName, '@');
    }
    echo "Bot username: '{$botUsername}'\n";

    echo "3. Querying users table...\n";
    $firstUserId = (int) ($db->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
    echo "firstUserId: {$firstUserId}\n";

    if (!$firstUserId) {
        echo "3b. Creating admin user...\n";
        $db->exec("INSERT INTO users (email, created_at) VALUES ('admin@chatgo.ru', NOW())");
        $firstUserId = (int) $db->lastInsertId();
        echo "Created admin user ID: {$firstUserId}\n";
    }

    echo "4. Inserting into auth_tokens...\n";
    $token = bin2hex(random_bytes(16));
    $expiresAt = date('Y-m-d H:i:s', time() + 600);

    $stmtToken = $db->prepare('
        INSERT INTO auth_tokens (user_id, token, expires_at, used)
        VALUES (?, ?, ?, 0)
    ');
    $stmtToken->execute([$firstUserId, $token, $expiresAt]);
    echo "Success! Auth token inserted with ID: " . $db->lastInsertId() . "\n";

} catch (Throwable $e) {
    echo "\n=== ERROR EXCEPTION ===\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
