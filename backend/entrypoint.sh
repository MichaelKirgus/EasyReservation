#!/bin/sh
set -e

ROLE="${CONTAINER_ROLE:-backend}"

# --- Common: cron heartbeat ---
if [ "$LOG_LEVEL" = "debug" ]; then
  HEARTBEAT_CMD="php /var/www/artisan worker:heartbeat --verbose && touch /tmp/health_heartbeat"
else
  HEARTBEAT_CMD="php /var/www/artisan worker:heartbeat && touch /tmp/health_heartbeat"
fi
echo "* * * * * $HEARTBEAT_CMD" > /etc/crontabs/root
crond &

echo "Starting container with role: $ROLE"

case "$ROLE" in
  backend)
    # Backend: serve via PHP-FPM + Nginx on port 8080
    php-fpm &
    nginx -g "daemon off;"
    ;;

  worker)
    # Worker: run queue worker + health check server on port 8081
    php -S 0.0.0.0:8081 /var/www/health-check.php &
    if [ "$LOG_LEVEL" = "debug" ]; then
      php artisan queue:work --queue=default,heartbeat,work --verbose
    else
      php artisan queue:work --queue=default,heartbeat,work
    fi
    ;;

  scheduler)
    # Scheduler: run schedule worker + health check server on port 8081
    php -S 0.0.0.0:8081 /var/www/health-check.php &
    if [ "$LOG_LEVEL" = "debug" ]; then
      php artisan schedule:work --verbose
    else
      php artisan schedule:work
    fi
    ;;

  *)
    echo "Error: Unknown CONTAINER_ROLE '$ROLE'. Expected: backend, worker, or scheduler."
    exit 1
    ;;
esac
