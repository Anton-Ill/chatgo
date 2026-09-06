#!/usr/bin/env bash
set -e

PROJECT_DIR="/www/wwwroot/chatgo.ru"

echo "=== Chatgo Deployment ==="
cd "$PROJECT_DIR"

echo "1. Pulling latest changes..."
git pull origin master

echo "2. Setting permissions..."
chown -R www:www "$PROJECT_DIR" 2>/dev/null || true
find "$PROJECT_DIR" -type d -exec chmod 755 {} + 2>/dev/null || true
find "$PROJECT_DIR" -type f -exec chmod 644 {} + 2>/dev/null || true
mkdir -p "$PROJECT_DIR/logs"
chmod 775 "$PROJECT_DIR/logs" 2>/dev/null || true
chmod 600 "$PROJECT_DIR/.env" 2>/dev/null || true

echo "3. Reloading PHP-FPM..."
if systemctl is-active --quiet php-fpm-83; then
    systemctl reload php-fpm-83
elif systemctl is-active --quiet php8.3-fpm; then
    systemctl reload php8.3-fpm
fi

echo "4. Restarting Telegram worker..."
if systemctl is-active --quiet chatgo-telegram; then
    systemctl restart chatgo-telegram
fi

echo "=== Deployment finished successfully ==="
