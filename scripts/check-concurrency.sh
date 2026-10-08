#!/usr/bin/env bash
# Sends parallel booking requests for the last seat of an event to the running app,
# and checks that exactly one booking is confirmed (BR-B14).
#
# Usage: scripts/check-concurrency.sh [base-url]
#
# The base URL defaults to http://localhost:8080. ARTISAN is the command that runs
# Artisan next to the app (default: docker compose exec -T app php artisan).
# The script deletes its users, event and bookings when it ends.
set -euo pipefail

base_url="${1:-http://localhost:8080}"
read -r -a artisan <<< "${ARTISAN:-docker compose exec -T app php artisan}"
work="$(mktemp -d)"
tag=""
event_id=""

fail() {
    echo "FAIL: $1"
    exit 1
}

tinker() {
    "${artisan[@]}" tinker --execute "$1"
}

cleanup() {
    if [ -n "$event_id" ]; then
        tinker "App\\Models\\Booking::where('event_id', $event_id)->delete(); App\\Models\\Event::whereKey($event_id)->delete();" > /dev/null \
            || echo "WARN: could not delete the event $event_id"
    fi
    if [ -n "$tag" ]; then
        tinker "App\\Models\\User::where('email', 'like', 'concurrency-$tag-%')->delete();" > /dev/null \
            || echo "WARN: could not delete the users of $tag"
    fi
    rm -rf "$work"
}
trap cleanup EXIT

# The value of the XSRF-TOKEN cookie in a cookie jar, URL-decoded.
xsrf_token() {
    local value
    value="$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$1")"
    printf '%b' "${value//%/\\x}"
}

echo "== seed"
seed_output="$("${artisan[@]}" db:seed --class=ConcurrencyCheckSeeder --force --no-interaction)"
tag="$(grep -o 'CONCURRENCY_TAG=[a-z0-9]*' <<< "$seed_output" | cut -d= -f2 || true)"
event_id="$(grep -o 'CONCURRENCY_EVENT_ID=[0-9]*' <<< "$seed_output" | cut -d= -f2 || true)"
[ -n "$tag" ] && [ -n "$event_id" ] || fail "the seeder did not print the tag and the event ID: $seed_output"
attendees="$(tinker "echo Database\\Seeders\\ConcurrencyCheckSeeder::ATTENDEES;" | tail -n 1)"

echo "== log in $attendees users"
for number in $(seq 1 "$attendees"); do
    jar="$work/$number.jar"
    curl -sS -o /dev/null -c "$jar" -b "$jar" "$base_url/login"
    redirect="$(curl -sS -o /dev/null -w '%{redirect_url}' -c "$jar" -b "$jar" \
        -H "X-XSRF-TOKEN: $(xsrf_token "$jar")" -H 'Accept: text/html' \
        --data-urlencode "email=concurrency-$tag-$number@example.test" \
        --data-urlencode 'password=password' \
        "$base_url/login")"
    [ -n "$redirect" ] && [ "$redirect" != "$base_url/login" ] \
        || fail "the login of user $number did not work (redirect: '$redirect')"
    xsrf_token "$jar" > "$work/$number.token"
done

echo "== send $attendees booking requests at the same time"
for number in $(seq 1 "$attendees"); do
    curl -sS -o /dev/null -w '%{http_code}\n' -b "$work/$number.jar" \
        -H "X-XSRF-TOKEN: $(cat "$work/$number.token")" -H 'Accept: text/html' \
        -d quantity=1 "$base_url/events/$event_id/bookings" > "$work/$number.status" &
done
wait

statuses="$(cat "$work"/*.status | sort | uniq -c | tr -s ' ' | tr '\n' ',')"
other="$(cat "$work"/*.status | grep -cv '^302$' || true)"
[ "$other" = 0 ] || fail "some requests did not get 302 (count status:$statuses)"

echo "== check the database"
result="$(tinker "echo App\\Models\\Booking::where('event_id', $event_id)->where('status', 'confirmed')->count().' '.App\\Models\\Event::findOrFail($event_id)->seats_available;" | tail -n 1)"
read -r confirmed seats <<< "$result"
[ "$confirmed" = 1 ] || fail "expected 1 confirmed booking, found $confirmed"
[ "$seats" = 0 ] || fail "expected 0 available seats, found $seats"

echo "PASS: $attendees parallel requests for 1 seat gave 1 confirmed booking."
