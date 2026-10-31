#!/bin/sh
set -eu
cd /var/www/html
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/public \
    storage/app/private \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache
php artisan package:discover --ansi
if [ ! -f .env ]; then
    touch .env
fi
if [ ! -e public/storage ]; then
    php artisan storage:link
fi
chown -R www-data:www-data storage bootstrap/cache
exec "$@"
