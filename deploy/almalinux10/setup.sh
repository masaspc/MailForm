#!/usr/bin/env bash
# Initial installation on a new, dedicated AlmaLinux server.
set -Eeuo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
umask 077

usage() {
    cat <<'HELP'
使い方  sudo bash deploy/almalinux10/setup.sh [preview|update-preview|production|review-themes|open]
  preview      SSH経由で開く練習環境を作ります（省略時）。
  update-preview  練習DBをバックアップし、練習環境を選択中の新しい版に更新します。
  production   練習データを退避し、同じ版で本番の最終取込を準備します。
  review-themes  本番受付と配信を停止したまま、SSH 経由でテーマを確認します。
  open         最終取込・SMTP接続を確認し、新サーバーの受付と配信を開始します。
対象は新規の専用 AlmaLinux 10.3 Minimal です。最初の Git コピーから実行してください。
HELP
}

die() { printf '%s\n' "$*" >&2; exit 1; }

save_value() {
    local name=$1 value=$2 temp
    temp=$(mktemp "$state/.write.XXXXXXXX")
    printf '%s\n' "$value" > "$temp"
    mv -T "$temp" "$state/$name"
}

parse_url() {
    local input=${1%/} label part
    local pattern='^https://([a-zA-Z0-9.-]+)(/[-a-zA-Z0-9._~/]+)?$'
    [[ $input =~ $pattern ]] || return 1
    host=${BASH_REMATCH[1],,}
    mount_path=${BASH_REMATCH[2]:-}
    [[ ${#host} -le 253 && $host == *.* && $host != .* && $host != *. && $host != *..* && ! $host =~ ^[0-9.]+$ ]] || return 1
    local -a labels segments
    IFS=. read -ra labels <<< "$host"
    for label in "${labels[@]}"; do
        [[ $label =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$ ]] || return 1
    done
    [[ $mount_path != *//* && $mount_path != */ && ${#mount_path} -le 1024 ]] || return 1
    IFS=/ read -ra segments <<< "${mount_path#/}"
    for part in "${segments[@]}"; do
        [[ $part != .* ]] || return 1
    done
    base_url=https://$host$mount_path
}

check_fresh_host() {
    local path package listening
    for path in /srv/mailform /var/lib/mysql /var/lib/mailform-deploy /etc/my.cnf.d/mailform-local.cnf \
        /etc/nginx /usr/share/nginx/html /etc/php-fpm.d/mailform.conf /etc/systemd/system/mailform-schedule.{service,timer}; do
        [[ ! -e $path && ! -L $path ]] || die "既設の $path があります。共用・手動設置済みのサーバーは詳細手順を使ってください。"
    done
    for package in nginx httpd php-fpm php8.4-fpm mariadb-server mariadb11.8-server mysql-server; do
        if rpm -q --quiet "$package"; then die "既設の $package があります。詳細手順を使ってください。"; fi
    done
    if getent passwd mailform >/dev/null || getent passwd mailform-deploy >/dev/null ||
       getent group mailform >/dev/null || getent group mailform-deploy >/dev/null; then
        die 'MailFormのアカウントが既にあります。既存環境の構成を確認してください。'
    fi
    listening=$(ss -Hltn '( sport = :80 or sport = :443 or sport = :8008 )') || die 'iproute を導入して待受ポートを確認してください。'
    if [[ -n $listening ]]; then
        die 'Webサービスのポートが使用中です。共用環境向けの詳細手順を使ってください。'
    fi
}

new_state() {
    local answer initial
    check_fresh_host
    printf '旧EasyMailの設置URLを入力してください。例 https://www.example.jp/contact （末尾のフォーム名や /admin は除く）\n'
    read -rp '設置URL: ' answer || die '設置URLを入力して、もう一度実行してください。'
    parse_url "$answer" || die 'HTTPSのドメインと設置パスを入力してください。日本語・空白・%表記などを含む設置パスは詳細手順を使ってください。'
    initial=$(mktemp -d /var/lib/.mailform-setup.XXXXXXXX)
    printf '%s\n' "$base_url" > "$initial/base-url"
    printf '%s\n' "$revision" > "$initial/revision"
    printf '%s\n' preview-installing > "$initial/phase"
    mv -T "$initial" "$state"
}

ensure_admin() {
    local email present
    present=$(sudo -u mailform mariadb --protocol=socket -N -B "$db_name" -e "SELECT EXISTS(SELECT 1 FROM users WHERE role = 'admin')")
    if [[ $present == 0 ]]; then
        read -rp '新しい管理者のメールアドレス: ' email || die 'メールアドレスを指定してもう一度実行してください。'
        sudo -u mailform php "$release/artisan" mailform:admin -- "$email"
    fi
}

show_preview() {
    printf '\n練習環境の準備ができました。お手元の端末で、普段のSSH接続先を指定してください。\n'
    printf '  ssh -N -L 127.0.0.1:8008:127.0.0.1:8008 ユーザー名@新サーバーのIP\n'
    printf '接続したままブラウザーで開く管理画面  http://127.0.0.1:8008%s/admin\n' "$mount_path"
}

show_production() {
    printf '\n本番の取込先を用意しました。受付と配信は停止中です。\n'
    printf '引き継ぐ設置URL  %s\n設定  /srv/mailform/shared/.env\n' "$base_url"
    printf '最終取込・照合、SMTPの接続確認、受付先の切替は docs/ALMALINUX10.md の「切替当日」へ進んでください。\n'
}

quiesce_preview() {
    install -m 0644 /dev/null /etc/nginx/mailform/maintenance
    restorecon /etc/nginx/mailform/maintenance
    systemctl disable --now mailform-schedule.timer
    # Wait for requests and any already running scheduled task before moving their data.
    while [[ $(systemctl show -p ActiveState --value mailform-schedule.service) =~ ^(activating|active|deactivating)$ ]] ||
        pgrep -f '^php-fpm: pool mailform([[:space:]]|$)' >/dev/null; do sleep 1; done
    systemctl stop php-fpm
}

archive_preview() {
    local hold=/srv/mailform/rehearsal marker former cache
    quiesce_preview
    snapshot_themes
    install -d -m 0700 "$hold"
    if [[ ! -d $hold/shared ]]; then mv -T /srv/mailform/shared "$hold/shared"; fi
    if [[ ! -d $hold/bootstrap-cache ]]; then mv -T "$release/bootstrap/cache" "$hold/bootstrap-cache"; fi
    # Earlier rehearsal releases also cached the old APP_KEY before update-preview switched current.
    for marker in "$state"/release-*; do
        former=${marker##*/release-}
        [[ $former != "$revision" && $former =~ ^[0-9a-f]{40}$ ]] || continue
        cache="/srv/mailform/releases/$former/bootstrap/cache"
        if [[ -d $cache && ! -e $hold/cache-$former ]]; then mv -T "$cache" "$hold/cache-$former"; fi
    done
    archive_stage_grant
}

open_production() {
    local env_file=/srv/mailform/shared/.env temporary fingerprint expected
    case $phase in
        live) printf '本番の受付開始を記録済みです。管理画面  %s/admin\n' "$base_url"; return ;;
        production-ready)
            [[ -s $state/production-import-verified && -f /etc/nginx/mailform/maintenance ]] ||
                die 'メンテナンス中に最終バックアップを import-backup.sh で取り込み、照合を完了してください。'
            sudo -u mailform php "$release/artisan" config:cache
            expected=$(sed -n '2p' "$state/production-import-verified")
            fingerprint=$(
                {
                    cd "$release"
                    sudo -u mailform env -i PATH="$PATH" HOME=/nonexistent php -- mailform /var/lib/mysql/mysql.sock "$base_url"
                } < "$repo/deploy/almalinux10/target.php"
            )
            [[ $fingerprint =~ ^[0-9a-f]{64}$ && $fingerprint == "$expected" ]] ||
                die '最終照合した本番 DB・URL・暗号鍵と現在の設定が異なります。公開前に確認してください。'
            sudo -u mailform php "$release/artisan" mailform:check --smtp --expect-paused
            sudo -u mailform php "$release/artisan" mailform:handoff --require-empty
            save_value phase production-opening ;;
        production-opening) ;;
        *) die '本番用の準備と最終取込を先に実施してください。' ;;
    esac
    if [[ -f /etc/nginx/mailform/maintenance ]]; then
        if [[ $(grep -Ec '^[[:space:]]*(export[[:space:]]+)?MAILFORM_DELIVERY_ENABLED[[:space:]]*=' "$env_file") != 1 ]] ||
            ! grep -Eq '^MAILFORM_DELIVERY_ENABLED=(false|true)$' "$env_file"; then
            die "$env_file の MAILFORM_DELIVERY_ENABLED を確認してください。"
        fi
        temporary=$(mktemp /srv/mailform/shared/.delivery.XXXXXXXX)
        sed -E 's/^MAILFORM_DELIVERY_ENABLED=(false|true)$/MAILFORM_DELIVERY_ENABLED=true/' "$env_file" > "$temporary"
        chown root:mailform "$temporary"
        chmod 0640 "$temporary"
        restorecon "$temporary"
        mv -T "$temporary" "$env_file"
        sudo -u mailform php "$release/artisan" config:cache
        sudo -u mailform php "$release/artisan" mailform:check --smtp
        systemctl reload php-fpm
        systemctl enable --now mailform-schedule.timer
        rm /etc/nginx/mailform/maintenance
    fi
    save_value phase live
    printf '新サーバーの受付と配信を開始しました。公開経路を切り替え、代表フォームの到達を確認してください。\n管理画面  %s/admin\n' "$base_url"
}

case ${1:-preview} in
    --help|-h) usage; exit 0 ;;
    preview|update-preview|production|review-themes|open) mode=${1:-preview} ;;
    *) usage >&2; exit 2 ;;
