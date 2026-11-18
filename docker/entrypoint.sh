#!/bin/sh
set -e
cd /app

# Without APP_KEY, sessions and encrypted data cannot work: stop now with a clear message.
if [ -z "${APP_KEY:-}" ]; then
    echo "ERROR: APP_KEY is empty. Set it in .env (generate one with: php artisan key:generate --show)." >&2
    exit 1
fi

# One volume (/data) holds everything that must survive a redeploy:
# the SQLite file and Laravel's storage directory. web, queue and scheduler all mount it.
mkdir -p /data/storage/app/public /data/storage/framework/cache /data/storage/framework/sessions \
         /data/storage/framework/views /data/storage/logs
if [ ! -L /app/storage ]; then
    rm -rf /app/storage
    ln -s /data/storage /app/storage
fi
touch "${DB_DATABASE:-/data/database.sqlite}"

# Only the web container migrates, so the three containers never race on the schema.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
