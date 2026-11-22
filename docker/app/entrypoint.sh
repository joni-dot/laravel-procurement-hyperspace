#!/bin/sh
# Runs before every command in the app image. For the long-running roles (web, background jobs,
# scheduler) it checks the most dangerous settings and caches the configuration, routes, events and
# views from the environment variables this container was started with.
set -eu

fail() {
    echo "app-entrypoint: $1" >&2
    exit 1
}

check_settings() {
    [ -n "${APP_KEY:-}" ] || fail "APP_KEY is empty. See docs/hosting.md."
    [ -n "${APP_URL:-}" ] || fail "APP_URL is empty. See docs/hosting.md."

    # This image is for servers only. "local" and "testing" switch off protections such as the check
    # that requests are for APP_URL's host name.
    case "${APP_ENV:-}" in
        local | testing) fail "APP_ENV=${APP_ENV} is for development only; use production (or a name such as staging)." ;;
    esac

    # Debug pages show visitors secrets and code, on any server.
    case "${APP_DEBUG:-false}" in
        false | 0 | '' | off | no | '(false)') ;;
        *) fail "APP_DEBUG must be false: debug pages would show visitors secrets and code." ;;
    esac
}

case "$*" in
    "frankenphp run"* | "php artisan queue:work"* | "php artisan schedule:work"*)
        check_settings
        php artisan optimize --no-interaction
        ;;
esac

exec "$@"
