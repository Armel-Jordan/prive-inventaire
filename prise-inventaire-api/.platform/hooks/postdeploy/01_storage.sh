#!/bin/bash
cd /var/app/current

# Create directories if they don't exist
mkdir -p storage/logs
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p bootstrap/cache

# Set permissions
chmod -R 775 storage
chmod -R 775 bootstrap/cache
chown -R webapp:webapp storage
chown -R webapp:webapp bootstrap/cache

# Create storage link
php artisan storage:link || true

# Clear and cache
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true
