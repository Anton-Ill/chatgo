<?php

/**
 * Diagnostic and setup assistant for testing Telegram WebApp integration
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

echo "=== Telegram WebApp Setup Assistant ===\n\n";

echo "Чтобы открыть панель как Telegram WebApp:\n";
echo "1. Получите публичный HTTPS URL для вашего локального сайта.\n";
echo "   Рекомендуется запустить в терминале ngrok:\n";
echo "   ngrok http 127.0.1.13:80\n";
echo "   (где 127.0.1.13 - IP-адрес Nginx в Open Server Panel 6).\n\n";

echo "2. Скопируйте полученную ссылку https://<субдомен>.ngrok-free.app.\n\n";

echo "3. Зарегистрируйте WebApp кнопку в вашем Telegram боте через BotFather:\n";
echo "   - Напишите @BotFather команду /mybots, выберите вашего бота.\n";
echo "   - Перейдите в Bot Settings -> Menu Button -> Configure Menu Button.\n";
echo "   - Отправьте ссылку https://<субдомен>.ngrok-free.app и задайте название кнопки (например, 'Панель').\n\n";

echo "4. Откройте вашего бота в Telegram. В нижнем левом углу появится кнопка 'Панель'.\n";
echo "   При клике на неё откроется ваш локальный дашборд с нативной кнопкой 'Назад' от Telegram!\n\n";

echo "Текущие настройки домена:\n";
echo "BASE_URL: " . BASE_URL . "\n";
echo "TELEGRAM_API_URL: " . TELEGRAM_API_URL . "\n";
