/**
 * Chatgo Frontend Application Logic
 */

const tg = window.Telegram?.WebApp;
let currentChatId = null;
let chatsList = [];
let pollingInterval = null;
let notificationsPollingInterval = null;

// Авто-открытие чата по параметру ?chat_id=... или Telegram startapp=chat_...
const urlParams = new URLSearchParams(window.location.search);
let initialChatIdToOpen = urlParams.get('chat_id');
if (!initialChatIdToOpen && tg?.initDataUnsafe?.start_param) {
    const sp = String(tg.initDataUnsafe.start_param);
    if (sp.startsWith('chat_')) {
        initialChatIdToOpen = sp.substring(5);
    }
}

function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? decodeURIComponent(match[2]) : null;
}

const isDevModeActive = Boolean(window.CHATGO_CONFIG?.isDevMode);
const activeDevKey = getCookie('chatgo_dev_key') || localStorage.getItem('chatgo_dev_key');
if (activeDevKey) {
    localStorage.setItem('chatgo_dev_key', activeDevKey);
}

let userChannelsCount = null;
let loginCheckInterval = null;

function logoutDevMode() {
    logoutSession();
}

async function logoutSession() {
    try {
        await fetch('/api/auth/session.php?action=logout', { method: 'POST' });
    } catch (e) {}
    localStorage.removeItem('chatgo_dev_key');
    window.location.href = window.location.pathname + '?logout=1';
}

// Вспомогательная функция для запросов с авторизацией WebApp
async function tgFetch(url, options = {}) {
    if (!options.headers) {
        options.headers = {};
    }
    if (tg && tg.initData) {
        options.headers['X-TG-Init-Data'] = tg.initData;
    }
    const currentDevKey = getCookie('chatgo_dev_key') || localStorage.getItem('chatgo_dev_key');
    if (currentDevKey) {
        options.headers['X-Dev-Key'] = currentDevKey;
    }
    try {
        const response = await fetch(url, options);

        // Проверяем, вернул ли API ошибку авторизации
        const clone = response.clone();
        try {
            const data = await clone.json();
            if (data && data.ok === false && data.error === 'Доступ запрещен: Не авторизован') {
                document.getElementById('auth-overlay').style.display = 'flex';
                if (pollingInterval) clearInterval(pollingInterval);
            }
        } catch (e) {
            // Игнорируем ошибки парсинга не-JSON ответов
        }
        return response;
    } catch (err) {
        console.error("tgFetch error:", err);
        throw err;
    }
}

async function checkAuthStatus() {
    try {
        const res = await tgFetch('/api/auth/session.php?action=status');
        const data = await res.json();
        if (data.ok && data.authenticated) {
            const authOverlay = document.getElementById('auth-overlay');
            if (authOverlay) authOverlay.style.display = 'none';
            return true;
        }
    } catch (e) {
        console.error('Ошибка проверки статуса авторизации:', e);
    }
    return false;
}

async function startTelegramLogin() {
    const initialBlock = document.getElementById('auth-initial-block');
    const waitingBlock = document.getElementById('auth-waiting-block');
    const errorDiv = document.getElementById('auth-login-error');
    const botLink = document.getElementById('auth-bot-link');

    if (errorDiv) errorDiv.style.display = 'none';

    try {
        const res = await fetch('/api/auth/session.php?action=init_login', { method: 'POST' });
        const data = await res.json();

        if (data.ok && data.bot_url) {
            if (initialBlock) initialBlock.style.display = 'none';
            if (waitingBlock) waitingBlock.style.display = 'block';
            if (botLink) botLink.href = data.bot_url;

            // На мобильных устройствах пробуем открыть ссылку в Telegram
            if (/Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) {
                window.location.href = data.bot_url;
            }

            // Запускаем опрос статуса подтверждения токена
            if (loginCheckInterval) clearInterval(loginCheckInterval);
            loginCheckInterval = setInterval(async () => {
                try {
                    const checkRes = await fetch(`/api/auth/session.php?action=check_token&token=${encodeURIComponent(data.token)}`);
                    const checkData = await checkRes.json();
                    if (checkData.ok && checkData.confirmed) {
                        clearInterval(loginCheckInterval);
                        window.location.reload();
                    }
                } catch (err) {
                    console.error('Ошибка проверки токена:', err);
                }
            }, 1500);
        } else {
            if (errorDiv) {
                errorDiv.innerText = data.error || 'Ошибка генерации ссылки на бота';
                errorDiv.style.display = 'block';
            }
        }
    } catch (err) {
        console.error('Ошибка входа через Telegram:', err);
        if (errorDiv) {
            errorDiv.innerText = 'Ошибка соединения с сервером';
            errorDiv.style.display = 'block';
        }
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', async () => {
    // Инициализация Telegram WebApp
    if (tg && tg.initData) {
        tg.ready();
        tg.expand();

        // Скрываем HTML кнопку назад, так как будем использовать нативную от Telegram
        const backBtn = document.getElementById('back-button');
        if (backBtn) {
            backBtn.style.display = 'none';
        }

        // Настраиваем клик по нативной кнопке назад Telegram
        tg.BackButton.onClick(() => {
            handleBackAction();
        });
    }

    // Проверяем статус авторизации (для WebApp и браузера)
    await checkAuthStatus();

    // Первичная проверка каналов и онбординг
    await initChannelsAndOnboarding();

    loadChats();
    // Запуск фонового опроса чатов каждые 3 секунды
    setInterval(loadChats, 3000);

    // Обработка кнопки "Назад" в HTML шапке
    const backBtn = document.getElementById('back-button');
    if (backBtn) {
        backBtn.addEventListener('click', () => {
            handleBackAction();
        });
    }

    // Обработка ввода поиска
    const searchInput = document.getElementById('search-input');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            filterChats(e.target.value);
        });
    }
});

