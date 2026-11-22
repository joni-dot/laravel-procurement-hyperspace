#!/bin/sh
# Docker's health check for the web role. The app answers only to APP_URL's host name, so the request
# names that host.
set -eu

host=${APP_URL#*://}
host=${host%%/*}
host=${host%%:*}

exec curl --fail --silent --show-error --max-time 4 --output /dev/null \
    --header "Host: ${host:-localhost}" "http://127.0.0.1:${PORT:-8080}/up"
