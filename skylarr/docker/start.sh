#!/bin/sh

# Start Laravel application
cd /var/www/html

# Generate application key if not exists
if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate
fi

# Clear all caches to prevent PailServiceProvider errors
rm -rf bootstrap/cache/*.php 2>/dev/null || true
rm -rf storage/framework/cache/* 2>/dev/null || true
php artisan config:clear 2>/dev/null || true
php artisan cache:clear 2>/dev/null || true
php artisan route:clear 2>/dev/null || true
php artisan view:clear 2>/dev/null || true
composer dump-autoload --no-dev --optimize 2>/dev/null || true

# Start supervisor
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
