<?php

/**
 * REST API: Browser Telegram Authentication & Session Management
 * PHP Version 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

use Chatgo\Security\WebAppAuthenticator;

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

try {
    $db = DB::getConnection();
    $action = $_GET['action'] ?? $_POST['action'] ?? 'status';

    switch ($action) {
        case 'status':
            $userId = WebAppAuthenticator::getAuthenticatedUserId($db);
            if ($userId === null) {
                echo json_encode([
                    'ok' => true,
                    'authenticated' => false
                ]);
                exit;
            }

            $stmt = $db->prepare('SELECT id, telegram_id, username, first_name, email FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            echo json_encode([
                'ok' => true,
                'authenticated' => true,
                'user' => $user ?: ['id' => $userId]
            ]);
            break;

        case 'init_login':
            $botUsername = defined('TELEGRAM_BOT_USERNAME') && TELEGRAM_BOT_USERNAME !== ''
                ? (string) TELEGRAM_BOT_USERNAME
                : 'chatgoservice_bot';
            $botUsername = trim(ltrim($botUsername, '@'));

            if ($botUsername === '') {
                $botUsername = 'chatgoservice_bot';
            }

            // Генерируем токен
            $token = bin2hex(random_bytes(16));
            $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 минут

            try {
                $firstUserId = (int) ($db->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $firstUserId = 0;
            }

            if (!$firstUserId) {
                try {
                    $db->exec("INSERT INTO users (email, created_at) VALUES ('admin@chatgo.ru', NOW())");
                    $firstUserId = (int) $db->lastInsertId();
                } catch (Throwable $e) {
                    try {
                        $db->exec("INSERT INTO users (user_id, email, created_at) VALUES (1, 'admin@chatgo.ru', NOW())");
                        $firstUserId = (int) $db->lastInsertId() ?: 1;
                    } catch (Throwable $e2) {
                        $firstUserId = 1;
                    }
                }
            }

            $stmtToken = $db->prepare('
                INSERT INTO auth_tokens (user_id, token, expires_at, used)
                VALUES (?, ?, ?, 0)
            ');
            $stmtToken->execute([$firstUserId > 0 ? $firstUserId : 1, $token, $expiresAt]);

            $botUrl = "https://t.me/{$botUsername}?start=auth_{$token}";

            echo json_encode([
                'ok' => true,
                'token' => $token,
                'bot_url' => $botUrl
            ]);
            break;

        case 'check_token':
            $token = trim((string) ($_GET['token'] ?? ''));
            if ($token === '') {
                throw new Exception('Параметр token обязателен.');
            }

            $stmt = $db->prepare('
                SELECT user_id, used, expires_at 
                FROM auth_tokens 
                WHERE token = ? 
                LIMIT 1
            ');
            $stmt->execute([$token]);
            $tokenRow = $stmt->fetch();

            if (!$tokenRow) {
                echo json_encode(['ok' => false, 'error' => 'Токен не найден']);
                exit;
            }

            if (strtotime($tokenRow['expires_at']) < time()) {
                echo json_encode(['ok' => false, 'error' => 'Срок действия токена истек']);
                exit;
            }

            // Если токен подтвержден ботом и привязан к user_id
            if (!empty($tokenRow['user_id']) && (int) $tokenRow['used'] === 1) {
                $uid = (int) $tokenRow['user_id'];
                $_SESSION['chatgo_user_id'] = $uid;

                echo json_encode([
                    'ok' => true,
                    'confirmed' => true,
                    'user_id' => $uid
                ]);
                exit;
            }

            echo json_encode([
                'ok' => true,
                'confirmed' => false
            ]);
            break;

        case 'logout':
            unset($_SESSION['chatgo_user_id']);
            unset($_SESSION['chatgo_dev_auth']);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            setcookie('chatgo_dev_key', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'httponly' => false,
                'samesite' => 'Lax'
            ]);

            echo json_encode(['ok' => true]);
            break;

        default:
            throw new Exception("Неизвестное действие: {$action}");
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
