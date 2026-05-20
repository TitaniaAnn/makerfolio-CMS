#!/usr/bin/env sh
# Web container entrypoint:
#   1. Wait until MySQL is reachable (compose's healthcheck handles it for
#      orchestrated boots, but this script doubles as a guard for standalone runs).
#   2. Apply pending migrations + bootstrap auth settings from .env.
#   3. exec apache2-foreground (the original CMD).
#
# Failures in step 2 are logged but DO NOT block container startup — the
# admin UI at /admin/migrations/ remains available as the manual fallback.

set -e

if [ -n "$DB_HOST" ] && [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
    echo "[entrypoint] running auto-migrate against $DB_HOST/$DB_NAME"
    php /var/www/html/docker/auto-migrate.php || \
        echo "[entrypoint] auto-migrate exited non-zero — continuing without it"
else
    echo "[entrypoint] DB_* env not set — skipping auto-migrate"
fi

# Seed the uploads/.htaccess into the named volume if it's missing. The
# `uploads` volume in compose.yml shadows the bind-mounted public/uploads/,
# so the .htaccess shipped in the repo doesn't reach the running container
# otherwise. The Dockerfile stashes the canonical at /usr/local/share/ so
# this entrypoint has a stable source path that no volume can hide.
UPLOADS_HTACCESS=/var/www/html/public/uploads/.htaccess
SEED=/usr/local/share/pottery-uploads-htaccess
if [ ! -f "$UPLOADS_HTACCESS" ] && [ -d /var/www/html/public/uploads ] && [ -f "$SEED" ]; then
    cp "$SEED" "$UPLOADS_HTACCESS"
    echo "[entrypoint] seeded uploads/.htaccess (PHP-execution lockdown)"
fi

exec "$@"
