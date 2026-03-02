#!/bin/bash
cd /var/app/current
php artisan storage:link
chmod -R 775 storage
chmod -R 775 bootstrap/cache
