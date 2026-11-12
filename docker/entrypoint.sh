#!/bin/sh
set -eu
cd /var/www/html
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
# Shared persistent key; never generate a different key for each container/restart.
php docker/key.php
export APP_KEY="$(cat storage/app/container.key)"
if [ "$(id -u)" = 0 ]; then
    chown -R www-data:www-data storage bootstrap/cache
fi
if [ ! -e public/storage ]; then ln -s /var/www/html/storage/app/public public/storage; fi
if [ "${1:-}" = init ]; then
    php artisan migrate --force --no-interaction
    php docker/initialize.php
    exit 0
fi
if [ "${1:-}" = scheduler ]; then
    exec su -s /bin/sh www-data -c 'php artisan schedule:work --no-interaction'
fi
if [ "${1:-}" = worker ]; then
    exec su -s /bin/sh www-data -c 'php artisan queue:work --queue=auth-mail,default --sleep=3 --tries=3 --timeout=90 --no-interaction'
fi
exec "$@"
