#!/bin/sh
if [ "$LOG_LEVEL" = "debug" ]; then
  echo "* * * * * php /var/www/artisan worker:heartbeat --verbose" > /etc/crontabs/root
  crond &
  php-fpm &
  nginx -g "daemon off;"
else
  echo "* * * * * php /var/www/artisan worker:heartbeat" > /etc/crontabs/root
  crond &
  php-fpm &
  nginx -g "daemon off;"
fi
