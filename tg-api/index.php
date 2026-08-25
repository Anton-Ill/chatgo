<?php

/**
 * Telegram API Proxy Gateway
 * PHP Version 8.x
 */

// Отключаем лимиты времени
set_time_limit(0);

// Логирование ошибок
ini_set('display_errors', 0);
error_reporting(0);

// Парсим путь запроса
$requestUri = $_SERVER['REQUEST_URI'];
$tgApiPattern = '/tg-api/';
$pos = strpos($requestUri, $tgApiPattern);

if ($pos !== false) {
    // Вырезаем все, что идет после /tg-api/
    $path = substr($requestUri, $pos + strlen($tgApiPattern));
    if (str_starts_with($path, 'index.php')) {
        $path = $_GET['tg_path'] ?? '';
    }
} else {
    // Альтернативный вариант через GET-параметр
    $path = $_GET['tg_path'] ?? '';
}

// Отрезаем GET параметры от пути, если они приклеились
if (($qPos = strpos($path, '?')) !== false) {
    $path = substr($path, 0, $qPos);
}

$path = ltrim($path, '/');

if (empty($path) || !str_starts_with($path, 'bot')) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode([
        'ok' => false,
        'error_code' => 400,
        'description' => 'Bad Request: Invalid Telegram bot token path'
    ]);
    exit;
}

// Режим песочницы для тестирования с мок-токенами
if (str_contains($path, 'MOCK')) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'result' => [
            'message_id' => rand(1000, 9999),
            'date' => time(),
            'text' => 'Mocked payload response'
        ]
    ]);
    exit;
}

// Официальный URL Telegram API
$telegramUrl = 'https://api.telegram.org/' . $path;

// Добавляем обратно query string для GET запросов
if (!empty($_SERVER['QUERY_STRING'])) {
    // Удаляем наш служебный параметр tg_path, если он использовался
    $queryParams = [];
    parse_str($_SERVER['QUERY_STRING'], $queryParams);
    unset($queryParams['tg_path']);
    if (!empty($queryParams)) {
        $telegramUrl .= '?' . http_build_query($queryParams);
    }
}

// Инициализируем cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $telegramUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true); // Получаем заголовки от Telegram
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

// Проксируем метод запроса
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    
    // Если переданы файлы, отправляем как multipart/form-data
    if (!empty($_FILES)) {
        $postData = $_POST;
        foreach ($_FILES as $key => $file) {
            if ($file['error'] === UPLOAD_ERR_OK) {
                $postData[$key] = new CURLFile($file['tmp_name'], $file['type'], $file['name']);
            }
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    } else {
        // Иначе шлем сырые данные (json или x-www-form-urlencoded)
        $rawInput = file_get_contents('php://input');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawInput);
    }
}

// Проксируем заголовки (кроме Host)
$headers = [];
foreach (getallheaders() as $name => $value) {
    if (strtolower($name) === 'host') {
        continue;
    }
    $headers[] = "$name: $value";
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Выполняем запрос к Telegram
$response = curl_exec($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    header('HTTP/1.1 502 Bad Gateway');
    echo json_encode([
        'ok' => false,
        'error_code' => 502,
        'description' => 'Bad Gateway: Failed to contact Telegram API servers'
    ]);
    exit;
}

// Разделяем заголовки и тело
$responseHeaders = substr($response, 0, $headerSize);
$responseBody = substr($response, $headerSize);

// Отправляем HTTP код обратно
http_response_code($httpCode);

// Пересылаем заголовки от Telegram (Content-Type, и т.д.)
$headerLines = explode("\r\n", $responseHeaders);
foreach ($headerLines as $headerLine) {
    if (empty($headerLine)) {
        continue;
    }
    // Проксируем только нужные заголовки ответа
    if (stripos($headerLine, 'Content-Type:') === 0 || 
        stripos($headerLine, 'Access-Control-') === 0 ||
        stripos($headerLine, 'Connection:') === 0) {
        header($headerLine);
    }
}

// Выводим тело ответа
echo $responseBody;
