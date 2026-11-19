#!/usr/bin/env bash
# Run inside a fresh AlmaLinux 10.3 test container with MariaDB 11.8, PHP and a verified 2.00.125 fixture.
set -euo pipefail
project=$1
stock=$2
state=/var/lib/mailform-setup
app=/srv/mailform/current
backup=/srv/mailform-import/first
[[ $EUID == 0 && ! -e $state && ! -e /srv/mailform && ! -e /srv/mailform-import && ! -e /var/lib/mysql/mysql ]]
[[ -f $project/vendor/autoload.php && -f $stock/config/app.php ]]
useradd --system --user-group --no-create-home mailform
install -d -m 0700 "$state"
install -d "$app" "$app/bootstrap/cache" "$app/storage/app/private" "$app/storage/framework"/{cache/data,sessions,views} "$app/storage/logs"
cp -R "$project"/{app,artisan,composer.json,config,database,lang,public,resources,routes,vendor} "$app/"
cp "$project"/bootstrap/{app,providers}.php "$app/bootstrap/"
chown -R mailform:mailform "$app/bootstrap/cache" "$app/storage"
install -d -o mysql -g mysql /var/lib/mysql /run/mariadb
mariadb-install-db --no-defaults --user=mysql --datadir=/var/lib/mysql > /tmp/mixed-db-init.log
socket=/var/lib/mysql/mysql.sock
mariadbd --no-defaults --user=mysql --datadir=/var/lib/mysql --socket="$socket" --skip-networking \
    --pid-file=/run/mariadb/mixed.pid --log-error=/run/mariadb/mixed.log &
db_pid=$!
trap 'kill "$db_pid" 2>/dev/null || true; wait "$db_pid" || true' EXIT
db=(mariadb --no-defaults --socket="$socket" --user=root -NB)
for _ in {1..100}; do
    if "${db[@]}" -e 'SELECT 1' >/dev/null 2>&1; then break; fi
    sleep 0.2
done
"${db[@]}" <<'SQL'
CREATE DATABASE mailform_stage CHARACTER SET utf8mb4;
CREATE DATABASE mailform CHARACTER SET utf8mb4;
CREATE USER 'mailform'@'localhost' IDENTIFIED VIA unix_socket;
GRANT ALL ON mailform_stage.* TO 'mailform'@'localhost';
GRANT ALL ON mailform.* TO 'mailform'@'localhost';
SQL
install -d -o root -g mailform -m 0750 /srv/mailform-import "$backup" "$backup/EasyMail"
cp -R "$stock/." "$backup/EasyMail/"
if [[ ! -d $backup/EasyMail/resources/views/theme/garden_example ]]; then
    bash "$project/tests/Fixtures/make-custom-theme.sh" "$backup/EasyMail"
fi
mkdir -p "$backup/EasyMail/storage/app/attach_file"
printf 'fixture' > "$backup/EasyMail/storage/app/attach_file/$(printf 'a%.0s' {1..40}).txt"
chown -R root:mailform "$backup/EasyMail"
chmod -R u=rwX,g=rX,o= "$backup/EasyMail"
cat "$project/tests/Fixtures/easymail-2.01.004.sql" "$project/tests/Fixtures/easymail-mixed-drafts.sql" > "$backup/backup.sql"
chmod 0600 "$backup/backup.sql"
origin=https://garden.example.test
printf '%s\n' "$origin" > "$state/base-url"
target() {
    printf '%s-ready\n' "$1" > "$state/phase"
    cat > "$app/.env" <<ENV
APP_ENV=$2
APP_URL=$3
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
DB_CONNECTION=mariadb
DB_DATABASE=$4
DB_HOST=localhost
DB_USERNAME=mailform
DB_SOCKET=$socket
MAILFORM_DELIVERY_ENABLED=false
ENV
    chmod 0640 "$app/.env"
    chgrp mailform "$app/.env"
    (cd "$app" && runuser -u mailform -- php artisan migrate --force >/dev/null)
}
wrapper=(bash "$project/deploy/almalinux10/import-backup.sh" "$backup/backup.sql" "$backup/EasyMail")
run_import() {
    # Real operator entry point from an unreadable cwd. Print the complete log on failure.
    (cd /root && "${wrapper[@]}") > /tmp/mixed-import.log || { cat /tmp/mixed-import.log; return 1; }
    grep '照合結果　旧フォーム 120 件・旧履歴 120 件' /tmp/mixed-import.log
    verify=$(awk '$1 == "取込記録" {print $2}' /tmp/mixed-import.log)/verify.json
    jq -e '.reconciliation.ok and ([.reconciliation.forms[].source.matched] | add == 120)
        and ([.reconciliation.forms[].files.verified] | add == 1)
        and ([.forms[] | select(.url | startswith("/legacy-draft-"))] | length == 60)
        and (.forms[] | select(.legacy_id == 6) | .issues | any(contains("未対応のHTML")))
        and (.forms[] | select(.legacy_id == 8) | .warnings | any(contains("JSON")))
        and (.forms[] | select(.legacy_id == 9) | .warnings | any(contains("HTMLの装飾")))
        and ([.forms[] | select(.legacy_id > 5 and .legacy_id < 64) | .published] | all(. == false))
        and (.forms[] | select(.legacy_id == 64) | .published)' "$verify" >/dev/null
}
target preview staging http://127.0.0.1:8008 mailform_stage
run_import
[[ $("${db[@]}" mailform_stage -e 'SELECT COUNT(*) FROM forms WHERE published = 1') == 1 ]]
install -d /etc/nginx/mailform
touch /etc/nginx/mailform/maintenance
target production production "$origin" mailform
run_import
[[ ! -e $state/production-import-verified ]]
(cd "$app" && runuser -u mailform -- php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$theme = App\Models\Theme::whereSlug('garden_example')->sole();
exit($theme->logo === 'images/logo_main.svg' && $theme->approveForPublication() === 1 ? 0 : 1);
PHP
)
run_import
[[ -s $state/production-import-verified ]]
jq -e '([.forms[] | select(.same_public_url == true)] | length == 2)' "$verify" >/dev/null
[[ $("${db[@]}" mailform -e 'SELECT COUNT(*) FROM submissions') == 120 ]]
[[ $("${db[@]}" mailform -e 'SELECT COUNT(*) FROM deliveries') == 0 ]]
expect_integrity_failure() {
    local status=0
    (cd /root && "${wrapper[@]}") >/tmp/mixed-rejected.log 2>&1 || status=$?
    [[ $status == 2 && ! -e $state/production-import-verified ]]
    grep -Fq "$1" /tmp/mixed-rejected.log
}
cp "$backup/backup.sql" /tmp/mixed-good.sql
printf "\nUPDATE forms SET url = 'garden-1' WHERE id = 64;\n" >> "$backup/backup.sql"
expect_integrity_failure '同じ公開パス'
cp /tmp/mixed-good.sql "$backup/backup.sql"
printf '\nBROKEN SQL;\n' >> "$backup/backup.sql"
expect_integrity_failure 'SQLの復元を停止'
echo 'Mixed backup verified through staging and production, with drafts, URL collisions and failed restores checked.'