// Проверка количества каналов при входе для авто-онбординга
async function initChannelsAndOnboarding() {
    try {
        const response = await tgFetch('/api/channels/list.php');
        const data = await response.json();
        if (data.ok) {
            userChannelsCount = data.channels.length;
            if (userChannelsCount === 0) {
                // Если каналов нет - сразу открываем панель подключения каналов
                toggleChannelsView();
                const banner = document.getElementById('onboarding-welcome-banner');
                if (banner) banner.style.display = 'block';
            }
        }
    } catch (err) {
        console.error("Ошибка при инициализации онбординга каналов:", err);
    }
}

// Единое действие возврата к списку чатов
function handleBackAction() {
    const channelsPanel = document.getElementById('channels-panel');
    const isChannelsOpen = channelsPanel && channelsPanel.style.display === 'flex';

    if (isChannelsOpen) {
        closeChannelsView();
    } else {
        document.getElementById('chat-window').classList.remove('active');
        document.getElementById('sidebar').classList.remove('hidden');
        currentChatId = null;
        if (pollingInterval) clearInterval(pollingInterval);

        // Восстанавливаем заглушку на десктопе
        document.getElementById('chat-content').style.display = 'none';
        document.getElementById('empty-state').style.display = 'flex';

        if (tg) {
            tg.BackButton.hide();
        }
    }
}

// --- Управление панелью каналов ---

// Открыть панель каналов
function toggleChannelsView() {
    // Скрываем текущий открытый чат и empty-state
    document.getElementById('chat-content').style.display = 'none';
    document.getElementById('empty-state').style.display = 'none';

    // Показываем панель каналов
    document.getElementById('channels-panel').style.display = 'flex';

    // Для адаптивности на мобильных устройствах
    document.getElementById('chat-window').classList.add('active');
    document.getElementById('sidebar').classList.add('hidden');

    if (tg) {
        tg.BackButton.show();
    }

    loadChannels();

    // Опрос статуса уведомлений
    checkNotificationsStatus();
    if (notificationsPollingInterval) clearInterval(notificationsPollingInterval);
    notificationsPollingInterval = setInterval(checkNotificationsStatus, 4000);
}

// Закрыть панель настроек каналов
function closeChannelsView() {
    document.getElementById('channels-panel').style.display = 'none';

    if (currentChatId) {
        // Если был открыт диалог, возвращаемся к нему
        document.getElementById('chat-content').style.display = 'flex';
        document.getElementById('empty-state').style.display = 'none';
    } else {
        // Иначе показываем заглушку
        document.getElementById('chat-content').style.display = 'none';
        document.getElementById('empty-state').style.display = 'flex';

        // На мобильных при отсутствии активного чата возвращаемся к сайдбару
        document.getElementById('chat-window').classList.remove('active');
        document.getElementById('sidebar').classList.remove('hidden');

        if (tg) {
            tg.BackButton.hide();
        }
    }

    if (notificationsPollingInterval) {
        clearInterval(notificationsPollingInterval);
        notificationsPollingInterval = null;
    }
}

// Проверить статус уведомлений оператора
async function checkNotificationsStatus() {
    try {
        const response = await tgFetch('/api/channels/get_bind_status.php');
        const data = await response.json();

        const badge = document.getElementById('notifications-status-badge');
        const btn = document.getElementById('notifications-bind-btn');

        if (!badge || !btn) return;

        if (data.ok) {
            if (data.is_bound) {
                badge.innerText = `Подключено (ID: ${data.telegram_id})`;
                badge.className = 'channel-status connected';
                btn.style.display = 'none';
            } else {
                badge.innerText = 'Не подключено';
                badge.className = 'channel-status disconnected';
                btn.style.display = 'block';
            }
        } else {
            badge.innerText = 'Ошибка проверки';
            badge.className = 'channel-status disconnected';
            btn.style.display = 'none';
        }
    } catch (err) {
        console.error("Ошибка при проверке статуса уведомлений:", err);
    }
}

