'use strict';

const express = require('express');
const { TelegramClient } = require('telegram');
const { StringSession } = require('telegram/sessions');
const { NewMessage } = require('telegram/events');
const QRCode = require('qrcode');
const fs = require('fs');
const path = require('path');
const http = require('http');

const PORT = parseInt(process.env.PORT || '3005', 10);
const HOST = process.env.HOST || '127.0.0.1';
const WEBHOOK_URL = process.env.CHATGO_WEBHOOK_URL || 'http://127.0.0.1/api/webhook/telegram_personal.php';
const CHATGO_SECRET = process.env.CHATGO_SECRET || 'CG_Secret_Gate_2026_Secure';

// Настройки SOCKS5 / MTProto прокси (для обхода сетевых ограничений)
const PROXY_HOST = process.env.TELEGRAM_PROXY_HOST || '';
const PROXY_PORT = parseInt(process.env.TELEGRAM_PROXY_PORT || '0', 10);
const PROXY_TYPE = parseInt(process.env.TELEGRAM_PROXY_TYPE || '5', 10);

// Telegram API credentials (стандартные Telegram Android / Desktop или из env)
const API_ID = parseInt(process.env.TELEGRAM_API_ID || '6', 10);
const API_HASH = process.env.TELEGRAM_API_HASH || 'eb06d4abfb49dc3eeb1aeb98ae0f581e';

const SESSION_FILE = path.join(__dirname, 'session.json');

const app = express();
app.use(express.json());

// Глобальное состояние
let client = null;
let currentSessionString = '';
let isConnected = false;
let currentUser = null;

// Состояние QR авторизации
let qrState = {
    inProgress: false,
    qrLink: '',
    qrDataUrl: '',
    expires: 0,
    status: 'idle', // 'idle' | 'waiting' | 'authorized' | 'needs_2fa' | 'expired' | 'error'
    error: null,
    resolvePassword: null
};

// Состояние SMS/Phone авторизации
let phoneState = {
    phone: '',
    phoneCodeHash: ''
};

// Чтение сессии с диска
function loadSession() {
    try {
        if (fs.existsSync(SESSION_FILE)) {
            const raw = fs.readFileSync(SESSION_FILE, 'utf8');
            const data = JSON.parse(raw);
            return data.session || '';
        }
    } catch (e) {
        console.error('Ошибка чтения session.json:', e.message);
    }
    return '';
}

// Сохранение сессии на диск
function saveSession(sessionString, userInfo = null) {
    try {
        currentSessionString = sessionString;
        fs.writeFileSync(SESSION_FILE, JSON.stringify({
            session: sessionString,
            user: userInfo,
            updatedAt: new Date().toISOString()
        }, null, 2), 'utf8');
    } catch (e) {
        console.error('Ошибка сохранения session.json:', e.message);
    }
}

// Очистка сессии
function clearSession() {
    currentSessionString = '';
    currentUser = null;
    isConnected = false;
    try {
        if (fs.existsSync(SESSION_FILE)) {
            fs.unlinkSync(SESSION_FILE);
        }
    } catch (e) {
        console.error('Ошибка удаления session.json:', e.message);
    }
}

// Форматирование данных пользователя
function formatUser(me) {
    if (!me) return null;
    const firstName = me.firstName || '';
    const lastName = me.lastName || '';
    const fullName = [firstName, lastName].filter(Boolean).join(' ') || me.username || 'Telegram User';
    return {
        id: String(me.id || ''),
        firstName: firstName,
        lastName: lastName,
        fullName: fullName,
        username: me.username || '',
        phone: me.phone ? '+' + me.phone.replace(/^\+/, '') : ''
    };
}

// Пересылка входящего сообщения в вебхук Chatgo
async function forwardToWebhook(payload) {
    try {
        const url = new URL(WEBHOOK_URL);
        const postData = JSON.stringify(payload);

        const options = {
            hostname: url.hostname,
            port: url.port || (url.protocol === 'https:' ? 443 : 80),
            path: url.pathname + (url.search || ''),
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(postData),
                'X-Chatgo-Secret': CHATGO_SECRET
            },
            timeout: 8000
        };

        const req = http.request(options, (res) => {
            let resBody = '';
            res.on('data', chunk => { resBody += chunk; });
            res.on('end', () => {
                if (res.statusCode >= 400) {
                    console.error(`[Webhook] Ошибка ответа сервера ${res.statusCode}: ${resBody}`);
                }
            });
        });

        req.on('error', (err) => {
            console.error('[Webhook] Ошибка отправки вебхука:', err.message);
        });

        req.write(postData);
        req.end();
    } catch (err) {
        console.error('[Webhook] Исключение при отправке:', err.message);
    }
}

