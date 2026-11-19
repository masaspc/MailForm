# AlmaLinux 10.3 Minimal を用意した後の MailForm 移行先の設置

旧 EasyMail が稼働している間に、新しいサーバーを用意し、同じドメイン・同じパスで事前にアクセスできるところまで進めます。以下は nginx が HTTPS を終端し、同じホストの PHP-FPM と MariaDB を使う構成です。旧版の確定、最終バックアップ、受付停止、切替、切り戻しは[移行当日の手順](CUTOVER.md)に沿って実施します。

| 旧サイトの例 | nginx の組み込み先 | 新しい管理画面 |
| --- | --- | --- |
| `https://forms.example.jp/` をフォーム専用で運用 | そのホストの HTTPS `server` に `root-locations.conf` | `/admin` |
| `https://www.example.jp/contact-form/` だけが EasyMail | 既存の HTTPS `server` に `subdirectory-locations.conf` | `/contact-form/admin` |

## 1. コンソールから起動を確認する

VPS のコンソールまたは SSH で管理者として入り、`sudo -i` で root の Bash を開きます。インストール時に一般ユーザーへ管理権限を与えていない場合は、コンソールの root で `dnf install sudo openssh-server` を実行し、実在する管理者を `usermod -aG wheel 管理者名` で追加します。SSH 公開鍵を管理者に登録し、`systemctl enable --now sshd` の後、別の端末からログインと `sudo -v` を確認します。以下の OS 操作は root、アプリの実行は指定した専用ユーザーで行います。

~~~bash
cat /etc/almalinux-release
uname -m
df -h / /var /srv
ip -br address
ip route
dnf repolist
dnf upgrade --refresh
~~~

BaseOS と AppStream が有効で、時刻・ネットワーク・DNS が正しいことを確認します。カーネル更新後は新サーバーをコンソールから再起動し、再び `sudo -i` で入ります。DNS の現行 A/AAAA、TTL、HTTPS 証明書の管理者、旧フォームの URL 一覧、旧 DB・添付の容量、送信元に許可された SMTP をこの時点で控えます。公開 DNS は切替時点で変更します。

~~~bash
dnf install -y sudo openssh-server firewalld policycoreutils policycoreutils-python-utils \
  libselinux-utils selinux-policy-targeted audit acl git tar gzip unzip /usr/bin/curl \
  ca-certificates openssl chrony iproute shadow-utils vim-minimal procps-ng util-linux-core
systemctl enable --now chronyd
timedatectl
chronyc tracking
getenforce
dnf info php8.4-cli php8.4-fpm mariadb11.8-server nginx
dnf install -y --setopt=install_weak_deps=False \
  php8.4-cli php8.4-common php8.4-fpm php8.4-mbstring php8.4-pdo \
  php8.4-mysqlnd php8.4-xml php8.4-opcache mariadb11.8 mariadb11.8-server nginx
rpm -q nginx php8.4-cli php8.4-fpm mariadb11.8-server
php -v
php -r 'if (PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 90000) exit(1);
foreach (["ctype","curl","dom","fileinfo","filter","hash","mbstring","openssl","pcre","PDO","session","tokenizer","xml","zlib","pdo_mysql"] as $e) {
    if (!extension_loaded($e)) { fwrite(STDERR, "Missing extension: $e\n"); exit(1); }
} echo "PHP extensions OK\n";'
~~~