// Запустить привязку уведомлений в Telegram
async function bindTelegramNotifications() {
    const btn = document.getElementById('notifications-bind-btn');
    if (!btn) return;

    btn.disabled = true;
    btn.innerText = 'Генерация...';

    try {
        const response = await tgFetch('/api/channels/add_bind.php');
        const data = await response.json();

        if (data.ok && data.bind_url) {
            btn.innerText = 'Переход...';
            // Если запущено внутри Telegram WebApp, используем нативный переход
            if (tg && typeof tg.openTelegramLink === 'function') {
                tg.openTelegramLink(data.bind_url);
            } else {
                window.open(data.bind_url, '_blank');
            }
        } else {
            alert("Ошибка генерации ссылки привязки:\n" + (data.error || 'Неизвестная ошибка'));
        }
    } catch (err) {
        console.error("Ошибка при привязке уведомлений:", err);
        alert("Ошибка сети при генерации ссылки привязки");
    } finally {
        btn.disabled = false;
        btn.innerText = 'Подключить';
    }
}

// Загрузить каналы
async function loadChannels() {
    const listContainer = document.getElementById('channels-list');
    if (!listContainer) return;

    listContainer.innerHTML = '<div style="text-align: center; color: var(--text-secondary); padding: 20px;">Загрузка каналов...</div>';

    try {
        const response = await tgFetch('/api/channels/list.php');
        const data = await response.json();

        if (!data.ok) {
            listContainer.innerHTML = `<div style="text-align: center; color: #ef4444; padding: 20px;">Ошибка: ${data.error}</div>`;
            return;
        }

        listContainer.innerHTML = '';
        userChannelsCount = data.channels.length;

        const banner = document.getElementById('onboarding-welcome-banner');
        if (banner) {
            banner.style.display = userChannelsCount === 0 ? 'block' : 'none';
        }

        if (data.channels.length === 0) {
            listContainer.innerHTML = '<div style="text-align: center; color: var(--text-secondary); padding: 20px;">У вас пока нет подключенных каналов</div>';
            return;
        }

        data.channels.forEach(channel => {
            const card = document.createElement('div');
            card.className = 'channel-card';

            card.innerHTML = `
                <div class="channel-info">
                    <div class="channel-type-badge ${channel.type}">${channel.type.slice(0, 2)}</div>
                    <div class="channel-card-details">
                        <span class="channel-card-name">${escapeHtml(channel.name)}</span>
                        <div class="channel-card-meta">
                            <span class="channel-status ${channel.status}">
                                ${channel.status === 'connected' ? 'Подключен' : 'Отключен'}
                            </span>
                        </div>
                    </div>
                </div>
                <button class="delete-channel-btn" onclick="deleteChannel(${channel.id})">Удалить</button>
            `;
            listContainer.appendChild(card);
        });

    } catch (err) {
        console.error("Ошибка при получении каналов:", err);
        listContainer.innerHTML = '<div style="text-align: center; color: #ef4444; padding: 20px;">Ошибка сети при загрузке каналов</div>';
    }
}

// Управление модальным окном добавления каналов
function openAddChannelModal() {
    document.getElementById('add-channel-form').reset();
    document.getElementById('add-channel-modal').style.display = 'flex';

    // Сбрасываем поля
    const fields = document.querySelectorAll('.channel-fields');
    fields.forEach(f => f.style.display = 'none');

    // Сбрасываем кнопки
    document.getElementById('modal-cancel-btn').style.display = 'block';
    document.getElementById('modal-submit-btn').innerText = 'Подключить';
    document.getElementById('modal-submit-btn').disabled = false;
    document.getElementById('webhook-info-box').style.display = 'none';
}

function closeAddChannelModal() {
    document.getElementById('add-channel-modal').style.display = 'none';
    loadChannels();
}

// --- Управление подключением личного Telegram (GramJS MTProto) ---
let tgQrPollingInterval = null;
let tgPhoneCodeHash = '';

function openTelegramConnectModal() {
    document.getElementById('telegram-connect-modal').style.display = 'flex';
    switchTgMode('qr');
}

function closeTelegramConnectModal() {
    if (tgQrPollingInterval) {
        clearInterval(tgQrPollingInterval);
        tgQrPollingInterval = null;
    }
    document.getElementById('telegram-connect-modal').style.display = 'none';
    loadChannels();
}