// Обработчик новых сообщений
async function handleNewMessage(event) {
    try {
        const message = event.message;
        if (!message) return;

        // Фильтруем: только личные диалоги 1-на-1
        if (!event.isPrivate) {
            return;
        }

        const sender = await event.getSender();
        const isOut = Boolean(message.out);
        const peerId = String(event.chatId || (sender && sender.id) || '');

        let clientName = 'Собеседник';
        let clientUsername = '';
        let clientPhone = '';

        if (sender) {
            const fn = sender.firstName || '';
            const ln = sender.lastName || '';
            clientName = [fn, ln].filter(Boolean).join(' ') || sender.username || `ID ${sender.id}`;
            clientUsername = sender.username || '';
            clientPhone = sender.phone ? '+' + sender.phone.replace(/^\+/, '') : '';
        }

        const payload = {
            event: 'message',
            direction: isOut ? 'outgoing' : 'incoming',
            message_id: String(message.id),
            peer_id: peerId,
            client_name: clientName,
            client_username: clientUsername,
            client_phone: clientPhone,
            text: message.message || '',
            date: message.date || Math.floor(Date.now() / 1000)
        };

        console.log(`[MTProto] ${isOut ? 'Исходящее ->' : 'Входящее <-'} [${clientName} (${peerId})]: ${payload.text}`);
        await forwardToWebhook(payload);
    } catch (err) {
        console.error('[MTProto] Ошибка обработки NewMessage:', err);
    }
}

// Инициализация TelegramClient
async function initClient(sessionString = '') {
    if (client) {
        try {
            await client.disconnect();
        } catch (e) {
            // ignore
        }
    }

    const clientOptions = {
        connectionRetries: 5,
        deviceModel: 'Chatgo Gateway',
        appVersion: '1.0.0',
        systemVersion: 'Linux',
        useWSS: false
    };

    if (PROXY_HOST && PROXY_PORT > 0) {
        clientOptions.proxy = {
            ip: PROXY_HOST,
            port: PROXY_PORT,
            socksType: PROXY_TYPE
        };
    }

    const stringSession = new StringSession(sessionString);
    client = new TelegramClient(stringSession, API_ID, API_HASH, clientOptions);

    await client.connect();

    // Регистрируем слушатель сообщений
    client.addEventHandler(handleNewMessage, new NewMessage({}));

    if (await client.isUserAuthorized()) {
        const me = await client.getMe();
        currentUser = formatUser(me);
        isConnected = true;
        saveSession(client.session.save(), currentUser);
        console.log(`[MTProto] Успешно подключен аккаунт: ${currentUser.fullName} (@${currentUser.username || 'нет'}) ID: ${currentUser.id}`);
    } else {
        isConnected = false;
        currentUser = null;
        console.log('[MTProto] Клиент запущен, требуется авторизация.');
    }

    return client;
}

// ================= API РОУТЫ =================

// Проверка секретного ключа для исходящих запросов от PHP
function requireSecret(req, res, next) {
    const secret = req.headers['x-chatgo-secret'];
    if (secret !== CHATGO_SECRET) {
        return res.status(403).json({ ok: false, error: 'Неверный секретный ключ' });
    }
    next();
}

// 1. Статус сервиса
app.get('/api/status', (req, res) => {
    res.json({
        ok: true,
        connected: isConnected,
        user: currentUser,
        qr: {
            inProgress: qrState.inProgress,
            status: qrState.status,
            expires: qrState.expires,
            error: qrState.error
        }
    });
});

