<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

use Chatgo\Security\WebAppAuthenticator;

// 0. Роутинг для /api/... если Nginx перенаправляет все запросы в index.php
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (is_string($requestUri) && str_starts_with($requestUri, '/api/')) {
    $targetFile = __DIR__ . $requestUri;
    if (file_exists($targetFile) && is_file($targetFile)) {
        require $targetFile;
        exit;
    }
}

// Обработка выхода из тестового режима
if (isset($_GET['logout'])) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['chatgo_dev_auth']);
    }
    setcookie('chatgo_dev_key', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
    $targetUrl = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '/';
    header('Location: ' . $targetUrl);
    exit;
}

// Обработка входа по ?dev_key=...
if (isset($_GET['dev_key'])) {
    $devKey = trim((string) $_GET['dev_key']);
    if ($devKey !== '' && hash_equals(CHATGO_SECRET, $devKey)) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['chatgo_dev_auth'] = true;
        }
        setcookie('chatgo_dev_key', $devKey, [
            'expires' => time() + 86400 * 30, // 30 дней
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax'
        ]);
        $targetUrl = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '/';
        header('Location: ' . $targetUrl);
        exit;
    }
}

$db = DB::getConnection();
try {
    @$db->exec("ALTER TABLE `auth_tokens` MODIFY `user_id` INT NULL DEFAULT NULL");
    @$db->exec("ALTER TABLE `users` MODIFY `email` VARCHAR(255) NULL DEFAULT NULL");
} catch (Throwable $e) {
    // Ignore if already altered or no permission
}
$currentUserId = WebAppAuthenticator::getAuthenticatedUserId($db);
$isDevMode = WebAppAuthenticator::isDevAuthorized();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chatgo Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
</head>
<body>
    <!-- Login / Auth Overlay -->
    <div class="access-denied-overlay" id="auth-overlay" style="<?= $currentUserId ? 'display: none;' : 'display: flex;' ?>">
        <div class="overlay-card">
            <div class="overlay-icon">💬</div>
            <h2 class="overlay-title">Вход в Chatgo</h2>
            <p class="overlay-desc">Единый дашборд для сообщений из Telegram, WhatsApp, VK, MAX и Instagram.</p>
            
            <div id="auth-initial-block">
                <button type="button" class="btn-tg-connect" style="margin-top: 20px; padding: 14px 20px; font-size: 15px;" onclick="startTelegramLogin()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 8px;"><path d="M21.5 2L2 9.5l7.5 3L17 6.5l-5.5 8.5v6l4-3.5 6 4.5 3-19.5z"/></svg>
                    Войти через Telegram
                </button>
                <div id="auth-login-error" style="color: #ef4444; font-size: 13px; margin-top: 10px; display: none;"></div>
            </div>

            <div id="auth-waiting-block" style="display: none; margin-top: 20px;">
                <div style="font-size: 24px; animation: pulse 1s infinite; margin-bottom: 8px;">⏳</div>
                <div style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 8px;">Подтвердите вход в Telegram</div>
                <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 16px;">Перейдите в бота и нажмите кнопку «Запустить»:</p>
                <a id="auth-bot-link" href="#" target="_blank" class="btn-tg-connect" style="text-decoration: none; display: inline-flex; width: 100%; justify-content: center;">
                    Открыть бота
                </a>
            </div>
        </div>
    </div>

    <div class="app-container">
        <!-- Sidebar / Chat list -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <h1 class="brand">Chatgo</h1>
                    <?php if ($isDevMode): ?>
                        <span class="dev-badge" title="Активен режим тестирования разработчика">Тест</span>
                    <?php endif; ?>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <?php if ($currentUserId): ?>
                        <button class="dev-logout-btn" onclick="logoutSession()" title="Выйти из аккаунта">Выход</button>
                    <?php endif; ?>
                    <button class="settings-btn" onclick="toggleChannelsView()" title="Подключение каналов">⚙️</button>
                </div>
            </div>
            <div class="search-container">
                <input type="text" class="search-input" id="search-input" placeholder="Поиск диалогов...">
            </div>
            <div class="chat-list" id="chat-list">
                <!-- Динамический список чатов -->
            </div>
        </div>

        <!-- Chat area -->
        <div class="chat-window" id="chat-window">
            <div class="empty-state" id="empty-state">
                <div class="empty-icon">💬</div>
                <h2 class="empty-title">Ваша рабочая область</h2>
                <p>Выберите любой чат в меню слева для начала общения.</p>
            </div>
            
            <div class="chat-content" id="chat-content" style="display: none; height: 100%; flex-direction: column;">
                <div class="chat-header">
                    <div class="header-info">
                        <button class="back-button" id="back-button">←</button>
                        <div class="header-avatar" id="header-avatar">?</div>
                        <div>
                            <div class="header-name" id="header-name">Клиент</div>
                            <div class="header-status">
                                <span class="status-dot"></span>
                                <span id="header-channel">Telegram</span>
                            </div>
                        </div>
                    </div>
                    <div class="header-moderation" id="header-moderation" style="display: none; align-items: center; gap: 8px;">
                        <span id="moderation-badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); font-size: 11px; padding: 4px 8px; border-radius: 6px; font-weight: 600;">На модерации</span>
                        <button type="button" id="btn-approve-chat" onclick="moderateCurrentChat('active')" style="background: #10b981; color: white; border: none; border-radius: 8px; padding: 6px 12px; font-size: 12px; font-weight: 600; cursor: pointer; transition: opacity 0.2s;">✅ Одобрить</button>
                        <button type="button" id="btn-reject-chat" onclick="moderateCurrentChat('rejected')" style="background: #ef4444; color: white; border: none; border-radius: 8px; padding: 6px 12px; font-size: 12px; font-weight: 600; cursor: pointer; transition: opacity 0.2s;">❌ Отклонить</button>
                    </div>
                </div>

                <div class="messages-container" id="messages-container">
                    <!-- Сообщения переписки -->
                </div>

                <div class="input-bar">
                    <form class="message-form" id="message-form" onsubmit="event.preventDefault(); sendMessage();">
                        <textarea class="input-textarea" id="message-input" placeholder="Введите сообщение..." onkeydown="handleKeyPress(event)"></textarea>
                        <button type="submit" class="send-button">
                            <svg viewBox="0 0 24 24">
                                <line x1="22" y1="2" x2="11" y2="13"></line>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Channels Configuration Panel -->
            <div class="channels-panel" id="channels-panel">
                <div class="panel-header">
                    <h2 class="panel-title">⚙️ Подключенные каналы</h2>
                    <button class="close-panel-btn" onclick="closeChannelsView()" title="Закрыть настройки">×</button>
                </div>
                <!-- Онбординг-баннер для нового клиента (0 каналов) -->
                <div id="onboarding-welcome-banner" style="display: none; background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(139, 92, 246, 0.15)); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 12px; padding: 16px; margin-bottom: 16px;">
                    <div style="font-size: 15px; font-weight: 700; color: var(--text-primary); margin-bottom: 6px;">👋 Добро пожаловать в Chatgo!</div>
                    <div style="font-size: 13px; color: var(--text-secondary); line-height: 1.5;">
                        Подключите ваш первый канал связи, чтобы начать принимать сообщения от клиентов в одном окне.
                    </div>
                </div>
                <div class="channels-list" id="channels-list">
                    <!-- Динамический список подключенных каналов -->
                </div>
                <!-- Секция Telegram-уведомлений для оператора -->
                <div class="notifications-section" id="notifications-section" style="margin: 15px 0; padding: 15px; background: rgba(255,255,255,0.05); border-radius: 8px;">
                    <div style="font-weight: 500; margin-bottom: 8px; font-size: 14px;">🔔 Уведомления оператора в Telegram</div>
                    <div id="notifications-status-container" style="display: flex; justify-content: space-between; align-items: center;">
                        <span id="notifications-status-badge" class="channel-status disconnected" style="font-size: 12px; padding: 4px 8px; border-radius: 4px; display: inline-block;">Проверка статуса...</span>
                        <button id="notifications-bind-btn" class="add-channel-btn" style="width: auto; margin: 0; padding: 6px 12px; font-size: 12px; display: none;" onclick="bindTelegramNotifications()">
                            Подключить
                        </button>
                    </div>
                </div>

                <button class="btn-tg-connect" onclick="openTelegramConnectModal()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px;"><path d="M21.5 2L2 9.5l7.5 3L17 6.5l-5.5 8.5v6l4-3.5 6 4.5 3-19.5z"/></svg>
                    Подключить Telegram (личный)
                </button>

                <button class="add-channel-btn" onclick="openAddChannelModal()">
                    <span>+</span> Подключить другой канал
                </button>
            </div>
        </div>
    </div>

    <!-- Modal for Adding Channel -->
    <div class="modal" id="add-channel-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Подключение нового канала</h3>
                <button class="modal-close" onclick="closeAddChannelModal()">×</button>
            </div>
            <form id="add-channel-form" onsubmit="event.preventDefault(); saveChannel();">
                <div class="form-group">
                    <label for="channel-name">Название канала</label>
                    <input type="text" class="form-control" id="channel-name" required placeholder="Например: Мой Бот Поддержки">
                </div>
                
                <div class="form-group">
                    <label for="channel-type">Тип канала</label>
                    <select class="form-control" id="channel-type" onchange="handleChannelTypeChange()" required>
                        <option value="" disabled selected>Выберите платформу...</option>
                        <option value="telegram_personal">Telegram (Личный профиль: QR-код / Номер)</option>
                        <option value="telegram">Telegram Bot (API токен)</option>
                        <option value="vk">VKontakte Group</option>
                        <option value="whatsapp">WhatsApp Business API (Meta)</option>
                        <option value="instagram">Instagram Direct (Meta)</option>
                        <option value="max">MAX Messenger Bot</option>
                    </select>
                </div>

                <!-- Динамические поля для Telegram -->
                <div id="fields-telegram" class="channel-fields" style="display: none;">
                    <div class="form-group">
                        <label for="tg-token">Токен бота (Bot Token)</label>
                        <input type="text" class="form-control" id="tg-token" placeholder="123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ">
                    </div>
                </div>

                <!-- Динамические поля для VK -->
                <div id="fields-vk" class="channel-fields" style="display: none;">
                    <div class="form-group">
                        <label for="vk-token">Токен доступа группы (Access Token)</label>
                        <input type="text" class="form-control" id="vk-token" placeholder="vk1.a.abcdef...">
                    </div>
                    <div class="form-group">
                        <label for="vk-secret">Секретный ключ (Secret Key) - опционально</label>
                        <input type="text" class="form-control" id="vk-secret" placeholder="Ваш секретный ключ для вебхука">
                    </div>
                    <div class="form-group">
                        <label for="vk-confirm">Код подтверждения (Confirmation Code)</label>
                        <input type="text" class="form-control" id="vk-confirm" placeholder="Строка, которую вернет сервер">
                    </div>
                </div>

                <!-- Динамические поля для WhatsApp -->
                <div id="fields-whatsapp" class="channel-fields" style="display: none;">
                    <div class="form-group">
                        <label for="wa-token">Токен доступа Meta (Access Token)</label>
                        <input type="text" class="form-control" id="wa-token" placeholder="EAAGz...">
                    </div>
                    <div class="form-group">
                        <label for="wa-phone-id">Phone Number ID</label>
                        <input type="text" class="form-control" id="wa-phone-id" placeholder="109876543210987">
                    </div>
                    <div class="form-group">
                        <label for="wa-verify">Секретный токен верификации (Verify Token)</label>
                        <input type="text" class="form-control" id="wa-verify" placeholder="Любая строка для проверки Meta">
                    </div>
                </div>

                <!-- Динамические поля для Instagram -->
                <div id="fields-instagram" class="channel-fields" style="display: none;">
                    <div class="form-group">
                        <label for="ig-token">Токен доступа Meta (Access Token)</label>
                        <input type="text" class="form-control" id="ig-token" placeholder="EAAGz...">
                    </div>
                    <div class="form-group">
                        <label for="ig-account-id">Instagram Account ID</label>
                        <input type="text" class="form-control" id="ig-account-id" placeholder="17841400000000000">
                    </div>
                    <div class="form-group">
                        <label for="ig-verify">Секретный токен верификации (Verify Token)</label>
                        <input type="text" class="form-control" id="ig-verify" placeholder="Любая строка для проверки Meta">
                    </div>
                </div>

                <!-- Динамические поля для MAX -->
                <div id="fields-max" class="channel-fields" style="display: none;">
                    <div class="form-group">
                        <label for="max-token">Токен доступа MAX (Access Token)</label>
                        <input type="text" class="form-control" id="max-token" placeholder="max_token_...">
                    </div>
                    <div class="form-group">
                        <label for="max-verify">Секретный токен верификации (Verify Token)</label>
                        <input type="text" class="form-control" id="max-verify" placeholder="Секретный ключ вебхука">
                    </div>
                </div>

                <!-- Блок вывода инструкций по вебхуку (после успешного добавления) -->
                <div id="webhook-info-box" style="display: none;">
                    <div class="instruction-box">
                        <strong>⚠️ Требуется ручная настройка вебхука!</strong><br>
                        Скопируйте URL и вставьте его в настройки платформы:<br>
                        <span id="webhook-display-url" style="font-family: monospace; font-size: 11px; display: block; margin: 4px 0; background: rgba(0,0,0,0.3); padding: 4px; border-radius: 4px;"></span>
                        <div id="webhook-verify-token-row" style="display: none;">
                            Секретный токен верификации (Verify Token):<br>
                            <span id="webhook-display-token" style="font-family: monospace; font-size: 11px; display: block; margin: 4px 0; background: rgba(0,0,0,0.3); padding: 4px; border-radius: 4px;"></span>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="modal-cancel-btn" onclick="closeAddChannelModal()">Отмена</button>
                    <button type="submit" class="btn-primary" id="modal-submit-btn">Подключить</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal for Connecting Personal Telegram (Umnico Style) -->
    <div class="modal" id="telegram-connect-modal">
        <div class="modal-content" style="max-width: 460px;">
            <div class="modal-header">
                <h3 class="modal-title" id="tg-modal-title">Подключение Telegram</h3>
                <button class="modal-close" onclick="closeTelegramConnectModal()">×</button>
            </div>

            <!-- View 1: QR-код (по умолчанию) -->
            <div id="tg-view-qr" class="tg-connect-box">
                <ol class="tg-steps-list">
                    <li>Откройте <strong>Telegram</strong> со своего смартфона</li>
                    <li>Перейдите в <strong>Настройки → Устройства → Подключить устройство</strong></li>
                    <li>Наведите камеру телефона на экран, чтобы сканировать QR-код</li>
                </ol>

                <div class="tg-qr-wrapper">
                    <div id="tg-qr-loading" class="tg-qr-spinner">
                        <div style="font-size: 28px; animation: pulse 1s infinite;">⏳</div>
                        <span>Генерация QR-кода...</span>
                    </div>
                    <img id="tg-qr-image" class="tg-qr-img" src="" alt="Telegram QR" style="display: none;">
                </div>

                <div id="tg-qr-status-text" class="tg-status-text">Ожидание сканирования...</div>

                <button type="button" class="tg-switch-mode-btn" onclick="switchTgMode('phone')">
                    Подключить по номеру телефона
                </button>
            </div>

            <!-- View 2: Вход по номеру телефона -->
            <div id="tg-view-phone" class="tg-connect-box" style="display: none;">
                <div class="tg-phone-form">
                    <div class="form-group">
                        <label for="tg-phone-number">Номер телефона аккаунта</label>
                        <input type="tel" class="form-control" id="tg-phone-number" placeholder="+7 999 123-45-67">
                    </div>

                    <button type="button" id="tg-btn-send-code" class="btn-primary" style="width: 100%;" onclick="sendTgPhoneCode()">
                        Получить код
                    </button>

                    <!-- Блок ввода кода -->
                    <div id="tg-code-block" style="display: none; margin-top: 10px;">
                        <div class="form-group">
                            <label for="tg-auth-code">Код подтверждения из Telegram</label>
                            <input type="text" class="form-control tg-code-input" id="tg-auth-code" maxlength="6" placeholder="• • • • •">
                            <span style="font-size: 11px; color: var(--text-secondary); margin-top: 4px; display: block;">
                                Код отправлен в ваше приложение Telegram
                            </span>
                        </div>

                        <!-- Блок 2FA облачного пароля -->
                        <div id="tg-2fa-block" style="display: none;">
                            <div class="form-group">
                                <label for="tg-2fa-password">Облачный пароль двухфакторной аутентификации (2FA)</label>
                                <input type="password" class="form-control" id="tg-2fa-password" placeholder="Введите ваш 2FA пароль">
                            </div>
                        </div>

                        <button type="button" id="tg-btn-submit-code" class="btn-primary" style="width: 100%; margin-top: 8px;" onclick="submitTgPhoneCode()">
                            Войти и подключить
                        </button>
                    </div>

                    <div id="tg-phone-status-text" class="tg-status-text" style="margin-top: 6px;"></div>
                </div>

                <button type="button" class="tg-switch-mode-btn" onclick="switchTgMode('qr')">
                    Подключить по QR-коду
                </button>
            </div>

            <!-- View 3: 2FA облачный пароль для QR сессии -->
            <div id="tg-view-2fa" class="tg-connect-box" style="display: none;">
                <div class="tg-phone-form">
                    <div class="form-group">
                        <label for="tg-qr-2fa-password">Облачный пароль (2FA)</label>
                        <input type="password" class="form-control" id="tg-qr-2fa-password" placeholder="Введите ваш 2FA пароль">
                        <span style="font-size: 12px; color: var(--text-secondary); margin-top: 4px; display: block;">
                            Для этого аккаунта включена двухфакторная аутентификация. Введите облачный пароль для завершения подключения.
                        </span>
                    </div>
                    <button type="button" class="btn-primary" style="width: 100%;" onclick="submitTgQrPassword()">
                        Подтвердить пароль
                    </button>
                    <div id="tg-2fa-status-text" class="tg-status-text" style="margin-top: 6px;"></div>
                </div>
            </div>

            <!-- View 4: Успешное подключение -->
            <div id="tg-view-success" class="tg-connect-box" style="display: none;">
                <div class="tg-success-icon">🎉</div>
                <h4 style="font-size: 18px; margin-bottom: 8px;">Telegram успешно подключен!</h4>
                <p id="tg-success-user-info" style="color: var(--text-secondary); font-size: 14px; margin-bottom: 20px;"></p>
                <button type="button" class="btn-primary" style="width: 100%;" onclick="closeTelegramConnectModal()">
                    Готово
                </button>
            </div>
        </div>
    </div>

    <script>
        window.CHATGO_CONFIG = {
            isDevMode: <?= json_encode($isDevMode) ?>,
            telegramApiUrl: <?= json_encode(TELEGRAM_API_URL) ?>,
            baseUrl: <?= json_encode(BASE_URL) ?>
        };
    </script>
    <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
