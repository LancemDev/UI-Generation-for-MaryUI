#!/bin/sh

# Start Laravel application
cd /var/www/html

# Generate application key if not exists
if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate
fi

# Clear caches
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Start supervisor
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