// 2. Старт авторизации по QR коду
app.get('/api/qr/start', async (req, res) => {
    try {
        if (isConnected) {
            return res.json({
                ok: true,
                connected: true,
                status: 'authorized',
                user: currentUser
            });
        }

        // Пересоздаем чистый клиент без сессии
        await initClient('');

        qrState = {
            inProgress: true,
            qrLink: '',
            qrDataUrl: '',
            expires: 0,
            status: 'waiting',
            error: null,
            resolvePassword: null
        };

        // Запуск signInUserWithQrCode в фоновом режиме
        client.signInUserWithQrCode(
            { apiId: API_ID, apiHash: API_HASH },
            {
                qrCode: async (code) => {
                    const tokenBase64 = Buffer.from(code.token).toString('base64url');
                    const qrLink = `tg://login?token=${tokenBase64}`;
                    const qrDataUrl = await QRCode.toDataURL(qrLink, {
                        margin: 2,
                        width: 280,
                        color: {
                            dark: '#000000',
                            light: '#ffffff'
                        }
                    });

                    qrState.qrLink = qrLink;
                    qrState.qrDataUrl = qrDataUrl;
                    qrState.expires = code.expires;
                    qrState.status = 'waiting';
                    console.log('[QR] Сгенерирован новый QR-код. Срок действия до:', new Date(code.expires * 1000).toLocaleTimeString());
                },
                password: async (hint) => {
                    qrState.status = 'needs_2fa';
                    console.log('[QR] Требуется 2FA пароль. Подсказка:', hint);
                    return new Promise((resolve) => {
                        qrState.resolvePassword = resolve;
                    });
                },
                onError: async (err) => {
                    console.error('[QR] Ошибка входа по QR:', err.message);
                    qrState.status = 'error';
                    qrState.error = err.message;
                    qrState.inProgress = false;
                }
            }
        ).then(async (user) => {
            const me = user || await client.getMe();
            currentUser = formatUser(me);
            isConnected = true;
            qrState.status = 'authorized';
            qrState.inProgress = false;
            saveSession(client.session.save(), currentUser);
            console.log(`[QR] Авторизация успешна! Пользователь: ${currentUser.fullName}`);
        }).catch((err) => {
            console.error('[QR] Фатальная ошибка QR авторизации:', err.message);
            qrState.status = 'error';
            qrState.error = err.message;
            qrState.inProgress = false;
        });

        // Даем небольшую паузу 300мс, чтобы сработал первый колбэк qrCode
        let waits = 0;
        while (!qrState.qrDataUrl && waits < 10) {
            await new Promise(r => setTimeout(r, 100));
            waits++;
        }

        res.json({
            ok: true,
            status: qrState.status,
            qr_link: qrState.qrLink,
            qr_data_url: qrState.qrDataUrl,
            expires: qrState.expires
        });
    } catch (err) {
        console.error('Ошибка в /api/qr/start:', err);
        res.status(500).json({ ok: false, error: err.message });
    }
});

// 3. Проверка статуса QR авторизации
app.get('/api/qr/status', (req, res) => {
    res.json({
        ok: true,
        connected: isConnected,
        status: qrState.status,
        user: currentUser,
        qr_link: qrState.qrLink,
        qr_data_url: qrState.qrDataUrl,
        expires: qrState.expires,
        error: qrState.error
    });
});

// 4. Отправка SMS / Telegram кода на телефон
app.post('/api/phone/send_code', async (req, res) => {
    try {
        const phone = (req.body.phone || '').trim().replace(/[^\d+]/g, '');
        if (!phone) {
            return res.status(400).json({ ok: false, error: 'Укажите корректный номер телефона' });
        }

        if (!client) {
            await initClient('');
        }

        console.log(`[Phone] Отправка кода подтверждения на номер ${phone}...`);
        const result = await client.sendCode(
            { apiId: API_ID, apiHash: API_HASH },
            phone
        );

        phoneState.phone = phone;
        phoneState.phoneCodeHash = result.phoneCodeHash;

        res.json({
            ok: true,
            phone: phone,
            phone_code_hash: result.phoneCodeHash,
            is_code_via_app: result.isCodeViaApp || false
        });
    } catch (err) {
        console.error('[Phone] Ошибка sendCode:', err);
        res.status(400).json({ ok: false, error: err.message });
    }
});

