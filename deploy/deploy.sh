#!/usr/bin/env bash
set -e

PROJECT_DIR="/www/wwwroot/chatgo.ru"

echo "=== Chatgo Deployment ==="
cd "$PROJECT_DIR"

echo "1. Pulling latest changes..."
git pull origin master

echo "2. Setting permissions..."
chown -R www:www "$PROJECT_DIR"
find "$PROJECT_DIR" -type d -exec chmod 755 {} +
find "$PROJECT_DIR" -type f -exec chmod 644 {} +
mkdir -p "$PROJECT_DIR/logs"
chmod 775 "$PROJECT_DIR/logs"

echo "3. Reloading PHP-FPM..."
if systemctl is-active --quiet php-fpm-83; then
    systemctl reload php-fpm-83
elif systemctl is-active --quiet php8.3-fpm; then
    systemctl reload php8.3-fpm
fi

echo "=== Deployment finished successfully ==="