esac
[[ $# -le 1 ]] || { usage >&2; exit 2; }
[[ $EUID == 0 ]] || die 'sudo bash で起動してください。'
[[ -r /etc/almalinux-release ]] || die 'この手順は新規の AlmaLinux 10.3 Minimal 用です。'
# shellcheck source=/dev/null
source /etc/os-release
[[ $ID == almalinux && $VERSION_ID == 10.3 ]] || die 'この手順で検証したOSは AlmaLinux 10.3 です。'
[[ -r /sys/fs/selinux/enforce && $(< /sys/fs/selinux/enforce) == 1 ]] || die 'SELinux Enforcing を確認してから実行してください。'
exec 9>/run/mailform-setup.lock
flock -n 9 || die '別の設置または取込が進行中です。完了後に再実行してください。'
repo=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)
checkout=(git -c "safe.directory=$repo" -C "$repo")
revision=$("${checkout[@]}" rev-parse --verify 'HEAD^{commit}') || die '最初に取得したMailFormのGitディレクトリから実行してください。'
"${checkout[@]}" diff --quiet HEAD -- deploy/almalinux10 || die '設置スクリプトに変更があります。取得した版のファイルを確認してください。'
state=/var/lib/mailform-setup
if [[ ! -e $state ]]; then
    [[ $mode == preview && ! -L $state ]] || die '最初に引数なしで練習環境を設置してください。'
    new_state
fi
[[ -d $state && ! -L $state && $(stat -c '%u:%a' "$state") == 0:700 && -s $state/phase ]] || die '既存の設置記録を確認してください。'
[[ $mode == update-preview || $(< "$state/revision") == "$revision" ]] ||
    die "確認済みの版 $(< "$state/revision") を git switch --detach で選び直してください。練習環境の更新は update-preview を使います。"
parse_url "$(< "$state/base-url")" || die '保存された設置URLを確認してください。'
release=/srv/mailform/releases/$revision
# shellcheck source=setup-core.sh
source "$repo/deploy/almalinux10/setup-core.sh"
# shellcheck source=setup-web.sh
source "$repo/deploy/almalinux10/setup-web.sh"
# shellcheck source=setup-themes.sh
source "$repo/deploy/almalinux10/setup-themes.sh"
# shellcheck source=setup-update.sh
source "$repo/deploy/almalinux10/setup-update.sh"
phase=$(< "$state/phase")
if [[ $mode == update-preview ]]; then update_preview; exit 0; fi
if [[ $mode == review-themes ]]; then review_themes; exit 0; fi
if [[ $mode == open ]]; then open_production; exit 0; fi
if [[ $mode == preview ]]; then
    case $phase in
        preview-installing)
            db_name=mailform_stage
            app_url=http://127.0.0.1:8008$mount_path
            provision_stage
            ensure_admin
            configure_preview_web
            sudo -u mailform php "$release/artisan" mailform:check --expect-paused
            save_value phase preview-ready ;;
        preview-ready)
            if ! systemctl is-active --quiet nginx || ! systemctl is-active --quiet php-fpm || ! web_responding preview; then
                die '練習環境の管理画面が応答していません。nginx と php-fpm の状態を確認してください。'
            fi ;;
        *) die '本番の準備に進んでいます。練習環境への再設置はできません。' ;;
    esac
    show_preview
    exit 0
