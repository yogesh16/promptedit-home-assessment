#!/bin/sh
set -e

cd /var/www/html

if [ "${RUN_SETUP:-0}" = "1" ]; then
    if [ ! -f .env ]; then
        cp .env.example .env
    fi

    if [ ! -f vendor/autoload.php ] || { [ -f composer.lock ] && [ composer.lock -nt vendor/composer/installed.php ]; }; then
        composer install --no-interaction --prefer-dist --optimize-autoloader
    fi

    if ! grep -q '^APP_KEY=base64:' .env; then
        php artisan key:generate --force
    fi

    if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
        php artisan migrate --force
        php artisan db:seed --class=AdminUserSeeder --force
    fi
fi

exec "$@"
