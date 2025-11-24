#!/bin/sh

# Start Laravel application
cd /var/www/html

# Ensure .env file exists
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Always ensure APP_KEY is set (even if .env exists but key is missing)
if ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
    echo "Generating application encryption key..."
    php artisan key:generate --force
    if [ $? -ne 0 ]; then
        echo "Warning: Failed to generate APP_KEY, attempting manual generation..."
        # Fallback: generate key manually
        KEY=$(php -r "echo 'base64:' . base64_encode(random_bytes(32));")
        if grep -q "^APP_KEY=" .env; then
            sed -i "s/^APP_KEY=.*/APP_KEY=${KEY}/" .env
        else
            echo "APP_KEY=${KEY}" >> .env
        fi
    fi
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
