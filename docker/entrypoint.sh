#!/bin/sh
set -e

# Campfire keeps all of its state under storage/. Make sure the directories
# exist before the database connection is opened, because SQLite refuses to
# create a database inside a missing directory.
mkdir -p storage/db storage/files storage/backups var

# The application cannot sign a session or a hub token without its secrets, and
# the image holds none. Fail here rather than serve requests that cannot work.
if [ -z "${APP_SECRET:-}" ] || [ -z "${MERCURE_JWT_SECRET:-}" ]; then
    echo "APP_SECRET and MERCURE_JWT_SECRET must be set." >&2
    echo "Run 'bin/console campfire:generate-secrets' to write them to .env.local." >&2
    exit 1
fi

# In development the source is mounted over the image, so the compiled container
# and the Twig cache of a previous start can disagree with the code. The server
# worker keeps its container in memory, so dropping the cache here means the next
# worker restart reads the code again.
if [ "${APP_ENV:-prod}" = "dev" ]; then
    # Both directories hold a part of the boot: var/build the compiled
    # container, var/cache the rest. See App\Kernel.
    rm -rf var/cache/dev var/build/dev

    # Development serves the assets from the source through AssetMapper. A
    # compiled directory left by a production build would be served as static
    # files instead, and would hide every change to the sources.
    rm -rf public/assets
fi

# Apply pending migrations before serving. The step is idempotent, so a restart
# is cheap. The production image already holds the compiled assets and a warmed
# cache, so nothing has to be built here.
if [ "${SKIP_MIGRATIONS:-0}" != "1" ]; then
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

exec frankenphp run --config /etc/caddy/Caddyfile "$@"
