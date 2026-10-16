#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
fi

# Each container gets its own key.
if ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
    php artisan key:generate --force
fi

chown -R www-data:www-data storage bootstrap/cache .env

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
