#!/bin/sh
echo "* * * * * php /var/www/artisan worker:heartbeat" > /etc/crontabs/root
crond &
php artisan queue:work redis --queue=default,heartbeat,work
