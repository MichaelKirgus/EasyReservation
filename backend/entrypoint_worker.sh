#!/bin/sh
if [ "$LOG_LEVEL" = "debug" ]; then
  echo "* * * * * php /var/www/artisan worker:heartbeat --verbose" > /etc/crontabs/root
  crond &
  php artisan queue:work --queue=default,heartbeat,work --verbose
else
  echo "* * * * * php /var/www/artisan worker:heartbeat" > /etc/crontabs/root
  crond &
  php artisan queue:work --queue=default,heartbeat,work
fi
