#!/usr/bin/env bash
# shellcheck disable=SC2154

preview_descendant() {
    [[ $1 =~ ^[0-9a-f]{40}$ && $2 =~ ^[0-9a-f]{40}$ && $1 != "$2" ]] &&
        "${checkout[@]}" merge-base --is-ancestor "$1" "$2"
}

backup_preview_database() (
    set -euo pipefail
    local record=$1 tmp
    if [[ -f $record/pre-migrate.sql.gz ]]; then
        gzip -t "$record/pre-migrate.sql.gz"
        return
    fi
    tmp=$(mktemp "$record/.database.XXXXXXXX")
    trap 'rm -f -- "$tmp"' EXIT
    mariadb-dump --no-defaults --protocol=socket --socket=/var/lib/mysql/mysql.sock --user=root \
        --single-transaction --quick --skip-lock-tables --hex-blob mailform_stage | gzip -c > "$tmp"
    gzip -t "$tmp"
    mv -T "$tmp" "$record/pre-migrate.sql.gz"
)

update_preview() (
    set -euo pipefail
    local old current record="$state/preview-updates/$revision" guard=/etc/nginx/mailform/maintenance switch probe old_identity new_identity
    case $phase in
        preview-ready)
            old=$(< "$state/revision")
            if [[ $old == "$revision" ]]; then
                if [[ $(readlink /srv/mailform/current) != "$release" ]] || ! web_responding preview; then
                    die '練習環境の現在の版と応答を確認してください。'
                fi
                show_preview
                return
            fi
            preview_descendant "$old" "$revision" || die '現在の練習版を含む検証済みの新しいコミットを選んでください。'
            [[ $(readlink /srv/mailform/current) == "/srv/mailform/releases/$old" ]] || die '練習環境の現在の版を確認してください。'
            validate_web_host
            [[ ! -e $guard ]] || die '既存のメンテナンス状態を確認してください。'
            core_build_release
            install -d -m 0700 "$record"
            save_value preview-update-from "$old"
            save_value preview-update-target "$revision"
            save_value phase preview-updating ;;
        preview-updating)
            old=$(< "$state/preview-update-from")
            if [[ $(< "$state/preview-update-target") != "$revision" ]] || ! preview_descendant "$old" "$revision"; then
                die '中断した練習更新と同じコミットを選んで update-preview を再実行してください。'
            fi
            core_check_release ;;
        *) die '版の更新は本番準備に入る前の練習環境で実行してください。' ;;
    esac
    current=$(readlink /srv/mailform/current)
    [[ $current == "/srv/mailform/releases/$old" || $current == "$release" ]] || die '練習環境の current を確認してください。'
    cd "$current"
    # Reuse the staging identity, APP_KEY, database and storage under the new release.
    local -a app=(runuser -u mailform -- env -i PATH="$PATH" HOME=/nonexistent)
    old_identity=$("${app[@]}" php -- mailform_stage /var/lib/mysql/mysql.sock "$base_url" < "$repo/deploy/almalinux10/target.php")
    cd "$release"
    probe=$(mktemp -d /run/mailform-preview.XXXXXXXX)
    trap 'rm -rf -- "$probe"' EXIT
    chown mailform:mailform "$probe"
    new_identity=$("${app[@]}" APP_CONFIG_CACHE="$probe/config.php" APP_PACKAGES_CACHE="$probe/packages.php" APP_SERVICES_CACHE="$probe/services.php" \
        php deploy/almalinux10/target.php mailform_stage /var/lib/mysql/mysql.sock "$base_url")
    [[ $old_identity =~ ^[0-9a-f]{64}$ && $old_identity == "$new_identity" ]] ||
        die '更新前後で練習DB、設置URLまたは暗号鍵が異なります。'
    quiesce_preview
    backup_preview_database "$record"
    core_migrate_app
    cd "$release"
    [[ $("${app[@]}" php deploy/almalinux10/target.php mailform_stage /var/lib/mysql/mysql.sock "$base_url") == "$old_identity" ]] ||
        die '更新した練習環境のDB・設置URL・暗号鍵を照合できません。'
    switch=$(mktemp -d /srv/mailform/.preview-link.XXXXXXXX)
    trap 'rm -rf -- "$probe" "$switch"' EXIT
    ln -sT "$release" "$switch/current"
    mv -Tf "$switch/current" /srv/mailform/current
    restorecon -F /srv/mailform/current
    rm -f -- "$guard"
    configure_preview_web
    save_value revision "$revision"
    save_value phase preview-ready
    printf '練習環境を更新しました。更新前のDB  %s/pre-migrate.sql.gz\n' "$record"
    show_preview
)
