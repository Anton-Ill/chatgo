<?php

declare(strict_types=1);

/**
 * REST API: Управление личным подключением Telegram (QR-код и номер телефона)
 */

require_once __DIR__ . '/../../config/db.php';

use Chatgo\Security\WebAppAuthenticator;

header('Content-Type: application/json');

try {
    $db = DB::getConnection();

    // 1. Проверка авторизации оператора
    if (!WebAppAuthenticator::authenticate($db)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Доступ запрещен: Не авторизован']);
        exit;
    }

    $serviceUrl = defined('TELEGRAM_PERSONAL_SERVICE_URL')
        ? TELEGRAM_PERSONAL_SERVICE_URL
        : 'http://127.0.0.1:3005';

    // Читаем входные данные (GET или JSON POST)
    $action = $_GET['action'] ?? '';
    $inputData = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $inputData = json_decode($raw, true) ?: [];
        }
        if (empty($action) && isset($inputData['action'])) {
            $action = (string) $inputData['action'];
        }
    }

    if (empty($action)) {
        throw new Exception('Параметр action обязателен.');
    }

    // Вспомогательная функция запроса к Node.js сервису
    $callGateway = function (string $endpoint, string $method = 'GET', array $data = []) use ($serviceUrl) {
        $url = rtrim($serviceUrl, '/') . $endpoint;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $headers = ['Content-Type: application/json'];
        if (defined('CHATGO_SECRET')) {
            $headers[] = 'X-Chatgo-Secret: ' . CHATGO_SECRET;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("Служба личного Telegram недоступна: {$curlErr}");
        }

        $decoded = json_decode((string) $response, true);
        if ($decoded === null) {
            throw new Exception("Некорректный ответ от шлюза (HTTP {$httpCode})");
        }

        return $decoded;
    };

    // Функция обновления/создания канала в БД
    $syncChannelInDb = function (array $user, string $status) use ($db) {
        $stmt = $db->query("SELECT id, settings FROM channels WHERE type = 'telegram' LIMIT 1");
        $channel = $stmt->fetch();

        $accountName = !empty($user['fullName']) ? $user['fullName'] : 'Telegram';
        if (!empty($user['username'])) {
            $accountName .= " (@{$user['username']})";
        } elseif (!empty($user['phone'])) {
            $accountName .= " ({$user['phone']})";
        }

        $settingsJson = json_encode([
            'account_type' => 'personal',
            'user'         => $user,
            'phone'        => $user['phone'] ?? '',
            'username'     => $user['username'] ?? '',
            'telegram_id'  => $user['id'] ?? '',
        ], JSON_UNESCAPED_UNICODE);

        if ($channel) {
            $stmtUpdate = $db->prepare('
                UPDATE channels 
                SET name = ?, status = ?, settings = ?
                WHERE id = ?
            ');
            $stmtUpdate->execute([$accountName, $status, $settingsJson, $channel['id']]);
            return (int) $channel['id'];
        }

        $userId = (int) ($db->query('SELECT id FROM users LIMIT 1')->fetchColumn() ?: 1);
        $stmtInsert = $db->prepare('
            INSERT INTO channels (user_id, type, name, status, settings, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ');
        $stmtInsert->execute([$userId, 'telegram', $accountName, $status, $settingsJson]);
        return (int) $db->lastInsertId();
    };

    // Маршрутизация действий
    switch ($action) {
        case 'status':
            $res = $callGateway('/api/status');
            if (!empty($res['connected']) && !empty($res['user'])) {
                $syncChannelInDb($res['user'], 'connected');
            }
            echo json_encode($res);
            break;

        case 'qr_start':
            $res = $callGateway('/api/qr/start');
            echo json_encode($res);
            break;

        case 'qr_status':
            $res = $callGateway('/api/qr/status');
            if (!empty($res['connected']) && !empty($res['user'])) {
                $syncChannelInDb($res['user'], 'connected');
            }
            echo json_encode($res);
            break;

        case 'phone_send_code':
            $phone = (string) ($inputData['phone'] ?? '');
            if (empty($phone)) {
                throw new Exception('Укажите номер телефона.');
            }
            $res = $callGateway('/api/phone/send_code', 'POST', ['phone' => $phone]);
            echo json_encode($res);
            break;

        case 'phone_sign_in':
            $res = $callGateway('/api/phone/sign_in', 'POST', $inputData);
            if (!empty($res['connected']) && !empty($res['user'])) {
                $syncChannelInDb($res['user'], 'connected');
            }
            echo json_encode($res);
            break;

        case 'password_submit':
            $password = (string) ($inputData['password'] ?? '');
            $res = $callGateway('/api/password/submit', 'POST', ['password' => $password]);
            echo json_encode($res);
            break;

        case 'disconnect':
            $res = $callGateway('/api/logout', 'POST');
            // Обновляем статус в БД на disconnected
            $db->prepare("UPDATE channels SET status = 'disconnected' WHERE type = 'telegram'")->execute();
            echo json_encode(['ok' => true]);
            break;

        default:
            throw new Exception("Неизвестное действие: {$action}");
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ]);
}
