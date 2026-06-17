#!/bin/sh
set -e

cd /var/www/html

# Создаём .env если его нет
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Гарантируем наличие ключа приложения
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
    php artisan key:generate --force
fi

# Убеждаемся что БД-файл существует
touch database/database.sqlite
chown www-data:www-data database/database.sqlite

# Миграции + сидер (безопасный повтор)
php artisan migrate --force
php artisan db:seed --force

# Кэши для продакшена
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Запускаем php-fpm в фоне, nginx на переднем плане
php-fpm -D
exec nginx -g "daemon off;"