function switchTgMode(mode) {
    if (tgQrPollingInterval) {
        clearInterval(tgQrPollingInterval);
        tgQrPollingInterval = null;
    }

    document.getElementById('tg-view-qr').style.display = 'none';
    document.getElementById('tg-view-phone').style.display = 'none';
    document.getElementById('tg-view-2fa').style.display = 'none';
    document.getElementById('tg-view-success').style.display = 'none';

    if (mode === 'qr') {
        document.getElementById('tg-view-qr').style.display = 'flex';
        document.getElementById('tg-modal-title').innerText = 'Подключение Telegram';
        startTgQrSession();
    } else if (mode === 'phone') {
        document.getElementById('tg-view-phone').style.display = 'flex';
        document.getElementById('tg-modal-title').innerText = 'Вход по номеру телефона';
        document.getElementById('tg-phone-status-text').innerText = '';
    } else if (mode === '2fa') {
        document.getElementById('tg-view-2fa').style.display = 'flex';
        document.getElementById('tg-modal-title').innerText = 'Двухфакторная защита (2FA)';
        document.getElementById('tg-2fa-status-text').innerText = '';
    } else if (mode === 'success') {
        document.getElementById('tg-view-success').style.display = 'flex';
        document.getElementById('tg-modal-title').innerText = 'Успешно';
    }
}

// Запуск сессии генерации QR-кода
async function startTgQrSession() {
    const loading = document.getElementById('tg-qr-loading');
    const img = document.getElementById('tg-qr-image');
    const statusText = document.getElementById('tg-qr-status-text');

    loading.style.display = 'flex';
    img.style.display = 'none';
    img.src = '';
    statusText.innerText = 'Генерация QR-кода...';
    statusText.style.color = 'var(--text-secondary)';

    try {
        const response = await tgFetch('/api/channels/telegram_personal.php?action=qr_start');
        const data = await response.json();

        if (!data.ok) {
            statusText.innerText = 'Ошибка: ' + (data.error || 'Не удалось запустить шлюз');
            statusText.style.color = '#ef4444';
            loading.style.display = 'none';
            return;
        }

        if (data.connected && data.user) {
            showTgSuccess(data.user);
            return;
        }

        if (data.qr_data_url) {
            img.src = data.qr_data_url;
            img.style.display = 'block';
            loading.style.display = 'none';
            statusText.innerText = 'Наведите камеру Telegram для сканирования';
            statusText.style.color = 'var(--text-secondary)';

            // Запуск опроса статуса сканирования
            if (tgQrPollingInterval) clearInterval(tgQrPollingInterval);
            tgQrPollingInterval = setInterval(checkTgQrStatus, 1500);
        }
    } catch (err) {
        console.error("Ошибка при получении QR:", err);
        statusText.innerText = 'Шлюз недоступен. Проверьте запуск службы service-telegram.';
        statusText.style.color = '#ef4444';
        loading.style.display = 'none';
    }
}

// Опрос статуса QR авторизации
async function checkTgQrStatus() {
    try {
        const response = await tgFetch('/api/channels/telegram_personal.php?action=qr_status');
        const data = await response.json();

        if (!data.ok) return;

        const statusText = document.getElementById('tg-qr-status-text');

        if (data.connected && data.user) {
            if (tgQrPollingInterval) clearInterval(tgQrPollingInterval);
            showTgSuccess(data.user);
        } else if (data.status === 'needs_2fa') {
            if (tgQrPollingInterval) clearInterval(tgQrPollingInterval);
            switchTgMode('2fa');
        } else if (data.status === 'expired') {
            if (tgQrPollingInterval) clearInterval(tgQrPollingInterval);
            statusText.innerText = 'Срок действия QR-кода истек. Обновление...';
            setTimeout(startTgQrSession, 1000);
        } else if (data.status === 'error') {
            if (tgQrPollingInterval) clearInterval(tgQrPollingInterval);
            statusText.innerText = 'Ошибка: ' + (data.error || 'Попробуйте снова');
            statusText.style.color = '#ef4444';
        }
    } catch (err) {
        console.error("Ошибка опроса статуса QR:", err);
    }
}

