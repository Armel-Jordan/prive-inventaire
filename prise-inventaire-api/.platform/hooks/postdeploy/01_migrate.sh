#!/bin/bash
cd /var/app/current
php artisan migrate --force
php artisan config:cache
php artisan route:cache
