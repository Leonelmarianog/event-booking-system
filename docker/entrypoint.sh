#!/bin/sh
set -e

# The first argument selects the role. Any other argument runs as a command,
# for example: docker run <image> php artisan about
role="${1:-web}"

case "$role" in
    web | worker | scheduler | migrate)
        echo "$role" > /tmp/role
        # The config is cached here, because the environment is known only at runtime.
        php artisan optimize
        ;;
esac

case "$role" in
    web) exec supervisord -c /etc/supervisord.conf ;;
    worker) exec php artisan queue:work redis --tries=3 --max-time=3600 ;;
    scheduler) exec php artisan schedule:work ;;
    migrate) exec php artisan migrate --force ;;
    *) exec "$@" ;;
esac
