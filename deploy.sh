#!/bin/sh
# serv00 cron deploy consumer.
#
# The GitHub push webhook (DeployController) can't run git/composer because serv00 disables PHP's
# exec/shell_exec — it just drops storage/deploy.request. This script runs from cron in a real
# shell, sees that flag, and performs the actual pull + dependency refresh + asset publish.
#
# Install once (serv00: `crontab -e`) — runs every minute but only acts when the flag exists,
# so it's a cheap local file check the rest of the time:
#   * * * * * /bin/sh "$HOME/domains/basttyydev.serv00.net/public_html/deploy.sh" >/dev/null 2>&1

set -u

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$APP_DIR" || exit 0

FLAG="$APP_DIR/storage/deploy.request"
LOG="$APP_DIR/storage/logs/deploy.log"
LOCK="$APP_DIR/storage/deploy.lock"

# Nothing requested → done (the common case, kept cheap).
[ -f "$FLAG" ] || exit 0

# Overlap guard: skip if a previous deploy is still running.
if [ -f "$LOCK" ] && kill -0 "$(cat "$LOCK" 2>/dev/null)" 2>/dev/null; then
    exit 0
fi
echo $$ > "$LOCK"
trap 'rm -f "$LOCK"' EXIT

# Consume the flag up-front so a push arriving mid-deploy re-triggers the next tick.
rm -f "$FLAG"

# Cron's PATH resolves `php`/`composer` to the system default (8.3.x), but the app's deps need
# 8.4 — the login shell's php lives at ~/bin/php. Use that interpreter, and run Composer THROUGH
# it (composer's own shebang would otherwise fall back to cron's older php).
PHP="$HOME/bin/php"
[ -x "$PHP" ] || PHP="$(command -v php)"
if [ -x /usr/local/bin/composer ]; then
    COMPOSER="$PHP /usr/local/bin/composer"
elif command -v composer >/dev/null 2>&1; then
    COMPOSER="$PHP $(command -v composer)"
else
    COMPOSER="$PHP composer.phar"
fi

{
    echo "===== deploy $(date) ====="

    BEFORE="$(git rev-parse HEAD 2>/dev/null)"
    if ! git pull --ff-only origin main; then
        echo "git pull failed"
        exit 1
    fi
    AFTER="$(git rev-parse HEAD 2>/dev/null)"

    if [ "$BEFORE" = "$AFTER" ]; then
        echo "already up to date; nothing to do"
        exit 0
    fi

    # Only touch Composer when dependencies actually changed (keeps markdown-only pushes fast).
    if git diff --name-only "$BEFORE" "$AFTER" | grep -qE '^composer\.(json|lock)$'; then
        if [ -f composer.lock ]; then
            $COMPOSER install --no-interaction --no-progress || $COMPOSER update -W --no-interaction --no-progress
        else
            $COMPOSER update -W --no-interaction --no-progress
        fi
    fi

    "$PHP" artisan vendor:publish --tag=atom-assets --force
    echo "===== done $(date) ====="
} >> "$LOG" 2>&1
