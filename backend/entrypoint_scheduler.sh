if [ "$LOG_LEVEL" = "debug" ]; then
  echo "* * * * * php /var/www/artisan worker:heartbeat --verbose" > /etc/crontabs/root
  crond &
  php artisan schedule:work --verbose
else
  echo "* * * * * php /var/www/artisan worker:heartbeat" > /etc/crontabs/root
  crond &
  php artisan schedule:work
fi