// Отправка пароля 2FA для QR сессии
async function submitTgQrPassword() {
    const pwdInput = document.getElementById('tg-qr-2fa-password');
    const statusText = document.getElementById('tg-2fa-status-text');
    const pwd = pwdInput.value.trim();

    if (!pwd) {
        statusText.innerText = 'Введите облачный пароль';
        statusText.style.color = '#ef4444';
        return;
    }

    statusText.innerText = 'Проверка пароля...';
    statusText.style.color = 'var(--text-secondary)';

    try {
        const response = await tgFetch('/api/channels/telegram_personal.php?action=password_submit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: pwd })
        });
        const data = await response.json();

        if (data.ok) {
            statusText.innerText = 'Пароль принят, завершение авторизации...';
            statusText.style.color = '#10b981';
            setTimeout(async () => {
                const check = await tgFetch('/api/channels/telegram_personal.php?action=status');
                const checkData = await check.json();
                if (checkData.connected && checkData.user) {
                    showTgSuccess(checkData.user);
                }
            }, 1000);
        } else {
            statusText.innerText = 'Ошибка: ' + (data.error || 'Неверный пароль');
            statusText.style.color = '#ef4444';
        }
    } catch (err) {
        statusText.innerText = 'Сетевая ошибка при проверке пароля';
        statusText.style.color = '#ef4444';
    }
}

// Отправка кода на телефон
async function sendTgPhoneCode() {
    const phoneInput = document.getElementById('tg-phone-number');
    const statusText = document.getElementById('tg-phone-status-text');
    const btn = document.getElementById('tg-btn-send-code');
    const phone = phoneInput.value.trim();

    if (!phone) {
        statusText.innerText = 'Введите номер телефона';
        statusText.style.color = '#ef4444';
        return;
    }

    btn.disabled = true;
    btn.innerText = 'Отправка кода...';
    statusText.innerText = '';

    try {
        const response = await tgFetch('/api/channels/telegram_personal.php?action=phone_send_code', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone: phone })
        });
        const data = await response.json();

        if (data.ok) {
            tgPhoneCodeHash = data.phone_code_hash;
            document.getElementById('tg-code-block').style.display = 'block';
            btn.style.display = 'none';
            statusText.innerText = 'Код подтверждения успешно отправлен!';
            statusText.style.color = '#10b981';
            document.getElementById('tg-auth-code').focus();
        } else {
            statusText.innerText = 'Ошибка: ' + (data.error || 'Не удалось отправить код');
            statusText.style.color = '#ef4444';
            btn.disabled = false;
            btn.innerText = 'Получить код';
        }
    } catch (err) {
        console.error("Ошибка при отправке кода:", err);
        statusText.innerText = 'Сетевая ошибка при отправке кода';
        statusText.style.color = '#ef4444';
        btn.disabled = false;
        btn.innerText = 'Получить код';
    }
}

// Ввод кода и завершение авторизации по телефону
async function submitTgPhoneCode() {
    const phone = document.getElementById('tg-phone-number').value.trim();
    const code = document.getElementById('tg-auth-code').value.trim();
    const password = document.getElementById('tg-2fa-password').value.trim();
    const statusText = document.getElementById('tg-phone-status-text');
    const btn = document.getElementById('tg-btn-submit-code');

    if (!code) {
        statusText.innerText = 'Введите код подтверждения';
        statusText.style.color = '#ef4444';
        return;
    }

    btn.disabled = true;
    btn.innerText = 'Проверка кода...';

    try {
        const response = await tgFetch('/api/channels/telegram_personal.php?action=phone_sign_in', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                phone: phone,
                phone_code_hash: tgPhoneCodeHash,
                code: code,
                password: password
            })
        });
        const data = await response.json();

        if (data.ok && data.connected && data.user) {
            showTgSuccess(data.user);
        } else if (data.needs_2fa) {
            document.getElementById('tg-2fa-block').style.display = 'block';
            statusText.innerText = 'На аккаунте включена 2FA. Введите облачный пароль.';
            statusText.style.color = '#f59e0b';
            btn.disabled = false;
            btn.innerText = 'Войти с паролем 2FA';
            document.getElementById('tg-2fa-password').focus();
        } else {
            statusText.innerText = 'Ошибка: ' + (data.error || 'Неверный код');
            statusText.style.color = '#ef4444';
            btn.disabled = false;
            btn.innerText = 'Войти и подключить';
        }
    } catch (err) {
        statusText.innerText = 'Сетевая ошибка при авторизации';
        statusText.style.color = '#ef4444';
        btn.disabled = false;
        btn.innerText = 'Войти и подключить';
    }
}

// Экран успеха Telegram
function showTgSuccess(user) {
    switchTgMode('success');
    const info = document.getElementById('tg-success-user-info');
    const usernameStr = user.username ? `@${user.username}` : (user.phone || `ID: ${user.id}`);
    info.innerHTML = `Подключен профиль: <strong style="color: var(--text-primary);">${escapeHtml(user.fullName)}</strong> (${escapeHtml(usernameStr)})`;
}

