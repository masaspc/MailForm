#!/usr/bin/env bash
# shellcheck disable=SC2154

snapshot_themes() (
    set -euo pipefail
    local snapshot="$state/rehearsal-themes" tmp
    if [[ -d $snapshot ]]; then
        cd "$snapshot"
        sha256sum --check --strict --status SHA256SUMS || die '練習テーマの引継ぎ記録を照合できません。'
        return
    fi
    [[ ! -e /srv/mailform/rehearsal/shared ]] || die '退避済み練習環境のテーマ引継ぎ記録がありません。'
    tmp=$(mktemp -d "$state/.themes.XXXXXXXX")
    trap 'rm -rf -- "$tmp"' EXIT
    cd "$release"
    runuser -u mailform -- env -i PATH="$PATH" HOME=/nonexistent php deploy/almalinux10/theme-handoff.php export > "$tmp/themes.jsonl"
    cd "$tmp"
    sha256sum themes.jsonl > SHA256SUMS
    mv -T "$tmp" "$snapshot"
)

restore_themes() (
    set -euo pipefail
    local snapshot="$state/rehearsal-themes"
    cd "$snapshot"
    sha256sum --check --strict --status SHA256SUMS || die '練習テーマの引継ぎ記録を照合できません。'
    cd "$release"
    runuser -u mailform -- env -i PATH="$PATH" HOME=/nonexistent php deploy/almalinux10/theme-handoff.php restore < "$snapshot/themes.jsonl"
)

archive_stage_grant() {
    local granted
    granted=$(core_sql "SELECT COUNT(*) FROM mysql.db WHERE Host='localhost' AND User='mailform' AND Db=CONCAT('mailform',CHAR(92),'_stage')")
    if [[ $granted == 1 ]]; then
        core_sql "REVOKE ALL PRIVILEGES ON \`mailform\\_stage\`.* FROM 'mailform'@'localhost'"
    fi
    if runuser -u mailform -- mariadb --no-defaults --protocol=socket --socket=/var/lib/mysql/mysql.sock \
            --user=mailform --database=mailform_stage --execute='SELECT 1' >/dev/null 2>&1; then
        die '退避した練習DBへのアプリ利用者のアクセス権を確認してください。'
    fi
}

review_themes() (
    set -euo pipefail
    local runtime socket=/var/lib/mysql/mysql.sock marker=/etc/nginx/mailform/maintenance verified
    [[ $phase == production-ready && -f $marker && ! -L $marker && $(readlink /srv/mailform/current) == "$release" ]] ||
        die 'テーマ確認は本番準備後、受付を停止している間に実行してください。'
    validate_web_host
    web_responding production || die '本番 nginx がメンテナンス中であることを確認してください。'
    [[ -z $(ss -Hltn 'sport = :8008') ]] || die '練習用ポート 8008 は既に使用されています。'
    cd "$release"
    local -a app=(runuser -u mailform -- env -i PATH="$PATH" HOME=/nonexistent)
    verified=$("${app[@]}" php deploy/almalinux10/target.php mailform "$socket" "$base_url")
    "${app[@]}" php artisan mailform:check --expect-paused
    runtime=$(mktemp -d /run/mailform-review.XXXXXXXX)
    trap 'rm -rf -- "$runtime"' EXIT
    trap 'exit 130' HUP INT TERM
    chown root:mailform "$runtime"
    chmod 0710 "$runtime"
    install -d -o mailform -g mailform -m 0700 "$runtime/cache" "$runtime/views" "$runtime/sessions"
    local -a isolated=("${app[@]}" APP_CONFIG_CACHE="$runtime/cache/config.php" APP_SERVICES_CACHE="$runtime/cache/services.php"
        APP_PACKAGES_CACHE="$runtime/cache/packages.php" APP_ROUTES_CACHE="$runtime/cache/routes.php"
        APP_EVENTS_CACHE="$runtime/cache/events.php" VIEW_COMPILED_PATH="$runtime/views")
    [[ $("${isolated[@]}" php deploy/almalinux10/target.php mailform "$socket" "$base_url") == "$verified" ]] ||
        die '本番の設定キャッシュと .env が一致しません。'
    rm -f -- "$state/production-import-verified"
    printf '%s\n' 'SSH 転送先  127.0.0.1:8008' 'テーマ確認  http://127.0.0.1:8008/admin/themes' \
        '終了は Ctrl-C。その後、同じ最終バックアップで import-backup.sh を再実行してください。'
    "${isolated[@]}" APP_URL=http://127.0.0.1:8008 ASSET_URL= APP_DEBUG=false \
        SESSION_DRIVER=file SESSION_COOKIE="mailform_review_$(openssl rand -hex 8)" SESSION_SECURE_COOKIE=false SESSION_PATH=/ SESSION_DOMAIN=null \
        MAILFORM_REVIEW_SESSIONS="$runtime/sessions" MAILFORM_REVIEW_MAINTENANCE="$marker" \
        MAILFORM_DELIVERY_ENABLED=false MAIL_MAILER=array RECAPTCHA_SITE_KEY= RECAPTCHA_SECRET= \
        php -d display_errors=0 -d upload_max_filesize=4M -d post_max_size=8M -S 127.0.0.1:8008 -t "$release/public" "$release/deploy/almalinux10/review-router.php"
)
