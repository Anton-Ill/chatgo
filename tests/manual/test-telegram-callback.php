<?php

/**
 * Manual test: Simulate Telegram inline callback approval
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/Services/ChatService.php';

use Chatgo\Services\ChatService;

echo "--- Тест обработки callback-кнопки одобрения/отклонения ---\n";

try {
    $db = DB::getConnection();
    $chatService = new ChatService($db);

    $stmt = $db->query("SELECT id FROM channels WHERE type = 'telegram' LIMIT 1");
    $channelId = (int) $stmt->fetchColumn();

    $testClientId = 'test_cb_client_' . time();
    $chatId = $chatService->getOrCreateChat($channelId, $testClientId, 'Тест Кнопки', null, null, 'pending');
    echo "[OK] Создан чат на модерации ID: {$chatId}\n";

    // 1. Симулируем полезную нагрузку callback_query для одобрения
    $payloadApprove = [
        'callback_query' => [
            'id' => 'cb_test_123',
            'from' => [
                'id' => 702041507,
                'first_name' => 'Operator',
                'username' => 'operator'
            ],
            'message' => [
                'message_id' => 99999,
                'chat' => [
                    'id' => 702041507
                ]
            ],
            'data' => "approve_{$chatId}"
        ]
    ];

    // Отправляем POST-запрос на локальный/боевой вебхук
    $ch = curl_init('http://127.0.0.1/api/webhook/telegram.php?channel_id=' . $channelId);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadApprove));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Host: chatgo.ru'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "[OK] Ответ вебхука (HTTP {$httpCode}): {$response}\n";

    $freshChat = $chatService->getChatById($chatId);
    if ($freshChat['status'] !== 'active') {
        throw new RuntimeException("Ожидался статус 'active' после callback, но получен: '{$freshChat['status']}'");
    }
    echo "[OK] Чат успешно переведен в 'active' через callback_query!\n";

    // Очистка
    $db->prepare("DELETE FROM chats WHERE id = ?")->execute([$chatId]);
    echo "[OK] Тестовый чат очищен\n";

    echo "\n=== ТЕСТ CALLBACK_QUERY УСПЕШНО ЗАВЕРШЕН ===\n";
} catch (Throwable $e) {
    echo "\n[ОШИБКА] " . $e->getMessage() . "\n";
    exit(1);
}