// Динамический показ полей при выборе платформы
function handleChannelTypeChange() {
    const type = document.getElementById('channel-type').value;

    if (type === 'telegram_personal') {
        closeAddChannelModal();
        openTelegramConnectModal();
        return;
    }

    // Прячем все блоки
    const fields = document.querySelectorAll('.channel-fields');
    fields.forEach(f => f.style.display = 'none');

    // Показываем нужный блок
    const targetFieldBlock = document.getElementById(`fields-${type}`);
    if (targetFieldBlock) {
        targetFieldBlock.style.display = 'block';

        // Делаем все поля ввода внутри этого блока required
        const inputs = targetFieldBlock.querySelectorAll('input');
        inputs.forEach(i => {
            if (i.id !== 'vk-secret') {
                i.required = true;
            }
        });
    }

    // Убираем required у полей ввода других скрытых блоков
    const hiddenFields = document.querySelectorAll(`.channel-fields:not(#fields-${type})`);
    hiddenFields.forEach(f => {
        const inputs = f.querySelectorAll('input');
        inputs.forEach(i => i.required = false);
    });
}

// Сохранить новый канал
async function saveChannel() {
    const submitBtn = document.getElementById('modal-submit-btn');

    if (submitBtn.innerText === 'Закрыть') {
        closeAddChannelModal();
        return;
    }

    const name = document.getElementById('channel-name').value.trim();
    const type = document.getElementById('channel-type').value;

    let settings = {};
    if (type === 'telegram') {
        settings.token = document.getElementById('tg-token').value.trim();
    } else if (type === 'vk') {
        settings.access_token = document.getElementById('vk-token').value.trim();
        settings.secret_key = document.getElementById('vk-secret').value.trim();
        settings.confirmation_code = document.getElementById('vk-confirm').value.trim();
    } else if (type === 'whatsapp') {
        settings.access_token = document.getElementById('wa-token').value.trim();
        settings.phone_number_id = document.getElementById('wa-phone-id').value.trim();
        settings.verify_token = document.getElementById('wa-verify').value.trim();
    } else if (type === 'instagram') {
        settings.access_token = document.getElementById('ig-token').value.trim();
        settings.instagram_account_id = document.getElementById('ig-account-id').value.trim();
        settings.verify_token = document.getElementById('ig-verify').value.trim();
    } else if (type === 'max') {
        settings.access_token = document.getElementById('max-token').value.trim();
        settings.verify_token = document.getElementById('max-verify').value.trim();
    }

    submitBtn.innerText = 'Проверка API и подключение...';
    submitBtn.disabled = true;
    document.getElementById('modal-cancel-btn').style.display = 'none';

    try {
        const response = await tgFetch('/api/channels/add.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                name: name,
                type: type,
                settings: settings
            })
        });

        const data = await response.json();

        if (data.ok) {
            if (data.requires_manual_webhook) {
                document.getElementById('webhook-display-url').innerText = data.webhook_url;
                if (data.verify_token) {
                    document.getElementById('webhook-verify-token-row').style.display = 'block';
                    document.getElementById('webhook-display-token').innerText = data.verify_token;
                } else {
                    document.getElementById('webhook-verify-token-row').style.display = 'none';
                }

                document.getElementById('webhook-info-box').style.display = 'block';
                submitBtn.innerText = 'Закрыть';
                submitBtn.disabled = false;
            } else {
                closeAddChannelModal();
            }
        } else {
            alert("Ошибка подключения канала:\n" + data.error);
            submitBtn.innerText = 'Подключить';
            submitBtn.disabled = false;
            document.getElementById('modal-cancel-btn').style.display = 'block';
        }

    } catch (err) {
        console.error("Ошибка при сохранении канала:", err);
        alert("Ошибка сети при сохранении канала");
        submitBtn.innerText = 'Подключить';
        submitBtn.disabled = false;
        document.getElementById('modal-cancel-btn').style.display = 'block';
    }
}

// Удалить канал
async function deleteChannel(channelId) {
    if (!confirm("Вы уверены, что хотите удалить этот канал? Это также приведет к удалению всех связанных чатов и истории переписки.")) {
        return;
    }

    try {
        const response = await tgFetch('/api/channels/delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                channel_id: channelId
            })
        });

        const data = await response.json();

        if (data.ok) {
            loadChannels();
        } else {
            alert("Ошибка при удалении канала:\n" + data.error);
        }
    } catch (err) {
        console.error("Ошибка при удалении канала:", err);
        alert("Ошибка сети при удалении канала");
    }
}

