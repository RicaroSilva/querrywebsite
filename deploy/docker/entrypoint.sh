#!/bin/sh
# Container start: wait for the application DB, run migrations + seed (idempotent).
set -e
cd /var/www/querydeck

if [ -z "$APP_KEY" ]; then
  echo "ERROR: APP_KEY is not set. Generate one with:"
  echo "  docker run --rm php:8.3-cli php -r 'echo \"base64:\".base64_encode(random_bytes(32)).PHP_EOL;'"
  exit 1
fi

if [ "${APP_DB_DRIVER:-mysql}" = "mysql" ]; then
  echo "Waiting for database ${APP_DB_HOST}:${APP_DB_PORT:-3306}..."
  i=0
  until php -r 'try { new PDO("mysql:host=".getenv("APP_DB_HOST").";port=".(getenv("APP_DB_PORT")?:3306).";dbname=".getenv("APP_DB_DATABASE"), getenv("APP_DB_USERNAME"), getenv("APP_DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
    i=$((i+1)); [ $i -gt 60 ] && echo "Database not reachable" && exit 1; sleep 2
  done
fi

su -s /bin/sh www-data -c "/usr/local/bin/php bin/console migrate && /usr/local/bin/php bin/console db:seed"
exec "$@"
