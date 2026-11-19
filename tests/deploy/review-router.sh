#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
runtime=$(mktemp -d)
pid=
cleanup() { if [[ -n $pid ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi; rm -rf "$runtime"; }
trap cleanup EXIT
mkdir "$runtime/views" "$runtime/sessions"
touch "$runtime/maintenance" "$runtime/db.sqlite"
# shellcheck disable=SC2016 # PHP expressions are literal.
port=$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')
base="http://127.0.0.1:$port"
export MAILFORM_REVIEW_MAINTENANCE="$runtime/maintenance" MAILFORM_REVIEW_SESSIONS="$runtime/sessions"
export APP_ENV=testing APP_KEY='base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=' APP_URL="$base" APP_DEBUG=false
export APP_CONFIG_CACHE="$runtime/config.php" APP_PACKAGES_CACHE="$runtime/packages.php" APP_SERVICES_CACHE="$runtime/services.php"
export APP_ROUTES_CACHE="$runtime/routes.php" APP_EVENTS_CACHE="$runtime/events.php" VIEW_COMPILED_PATH="$runtime/views"
export DB_CONNECTION=sqlite DB_DATABASE="$runtime/db.sqlite" CACHE_STORE=array SESSION_DRIVER=file SESSION_SECURE_COOKIE=false
export SESSION_COOKIE=mailform_theme_review_test SESSION_PATH=/ SESSION_DOMAIN=null
php artisan migrate --force --no-interaction >/dev/null
php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Models\User::create(['email' => 'review@example.test', 'name' => 'Reviewer', 'role' => 'admin', 'password' => 'temporary-fixture']);
PHP
php -S "127.0.0.1:$port" -t public deploy/almalinux10/review-router.php > "$runtime/server.log" 2>&1 &
pid=$!
for _ in {1..30}; do
    if curl -fsS "$base/assets/admin.css" -o "$runtime/css" 2>/dev/null; then break; fi
    sleep 0.1
done
cmp public/assets/admin.css "$runtime/css"
curl -fsS "$base/assets/themes/base.css" -o "$runtime/base.css"
cmp public/assets/themes/base.css "$runtime/base.css"
status() { curl -sS --path-as-is -o /dev/null -w '%{http_code}' "$@"; }
[[ $(status "$base/admin/themes") == 302 ]]
curl -fsS -c "$runtime/cookies" "$base/admin/login" > "$runtime/login"
# shellcheck disable=SC2016 # PHP expressions are literal.
token=$(php -r 'preg_match("/name=\"_token\" value=\"([^\"]+)\"/", file_get_contents($argv[1]), $m); echo $m[1] ?? "";' "$runtime/login")
[[ -n $token ]]
[[ $(status -b "$runtime/cookies" -c "$runtime/cookies" --data-urlencode "_token=$token" \
    --data 'email=review%40example.test&password=temporary-fixture' "$base/admin/login") == 302 ]]
curl -fsS -b "$runtime/cookies" "$base/admin/themes" > "$runtime/themes"
grep -q 'テーマ管理' "$runtime/themes"
[[ -n $(ls -A "$runtime/sessions") ]]
for path in /contact /index.php /admin/history /admin/users /assets/form-postal/SOURCE.txt /storage/other; do
    [[ $(status -b "$runtime/cookies" "$base$path") == 404 ]]
done
for path in /contact/send /confirm /admin/forms /admin/forms/1/preview; do
    [[ $(status -X POST -b "$runtime/cookies" "$base$path") == 404 ]]
done
[[ $(status -H 'Host: example.invalid' "$base/assets/admin.css") == 404 ]]
rm "$runtime/maintenance"
[[ $(status -b "$runtime/cookies" "$base/admin/themes") == 404 ]]
echo 'Review router login, isolated session, allowlist and shutdown checks passed.'
