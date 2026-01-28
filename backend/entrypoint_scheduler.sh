#!/bin/sh
echo "* * * * * php /var/www/artisan worker:heartbeat" > /etc/crontabs/root
crond &
php artisan schedule:work
