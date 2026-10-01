#!/bin/sh
set -e
cd /var/www/html

# Apache listens on Railway's $PORT.
PORT="${PORT:-8080}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Storage may be a mounted volume (empty on first boot), so rebuild what Laravel needs.
mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    export DB_DATABASE="${DB_DATABASE:-/var/www/html/storage/app/database.sqlite}"
    touch "$DB_DATABASE"
fi
chown -R www-data:www-data storage bootstrap/cache

php artisan migrate --force
php artisan db:seed --force
[ "${SAFARA_DEMO_DATA:-false}" = "true" ] && php artisan db:seed --class=DemoSeeder --force || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run the scheduler (price holds, passport clean-up) every minute alongside the web server.
( while true; do su -s /bin/sh www-data -c "php artisan schedule:run" >/dev/null 2>&1; sleep 60; done ) &

exec apache2-foreground
