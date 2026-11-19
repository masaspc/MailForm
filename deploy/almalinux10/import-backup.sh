#!/usr/bin/env bash
set -euo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
umask 077

usage() {
    cat <<'HELP'
使い方  sudo bash MailForm/deploy/almalinux10/import-backup.sh /srv/mailform-import/複製名/backup.sql /srv/mailform-import/複製名/EasyMail
setup.sh で準備した新サーバーで、旧サイトの単一DBのSQLと同時点のファイル複製を指定します。
練習では練習DBに、本番準備後は受付・配信を止めた本番DBに取り込みます。
コピーするファイル、所有者、権限は docs/IMPORT.md を確認してください。
HELP
}
die() { printf '%s\n' "$*" >&2; exit 1; }

if [[ $# == 1 && ( $1 == --help || $1 == -h ) ]]; then usage; exit 0; fi
[[ $# == 2 ]] || { usage >&2; exit 2; }
[[ $EUID == 0 ]] || die '新サーバーで sudo bash を使ってください。'
exec 9>>/run/mailform-setup.lock
flock -n 9 || die '別の設置または取込が進行中です。終了後に再実行してください。'

state=/var/lib/mailform-setup
[[ -d $state && ! -L $state && $(stat -c '%u:%a' "$state") == 0:700 && -s $state/phase ]] ||
    die 'setup.sh による練習環境または本番取込先の準備を先に完了してください。'
phase=$(< "$state/phase")
case $phase in
    preview-ready) target=mailform_stage ;;
    production-ready)
        target=mailform
        rm -f -- "$state/production-import-verified"
        [[ -f /etc/nginx/mailform/maintenance ]] || die '本番の受付停止を確認してください。'
        ;;
    *) die '取込は練習環境または公開前の本番取込先でのみ実行できます。' ;;
esac
base_url=$(< "$state/base-url")
[[ -n $base_url ]] || die '旧サイトの設置URLを確認してください。'

dump=$1
source_root=${2%/}
copy_dir=${dump%/*}
backup_dir=/srv/mailform-import
[[ $copy_dir == "$backup_dir/"* && ${copy_dir#"$backup_dir/"} != */* && ${source_root%/*} == "$copy_dir" ]] ||
    die '/srv/mailform-import/複製名/ の直下にあるSQLと旧ファイルのディレクトリを指定してください。'
[[ -s $dump && -f $dump && -d $source_root && $(realpath -e -- "$dump") == "$dump" && $(realpath -e -- "$source_root") == "$source_root" ]] ||
    die 'SQLと旧ファイルに、リンクを経由しない実在する絶対パスを指定してください。'
for dir in "$backup_dir" "$copy_dir"; do
    [[ $(stat -c '%u' "$dir") == 0 && -z $(find "$dir" -maxdepth 0 -perm /027 -printf x) ]] ||
        die '複製の親ディレクトリを root:mailform、0750 にしてください。'
done
[[ $(stat -c '%u:%h' "$dump") == 0:1 && -z $(find "$dump" -maxdepth 0 -perm /077 -printf x) ]] ||
    die 'SQLは root 所有の通常ファイルを 0600 で配置してください。'
unsafe=$(find -P "$source_root" \( ! -uid 0 -o -perm /027 -o ! \( -type f -o -type d \) -o \( -type f -links +1 \) -o -name '.env' -o -name '.env.*' \) -printf x -quit)
[[ -z $unsafe ]] || die '旧ファイルを root:mailform の私有コピーとして用意してください。リンク・特殊ファイル・.env は除いてください。'
if ! unreadable=$(runuser -u mailform -- find "$source_root" \( ! -readable -o \( -type d ! -executable \) \) -printf x -quit) || [[ -n $unreadable ]]; then
    die 'mailform が旧ファイルを読めるように、コピー内のディレクトリを 0750、ファイルを 0640 にしてください。'
fi

socket=/var/lib/mysql/mysql.sock
admin=(mariadb --no-defaults --protocol=socket --socket="$socket" --user=root --batch)
app=(runuser -u mailform -- env -i PATH="$PATH" HOME=/nonexistent)
helper_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
cd /srv/mailform/current
target_fingerprint() { "$@" php -- "$target" "$socket" "$base_url" < "$helper_dir/target.php"; }
cached_before=$(target_fingerprint "${app[@]}")
"${app[@]}" php artisan mailform:check --expect-paused
if [[ $phase == production-ready ]]; then "${app[@]}" php artisan mailform:handoff --require-empty; fi

token=$(openssl rand -hex 10)
schema=mailformsnap$token
writer=mfload$token
reader=mfread$token
record=$state/$schema
mkdir -- "$record"
cache_dir=$(mktemp -d /run/mailform-import.XXXXXXXX)
os_writer=0 db_writer=0 db_reader=0
release_writer() {
    if (( db_writer )); then
        "${admin[@]}" -e "DROP USER '$writer'@'localhost'" || return 1
        db_writer=0
    fi
    if (( os_writer )); then
        userdel "$writer" || return 1
        os_writer=0
    fi
}
cleanup() {
    local result=$?
    trap - EXIT
    release_writer || result=1
    if (( db_reader )); then "${admin[@]}" -e "DROP USER '$reader'@'localhost'" || result=1; fi
    rmdir -- "$cache_dir" || result=1
    exit "$result"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
chgrp mailform "$cache_dir"
chmod 0710 "$cache_dir"
import_app=("${app[@]}" APP_CONFIG_CACHE="$cache_dir/config.php" LEGACY_DB_DRIVER=mariadb
    LEGACY_DB_HOST=localhost LEGACY_DB_SOCKET="$socket" LEGACY_DB_DATABASE="$schema" LEGACY_DB_USERNAME="$reader" LEGACY_DB_PASSWORD=)
uncached_before=$(target_fingerprint "${import_app[@]}")
[[ $cached_before =~ ^[0-9a-f]{64}$ && $uncached_before == "$cached_before" ]] ||
    die '取込先の設定キャッシュと .env が一致しません。アプリ鍵・DB・設置URLを確認してください。'

printf '旧SQLの復元先 %s\n取込記録 %s\n' "$schema" "$record"
# Only generated alphanumeric identifiers reach administrative SQL. No wildcard database grants.
"${admin[@]}" -e "CREATE DATABASE $schema CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$writer"
os_writer=1
"${admin[@]}" -e "CREATE USER '$writer'@'localhost' IDENTIFIED VIA unix_socket"
db_writer=1
"${admin[@]}" -e "GRANT CREATE, ALTER, DROP, INDEX, REFERENCES, SELECT, INSERT, UPDATE, DELETE, LOCK TABLES ON $schema.* TO '$writer'@'localhost'"
# Root opens the private copy. Only a dedicated, short-lived OS and database identity processes its SQL.
if ! runuser -u "$writer" -- env -i PATH="$PATH" HOME=/nonexistent LC_ALL=C \
    mariadb --no-defaults --protocol=socket --socket="$socket" --user="$writer" --database="$schema" \
    --batch --binary-mode --sandbox --local-infile=0 --skip-reconnect --show-warnings \
    < "$dump" > "$record/restore.log" 2>&1; then
    die "SQLの復元を停止しました。単一DBのエクスポートと管理者用の記録 $record/restore.log を確認してください。"
fi
if grep -Eq '^Warning \(Code [0-9]+\)' "$record/restore.log"; then
    die "SQL復元時に警告がありました。管理者用の記録 $record/restore.log を確認してください。"
fi
release_writer
"${admin[@]}" -e "CREATE USER '$reader'@'localhost' IDENTIFIED VIA unix_socket AS 'mailform'"
db_reader=1
"${admin[@]}" -e "GRANT SELECT ON $schema.* TO '$reader'@'localhost'"

import=("${import_app[@]}" php artisan easymail:import --strict "--source-base=$base_url" "--source-root=$source_root")
"${import[@]}" --dry-run
"${import[@]}"
verify_status=0
"${import[@]}" --verify-only --json > "$record/verify.json" || verify_status=$?
# shellcheck disable=SC2016 # The variables below belong to PHP.
"${app[@]}" php -r '
$r = json_decode(stream_get_contents(STDIN), true);
if (!isset($r["forms"], $r["reconciliation"]["forms"], $r["source_history_count"])) {
    fwrite(STDERR, "照合結果を取得できませんでした。管理者用の verify.json を確認してください。\n"); exit(1);
}
printf("照合結果　旧フォーム %d 件・旧履歴 %d 件\n", count($r["forms"]), $r["source_history_count"]);
foreach ($r["reconciliation"]["forms"] as $f) {
    printf("旧フォーム %s　旧履歴 %d・一致 %d・変更 %d・未取込 %d・添付一致 %d/%d\n",
        $f["legacy_id"] ?? "―", $f["source"]["histories"], $f["source"]["matched"], $f["source"]["changed"],
        $f["source"]["missing"], $f["files"]["verified"], $f["files"]["expected"]);
}
foreach ($r["forms"] as $f) {
    if ($argv[1] === "production-ready" && $f["source_published"] && $f["same_public_url"] !== true) {
        fprintf(STDERR, "旧フォーム #%d の公開URLが一致しません。\n", $f["legacy_id"]); exit(2);
    }
}
exit(($r["reconciliation"]["ok"] ?? false) === true ? 0 : 2);
' "$phase" < "$record/verify.json"
(( verify_status == 0 )) || exit "$verify_status"
"${admin[@]}" -e "DROP USER '$reader'@'localhost'"
db_reader=0
if [[ $phase == production-ready ]]; then
    [[ -f /etc/nginx/mailform/maintenance ]] || die '本番の受付停止を確認してください。'
    "${app[@]}" php artisan mailform:handoff --require-empty
    cached_after=$(target_fingerprint "${app[@]}")
    uncached_after=$(target_fingerprint "${import_app[@]}")
    [[ $cached_after == "$cached_before" && $uncached_after == "$cached_after" ]] ||
        die '取込中に本番のアプリ鍵または接続先が変わりました。設定を確認し、最終取込をやり直してください。'
    printf '%s\n%s\n' "$schema" "$cached_after" > "$record/verified"
    ln -- "$record/verified" "$state/production-import-verified"
fi
printf '取込と全件照合が完了しました。管理者用の照合記録 %s/verify.json\n' "$record"
