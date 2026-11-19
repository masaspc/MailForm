#!/usr/bin/env bash
# shellcheck disable=SC2154
# Sourced by the setup entry point under its exclusive lock. Call each provision
# function as a standalone command so that Bash stops at the first failed step.

core_error() {
    printf 'MailForm: %s\n' "$*" >&2
    exit 1
}

core_install_host() {
    dnf -y --setopt=install_weak_deps=False install \
        sudo acl git tar gzip unzip diffutils /usr/bin/curl ca-certificates openssl \
        policycoreutils policycoreutils-python-utils libselinux-utils shadow-utils procps-ng iproute util-linux \
        nginx php8.4-cli php8.4-common php8.4-fpm php8.4-mbstring php8.4-pdo \
        php8.4-mysqlnd php8.4-xml php8.4-opcache mariadb11.8 mariadb11.8-server
    # shellcheck disable=SC2016 # PHP reads these variables.
    php -r '
        if (PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 90000) exit(1);
        foreach (["ctype","curl","dom","fileinfo","filter","hash","mbstring","openssl",
                  "pcre","PDO","pdo_mysql","session","tokenizer","xml","zlib"] as $ext) {
            if (!extension_loaded($ext)) { fwrite(STDERR, "Missing PHP extension: $ext\n"); exit(1); }
        }
    '
    if ! getent passwd mailform >/dev/null; then
        useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin mailform
    fi
    if ! getent passwd mailform-deploy >/dev/null; then
        useradd --system --user-group --create-home --home-dir /var/lib/mailform-deploy --shell /usr/sbin/nologin mailform-deploy
    fi
    install -d -o root -g root -m 0711 /srv/mailform
    install -d -o mailform-deploy -g mailform -m 2711 /srv/mailform/releases

    local conf=/etc/my.cnf.d/mailform-local.cnf pending
    if [[ ! -e $conf && ! -L $conf ]]; then
        pending=$(mktemp "${conf}.XXXXXXXX")
        printf '[mariadb]\nskip-networking\n' > "$pending"
        chmod 0644 "$pending"
        ln -T "$pending" "$conf"
        rm "$pending"
    fi
    if [[ ! -f $conf || -L $conf ]] || ! cmp -s "$conf" <(printf '[mariadb]\nskip-networking\n'); then
        core_error "$conf の既存設定を確認してください。"
    fi
    restorecon "$conf"
    systemctl enable --now mariadb
}

core_install_composer() {
    sudo -Hu mailform-deploy bash -se <<'COMPOSER'
set -u -o pipefail
umask 077
install -d -m 0700 "$HOME/bin"
if [[ ! -x $HOME/bin/composer ]]; then
    build=$(mktemp -d "$HOME/bin/.composer.XXXXXXXX")
    trap 'rm -rf -- "$build"' EXIT
    cd "$build"
    curl --proto '=https' -fsSL --retry 2 -o installer.php https://getcomposer.org/installer
    curl --proto '=https' -fsSL --retry 2 -o installer.sha384 https://composer.github.io/installer.sig
    printf '%s  installer.php\n' "$(cat installer.sha384)" | sha384sum --check --status
    php installer.php --2 --install-dir="$build" --filename=composer
    chmod 0700 composer
    mv -T composer "$HOME/bin/composer"
fi
"$HOME/bin/composer" --version
COMPOSER
}

core_check_release() {
    [[ -f $state/release-$revision && -d $release && ! -L $release &&
       -f $release/artisan && -f $release/vendor/autoload.php &&
       $(readlink "$release/.env") == /srv/mailform/shared/.env &&
       $(readlink "$release/storage") == /srv/mailform/shared/storage ]] ||
        core_error "既存のリリースを確認してください: $release"
}

core_build_release() (
    if [[ -e $release || -L $release ]]; then
        core_check_release
        return
    fi
    core_install_composer
    local build
    build=$(mktemp -d "/srv/mailform/releases/.build.XXXXXXXX")
    trap 'rm -rf -- "$build"' EXIT
    chown mailform-deploy:mailform "$build"
    chmod 2750 "$build"
    # A checkout under /root cannot be traversed by the deploy account.
    git -c safe.directory="$repo" -C "$repo" archive --format=tar "$revision" -- . ':(exclude)storage' |
        sudo -Hu mailform-deploy tar -C "$build" --no-same-owner --no-same-permissions -xf -
    sudo -Hu mailform-deploy bash -se -- "$build" <<'BUILD'
set -u -o pipefail
umask 027
cd "$1"
test -f composer.lock
"$HOME/bin/composer" install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts
"$HOME/bin/composer" check-platform-reqs --no-dev
BUILD
    chgrp -Rh mailform "$build"
    chmod -R u=rwX,g=rX,o= "$build"
    setfacl -m u:nginx:--x "$build"
    setfacl -Rm u:nginx:rX "$build/public"
    sudo -Hu mailform-deploy ln -s /srv/mailform/shared/.env "$build/.env"
    sudo -Hu mailform-deploy ln -s /srv/mailform/shared/storage "$build/storage"
    # An interrupted install can reuse a completely published release. Composer
    # downloads are cached in the deploy user's home even if a build fails.
    touch "$state/release-$revision"
    mv -nT "$build" "$release"
    [[ ! -e $build ]] || core_error "同名のリリースがあるため配置できません: $release"
)

