#!/bin/sh
set -e

# Only the web role answers HTTP. The other roles pass.
if [ "$(cat /tmp/role 2>/dev/null)" = "web" ]; then
    wget -q -O /dev/null http://127.0.0.1:8080/up
    php artisan inertia:check-ssr > /dev/null
fi