// Загрузить список чатов с сервера
async function loadChats() {
    try {
        const response = await tgFetch('/api/chats/list.php');
        const data = await response.json();
        if (data.ok) {
            chatsList = data.chats;
            renderChats();

            // Автоматическое открытие чата по переданному chat_id
            if (initialChatIdToOpen) {
                const target = chatsList.find(c => String(c.id) === String(initialChatIdToOpen));
                if (target) {
                    initialChatIdToOpen = null; // Сбрасываем, чтобы не сбивать навигацию пользователя
                    selectChat(target.id, target.client_name, target.channel_type);
                }
            }
        }
    } catch (err) {
        console.error("Ошибка загрузки списка чатов:", err);
    }
}

// Рендер списка чатов в боковую панель
function renderChats() {
    const container = document.getElementById('chat-list');
    const searchInput = document.getElementById('search-input');
    const searchVal = searchInput ? searchInput.value.toLowerCase() : '';
    if (!container) return;

    container.innerHTML = '';

    const filtered = chatsList.filter(chat =>
        chat.client_name.toLowerCase().includes(searchVal) ||
        (chat.last_message_text && chat.last_message_text.toLowerCase().includes(searchVal))
    );

    if (filtered.length === 0) {
        if (searchVal !== '') {
            container.innerHTML = '<div style="text-align: center; color: var(--text-secondary); padding: 20px; font-size: 13px;">Диалоги не найдены</div>';
        } else if (userChannelsCount === 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 28px 16px; color: var(--text-secondary);">
                    <div style="font-size: 32px; margin-bottom: 10px;">🔌</div>
                    <div style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 6px;">Каналы не подключены</div>
                    <div style="font-size: 12px; line-height: 1.5; margin-bottom: 16px;">Подключите первый канал связи для приема диалогов.</div>
                    <button type="button" class="add-channel-btn" style="padding: 10px 16px; font-size: 13px;" onclick="toggleChannelsView()">+ Подключить канал</button>
                </div>
            `;
        } else {
            container.innerHTML = `
                <div style="text-align: center; color: var(--text-secondary); padding: 30px 16px; font-size: 13px;">
                    <div style="font-size: 28px; margin-bottom: 8px;">⏳</div>
                    <div>Ожидание сообщений от клиентов...</div>
                </div>
            `;
        }
        return;
    }

    filtered.forEach(chat => {
        const isActive = chat.id == currentChatId;
        const initials = chat.client_name.slice(0, 2);
        const hasUnread = chat.unread_count > 0;
        const isPending = chat.status === 'pending';
        const isRejected = chat.status === 'rejected';

        const timeStr = chat.last_message_at
            ? new Date(chat.last_message_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})
            : '';

        let statusBadge = '';
        if (isPending) {
            statusBadge = '<span style="background: rgba(245, 158, 11, 0.2); color: #f59e0b; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-left: 6px; font-weight: 600;">Заявка</span>';
        } else if (isRejected) {
            statusBadge = '<span style="background: rgba(239, 68, 68, 0.2); color: #ef4444; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-left: 6px; font-weight: 600;">Отклонен</span>';
        }

        const item = document.createElement('div');
        item.className = `chat-item ${isActive ? 'active' : ''}`;
        item.onclick = () => selectChat(chat.id, chat.client_name, chat.channel_type);

        item.innerHTML = `
            <div class="avatar">
                ${initials}
                <span class="channel-badge ${chat.channel_type}">${chat.channel_type.slice(0, 2)}</span>
            </div>
            <div class="chat-details">
                <div class="chat-name-row">
                    <span class="chat-name">${escapeHtml(chat.client_name)}${statusBadge}</span>
                    <span class="chat-time">${timeStr}</span>
                </div>
                <div class="chat-last-message-row">
                    <span class="chat-last-message">${escapeHtml(chat.last_message_text || 'Нет сообщений')}</span>
                    ${hasUnread ? `<span class="unread-badge">${chat.unread_count}</span>` : ''}
                </div>
            </div>
        `;
        container.appendChild(item);
    });
}

// Выбор чата для общения
function selectChat(chatId, clientName, channelType) {
    currentChatId = chatId;

    // Скрываем настройки каналов и заглушку
    document.getElementById('channels-panel').style.display = 'none';
    document.getElementById('empty-state').style.display = 'none';
    document.getElementById('chat-content').style.display = 'flex';

    // Адаптивная мобильная навигация
    document.getElementById('chat-window').classList.add('active');
    document.getElementById('sidebar').classList.add('hidden');

    if (tg) {
        tg.BackButton.show();
    }

    // Обновляем шапку чата
    document.getElementById('header-name').innerText = clientName;
    document.getElementById('header-channel').innerText = channelType.charAt(0).toUpperCase() + channelType.slice(1);
    document.getElementById('header-avatar').innerText = clientName.slice(0, 2);

    // Обновляем модерацию
    const currentChat = chatsList.find(c => c.id == chatId);
    const modContainer = document.getElementById('header-moderation');
    const modBadge = document.getElementById('moderation-badge');
    const btnApprove = document.getElementById('btn-approve-chat');
    const btnReject = document.getElementById('btn-reject-chat');

    if (currentChat && currentChat.status === 'pending') {
        modContainer.style.display = 'flex';
        modBadge.style.display = 'inline-block';
        modBadge.style.background = 'rgba(245, 158, 11, 0.15)';
        modBadge.style.color = '#f59e0b';
        modBadge.style.borderColor = 'rgba(245, 158, 11, 0.3)';
        modBadge.innerText = 'На модерации';
        btnApprove.style.display = 'inline-flex';
        btnApprove.innerText = '✅ Одобрить';
        btnReject.style.display = 'inline-flex';
    } else if (currentChat && currentChat.status === 'rejected') {
        modContainer.style.display = 'flex';
        modBadge.style.display = 'inline-block';
        modBadge.style.background = 'rgba(239, 68, 68, 0.15)';
        modBadge.style.color = '#ef4444';
        modBadge.style.borderColor = 'rgba(239, 68, 68, 0.3)';
        modBadge.innerText = 'Отклонен';
        btnApprove.style.display = 'inline-flex';
        btnApprove.innerText = '✅ Возобновить';
        btnReject.style.display = 'none';
    } else {
        modContainer.style.display = 'none';
    }

    renderChats();

    // Запуск опроса сообщений
    if (pollingInterval) clearInterval(pollingInterval);
    loadMessages();
    pollingInterval = setInterval(loadMessages, 2000);
}

// Модерация чата
async function moderateCurrentChat(newStatus) {
    if (!currentChatId) return;
    const confirmMsg = newStatus === 'active'
        ? 'Одобрить запрос клиента на диалог?'
        : 'Отклонить запрос клиента на диалог?';
    if (!confirm(confirmMsg)) return;

    try {
        const response = await tgFetch('/api/chats/update_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                chat_id: currentChatId,
                status: newStatus
            })
        });
        const data = await response.json();
        if (data.ok) {
            await loadChats();
            const updatedChat = chatsList.find(c => c.id == currentChatId);
            if (updatedChat) {
                selectChat(updatedChat.id, updatedChat.client_name, updatedChat.channel_type);
            }
        } else {
            alert('Ошибка обновления статуса: ' + data.error);
        }
    } catch (err) {
        console.error('Ошибка при модерации чата:', err);
        alert('Сетевая ошибка при изменении статуса чата');
    }
}

// Загрузить историю сообщений с сервера
async function loadMessages() {
    if (!currentChatId) return;

    try {
        const response = await tgFetch(`/api/chats/messages.php?chat_id=${currentChatId}`);
        const data = await response.json();
        if (data.ok) {
            renderMessages(data.messages);
        }
    } catch (err) {
        console.error("Ошибка загрузки истории сообщений:", err);
    }
}

// Рендер сообщений
function renderMessages(messages) {
    const container = document.getElementById('messages-container');
    if (!container) return;

    const isScrolledToBottom = container.scrollHeight - container.clientHeight <= container.scrollTop + 50;
    const currentCount = container.children.length;

    if (currentCount !== messages.length) {
        container.innerHTML = '';

        messages.forEach(msg => {
            const wrapper = document.createElement('div');
            wrapper.className = `message-wrapper ${msg.direction}`;

            const timeStr = new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

            wrapper.innerHTML = `
                <div class="message-bubble">
                    ${escapeHtml(msg.text)}
                </div>
                <div class="message-meta">
                    ${timeStr}
                </div>
            `;
            container.appendChild(wrapper);
        });

        container.scrollTop = container.scrollHeight;
    } else if (isScrolledToBottom) {
        container.scrollTop = container.scrollHeight;
    }
}

// Отправка ответа оператора
async function sendMessage() {
    const input = document.getElementById('message-input');
    if (!input) return;

    const text = input.value.trim();
    if (!text || !currentChatId) return;

    input.value = '';
    input.style.height = '48px';

    try {
        const response = await tgFetch('/api/send_message.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                chat_id: currentChatId,
                text: text
            })
        });

        const data = await response.json();
        if (data.ok) {
            loadMessages();
            loadChats();
        } else {
            alert("Ошибка при отправке: " + data.error);
        }
    } catch (err) {
        console.error("Ошибка отправки:", err);
    }
}

// Отправка по Enter без Shift
function handleKeyPress(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

// Фильтрация чатов при поиске
function filterChats(value) {
    renderChats();
}

// Безопасный вывод HTML
function escapeHtml(text) {
    return String(text || '')
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
