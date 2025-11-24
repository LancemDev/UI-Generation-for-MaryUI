#!/bin/bash

# Skylarr Docker Preview Build Script
# This script builds the Docker image for preview containers

set -e

echo "🐳 Building Skylarr Preview Docker Image..."

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo "❌ Docker is not running. Please start Docker and try again."
    exit 1
fi

# Check if preview-reference directory exists
if [ ! -d "../preview-reference" ]; then
    echo "❌ preview-reference directory not found!"
    echo "Please create the preview-reference directory with a clean Laravel app."
    exit 1
fi

# Create preview-base directory if it doesn't exist
if [ ! -d "preview-base" ]; then
    echo "📁 Creating preview-base directory..."
    mkdir -p preview-base
fi

# Copy necessary Laravel files from preview-reference to preview-base
echo "📋 Copying Laravel files from preview-reference to preview-base..."

# Change to preview-reference directory
cd ../preview-reference

# Essential Laravel directories and files
cp -r app ../skylarr/preview-base/
cp -r bootstrap ../skylarr/preview-base/
cp -r config ../skylarr/preview-base/
cp -r database ../skylarr/preview-base/
cp -r public ../skylarr/preview-base/
cp -r resources ../skylarr/preview-base/
cp -r routes ../skylarr/preview-base/
cp -r storage ../skylarr/preview-base/
cp -r vendor ../skylarr/preview-base/

# Essential files
cp artisan ../skylarr/preview-base/
cp composer.json ../skylarr/preview-base/
cp composer.lock ../skylarr/preview-base/
cp package.json ../skylarr/preview-base/
cp bun.lockb ../skylarr/preview-base/ 2>/dev/null || true  # Bun lockfile (optional)
cp vite.config.js ../skylarr/preview-base/
cp .env.example ../skylarr/preview-base/ 2>/dev/null || true

# Return to skylarr directory
cd ../skylarr

# Create a minimal .env for preview
cat > preview-base/.env << EOF
APP_NAME="Skylarr Preview"
APP_ENV=preview
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=sqlite
DB_DATABASE=/var/www/html/database/database.sqlite

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

MEMCACHED_HOST=127.0.0.1

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="\${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_HOST=
PUSHER_PORT=443
PUSHER_SCHEME=https
PUSHER_APP_CLUSTER=mt1

VITE_APP_NAME="\${APP_NAME}"
VITE_PUSHER_APP_KEY="\${PUSHER_APP_KEY}"
VITE_PUSHER_HOST="\${PUSHER_HOST}"
VITE_PUSHER_PORT="\${PUSHER_PORT}"
VITE_PUSHER_SCHEME="\${PUSHER_SCHEME}"
VITE_PUSHER_APP_CLUSTER="\${PUSHER_APP_CLUSTER}"
EOF

# Create database directory
mkdir -p preview-base/database
touch preview-base/database/database.sqlite

# Build the Docker image
echo "🔨 Building Docker image..."
docker build -f docker/Dockerfile.preview -t skylarr-preview:latest .

# Clean up preview-base directory
echo "🧹 Cleaning up..."
rm -rf preview-base

echo "✅ Docker image built successfully!"
echo "📦 Image: skylarr-preview:latest"
echo ""
echo "🚀 You can now start generating previews!"
echo ""
echo "To test the image:"
echo "  docker run -d -p 8001:80 --name test-preview skylarr-preview:latest"
echo "  curl http://localhost:8001/health"
echo "  docker stop test-preview && docker rm test-preview"
