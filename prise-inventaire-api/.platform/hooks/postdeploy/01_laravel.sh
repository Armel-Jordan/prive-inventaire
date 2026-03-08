#!/bin/bash

cd /var/app/current

# Fix permissions
chmod -R 777 storage bootstrap/cache

# Run migrations
php artisan migrate --force

# Clear and cache
php artisan config:clear
php artisan route:clear
php artisan view:clear