`getenforce` の期待値は `Enforcing` です。10.3 AppStream の PHP 8.4 はバージョン付き RPM を使い、CLI は `/usr/bin/php`、サービスは `php-fpm.service` です。`php-cli` の既定 8.3 はこのプロジェクトの PHP 条件を満たしません。MariaDB は 11.8 系を選択します。[AlmaLinux 10.3](https://wiki.almalinux.org/release-notes/10.3)・[10.2 で追加された AppStream](https://wiki.almalinux.org/release-notes/10.2)・[PHP のパッケージ構成](https://docs.redhat.com/en/documentation/red_hat_enterprise_linux/10/html/installing_and_using_dynamic_programming_languages/using-the-php-scripting-language)・[Laravel のサーバー要件](https://laravel.com/docs/13.x/deployment)

## 2. 公開ポートと管理経路を決める

`ZONE` は外部向け NIC に割り当てた firewalld ゾーンです。SSH が標準の 22/tcp 以外なら、下記の `ssh` 許可を実際の管理ポートの `--add-port=番号/tcp` に合わせます。コンソール接続を保持してください。VPS 側のファイアウォールでも管理者の接続元を許可します。

~~~bash
ZONE=public
if ! systemctl is-active --quiet firewalld; then
    firewall-offline-cmd --zone="$ZONE" --add-service=ssh
fi
systemctl enable --now firewalld
firewall-cmd --get-active-zones
firewall-cmd --zone="$ZONE" --list-all
~~~

表示されたインターフェースを見て `ZONE` を合わせます。検証中の 443/tcp は VPN・管理元 IP に限定します。例は IPv4 の管理元 1 アドレスを許可する設定で、`192.0.2.10` を実 IP に置き換えます。管理用の別端末から疎通を確認します。

~~~bash
rule='rule family="ipv4" source address="192.0.2.10/32" service name="https" accept'
firewall-cmd --zone="$ZONE" --add-rich-rule="$rule"
firewall-cmd --permanent --zone="$ZONE" --add-rich-rule="$rule"
~~~

本番公開時は対象ゾーンと VPS 側の両方で 80/tcp・443/tcp を許可します。SSH の既存設定を保ち、外部から DB や FPM に接続するポートは開けません。公開時のコマンドは第 8 節にあります。[firewall-cmd の実行時・永続設定](https://firewalld.org/documentation/man-pages/firewall-cmd.html)

## 3. アカウント、非公開領域、DB を作る

ここからは新規の専用環境での初回操作です。`mailform-deploy` は Git・Composer とリリース配置、`mailform` は PHP-FPM・Artisan・定期実行、`nginx` は静的ファイルの配信を担当します。**移行先が 1 台の場合**は、そのマシンの `/srv/mailform` にまず検証用の DB・専用鍵・添付領域を用意します。旧サイトが受付中の間にリハーサルを終え、検証の受付とタイマーを停止して保全し、同じ移行先に本番用の空の DB、添付領域、専用鍵を用意して本取込を行います。具体的なディレクトリの入替は[切替手順](CUTOVER.md)にあります。検証機を別途用意できる場合も同じ構成を使えます。

~~~bash
useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin mailform
useradd --system --create-home --user-group --home-dir /var/lib/mailform-deploy --shell /usr/sbin/nologin mailform-deploy
install -d -o root -g root -m 0711 /srv/mailform
install -d -o mailform-deploy -g mailform -m 2711 /srv/mailform/releases
install -d -o root -g mailform -m 0750 /srv/mailform/shared
install -d -o mailform -g mailform -m 0700 /srv/mailform/shared/storage{,/app,/app/private,/framework,/framework/cache,/framework/cache/data,/framework/cache/locks,/framework/sessions,/framework/testing,/framework/views,/logs}
semanage fcontext -a -t httpd_sys_content_t '/srv/mailform(/.*)?'
semanage fcontext -a -t httpd_sys_rw_content_t '/srv/mailform/shared/storage(/.*)?'
semanage fcontext -a -t httpd_sys_rw_content_t '/srv/mailform/releases/[^/]+/bootstrap/cache(/.*)?'
restorecon -RF /srv/mailform
~~~

専用 DB サーバーを同じマシンで初期構築する例です。既設の MariaDB を他アプリと共用する場合は、既存の待受・認証設定を維持し、空の DB と専用ユーザーの作成のみを管理者に依頼します。下記はローカル Unix ソケットと OS アカウントで認証します。

~~~bash
printf '[mariadb]\nskip-networking\n' > /etc/my.cnf.d/mailform-local.cnf
systemctl enable --now mariadb
mariadb <<'SQL'
CREATE DATABASE mailform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mailform'@'localhost' IDENTIFIED VIA unix_socket;
GRANT ALL PRIVILEGES ON mailform.* TO 'mailform'@'localhost';
SQL
sudo -u mailform mariadb --protocol=socket mailform -Ne 'SELECT CURRENT_USER(), @@socket, @@skip_networking, VERSION()'
~~~

期待値は `mailform@localhost`、`/var/lib/mysql/mysql.sock`、`1`、`11.8.x` です。検証環境ではスキーマを `mailform_stage` とし、SQL の DB 名、GRANT 先、確認コマンド、後述の `DB_DATABASE` をそろえます。同一サーバーで本番用 DB を後から追加する場合は、既設の OS・DB ユーザーを使い、本番 DB の CREATE と GRANT のみを実行します。[MariaDB の Unix ソケット認証](https://mariadb.com/docs/server/reference/plugins/authentication-plugins/authentication-plugin-unix-socket)

旧 DB の複製はさらに別のスキーマへ復元します。例として、専用 DB `mailform_legacy_snapshot` に復元した後、MariaDB 11.8 で SQL を実行します。旧アプリを起動せず、この複製のみを参照します。

~~~sql
CREATE USER 'mailform_legacy_ro'@'localhost' IDENTIFIED VIA unix_socket AS 'mailform';
GRANT SELECT ON mailform_legacy_snapshot.* TO 'mailform_legacy_ro'@'localhost';
~~~

`sudo -u mailform mariadb --protocol=socket -u mailform_legacy_ro mailform_legacy_snapshot -e 'SHOW TABLES'` で確認します。旧ファイルは Web 配信領域外（例 `/srv/mailform-import/easymail`）に復号・展開し、所有者を管理者、グループを `mailform`、ディレクトリを `0750`、通常ファイルを `0640` とします。復元と件数の照合は[旧データの取込手順](IMPORT.md)と[切替手順](CUTOVER.md)に従います。

## 4. 固定した版を配置する

Composer の公式インストーラーを SHA-384 で照合し、配置専用ユーザーのホームへ導入します。[Composer の検証方法](https://getcomposer.org/doc/faqs/how-to-install-composer-programmatically.md)

~~~bash
sudo -Hu mailform-deploy bash -se <<'BUILD'
set -o pipefail
umask 077
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
cd "$stage"
curl -fsSLo installer.php https://getcomposer.org/installer
curl -fsSLo installer.sha384 https://composer.github.io/installer.sig
printf '%s  installer.php\n' "$(cat installer.sha384)" | sha384sum --check --status
install -d -m 0700 "$HOME/bin"
php installer.php --2 --install-dir="$HOME/bin" --filename=composer
"$HOME/bin/composer" --version
BUILD
~~~

GitHub の「Actions」→「MailForm tests」で、`main` ブランチの緑のチェックが付いた対象実行を開きます。実行概要の短いコミット ID をクリックし、コミット画面の「Copy full SHA」を押すか、そのページの URL の `/commit/` の後にある **40 桁の英数字** をコピーします。検証環境で使ったこの SHA を記録し、本番にも同じものを入力します。以降、リリース構築と初回設定のコマンドは同じ root の Bash で実行します。`set -e` は取得・検証に失敗した時点でこのシェルを終了します。[MailForm のテスト実行一覧](https://github.com/masaspc/MailForm/actions/workflows/test.yml)

~~~bash
set -euo pipefail
repo=/var/lib/mailform-deploy/repository
if ! test -d "$repo"; then
    sudo -Hu mailform-deploy git clone --no-checkout https://github.com/masaspc/MailForm.git "$repo"
fi
sudo -Hu mailform-deploy git -C "$repo" fetch --tags origin
read -rp '検証済みのコミット SHA (40 桁): ' revision
[[ "$revision" =~ ^[0-9a-f]{40}$ ]]
test "$(sudo -Hu mailform-deploy git -C "$repo" rev-parse --verify "$revision^{commit}")" = "$revision"
release=/srv/mailform/releases/$revision
test ! -e "$release"
install -d -o mailform-deploy -g mailform -m 2750 "$release"
sudo -Hu mailform-deploy bash -ceu '
set -o pipefail
umask 027
cd "$1"
git -C "$2" archive "$3" -- . ":(exclude)storage" | tar -x --no-same-permissions
test -f composer.lock
/var/lib/mailform-deploy/bin/composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts
/var/lib/mailform-deploy/bin/composer check-platform-reqs --no-dev
' -- "$release" "$repo" "$revision"
chgrp -Rh mailform "$release"
chmod -R u=rwX,g=rX,o= "$release"
chown -R mailform:mailform "$release/bootstrap/cache"
chmod 0700 "$release/bootstrap/cache"
setfacl -m u:nginx:--x "$release"
setfacl -Rm u:nginx:rX "$release/public"
sudo -u mailform-deploy ln -s /srv/mailform/shared/storage "$release/storage"
sudo -u mailform-deploy ln -s /srv/mailform/shared/.env "$release/.env"
~~~

`check-platform-reqs` が実 PHP と拡張を検査します。`composer.lock` を使い、`composer update` や `--ignore-platform-reqs` による解決を避けます。このプロジェクトのインストール後処理で必要な `package:discover` は、設定を用意した次の段階で実行します。[Composer CLI](https://getcomposer.org/doc/03-cli.md#check-platform-reqs)

## 5. 鍵、メール、セッション、DB を設定する

新規インストールで一度だけ、`.env.example` から鍵付きの `shared/.env` を作ります。`fopen(...,"x")` は同名ファイルがあると停止します。バックアップからの復元では、その DB のデータを暗号化した元の `APP_KEY` が必要です。

~~~bash
umask 077
php -r '
$source = file_get_contents($argv[1]);
$source = preg_replace("/^APP_KEY=$/m", "APP_KEY=base64:".base64_encode(random_bytes(32)), $source, 1, $count);
if ($count !== 1) exit(1);
$target = fopen($argv[2], "x");
if (!$target || fwrite($target, $source) !== strlen($source)) exit(1);
' "$release/.env.example" /srv/mailform/shared/.env
chown root:mailform /srv/mailform/shared/.env
chmod 0640 /srv/mailform/shared/.env
sed -i -E '/^[[:space:]]*(export[[:space:]]+)?MAILFORM_DELIVERY_ENABLED[[:space:]]*=/d' /srv/mailform/shared/.env
printf '\nMAILFORM_DELIVERY_ENABLED=false\n' >> /srv/mailform/shared/.env
vi /srv/mailform/shared/.env
~~~

以下は既存サイトの `/contact-form` に設置する本番準備時の設定例です。`APP_URL` は本番で使う HTTPS のホストとベースパスに置き換え、既存 `APP_KEY` を保ちます。ドメイン直下では `APP_URL=https://forms.example.jp`、`SESSION_PATH=/` です。旧 URL に `index.php` を含む場合も実際の印刷済み URL で確認します。

~~~dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://www.example.jp/contact-form
DB_CONNECTION=mariadb
DB_HOST=localhost
DB_SOCKET=/var/lib/mysql/mysql.sock
DB_DATABASE=mailform
DB_USERNAME=mailform
DB_PASSWORD=
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_COOKIE=mailform_prod_session
SESSION_PATH=/contact-form
CACHE_STORE=database
MAILFORM_DELIVERY_ENABLED=false
MAIL_MAILER=array
~~~

`SESSION_DOMAIN` は空のままでホスト限定にします。検証環境は独自の `APP_KEY`、`APP_ENV=staging`、検証用 `APP_URL`、`DB_DATABASE=mailform_stage`、`SESSION_COOKIE=mailform_stage_session` とします。検証環境に本番の SMTP 資格情報を置かず、通常は `MAILFORM_DELIVERY_ENABLED=false` です。承認された旧 DB の読み取り専用複製で移行を練習できます。試験受付には合成データを使います。送信経路の試験時だけ、転送機能のないループバック／閉域の SMTP 捕捉サーバーを指定し、送信待ちがテスト分だけであると確認して配信を有効にします。`array` で配信を有効にすると受信箱へ届く前にアプリの状態が送信済みになるため、配信確認には捕捉 SMTP を使います。

旧データ取込時に `LEGACY_DB_DRIVER=mariadb`、`LEGACY_DB_HOST=localhost`、`LEGACY_DB_SOCKET=/var/lib/mysql/mysql.sock`、`LEGACY_DB_DATABASE=mailform_legacy_snapshot`、`LEGACY_DB_USERNAME=mailform_legacy_ro`、`LEGACY_DB_PASSWORD=` を設定します。

~~~bash
restorecon -RF /srv/mailform
sudo -u mailform php "$release/artisan" package:discover
sudo -u mailform php "$release/artisan" migrate --force
sudo -u mailform php "$release/artisan" config:cache
sudo -u mailform php "$release/artisan" mailform:admin admin@example.jp
sudo -u mailform php "$release/artisan" mailform:check --expect-paused
ln -s "$release" /srv/mailform/.current.new
mv -Tf /srv/mailform/.current.new /srv/mailform/current
~~~

実際の管理者アドレスを入力し、パスワードは対話入力で設定します。DB・鍵・非公開ストレージが `OK`、メール配信が「停止中」なら初期化完了です。稼働中の更新ではマイグレーション前に第 8 節の保全を行います。

## 6. 証明書と nginx・PHP-FPM を設定する

DNS 切替前でも、本番ドメイン名に有効なフルチェーン証明書と秘密鍵を新サーバーに用意できます。既存証明書の管理者に再配備または発行を依頼するか、DNS の TXT レコードを使う ACME DNS-01 で発行します。DNS-01 は旧サーバーの A/AAAA レコードを保ったまま使えます。

* **管理している証明書を配置する場合**　管理者から受け取ったフルチェーンを `/etc/pki/nginx/mailform-fullchain.pem`（0644）、対応する秘密鍵を `/etc/pki/nginx/private/mailform.key`（root 所有、0600）に置きます。ディレクトリは `install -d -m 0755 /etc/pki/nginx` と `install -d -m 0700 /etc/pki/nginx/private` で作成し、`restorecon -RF /etc/pki/nginx` を実行します。証明書と鍵はリポジトリ・公開領域の外で管理します。
* **新規に DNS-01 で発行する場合**　Certbot を使う例は `dnf install dnf-plugins-core epel-release`、`dnf config-manager --set-enabled crb`、`dnf install certbot` の順です。`certbot certonly --manual --preferred-challenges dns -d forms.example.jp` を実行し、画面が示す TXT レコードを DNS 管理者が公開して検証完了を待ちます。nginx は `/etc/letsencrypt/live/実際の証明書名/fullchain.pem` と `privkey.pem` を直接参照します。手動 DNS の場合は失効前に再検証と nginx reload を行う担当者・期日を決めます。DNS API プラグインで自動化した場合は `certbot renew --dry-run`、`systemctl enable --now certbot-renew.timer` を確認し、更新成功時の deploy hook に `nginx -t && systemctl reload nginx` を登録します。[Certbot の発行・更新](https://eff-certbot.readthedocs.io/en/stable/using.html)・[EPEL 10.3 のパッケージ](https://packages.fedoraproject.org/pkgs/certbot/certbot/epel-10.3.html)

ホスト名と満了日を確認し、同梱のファイルを配置します。以下の `release` は新しいシェルに入り直した場合にも取得できます。

~~~bash
release=$(readlink -f /srv/mailform/current)
tpl=$release/deploy/almalinux10
openssl x509 -in /etc/pki/nginx/mailform-fullchain.pem -noout -subject -dates -checkhost forms.example.jp
install -d -m 0755 /etc/nginx/mailform
install -m 0644 "$tpl"/{fastcgi,root-locations,subdirectory-locations}.conf /etc/nginx/mailform/
install -m 0644 "$tpl/php-fpm.conf" /etc/php-fpm.d/mailform.conf
restorecon -RF /etc/php-fpm.d/mailform.conf /etc/nginx/mailform
/usr/sbin/php-fpm -t
systemctl enable --now php-fpm
systemctl reload php-fpm
ls -lZ /run/php-fpm/mailform.sock
~~~

専用プールのソケットは `nginx:nginx`、`srw-------`、`httpd_var_run_t` になります。他サービスのプールと既定の `www.sock` はそのまま利用できます。同梱例は 1 ファイル 10 MiB、添付合計 20 MiB のフォーム用に、PHP POST と nginx の受信上限を 24 MiB に設定しています。`pm.max_children` は 6 から始め、メモリーと同時接続数を実測して調整します。

**ドメイン直下**では、専用ホスト用に `/etc/nginx/conf.d/mailform-host.conf` を作ります。ドメイン・証明書パスは実値に置き換えます。HTTP の 308 はパスとクエリを保持します。

~~~nginx
server {
    listen 80;
    listen [::]:80;
    server_name forms.example.jp;
    return 308 https://forms.example.jp$request_uri;
}
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name forms.example.jp;
    ssl_certificate /etc/pki/nginx/mailform-fullchain.pem;
    ssl_certificate_key /etc/pki/nginx/private/mailform.key;
    include /etc/nginx/mailform/root-locations.conf;
}
~~~

**既存ドメインのサブディレクトリ**では、同名の `server` を追加せず、既存の HTTPS `server { ... }` の中に次の 1 行を加えます。新サーバーへドメイン全体を移すなら、既存サイトのファイル、仮想ホスト、TLS、他の URL の配信も先に復元します。既存のリバースプロキシで振り分ける場合は prefix を保持し、該当ホスト・パスだけをこの nginx に到達させます。例はこの nginx まで HTTPS で接続する構成です。

~~~nginx
include /etc/nginx/mailform/subdirectory-locations.conf;
~~~

テンプレートの `/contact-form` は旧設置パスにそろえ、ファイル内のすべての同じ箇所、`APP_URL`、`SESSION_PATH` を変更します。正規表現に使う `.` 等を含む既存パスは、正規表現の行でエスケープします。既存サイトの `root` や `location /` は維持します。ドメイン直下を他のコンテンツと共用する場合も、既存の優先 `location` と `/admin`・`/assets` 等の所有先を確認し、両アプリが同じ URL を必要とする箇所は個別に割り当ててから切り替えます。[現行 URL の対応表と例外](URLS.md)

いずれの設定も、静的配信は `public/assets` 内の CSS・JS・郵便番号データ等と `robots.txt`・`favicon.ico` に限定します。その他の動的リクエストでは、`SCRIPT_FILENAME` を常に `/srv/mailform/current/public/index.php` に固定します。これにより古い `index.php/問い合わせ` を受け付けつつ、別の PHP ファイルや添付の直アクセスを防ぎます。`storage:link` は不要です。[nginx の location・alias](https://nginx.org/en/docs/http/ngx_http_core_module.html#location)

~~~bash
nginx -t
systemctl enable --now nginx
systemctl reload nginx
systemctl is-active nginx php-fpm mariadb firewalld
ls -ldZ /srv/mailform/current/bootstrap/cache /srv/mailform/shared/storage/app/private
sudo -u nginx test -r /srv/mailform/current/public/assets/form.css
sudo -u nginx test ! -r /srv/mailform/shared/.env
sudo -u mailform test ! -w /srv/mailform/current/public/index.php
sudo -u mailform test -w /srv/mailform/shared/storage/app/private
~~~

ローカルの MariaDB ソケットでは SELinux の外向き TCP 接続許可は不要です。reCAPTCHA v3 を使うフォームは PHP-FPM から Google の HTTPS API を呼びます。実際に利用する場合は出口で対象を制限したうえで `setsebool -P httpd_can_network_connect on` の適用範囲（ホストの Web プロセス全体）を確認し、対象ドメイン用の鍵で動作を試します。403・502・500 の調査には `journalctl -u php-fpm -u nginx`、`/var/log/nginx/error.log`、`ausearch -m AVC -ts recent`、`ls -lZ` を使います。配置を変更したときは `semanage fcontext` のルールと `restorecon` でラベルを適用します。

## 7. 定期処理と本番切替前の確認

~~~bash
tpl=/srv/mailform/current/deploy/almalinux10
install -m 0644 "$tpl"/mailform-schedule.{service,timer} /etc/systemd/system/
restorecon /etc/systemd/system/mailform-schedule.{service,timer}
systemctl daemon-reload
systemd-analyze verify /etc/systemd/system/mailform-schedule.{service,timer}
systemctl enable --now mailform-schedule.timer
systemctl start mailform-schedule.service
systemctl list-timers --all mailform-schedule.timer
journalctl -u mailform-schedule.service -n 15 --no-pager
sudo -u mailform php /srv/mailform/current/artisan mailform:check --expect-paused
~~~

毎分の `schedule:run` は送信待ち配信、期限切れデータの整理、実行記録を担当します。`mailform:check` に直近 5 分の時刻が出ることを確認します。`MAILFORM_DELIVERY_ENABLED=false` で停止するのはメール配信で、整理処理と実行記録は動きます。同じアプリに cron を重ねて登録する必要はありません。

本番の DNS を変える前に管理端末から SNI・Host の両方を旧ドメイン名にして新 IP を確認します。ブラウザーでのリハーサルでは、その端末だけ hosts を上書きして同じ URL を開きます。以下はサブディレクトリの例です。検証用 CA を使用する場合は `--cacert 検証用CA.pem` で署名を検証します。

~~~bash
host=www.example.jp
ip=192.0.2.20
base=/contact-form
for path in /up /admin/login /assets/form.css /assets/form.js /assets/form-postal/1.json.gz /.env /storage/app/private/test; do
    curl --resolve "$host:443:$ip" -sS -o /dev/null -w '%{http_code} %{url_effective}\n' "https://$host$base$path"
done
~~~

先頭 5 件が 200、末尾 2 件が 404 になることを確認します。`/up` だけでは DB・添付・配信の確認はできません。代表フォームで入力、必須エラー、確認、戻る、添付、送信、完了、管理ログイン、件数・CSV・添付取得を試します。旧 URL、クエリ付き URL、郵便番号補完、隣接する既存ページ、Cookie の Secure・HttpOnly・Path を確認します。検証用 DB で捕捉 SMTP を使い、通知・自動返信が想定件数だけ届くことを確認します。[切替時の受入項目](CUTOVER.md)

## 8. 公開、バックアップ、更新と切り戻し

旧サイト受付停止から最終取込、照合、DNS／既存プロキシの切替、切替後の受付の保全と引継ぎまでの順序は[移行当日の手順](CUTOVER.md)にあります。本番 SMTP の例は `MAIL_MAILER=smtp`、`MAIL_SCHEME=smtps`、`MAIL_PORT=465` です。プロバイダー指定のホスト・TLS 方式・認証・`MAIL_FROM_ADDRESS` を設定し、SPF・DKIM・DMARC の既存契約を確認してください。`sudo -u mailform php /srv/mailform/current/artisan config:cache` の後、配信停止のまま `mailform:check --smtp --expect-paused` で接続を確認します。公開判定後に `MAILFORM_DELIVERY_ENABLED=true`、`config:cache`、`systemctl reload php-fpm` を適用して配信状態を再確認します。

~~~bash
ZONE=public  # 第 2 節で確認した外部ゾーン
for service in http https; do
    firewall-cmd --zone="$ZONE" --add-service="$service"
    firewall-cmd --permanent --zone="$ZONE" --add-service="$service"
done
firewall-cmd --zone="$ZONE" --list-services
~~~

**稼働後のバックアップ**では、受付・処理中のリクエスト・添付・メール配信の時点をそろえます。以下はアプリを短時間停止できる場合の例です。事前に暗号化バックアップ用媒体を `/mnt/mailform-backup` にマウントし、別ホストへの保管と取り出し権限を確認します。

~~~bash
set -euo pipefail
umask 077
mountpoint -q /mnt/mailform-backup
backup=/mnt/mailform-backup/$(date -u +%Y%m%dT%H%M%SZ)
mkdir -m 0700 "$backup"
install -m 0644 /dev/null /etc/nginx/mailform/maintenance
restorecon /etc/nginx/mailform/maintenance
systemctl stop mailform-schedule.timer
while :; do
    case "$(systemctl show -p ActiveState --value mailform-schedule.service)" in inactive|failed) break ;; esac
    sleep 1
done
while pgrep -f '^php-fpm: pool mailform$' >/dev/null; do sleep 1; done
mariadb-dump --single-transaction --quick --hex-blob --routines --events --triggers mailform | gzip > "$backup/db.sql.gz"
tar -C /srv/mailform/shared -czf "$backup/shared.tar.gz" .env storage
basename "$(readlink -f /srv/mailform/current)" > "$backup/revision"
(cd "$backup" && sha256sum db.sql.gz shared.tar.gz revision > SHA256SUMS)
~~~

メンテナンスファイルは MailForm の動的 GET・POST を 503 にし、専用プールの処理中リクエストが終わるまで待てます。既存サイトの他パスは継続します。取込・手操作による更新もこの間止めます。`db.sql.gz`、非公開添付を含む `storage`、`APP_KEY` を含む `.env` とバージョンを一組で保管します。通常バックアップを終えたら `rm /etc/nginx/mailform/maintenance` と `systemctl start mailform-schedule.timer` で元の運用を再開します。切り戻し作業中は停止を維持します。

**復元訓練**は接続先を隔離した環境に、上記と同じ SHA を配置して行います。タイマーと受付を止め、`(cd "$backup" && sha256sum -c SHA256SUMS)` を確認します。空の専用 DB 例 `mailform_restore` に必要な権限を付け、`gzip -dc "$backup/db.sql.gz" | mariadb mailform_restore` で復元します。`shared.tar.gz` は所有者限定の作業ディレクトリに展開し、復元先の空の `shared/storage` に中身を移し、`chown -R mailform:mailform /srv/mailform/shared/storage`、`restorecon -RF /srv/mailform` を実行します。復元用 `.env` にはバックアップと同じ `APP_KEY` を設定し、DB・URL・Cookie・メール設定を復元環境専用にします。`config:cache` と `mailform:check --expect-paused` の成功を確認してから、既知の受付の回答を復号でき、添付の件数・SHA-256 とメール状態が保存時と一致することを確かめます。

**更新時**は旧リリースとバックアップを保ち、第 4 節から新しい SHA を別ディレクトリへ展開します。共有 `.env` と `storage` を参照し、リリースごとの `bootstrap/cache` を用意します。受付・定期処理を止めて復旧点を取ってから `package:discover`、`migrate --force`、`config:cache`、`mailform:check` を新リリースの `artisan` で実行し、`current` を同じファイルシステム上で `mv -Tf` により切り替え、PHP-FPM を reload します。DB 形式に互換性があり受付再開前なら、`current` を旧リリースに戻せます。DB・鍵・添付の変更や切替後の受付がある場合は、稼働 DB への上書きを避け、受付・配信状態の保全と照合後に[切り戻し手順](CUTOVER.md)を実行してください。

## この手順で確認した環境

2026-11-19 に公式 AlmaLinux 10.3 の aarch64 `Minimal Install` グループ（基礎環境 314 RPM）から、上記パッケージの導入、PHP 拡張、固定依存関係のプラットフォーム検査を確認しました。サービスの起動と HTTP の試験には、別の公式 AlmaLinux 10.3 GenericCloud aarch64 仮想マシンを使いました。PHP/FPM 8.4.27、nginx 1.26.3、MariaDB 11.8.8、Composer 2.11.2、SELinux Enforcing、firewalld 有効で、ドメイン直下と既存サイトのサブディレクトリの両構成を確認しています。

仮想マシンでは、検証用証明書での HTTPS、サブディレクトリ外の既存ページ、`index.php` 付き URL、日本語・空白入り URL、CSS・JS・郵便番号データ、フォームの受付から完了、非公開ファイルの HTTP 404、systemd の毎分実行、配信停止と再開、ループバックの SMTP 捕捉先への到着を確認しました。利用者の VPS、公的証明書の発行、公開 DNS、契約 SMTP、実際の旧データの一致は設置先の確認項目です。
