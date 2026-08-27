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
    <style>
        :root {
            --bg-color: #0b0e14;
            --sidebar-color: #121824;
            --card-color: #1a2235;
            --card-hover: #222d46;
            --accent-color: #3b82f6;
            --accent-gradient: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --border-color: #232d42;
            --incoming-bg: #232c3f;
            --outgoing-bg: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
            --scrollbar-thumb: #2d3b56;
            --scrollbar-track: #0e131d;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-primary);
            height: 100dvh;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .app-container {
            width: 100vw;
            height: 100dvh;
            display: flex;
            background: radial-gradient(circle at top right, #1e1b4b 0%, var(--bg-color) 60%);
        }

        /* Sidebar / Chat list */
        .sidebar {
            width: 360px;
            height: 100%;
            background-color: rgba(18, 24, 36, 0.85);
            border-right: 1px solid var(--border-color);
            backdrop-filter: blur(16px);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 10;
        }

        .sidebar-header {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .brand::before {
            content: '';
            display: inline-block;
            width: 12px;
            height: 12px;
            background: var(--accent-gradient);
            border-radius: 50%;
            box-shadow: 0 0 10px rgba(59, 130, 246, 0.5);
        }

        .search-container {
            padding: 16px 20px;
            position: relative;
        }

        .search-input {
            width: 100%;
            background-color: rgba(26, 34, 53, 0.6);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 12px 16px;
            color: var(--text-primary);
            font-size: 14px;
            outline: none;
            transition: all 0.2s ease;
        }

        .search-input:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25);
            background-color: var(--card-color);
        }

        .chat-list {
            flex: 1;
            overflow-y: auto;
            padding: 0 12px 12px 12px;
        }

        .chat-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-bottom: 8px;
            position: relative;
            border: 1px solid transparent;
        }

        .chat-item:hover {
            background-color: rgba(34, 45, 70, 0.4);
            border-color: rgba(59, 130, 246, 0.1);
        }

        .chat-item.active {
            background-color: var(--card-color);
            border-color: var(--border-color);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .avatar {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: var(--accent-gradient);
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600;
            color: white;
            font-size: 16px;
            text-transform: uppercase;
            position: relative;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(59, 130, 246, 0.25);
        }

        .channel-badge {
            position: absolute;
            bottom: -4px;
            right: -4px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background-color: #2563eb;
            font-size: 10px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 700;
            border: 2px solid var(--bg-color);
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .channel-badge.telegram { background-color: #24A1DE; }
        .channel-badge.whatsapp { background-color: #25D366; }
        .channel-badge.vk { background-color: #4C75A3; }
        .channel-badge.instagram { background-color: #E1306C; }
        .channel-badge.max { background-color: #f59e0b; }

        .chat-details {
            flex: 1;
            min-width: 0;
        }

        .chat-name-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }

        .chat-name {
            font-weight: 600;
            font-size: 15px;
            color: var(--text-primary);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .chat-time {
            font-size: 11px;
            color: var(--text-secondary);
            flex-shrink: 0;
        }

        .chat-last-message-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chat-last-message {
            font-size: 13px;
            color: var(--text-secondary);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            margin-right: 8px;
        }

        .unread-badge {
            background: var(--accent-gradient);
            color: white;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 20px;
            min-width: 20px;
            text-align: center;
            box-shadow: 0 2px 6px rgba(59, 130, 246, 0.4);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        /* Chat View Window */
        .chat-window {
            flex: 1;
            height: 100%;
            display: flex;
            flex-direction: column;
            background-color: transparent;
            position: relative;
        }

        .empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: var(--text-secondary);
            text-align: center;
            padding: 40px;
        }

        .empty-icon {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.3;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .empty-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .chat-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            background-color: rgba(18, 24, 36, 0.4);
            backdrop-filter: blur(12px);
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 5;
        }

        .header-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--accent-gradient);
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600;
            font-size: 14px;
        }

        .header-name {
            font-weight: 600;
            font-size: 16px;
        }

        .header-status {
            font-size: 12px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 2px;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background-color: #10b981;
            box-shadow: 0 0 6px #10b981;
        }

        .back-button {
            display: none;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 20px;
            cursor: pointer;
            margin-right: 12px;
        }

        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            background-image: radial-gradient(rgba(59, 130, 246, 0.03) 1px, transparent 1px);
            background-size: 20px 20px;
        }

        .message-wrapper {
            display: flex;
            flex-direction: column;
            max-width: 70%;
        }

        .message-wrapper.incoming {
            align-self: flex-start;
        }

        .message-wrapper.outgoing {
            align-self: flex-end;
            align-items: flex-end;
        }

        .message-bubble {
            padding: 12px 18px;
            border-radius: 18px;
            font-size: 14px;
            line-height: 1.5;
            word-break: break-word;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }

        .message-wrapper.incoming .message-bubble {
            background-color: var(--incoming-bg);
            color: var(--text-primary);
            border-bottom-left-radius: 4px;
            border: 1px solid var(--border-color);
        }

        .message-wrapper.outgoing .message-bubble {
            background: var(--outgoing-bg);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message-meta {
            font-size: 10px;
            color: var(--text-secondary);
            margin-top: 6px;
            padding: 0 4px;
        }

        /* Message input area */
        .input-bar {
            padding: 20px 24px;
            border-top: 1px solid var(--border-color);
            background-color: rgba(18, 24, 36, 0.6);
            backdrop-filter: blur(12px);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .message-form {
            flex: 1;
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .input-textarea {
            flex: 1;
            background-color: rgba(14, 19, 29, 0.8);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 14px 18px;
            color: var(--text-primary);
            font-size: 14px;
            outline: none;
            resize: none;
            height: 48px;
            max-height: 120px;
            transition: all 0.2s ease;
        }

        .input-textarea:focus {
            border-color: var(--accent-color);
            background-color: rgba(14, 19, 29, 1);
        }

        .send-button {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: var(--accent-gradient);
            border: none;
            color: white;
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            transition: all 0.2s ease;
            box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3);
        }

        .send-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(59, 130, 246, 0.4);
        }

        .send-button:active {
            transform: translateY(0);
        }

        .send-button svg {
            width: 20px;
            height: 20px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        /* Scrollbars */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: var(--scrollbar-track);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--scrollbar-thumb);
            border-radius: 10px;
        }

        /* Responsive styling */
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
            }
            .sidebar.hidden {
                transform: translateX(-100%);
                position: absolute;
            }
            .chat-window {
                width: 100%;
                position: absolute;
                top: 0;
                left: 0;
                height: 100dvh;
                background-color: var(--bg-color);
                z-index: 20;
                transform: translateX(100%);
                transition: transform 0.3s ease;
            }
            .chat-window.active {
                transform: translateX(0);
            }
            .back-button {
                display: block;
            }
        /* Access Denied Overlay */
        .access-denied-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100dvh;
            background-color: rgba(11, 14, 20, 0.95);
            backdrop-filter: blur(20px);
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 24px;
        }

        .overlay-card {
            background-color: var(--card-color);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 40px;
            max-width: 420px;
            text-align: center;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);
            animation: slideUp 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .overlay-icon {
            font-size: 56px;
            margin-bottom: 20px;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: inline-block;
        }

        .overlay-title {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 12px;
            color: var(--text-primary);
        }

        .overlay-desc {
            font-size: 14px;
            line-height: 1.6;
            color: var(--text-secondary);
        }

        /* Channels Panel UI Styles */
        .settings-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 20px;
            cursor: pointer;
            transition: color 0.2s ease, transform 0.2s ease;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .settings-btn:hover {
            color: var(--text-primary);
            transform: rotate(45deg);
        }
        .channels-panel {
            flex: 1;
            height: 100%;
            display: none;
            flex-direction: column;
            background-color: transparent;
            z-index: 5;
            padding: 24px;
            overflow-y: auto;
        }
        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 16px;
        }
        .panel-title {
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .close-panel-btn {
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 24px;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        .close-panel-btn:hover {
            transform: scale(1.1);
        }
        .channels-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 24px;
        }
        .channel-card {
            background-color: var(--card-color);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.2s ease;
        }
        .channel-card:hover {
            border-color: rgba(59, 130, 246, 0.3);
            background-color: var(--card-hover);
        }
        .channel-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .channel-type-badge {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            color: white;
            text-transform: uppercase;
        }
        .channel-type-badge.telegram { background-color: #24A1DE; }
        .channel-type-badge.whatsapp { background-color: #25D366; }
        .channel-type-badge.vk { background-color: #4C75A3; }
        .channel-type-badge.instagram { background-color: #E1306C; }
        .channel-type-badge.max { background-color: #f59e0b; }
        
        .channel-card-details {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .channel-card-name {
            font-weight: 600;
            font-size: 15px;
        }
        .channel-card-meta {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .channel-status {
            font-size: 11px;
            padding: 1px 6px;
            border-radius: 10px;
            font-weight: 600;
        }
        .channel-status.connected {
            background-color: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
        .channel-status.disconnected {
            background-color: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        .delete-channel-btn {
            background-color: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .delete-channel-btn:hover {
            background-color: #ef4444;
            color: white;
        }
        .add-channel-btn {
            background: var(--accent-gradient);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 12px 20px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            align-self: flex-start;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
            transition: all 0.2s ease;
        }
        .add-channel-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
        }

        /* Modal styling */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(11, 14, 20, 0.85);
            backdrop-filter: blur(8px);
            z-index: 100;
            justify-content: center;
            align-items: center;
            padding: 16px;
        }
        .modal-content {
            background-color: var(--card-color);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            width: 100%;
            max-width: 480px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            animation: slideUpModal 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes slideUpModal {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .modal-title {
            font-size: 18px;
            font-weight: 700;
        }
        .modal-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 24px;
            cursor: pointer;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 6px;
            font-weight: 500;
        }
        .form-control {
            width: 100%;
            background-color: rgba(14, 19, 29, 0.8);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 10px 14px;
            color: var(--text-primary);
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s ease;
        }
        .form-control:focus {
            border-color: var(--accent-color);
        }
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 24px;
        }
        .btn-secondary {
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 10px;
            padding: 10px 16px;
            cursor: pointer;
        }
        .btn-primary {
            background: var(--accent-gradient);
            border: none;
            color: white;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 600;
            cursor: pointer;
        }
        .instruction-box {
            background-color: rgba(59, 130, 246, 0.05);
            border: 1px dashed rgba(59, 130, 246, 0.2);
            border-radius: 10px;
            padding: 12px;
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 16px;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <!-- Access Denied Overlay -->
    <div class="access-denied-overlay" id="auth-overlay" style="display: none;">
        <div class="overlay-card">
            <div class="overlay-icon">🔒</div>
            <h2 class="overlay-title">Доступ ограничен</h2>
            <p class="overlay-desc">Эта панель доступна исключительно авторизованным операторам внутри встроенного приложения Telegram (WebApp).</p>
        </div>
    </div>

    <div class="app-container">
        <!-- Sidebar / Chat list -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h1 class="brand">Chatgo</h1>
                <button class="settings-btn" onclick="toggleChannelsView()" title="Подключение каналов">⚙️</button>
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
                <div class="channels-list" id="channels-list">
                    <!-- Динамический список подключенных каналов -->
                </div>
                <button class="add-channel-btn" onclick="openAddChannelModal()">
                    <span>+</span> Подключить канал
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
                        <option value="telegram">Telegram Bot</option>
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

    <script>
        const tg = window.Telegram?.WebApp;
        let currentChatId = null;
        let chatsList = [];
        let pollingInterval = null;

        // Вспомогательная функция для запросов с авторизацией WebApp
        async function tgFetch(url, options = {}) {
            if (!options.headers) {
                options.headers = {};
            }
            if (tg && tg.initData) {
                options.headers['X-TG-Init-Data'] = tg.initData;
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
                    // Игнорируем ошибки парсинга
                }
                return response;
            } catch (err) {
                console.error("tgFetch error:", err);
                throw err;
            }
        }

        // Инициализация при загрузке страницы
        document.addEventListener('DOMContentLoaded', () => {
            // Инициализация Telegram WebApp
            if (tg) {
                tg.ready();
                tg.expand();
                
                // Скрываем HTML кнопку назад, так как будем использовать нативную от Telegram
                document.getElementById('back-button').style.display = 'none';
                
                // Настраиваем клик по нативной кнопке назад Telegram
                tg.BackButton.onClick(() => {
                    handleBackAction();
                });
            }

            loadChats();
            // Запуск фонового опроса чатов каждые 3 секунды
            setInterval(loadChats, 3000);

            // Обработка кнопки "Назад" на мобильных устройствах
            document.getElementById('back-button').addEventListener('click', () => {
                handleBackAction();
            });

            // Обработка ввода поиска
            document.getElementById('search-input').addEventListener('input', (e) => {
                filterChats(e.target.value);
            });
        });

        // Единое действие возврата к списку чатов
        function handleBackAction() {
            if (document.getElementById('channels-panel').style.display === 'flex') {
                closeChannelsView();
            } else {
                document.getElementById('chat-window').classList.remove('active');
                document.getElementById('sidebar').classList.remove('hidden');
                currentChatId = null;
                if (pollingInterval) clearInterval(pollingInterval);
                if (tg) {
                    tg.BackButton.hide();
                }
            }
        }

        // --- Управление панелью каналов (JS Logic) ---

        // Открыть панель каналов
        function toggleChannelsView() {
            // Закрываем текущий открытый чат
            currentChatId = null;
            if (pollingInterval) clearInterval(pollingInterval);
            
            // Скрываем чат и empty-state
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
        }

        // Закрыть панель настроек каналов
        function closeChannelsView() {
            document.getElementById('channels-panel').style.display = 'none';
            document.getElementById('empty-state').style.display = 'flex';
            
            // Для адаптивности на мобильных устройствах возвращаемся к списку чатов
            document.getElementById('chat-window').classList.remove('active');
            document.getElementById('sidebar').classList.remove('hidden');

            if (tg) {
                tg.BackButton.hide();
            }
        }

        // Загрузить каналы
        async function loadChannels() {
            const listContainer = document.getElementById('channels-list');
            listContainer.innerHTML = '<div style="text-align: center; color: var(--text-secondary); padding: 20px;">Загрузка каналов...</div>';
            
            try {
                const response = await tgFetch('/api/channels/list.php');
                const data = await response.json();
                
                if (!data.ok) {
                    listContainer.innerHTML = `<div style="text-align: center; color: #ef4444; padding: 20px;">Ошибка: ${data.error}</div>`;
                    return;
                }
                
                listContainer.innerHTML = '';
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

        // Управление модальным окном
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

        // Динамический показ полей при выборе платформы
        function handleChannelTypeChange() {
            const type = document.getElementById('channel-type').value;
            
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
                    // Исключаем опциональный secret для VK
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
            
            // Если кнопка имеет статус "Закрыть", просто закрываем модал
            if (submitBtn.innerText === 'Закрыть') {
                closeAddChannelModal();
                return;
            }

            const name = document.getElementById('channel-name').value.trim();
            const type = document.getElementById('channel-type').value;
            
            // Собираем settings в зависимости от типа
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

            // Меняем статус UI во время сохранения
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
                        // Показываем инструкцию по ручной настройке вебхука
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
                        // Если автоматическая регистрация успешна (Telegram / MAX)
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
                }
            } catch (err) {
                console.error("Ошибка загрузки списка чатов:", err);
            }
        }

        // Рендер списка чатов в боковую панель
        function renderChats() {
            const container = document.getElementById('chat-list');
            const searchVal = document.getElementById('search-input').value.toLowerCase();
            container.innerHTML = '';

            const filtered = chatsList.filter(chat => 
                chat.client_name.toLowerCase().includes(searchVal) || 
                (chat.last_message_text && chat.last_message_text.toLowerCase().includes(searchVal))
            );

            if (filtered.length === 0) {
                container.innerHTML = '<div style="text-align: center; color: var(--text-secondary); padding: 20px; font-size: 13px;">Диалоги не найдены</div>';
                return;
            }

            filtered.forEach(chat => {
                const isActive = chat.id == currentChatId;
                const initials = chat.client_name.slice(0, 2);
                const hasUnread = chat.unread_count > 0;
                
                const timeStr = chat.last_message_at 
                    ? new Date(chat.last_message_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})
                    : '';

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
                            <span class="chat-name">${chat.client_name}</span>
                            <span class="chat-time">${timeStr}</span>
                        </div>
                        <div class="chat-last-message-row">
                            <span class="chat-last-message">${chat.last_message_text || 'Нет сообщений'}</span>
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
            
            // Отображаем окно чата и прячем empty-state
            document.getElementById('empty-state').style.display = 'none';
            document.getElementById('chat-content').style.display = 'flex';
            
            // Адаптивная мобильная анимация (классы применяются только при мобильном CSS)
            document.getElementById('chat-window').classList.add('active');
            document.getElementById('sidebar').classList.add('hidden');

            if (tg) {
                tg.BackButton.show();
            }

            // Обновляем шапку чата
            document.getElementById('header-name').innerText = clientName;
            document.getElementById('header-channel').innerText = channelType.charAt(0).toUpperCase() + channelType.slice(1);
            document.getElementById('header-avatar').innerText = clientName.slice(0, 2);

            // Подсвечиваем активный чат в списке
            const items = document.querySelectorAll('.chat-item');
            items.forEach(item => item.classList.remove('active'));
            renderChats();

            // Сбрасываем старый опрос и запускаем опрос сообщений для выбранного чата
            if (pollingInterval) clearInterval(pollingInterval);
            loadMessages();
            pollingInterval = setInterval(loadMessages, 2000);
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
            const isScrolledToBottom = container.scrollHeight - container.clientHeight <= container.scrollTop + 50;
            
            // Запоминаем текущее количество отрисованных сообщений, чтобы не мигать
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

                // Всегда скроллим вниз при изменении количества сообщений
                container.scrollTop = container.scrollHeight;
            } else if (isScrolledToBottom) {
                // Если мы были внизу и сообщений столько же, просто держим скролл внизу
                container.scrollTop = container.scrollHeight;
            }
        }

        // Отправка ответа оператора
        async function sendMessage() {
            const input = document.getElementById('message-input');
            const text = input.value.trim();
            if (!text || !currentChatId) return;

            // Очищаем ввод сразу
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
            return text
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
    </script>
</body>
</html>
