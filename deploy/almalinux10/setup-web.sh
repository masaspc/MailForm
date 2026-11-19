#!/usr/bin/env bash
# setup.sh supplies host, mount_path, cert_file, key_file, release and state.
# shellcheck disable=SC2154

web_error() {
    printf 'MailForm: %s\n' "$*" >&2
    return 1
}

web_valid_mount() {
    local LC_ALL=C
    [[ -z $mount_path || $mount_path =~ ^(/[a-zA-Z0-9._~-]+)+$ ]] &&
        [[ /${mount_path#/}/ != */./* && /${mount_path#/}/ != */../* ]] ||
        web_error "設置パスが正しくありません。"
}

validate_production_tls() {
    local LC_ALL=C file mode key_mode cert_pub key_pub chain
    local label='[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?'
    [[ ${#host} -le 253 && $host =~ ^$label(\.$label)+$ && ! $host =~ ^[0-9.]+$ ]] ||
        { web_error "本番ホストには DNS の完全なホスト名を指定してください。"; return 1; }
    for file in "$cert_file" "$key_file"; do
        # Paths are interpolated into nginx configuration. namei follows each
        # link, including the relative live -> archive links used by Certbot.
        [[ $file =~ ^(/[a-zA-Z0-9._+\ -]+)+$ && $file/ != */./* && $file/ != */../* &&
           -f $file && -r $file && $(stat -Lc %u -- "$file") == 0 ]] ||
            { web_error "証明書と秘密鍵には root 所有で読み取り可能な絶対パスを指定してください。"; return 1; }
        chain=$(namei -l -- "$file") || return 1
        awk 'NR > 1 {
            if ($2 != "root" || $1 !~ /^[-dl]/) bad=1
            if ($1 ~ /^d/ && (substr($1,6,1) == "w" || substr($1,9,1) == "w")) bad=1
        } END { exit !(NR > 1 && !bad) }' <<< "$chain" ||
            { web_error "証明書・秘密鍵とリンク先までのディレクトリは root 所有にし、他のユーザーによる書き込みを禁止してください。"; return 1; }
        mode=$(stat -Lc %a -- "$file") || return 1
        (( (8#$mode & 0022) == 0 )) ||
            { web_error "証明書と秘密鍵は root だけが変更できる権限にしてください。"; return 1; }
        if [[ $file == "$key_file" ]]; then key_mode=$mode; fi
    done
    (( (8#$key_mode & 0077) == 0 )) ||
        { web_error "TLS 秘密鍵は root だけが読める権限（0600 または 0400）にしてください。"; return 1; }
    if grep -Eq '^-----BEGIN .*PRIVATE KEY-----' "$cert_file"; then
        web_error "フルチェーンには公開証明書だけを含めてください。"; return 1
    fi
    if grep -Eq '^(-----BEGIN ENCRYPTED PRIVATE KEY-----|Proc-Type: *4,ENCRYPTED)' "$key_file"; then
        web_error "nginx が無人で読み込めるパスフレーズなしの TLS 秘密鍵が必要です。"; return 1
    fi
    openssl verify -purpose sslserver -verify_hostname "$host" -untrusted "$cert_file" "$cert_file" >/dev/null ||
        { web_error "証明書のホスト名・有効期間・信頼チェーンを確認してください。"; return 1; }
    cert_pub=$(openssl x509 -in "$cert_file" -noout -pubkey) || return 1
    key_pub=$(openssl pkey -in "$key_file" -passin pass: -pubout) || return 1
    [[ -n $cert_pub && $cert_pub == "$key_pub" ]] ||
        web_error "証明書と TLS 秘密鍵の組が一致しません。"
}

# Keep hashes in the root-only installer state, including a pending replacement.
# A retry can recognize either side of an interrupted atomic rename.
web_owned_file() {
    local dest=$1 hash record="$state/web/${1##*/}.sha256"
    [[ -f $dest && ! -L $dest && -f $record && $(stat -c %u:%g:%a -- "$dest") == 0:0:644 ]] || return 1
    hash=$(sha256sum < "$dest") || return 1
    grep -Fxq -- "${hash%% *}" "$record"
}

web_check_target() {
    [[ ! -e $1 && ! -L $1 ]] || web_owned_file "$1" ||
        web_error "既存の設定 $1 に変更があるため停止しました。"
}

web_write() (
    local dest=$1 tmp hash record="$state/web/${1##*/}.sha256"
    web_check_target "$dest" || return 1
    tmp=$(mktemp "${dest%/*}/.mailform-setup.XXXXXX") || return 1
    trap 'rm -f -- "$tmp"' EXIT
    cat > "$tmp" || return 1
    if [[ -f $dest ]] && cmp -s "$tmp" "$dest"; then
        restorecon "$dest"
        return
    fi
    hash=$(sha256sum < "$tmp") || return 1
    if ! grep -Fxq -- "${hash%% *}" "$record" 2>/dev/null; then
        printf '%s\n' "${hash%% *}" >> "$record" || return 1
    fi
    chown root:root "$tmp" && chmod 0644 "$tmp" && mv -fT -- "$tmp" "$dest" &&
        restorecon "$dest"
)

# The stock packages have no extra vhosts. Check the original files before
# taking over nginx or transitioning a preview that may have been customized.
validate_web_host() {
    local file hash manifest mode
    [[ -f /etc/nginx/nginx.conf && -f /etc/nginx/mime.types && -f /etc/php-fpm.conf ]] ||
        { web_error "nginx と PHP-FPM の標準設定が見つかりません。"; return 1; }
    manifest=$(rpm -q --qf '[%{FILENAMES}\t%{FILEDIGESTS}\n]' nginx nginx-core php8.4-fpm) || return 1
    for file in /etc/nginx/nginx.conf /etc/nginx/mime.types /etc/nginx/conf.d/*.conf \
                /etc/nginx/default.d/*.conf /etc/nginx/fastcgi_params \
                /usr/share/nginx/modules/*.conf /etc/php-fpm.conf /etc/php-fpm.d/*.conf \
                /etc/systemd/system/{nginx,php-fpm}.service{,.d/*.conf} \
                /etc/systemd/system/mailform-schedule.{service,timer}.d/*.conf; do
        [[ -e $file || -L $file ]] || continue
        case "$file" in
            /etc/nginx/nginx.conf) web_owned_file "$file" && continue ;;
            /etc/php-fpm.d/mailform.conf) web_check_target "$file" || return 1; continue ;;
        esac
        [[ -f $file && ! -L $file ]] ||
            { web_error "既存設定 $file を確認してください。"; return 1; }
        hash=$(sha256sum < "$file") || return 1
        grep -Fxq -- "$file"$'\t'"${hash%% *}" <<< "$manifest" ||
            { web_error "専用サーバーでのみ利用できます。既存設定 $file を検出しました。"; return 1; }
    done
    for file in /etc/nginx /etc/nginx/{conf.d,default.d,mailform} /etc/php-fpm.d /etc/systemd/system; do
        [[ ! -e $file && ! -L $file ]] && continue
        [[ -d $file && $(realpath -- "$file") == "$file" && $(stat -c %u -- "$file") == 0 ]] ||
            { web_error "設定ディレクトリ $file を確認してください。"; return 1; }
        mode=$(stat -c %a -- "$file") || return 1
        (( (8#$mode & 0022) == 0 )) ||
            { web_error "設定ディレクトリ $file は root だけが変更できる権限にしてください。"; return 1; }
    done
    for file in /etc/nginx/mailform/{site,locations,fastcgi}.conf \
                /etc/systemd/system/mailform-schedule.{service,timer}; do
        web_check_target "$file" || return 1
    done
}

web_port_available() {
    local listeners main_pid line
    listeners=$(ss -H -ltnp "sport = :$1") || return 1
    [[ -n $listeners ]] || return 0
    main_pid=$(systemctl show --property=MainPID --value nginx.service) || return 1
    while IFS= read -r line; do
        [[ $main_pid != 0 && $line == *"\"nginx\",pid=$main_pid,"* ]] ||
            { web_error "TCP $1 は別のサービスが使用しています。"; return 1; }
    done <<< "$listeners"
}

web_render_locations() {
    local tpl=$1
    web_valid_mount || return 1
    if [[ -z $mount_path ]]; then
        cat "$tpl/root-locations.conf"
        return
    fi
    awk -v base="$mount_path" '
        BEGIN {
            n = split(base, part, /[.]/)
            rx = part[1]
            for (i = 2; i <= n; i++) rx = rx "\\." part[i]
            token = "/contact-form"
        }
        index($0, "location ~ (?:^|/)\\.") {
            print "    location ~ ^" rx "/(?:[^/]+/)*\\. { return 404; }"
            next
        }
        {
            pos = index($0, token)
            if (!pos) { print; next }
            value = ($0 ~ /^ *location ~ /) ? rx : base
            print substr($0, 1, pos - 1) value substr($0, pos + length(token))
        }
    ' "$tpl/subdirectory-locations.conf"
}

web_render_site() {
    if [[ $1 == preview ]]; then
        cat <<'NGINX'
server {
    listen 127.0.0.1:8008 default_server;
    server_name _;
    return 444;
}
server {
    listen 127.0.0.1:8008;
    server_name 127.0.0.1 localhost;
NGINX
    else
        cat <<NGINX
server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    server_name _;
    ssl_reject_handshake on;
    return 444;
}
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name $host;
    ssl_certificate "$cert_file";
    ssl_certificate_key "$key_file";
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers PROFILE=SYSTEM;
    if (\$host != $host) { return 444; }
NGINX
    fi
    if [[ -n $mount_path ]]; then
        printf '    location / { return 404; }\n'
    fi
    printf '    include /etc/nginx/mailform/locations.conf;\n}\n'
}

web_configure_nginx() {
    local phase=$1 tpl=$2 main=/etc/nginx/nginx.conf hash locations site
    local backup="$state/web/nginx.conf.vendor"
    locations=$(web_render_locations "$tpl") && site=$(web_render_site "$phase") || return 1
    install -d -o root -g root -m 0700 "$state/web" || return 1
    install -d -o root -g root -m 0755 /etc/nginx/mailform || return 1
    if ! web_owned_file "$main"; then
        # validate_web_host accepted only the exact vendor file. Save it before
        # enabling our only listener, without starting the stock wildcard :80.
        if [[ -e $backup ]]; then
            cmp -s "$main" "$backup" ||
                { web_error "保存済みの nginx 初期設定と現在の設定が異なります。"; return 1; }
        else
            install -m 0600 "$main" "$backup.new" && mv -fT "$backup.new" "$backup" || return 1
        fi
        hash=$(sha256sum < "$main") || return 1
        printf '%s\n' "${hash%% *}" >> "$state/web/nginx.conf.sha256" || return 1
    fi
    web_write /etc/nginx/mailform/fastcgi.conf < "$tpl/fastcgi.conf" || return 1
    web_write /etc/nginx/mailform/locations.conf <<< "$locations" || return 1
    web_write /etc/nginx/mailform/site.conf <<< "$site" || return 1
    web_write "$main" <<'NGINX'
# MailForm dedicated host. The original configuration is kept by mailform-setup.
user nginx;
worker_processes auto;
error_log /var/log/nginx/error.log notice;
pid /run/nginx.pid;
events { worker_connections 1024; }
http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    types_hash_max_size 4096;
    access_log /var/log/nginx/access.log;
    sendfile on;
    keepalive_timeout 65;
    include /etc/nginx/mailform/site.conf;
}
NGINX
}

web_responding() {
    local phase=$1 local_url path code listeners
    local -a options=(--disable --noproxy '*' --silent --show-error --max-time 5)
    listeners=$(ss -H -ltnp) || return 1
    if [[ $phase == preview ]]; then
        awk '/"nginx"/ { if ($4 != "127.0.0.1:8008") bad=1; seen=1 } END { exit !(seen && !bad) }' \
            <<< "$listeners" || return 1
        local_url="http://127.0.0.1:8008$mount_path"
        for path in /up /admin/login; do
            code=$(curl "${options[@]}" -o /dev/null -w '%{http_code}' "$local_url$path") || return 1
            [[ $code == 200 ]] || return 1
        done
        curl "${options[@]}" --fail "$local_url/assets/admin.css" |
            cmp -s - "$release/public/assets/admin.css" || return 1
        for path in /.mailform-path-check /storage/mailform-path-check; do
            code=$(curl "${options[@]}" -o /dev/null -w '%{http_code}' "$local_url$path") || return 1
            [[ $code == 404 ]] || return 1
        done
    else
        awk '/"nginx"/ { if ($4 !~ /:443$/) bad=1; seen=1 } END { exit !(seen && !bad) }' \
            <<< "$listeners" || return 1
        options+=(--resolve "$host:443:127.0.0.1")
        local_url="https://$host$mount_path"
        for path in /up /admin/login; do
            code=$(curl "${options[@]}" -o /dev/null -w '%{http_code}' "$local_url$path") || return 1
            [[ $code == 503 ]] || return 1
        done
    fi
}

web_configure() (
    set -o pipefail
    umask 077
    local phase=$1 attempt unit tpl
    # Install the templates beside this verified setup script. The deployment
    # account owns the separate application release and its Composer build.
    tpl=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P) || return 1
    web_valid_mount && validate_web_host || return 1
    [[ $(readlink -f /srv/mailform/current) == "$release" ]] ||
        { web_error "current が対象リリースを指していません。"; return 1; }
    if [[ $phase == production ]]; then
        validate_production_tls && web_port_available 443 || return 1
        [[ -f /etc/nginx/mailform/maintenance && ! -L /etc/nginx/mailform/maintenance ]] ||
            { web_error "本番を準備する前に受付を停止してください。"; return 1; }
    else
        web_port_available 8008 || return 1
        [[ ! -e /etc/nginx/mailform/maintenance ]] ||
            { web_error "受付停止中です。検証の起動状態を確認してください。"; return 1; }
    fi
    web_configure_nginx "$phase" "$tpl" && restorecon -RF /etc/nginx/mailform || return 1
    web_write /etc/php-fpm.d/mailform.conf < "$tpl/php-fpm.conf" || return 1
    for unit in mailform-schedule.service mailform-schedule.timer; do
        web_write "/etc/systemd/system/$unit" < "$tpl/$unit" || return 1
    done
    systemctl daemon-reload && systemd-analyze verify /etc/systemd/system/mailform-schedule.{service,timer} || return 1
    if [[ $phase == production ]]; then
        systemctl disable --now mailform-schedule.timer || return 1
    fi
    /usr/sbin/php-fpm -t && nginx -t || return 1
    systemctl enable --now php-fpm && systemctl reload php-fpm || return 1
    systemctl enable --now nginx && systemctl reload nginx || return 1
    # systemctl reload returns before the new nginx workers accept connections.
    for ((attempt=1; attempt<=15; attempt++)); do
        if web_responding "$phase"; then
            if [[ $phase == preview ]]; then
                systemctl enable --now mailform-schedule.timer && systemctl start mailform-schedule.service || return 1
            fi
            return 0
        fi
        sleep 1
    done
    web_error "nginx の待受と管理画面の確認に失敗しました。journalctl -u nginx -u php-fpm を確認してください。"
)

configure_preview_web() { web_configure preview; }
prepare_production_web() { web_configure production; }
