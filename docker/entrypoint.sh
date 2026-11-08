#!/usr/bin/env bash
# Container start-up for the Airking Inventory API.
#
# Everything here is deliberately done at RUN time, not BUILD time: the config
# cache freezes whatever the environment holds when it is written, and the
# environment (DB credentials, APP_KEY, FRONTEND_URL) only exists once Render
# starts the container.
set -euo pipefail

log() { printf '[entrypoint] %s\n' "$*" >&2; }

cd /var/www/html

# --- Fail fast on missing required configuration ---------------------------
: "${PORT:=10000}"
export PORT

if [ -z "${APP_KEY:-}" ]; then
    log "FATAL: APP_KEY is not set. Generate one with 'php artisan key:generate --show'"
    log "       and add it to the service's environment. Refusing to start: without"
    log "       it every encrypted value and session cookie is unreadable."
    exit 1
fi

# --- Writable paths ---------------------------------------------------------
mkdir -p storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# public/storage must be a symlink into storage/app/public. It is removed from
# the image (it pointed at a Windows dev path), so recreate it every boot.
if [ ! -L public/storage ]; then
    php artisan storage:link --force || log "WARN: storage:link failed; public disk URLs may 404"
fi

# --- Optional: run migrations on boot --------------------------------------
# Read the runtime database environment before any migration.
php artisan config:clear >/dev/null 2>&1 || true

# Render Free has no Shell. Enable this targeted repair from Environment,
# deploy once, then remove the flag after the logs confirm success.
if [ "${RUN_PURCHASE_APPROVAL_REPAIR:-false}" = "true" ]; then
    log "Running branch manager purchase approval permission repair"
    php artisan migrate --path=database/migrations/2026_10_13_000008_restore_branch_manager_purchase_approval_access.php --force --no-interaction
    log "Purchase approval permission repair complete"
fi

# Render Free has no Shell. Same one-shot pattern as the repair above: enable
# from Environment, deploy once, then remove the flag after the logs confirm.
#
# Grants inventory_analyst delivery_receiving (read/create/update) and re-keys
# the /inventory-operation nav bundle to that feature, so a purchasing analyst
# can receive without gaining transfer or issuance rights. Targeted on purpose:
# a blanket `migrate` here would also apply drop_budget_management and the
# transfer data migrations, which are a separate decision.
#
# Safe to leave enabled by accident: the migration returns early when its
# system_settings backup row already exists, so a second run is a no-op rather
# than a second rewrite.
if [ "${RUN_PO_RECEIVING_GRANT:-false}" = "true" ]; then
    log "Granting inventory analyst PO receiving permissions"
    php artisan migrate --path=database/migrations/2026_10_13_000006_grant_po_receiving_permissions.php --force --no-interaction
    log "PO receiving permission grant complete"
fi

# Off by default. With more than one instance running, two containers booting
# together would migrate concurrently. Prefer a Render "Pre-Deploy Command" of
# `php artisan migrate --force`, or flip RUN_MIGRATIONS=true for a single-instance
# service.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    log "RUN_MIGRATIONS=true — running database migrations"
    php artisan migrate --force --no-interaction
fi

# --- Warm the caches --------------------------------------------------------
# clear first: a stale cached config baked into an earlier layer would win over
# the current environment.
php artisan config:clear >/dev/null 2>&1 || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache || true

# The commands above run as root, so the files they just wrote are root-owned.
# Apache's workers run as www-data and need to write into storage/ (logs,
# compiled views, sessions) once requests start arriving.
chown -R www-data:www-data storage bootstrap/cache

log "listening on port ${PORT}"

exec "$@"