core_sql() {
    mariadb --no-defaults --protocol=socket --socket=/var/lib/mysql/mysql.sock \
        --user=root --batch --skip-column-names --execute="$1"
}

core_database() {
    local phase=$1 reservation="$state/db-$db_name" present plugin grant_db
    [[ $(core_sql 'SELECT @@skip_networking, @@socket, VERSION()') == 1$'\t'/var/lib/mysql/mysql.sock$'\t'11.8.* ]] ||
        core_error 'MariaDB 11.8 のローカルソケットと skip-networking を確認してください。'
    present=$(core_sql "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$db_name'")
    plugin=$(core_sql "SELECT plugin FROM mysql.user WHERE User='mailform' AND Host='localhost'")
    if [[ ! -d $reservation ]]; then
        [[ $present == 0 ]] || core_error "既存DB $db_name の使用を中止しました。"
        if [[ $phase == staging && -n $plugin ]]; then
            core_error '既存の MariaDB ユーザー mailform を確認してください。'
        fi
        mkdir -m 0700 "$reservation"
    fi
    if [[ -z $plugin && $phase == staging ]]; then
        core_sql "CREATE USER 'mailform'@'localhost' IDENTIFIED VIA unix_socket"
    elif [[ $plugin != unix_socket ]]; then
        core_error 'mailform の Unix ソケット認証を確認してください。'
    fi
    if [[ $present == 0 ]]; then
        [[ ! -e $reservation/created ]] || core_error "初期化済みの DB $db_name が見つかりません。"
        core_sql "CREATE DATABASE \`$db_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    fi
    touch "$reservation/created"
    # Database-level GRANT treats underscores as wildcard characters.
    grant_db=${db_name//_/\\_}
    core_sql "GRANT ALL PRIVILEGES ON \`$grant_db\`.* TO 'mailform'@'localhost'"
    sudo -Hu mailform mariadb --no-defaults --protocol=socket --socket=/var/lib/mysql/mysql.sock \
        --user=mailform --database="$db_name" --execute='SELECT 1' >/dev/null
}

core_render_env() {
    # shellcheck disable=SC2016 # Keep PHP expressions literal.
    php -r '
        [, $example, $phase, $url, $path, $database] = $argv;
        $production = $phase === "production";
        $socket = "/var/lib/mysql/mysql.sock";
        $settings = [
            "APP_ENV" => $phase, "APP_KEY" => "base64:".base64_encode(random_bytes(32)),
            "APP_DEBUG" => "false", "APP_URL" => $url,
            "DB_CONNECTION" => "mariadb", "DB_HOST" => "localhost", "DB_SOCKET" => $socket,
            "DB_DATABASE" => $database, "DB_USERNAME" => "mailform", "DB_PASSWORD" => "",
            "LEGACY_DB_DRIVER" => "mariadb", "LEGACY_DB_HOST" => "localhost", "LEGACY_DB_SOCKET" => $socket,
            "LEGACY_DB_DATABASE" => "mailform_legacy_snapshot", "LEGACY_DB_USERNAME" => "mailform_legacy_ro",
            "LEGACY_DB_PASSWORD" => "", "SESSION_DRIVER" => "database", "SESSION_ENCRYPT" => "true",
            "SESSION_SECURE_COOKIE" => $production ? "true" : "false",
            "SESSION_COOKIE" => $production ? "mailform_prod_session" : "mailform_stage_session",
            "SESSION_PATH" => $path === "" ? "/" : $path, "CACHE_STORE" => "database",
            "MAILFORM_DELIVERY_ENABLED" => "false", "MAIL_MAILER" => "array",
        ];
        $lines = file($example, FILE_IGNORE_NEW_LINES);
        if ($lines === false) exit(1);
        $output = "";
        $written = [];
        foreach ($lines as $line) {
            if (preg_match("/^\\s*(?:export\\s+)?([A-Z0-9_]+)\\s*=/", $line, $m) && isset($settings[$m[1]])) {
                if (isset($written[$m[1]])) continue;
                $line = $m[1]."=".$settings[$m[1]];
                $written[$m[1]] = true;
            }
            $output .= $line."\n";
        }
        foreach (array_diff_key($settings, $written) as $key => $value) $output .= "$key=$value\n";
        if (fwrite(STDOUT, $output) !== strlen($output) || !fflush(STDOUT)) exit(1);
    ' "$release/.env.example" "$1" "$app_url" "$mount_path" "$db_name"
}

core_environment() (
    local phase=$1 shared=$2 record="$state/$1-env.sha256" tmp existing sum
    local env_file="$shared/.env" pending="$shared/.setup-$phase.env"
    if [[ ! -f $record ]]; then
        [[ ! -e $env_file && ! -L $env_file ]] || core_error "既存の $env_file を保全しました。"
        if [[ ! -e $pending ]]; then
            tmp=$(mktemp "$shared/.env.XXXXXXXX")
            trap 'rm -f -- "$tmp"' EXIT
            core_render_env "$phase" > "$tmp"
            chown root:mailform "$tmp"
            chmod 0640 "$tmp"
            ln -T "$tmp" "$pending"
        fi
        # The root-only record contains a digest, never a second copy of APP_KEY.
        sha256sum "$pending" | cut -d ' ' -f 1 > "$record.tmp"
        mv -T "$record.tmp" "$record"
    fi
    existing=$env_file
    if [[ ! -e $env_file && ! -L $env_file ]]; then existing=$pending; fi
    [[ -f $existing && ! -L $existing ]] || core_error "初期設定で作成した $env_file が見つかりません。"
    sum=$(sha256sum "$existing")
    [[ ${sum%% *} == "$(cat "$record")" ]] || core_error "$env_file は初期設定から変更されています。内容を保全しました。"
    if [[ $existing == "$pending" ]]; then ln -T "$pending" "$env_file"; fi
    if [[ -f $pending && $pending -ef $env_file ]]; then rm "$pending"; fi
)

core_label() {
    local path=$1 pattern=$2 type=$3
    if [[ $(matchpathcon -n "$path") != *":$type:"* ]]; then
        semanage fcontext -a -t "$type" "$pattern"
    fi
}

core_migrate_app() {
    local shared=/srv/mailform/shared cache="$release/bootstrap/cache"
    [[ ! -L $shared && ! -L $shared/storage && ! -L $release/bootstrap && ! -L $cache ]] ||
        core_error '共有領域またはキャッシュへのシンボリックリンクを確認してください。'
    install -d -o mailform -g mailform -m 0700 "$shared/storage" "$cache"
    sudo -Hu mailform bash -ceu 'umask 077; cd "$1"; mkdir -p app/private framework/{cache/data,cache/locks,sessions,testing,views} logs' -- "$shared/storage"
    core_label /srv/mailform '/srv/mailform(/.*)?' httpd_sys_content_t
    core_label "$shared/storage" '/srv/mailform/shared/storage(/.*)?' httpd_sys_rw_content_t
    core_label "$cache" '/srv/mailform/releases/[^/]+/bootstrap/cache(/.*)?' httpd_sys_rw_content_t
    restorecon -F /srv/mailform /srv/mailform/releases
    restorecon -RF "$shared" "$release"
    sudo -Hu mailform bash -se -- "$release" <<'ARTISAN'
set -u
umask 077
cd "$1"
php artisan config:clear --no-interaction
php artisan package:discover --no-interaction
php artisan migrate --force --no-interaction
php artisan config:cache --no-interaction
php artisan mailform:check --expect-paused
ARTISAN
}

core_initialize_app() {
    local phase=$1 shared=/srv/mailform/shared
    [[ ! -L $shared ]] || core_error '共有領域へのシンボリックリンクを確認してください。'
    install -d -o root -g mailform -m 0750 "$shared"
    core_environment "$phase" "$shared"
    core_database "$phase"
    core_migrate_app
}

provision_stage() (
    set -euo pipefail
    umask 077
    [[ $db_name == mailform_stage && $app_url == "http://127.0.0.1:8008$mount_path" ]] ||
        core_error '検証用DBとループバックURLを確認してください。'
    core_install_host
    core_build_release
    core_initialize_app staging
    if [[ ! -e /srv/mailform/current && ! -L /srv/mailform/current ]]; then
        ln -sT "$release" /srv/mailform/current
    fi
    [[ $(readlink /srv/mailform/current) == "$release" ]] || core_error 'current の参照先を確認してください。'
    restorecon -F /srv/mailform/current
)

provision_production() (
    set -euo pipefail
    umask 077
    [[ $db_name == mailform && $app_url == https://* ]] || core_error '本番用DBとHTTPSのURLを確認してください。'
    [[ $(readlink /srv/mailform/current) == "$release" ]] || core_error '検証したリリースの参照先を確認してください。'
    core_check_release
    # These directories are created only after the production env is recorded.
    # On the first run, both rehearsal directories must have been moved away.
    if [[ ! -f $state/production-env.sha256 && ( -e /srv/mailform/shared/storage || -e $release/bootstrap/cache ) ]]; then
        core_error '練習用の保存領域と設定キャッシュの退避を確認してください。'
    fi
    core_initialize_app production
)
