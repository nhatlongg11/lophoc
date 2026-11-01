#!/bin/sh
set -eu

# Generate caches from this container's runtime environment, never from build secrets.
# bootstrap/cache is private to each container; shared views and Redis are not cleared.
if [ "${LARAVEL_OPTIMIZE:-1}" = "1" ]; then
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan event:cache --no-interaction
fi

# Keep the PHP image's argument handling and deliver stop signals to the main process.
exec docker-php-entrypoint "$@"