// 5. Вход по коду телефона (+ пароль 2FA при наличии)
app.post('/api/phone/sign_in', async (req, res) => {
    try {
        const phone = (req.body.phone || phoneState.phone || '').trim();
        const code = (req.body.code || '').trim();
        const phoneCodeHash = req.body.phone_code_hash || phoneState.phoneCodeHash;
        const password = req.body.password || '';

        if (!phone || !code || !phoneCodeHash) {
            return res.status(400).json({ ok: false, error: 'Параметры phone, code и phone_code_hash обязательны' });
        }

        try {
            await client.signInUser(
                { apiId: API_ID, apiHash: API_HASH },
                {
                    phoneNumber: phone,
                    phoneCodeHash: phoneCodeHash,
                    phoneCode: code,
                    password: async () => password
                }
            );
        } catch (signErr) {
            if (signErr.message.includes('SESSION_PASSWORD_NEEDED')) {
                if (!password) {
                    return res.json({
                        ok: false,
                        needs_2fa: true,
                        error: 'Требуется облачный пароль двухфакторной аутентификации (2FA)'
                    });
                }
                // Проверяем облачный пароль
                await client.checkPassword({ password });
            } else {
                throw signErr;
            }
        }

        const me = await client.getMe();
        currentUser = formatUser(me);
        isConnected = true;
        saveSession(client.session.save(), currentUser);

        console.log(`[Phone] Успешная авторизация по телефону! Пользователь: ${currentUser.fullName}`);
        res.json({
            ok: true,
            connected: true,
            user: currentUser
        });
    } catch (err) {
        console.error('[Phone] Ошибка signIn:', err);
        res.status(400).json({ ok: false, error: err.message });
    }
});

// 6. Передача пароля 2FA (для QR сессии)
app.post('/api/password/submit', (req, res) => {
    const password = req.body.password || '';
    if (qrState.resolvePassword) {
        qrState.resolvePassword(password);
        qrState.resolvePassword = null;
        res.json({ ok: true, message: 'Пароль передан' });
    } else {
        res.status(400).json({ ok: false, error: 'Нет активного запроса 2FA пароля' });
    }
});

// 7. Отправка сообщения собеседнику от имени подключенного аккаунта
app.post('/api/send_message', requireSecret, async (req, res) => {
    try {
        if (!isConnected || !client) {
            return res.status(400).json({ ok: false, error: 'Telegram аккаунт не подключен' });
        }

        const peerId = (req.body.peer_id || '').trim();
        const text = (req.body.text || '').trim();

        if (!peerId || !text) {
            return res.status(400).json({ ok: false, error: 'peer_id и text обязательны' });
        }

        // Отправка через GramJS
        const result = await client.sendMessage(peerId, { message: text });

        console.log(`[MTProto] Отправлено сообщение пользователю ${peerId}: ${text}`);
        res.json({
            ok: true,
            message_id: String(result.id)
        });
    } catch (err) {
        console.error('[MTProto] Ошибка sendMessage:', err);
        res.status(500).json({ ok: false, error: err.message });
    }
});

// 8. Отключение и выход из аккаунта
app.post('/api/logout', async (req, res) => {
    try {
        if (client) {
            try {
                // Пытаемся сделать logOut на серверах Telegram
                await client.logOut();
            } catch (e) {
                console.warn('[Logout] Не удалось сделать client.logOut():', e.message);
            }
            try {
                await client.disconnect();
            } catch (e) {
                // ignore
            }
        }
        clearSession();
        console.log('[Logout] Аккаунт отключен, сессия очищена.');
        res.json({ ok: true });
    } catch (err) {
        clearSession();
        res.json({ ok: true, error: err.message });
    }
});

// Запуск сервера
async function main() {
    // 1. Немедленно запускаем HTTP-сервер, чтобы порт 3005 был ВСЕГДА доступен для PHP API
    app.listen(PORT, HOST, () => {
        console.log(`[Server] Chatgo Telegram Personal Service запущен на http://${HOST}:${PORT}`);
        console.log(`[Server] Вебхук URL Chatgo: ${WEBHOOK_URL}`);
        if (PROXY_HOST && PROXY_PORT > 0) {
            console.log(`[Server] MTProto прокси активирован: ${PROXY_HOST}:${PROXY_PORT} (SOCKS${PROXY_TYPE})`);
        }
    });

    // 2. Если есть сохраненная сессия, восстанавливаем подключение в фоне без блокировки сервера
    const savedSession = loadSession();
    if (savedSession) {
        initClient(savedSession).catch(err => {
            console.error('[Init] Ошибка восстановления сохраненной сессии:', err.message);
        });
    } else {
        console.log('[Init] Сохраненная сессия отсутствует. Ожидание авторизации через QR или телефон.');
    }
}

main().catch(err => {
    console.error('Фатальный сбой при запуске:', err);
    process.exit(1);
});
