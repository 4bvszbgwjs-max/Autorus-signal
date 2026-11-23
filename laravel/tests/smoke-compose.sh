#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

image="${1:-homer-octane:local}"
# An exported deployment setting must not override the isolated test configuration.
unset APP_ENV APP_KEY APP_URL HOMER_IMAGE HOMER_HTTP_PORT HOMER_API_TOKEN HOMER_ADMIN_PASSWORD_HASH HOMER_PURGE_EXPIRED
unset POSTGRES_PASSWORD RABBITMQ_PASSWORD RMQ_CREATE_MESSAGE_EXCHANGE
env_file="$(mktemp)"
chmod 600 "$env_file"
project="homer-smoke-$(openssl rand -hex 6)"
compose=(docker compose --env-file "$env_file" -p "$project")

cleanup() {
    local result=$?
    if (( result != 0 )); then
        "${compose[@]}" ps -a >&2 || true
        "${compose[@]}" logs --tail 40 >&2 || true
    fi
    "${compose[@]}" down --volumes >&2 || true
    rm -f "$env_file"
    return "$result"
}
trap cleanup EXIT

{
    printf 'HOMER_IMAGE=%s\n' "$image"
    printf 'HOMER_HTTP_PORT=0\nAPP_ENV=testing\n'
    printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
    printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 24)"
    printf 'RABBITMQ_PASSWORD=%s\n' "$(openssl rand -hex 24)"
    printf 'HOMER_API_TOKEN=%s\n' "$(openssl rand -hex 32)"
} > "$env_file"

"${compose[@]}" up -d --wait --wait-timeout 120
"${compose[@]}" exec -T web php tests/delivery-regression.php
"${compose[@]}" exec -T web php tests/admin-transfer-regression.php
"${compose[@]}" exec -T web php tests/admin-access-regression.php
"${compose[@]}" exec -T web php < tests/smoke-compose.php

# This stack has its own database. Stopping it must affect readiness, not liveness.
"${compose[@]}" stop postgres
ready_status=$("${compose[@]}" exec -T web curl -sS --max-time 10 -o /dev/null -w '%{http_code}' http://127.0.0.1:8000/ready)
test "$ready_status" = 503
"${compose[@]}" exec -T web curl -fsS --max-time 10 -o /dev/null http://127.0.0.1:8000/ping
"${compose[@]}" up -d --wait --wait-timeout 60 postgres
"${compose[@]}" exec -T web curl -fsS --max-time 10 -o /dev/null http://127.0.0.1:8000/ready
printf 'Readiness returns 503 during a database outage and recovers after restart.\n'
