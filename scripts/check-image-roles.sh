#!/usr/bin/env bash
# Starts the runtime image in each role and checks that the role works.
#
# Usage: scripts/check-image-roles.sh <image> <env-file>
#
# The env file holds all the settings of the app (APP_KEY, DB_*, REDIS_*, ...).
# DOCKER_NETWORK selects the Docker network of the containers (default: host).
set -euo pipefail

image="$1"
env_file="$2"
network="${DOCKER_NETWORK:-host}"
prefix="role-check-$$"
run=(docker run --network "$network" --env-file "$env_file")

cleanup() {
    docker rm -f "$prefix-web" "$prefix-worker" "$prefix-scheduler" > /dev/null 2>&1 || true
}
trap cleanup EXIT

fail() {
    echo "FAIL: $1"
    for name in web worker scheduler; do
        echo "--- logs of $name"
        docker logs "$prefix-$name" 2>&1 | tail -30 || true
    done
    exit 1
}

echo "== migrate"
"${run[@]}" --rm "$image" migrate || fail "migrate did not end with exit code 0"

echo "== web"
"${run[@]}" -d --name "$prefix-web" "$image" web > /dev/null
for _ in $(seq 1 30); do
    status="$(docker inspect -f '{{.State.Health.Status}}' "$prefix-web")"
    [ "$status" = healthy ] && break
    sleep 3
done
[ "$status" = healthy ] || fail "web is $status, not healthy"
docker exec "$prefix-web" wget -q -O - http://127.0.0.1:8080/login | grep -q 'data-server-rendered="true"' \
    || fail "web did not render /login on the server"

echo "== worker"
"${run[@]}" -d --name "$prefix-worker" "$image" worker > /dev/null
docker exec "$prefix-web" php artisan tinker --execute \
    'Illuminate\Support\Facades\Mail::queue(new Illuminate\Mail\Mailable()->to("check@example.com")->subject("Role check")->html("ok"));'
for _ in $(seq 1 20); do
    docker logs "$prefix-worker" 2>&1 | grep -q 'Mailable .* DONE' && break
    sleep 3
done
docker logs "$prefix-worker" 2>&1 | grep -q 'Mailable .* DONE' || fail "worker did not run the queued job"

echo "== scheduler"
"${run[@]}" -d --name "$prefix-scheduler" "$image" scheduler > /dev/null
sleep 10
[ "$(docker inspect -f '{{.State.Running}}' "$prefix-scheduler")" = true ] || fail "scheduler stopped"

echo "All roles work."