fi

db_name=mailform
app_url=$base_url
case $phase in
    preview-ready)
        validate_web_host
        web_port_available 443
        [[ ! -e /srv/mailform/rehearsal && ! -L /srv/mailform/rehearsal ]] || die '練習の退避先が既に存在します。'
        existing=$(mariadb --protocol=socket -N -B -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='mailform'")
        [[ -z $existing ]] || die '本番名のDBが既にあります。既存のDBを確認してください。'
        printf '練習環境を退避し、本番用の空DB・保存領域・鍵を作ります。%s のHTTPS証明書を指定してください。\n' "$host"
        read -rp 'フルチェーンのパス [/etc/pki/nginx/mailform-fullchain.pem]: ' cert_file
        read -rp '秘密鍵のパス [/etc/pki/nginx/private/mailform.key]: ' key_file
        cert_file=${cert_file:-/etc/pki/nginx/mailform-fullchain.pem}
        key_file=${key_file:-/etc/pki/nginx/private/mailform.key}
        validate_production_tls
        save_value cert-path "$cert_file"
        save_value key-path "$key_file"
        save_value phase production-preparing ;;
    production-preparing) ;;
    production-ready) show_production; exit 0 ;;
    *) die 'まず練習環境の設置と確認を完了してください。' ;;
esac
cert_file=$(< "$state/cert-path")
key_file=$(< "$state/key-path")
validate_production_tls
archive_preview
provision_production
restore_themes
ensure_admin
prepare_production_web
sudo -u mailform php "$release/artisan" mailform:handoff --require-empty
sudo -u mailform php "$release/artisan" mailform:check --expect-paused
save_value phase production-ready
show_production
