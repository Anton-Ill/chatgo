#!/usr/bin/env bash
set -e

PROJECT_DIR="/www/wwwroot/chatgo.ru"

echo "=== Chatgo Deployment ==="
cd "$PROJECT_DIR"

echo "1. Pulling latest changes..."
git pull origin master

echo "2. Setting permissions..."
chown -R www:www "$PROJECT_DIR" 2>/dev/null || true
find "$PROJECT_DIR" -not -path '*/.*' -not -path '*/node_modules*' -type d -exec chmod 755 {} + 2>/dev/null || true
find "$PROJECT_DIR" -not -path '*/.*' -not -path '*/node_modules*' -type f -exec chmod 644 {} + 2>/dev/null || true
mkdir -p "$PROJECT_DIR/logs"
chmod 775 "$PROJECT_DIR/logs" 2>/dev/null || true
chmod 600 "$PROJECT_DIR/.env" 2>/dev/null || true

# Актуализация системного бота в .env
if [ -f "$PROJECT_DIR/.env" ]; then
    if ! grep -q "^TELEGRAM_BOT_TOKEN=" "$PROJECT_DIR/.env"; then
        echo "TELEGRAM_BOT_TOKEN=" >> "$PROJECT_DIR/.env"
    fi
    if ! grep -q "^TELEGRAM_BOT_USERNAME=" "$PROJECT_DIR/.env"; then
        echo "TELEGRAM_BOT_USERNAME=chatgoservice_bot" >> "$PROJECT_DIR/.env"
    fi
fi

echo "3. Reloading PHP-FPM..."
if systemctl is-active --quiet php-fpm-83; then
    systemctl reload php-fpm-83
elif systemctl is-active --quiet php8.3-fpm; then
    systemctl reload php8.3-fpm
fi

echo "4. Setting up Telegram Personal Gateway (Node.js)..."
if [ -d "$PROJECT_DIR/service-telegram" ]; then
    cd "$PROJECT_DIR/service-telegram"
    if [ ! -d "node_modules" ] || [ package.json -nt node_modules ]; then
        npm install --omit=dev 2>/dev/null || true
    fi
    cd "$PROJECT_DIR"
fi

if [ -f "$PROJECT_DIR/deploy/chatgo-telegram-tunnel.service" ]; then
    cp "$PROJECT_DIR/deploy/chatgo-telegram-tunnel.service" /etc/systemd/system/chatgo-telegram-tunnel.service
    systemctl daemon-reload
    systemctl enable chatgo-telegram-tunnel
    systemctl restart chatgo-telegram-tunnel
fi

if [ -f "$PROJECT_DIR/deploy/chatgo-telegram-personal.service" ]; then
    cp "$PROJECT_DIR/deploy/chatgo-telegram-personal.service" /etc/systemd/system/chatgo-telegram-personal.service
    systemctl daemon-reload
    systemctl enable chatgo-telegram-personal
    systemctl restart chatgo-telegram-personal
fi

# Отключаем старый bot long-polling воркер, если он активен
if systemctl is-active --quiet chatgo-telegram; then
    systemctl stop chatgo-telegram
    systemctl disable chatgo-telegram 2>/dev/null || true
fi

echo "5. Updating DB schema column definitions..."
if [ -f "$PROJECT_DIR/tests/manual/fix_db_schema.php" ]; then
    php "$PROJECT_DIR/tests/manual/fix_db_schema.php" || true
fi

echo "6. Cleaning up legacy test database records (one-time)..."
if [ -f "$PROJECT_DIR/tests/manual/test-cleanup-data.php" ] && [ ! -f "$PROJECT_DIR/logs/.cleanup_done" ]; then
    php "$PROJECT_DIR/tests/manual/test-cleanup-data.php" || true
    touch "$PROJECT_DIR/logs/.cleanup_done"
fi

echo "=== Deployment finished successfully ==="
