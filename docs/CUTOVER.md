# 同じURLでEasyMailから切り替える

旧サイトで受付を続けたまま、新しいAlmaLinuxサーバーで移行を練習できます。ドメイン、設置パス、フォームの公開パスを維持し、利用者がアクセスするサーバーを最後に一度切り替えます。旧版にしかない入力中のセッション・一時添付は引き継げないため、最終取込には短い受付停止時間を設けます。

## 1. 旧サイトで控えるもの

1. **管理入口と版番号**。旧EasyMailの設置URLの末尾に `/admin` を付けます。たとえば `https://www.example.jp/contact/recruit` の `recruit` がフォーム名なら、入口は `https://www.example.jp/contact/admin` です。管理トップの製品名の横に版番号があります。ログインできない場合は設定ファイルをテキストで読めます。[画面とファイルから版番号を確認する](EASYMAIL-VERSION.md)
2. **現行URL**。ドメイン・サブディレクトリ・個別フォーム名を分けて記録します。管理画面の一覧に加え、Webサイト、印刷物、QRコード、広告や外部サイトで実際に使うURLも控えます。プロキシの別名、独自の書き換え、クエリーつきの入口は、少なくとも代表例を1件ずつ保存します。[URLの対応と確認方法](URLS.md)
3. **移行対象と判定基準**。フォームごとの公開・停止状態、項目、必須条件、返信・通知先、保存の有無、デザイン、代表メールとCSVを控えます。DB、設置ファイル、添付ディレクトリ、Webサーバーの現行設定を権限のある担当者がバックアップします。旧版の管理ユーザーやパスワードは新版で個別に発行します。[DBと添付の対応範囲](IMPORT.md)

## 2. 並行稼働の配置

| 環境 | データ | 利用とメール |
| --- | --- | --- |
| 旧本番 | 旧DB・旧添付 | 切替まで実際の受付を担当。試験中も通常どおり運用する。 |
| 新版の練習環境 | 旧バックアップの複製を読み取り、練習専用DB・専用ストレージに取り込む | 限定公開。試験送信は転送先のない捕捉用SMTPへ。 |
| 新本番の移行先 | 最終的に利用する別の新DB・非公開ストレージ・アプリ鍵 | 切替前は一般から遮断し、実際のフォーム送信は受け付けない。旧履歴を何回取り込んでも通知は生成されない。 |

新しいマシンにはMinimalの初期状態から手順に沿ってMailFormを設置します。練習用とは別の `APP_KEY`、DB、保存ディレクトリ、セッションCookie名を使います。新本番の `APP_URL` には実際に引き継ぐHTTPSの設置URLを指定します。例は `https://www.example.jp/contact` です。[AlmaLinux 10.3 Minimalの設置手順](ALMALINUX10.md)

練習中と新本番の待機中は `MAILFORM_DELIVERY_ENABLED=false` を `.env` に設定し、実行ユーザーで `php artisan config:cache` を実行します。`php artisan mailform:check --expect-paused` の終了コード0と「メール配信　停止中」を確認します。配信が有効ならこの検査は終了コード1になります。停止中は定期実行・手動のどちらでも送信待ちを消費しません。メール本文・添付を練習する時だけ、練習環境のSMTPを捕捉サーバーに固定して配信を有効にします。試験受付を含む練習DBを本番に転用すると、残った送信待ちも配信対象になります。

### 新しいAlmaLinuxが1台の場合

旧サーバーと新サーバーの2台で実施できます。新サーバーではまず `mailform_stage` と練習用の鍵・ストレージで第3節のリハーサルを行います。簡単設置を使った方は、[切替当日](ALMALINUX10.md#切替当日)の `setup.sh production` がこの入れ替えを行います。以下は[手動設置](ALMALINUX10-MANUAL.md)を選んだ場合に、新サーバーで行う操作です。

1. nginxの管理元制限を維持し、MailForm専用のメンテナンスファイルを作ります。試験のGET・POSTが503となることを確認します。定期実行を止め、起動済みの `mailform-schedule.service` と処理中のWebリクエストが終わるのを待ちます。専用マシンならPHP-FPMを停止します。他のサービスと同居していればMailFormのプールのみ停止してください。
2. 練習用の設定・鍵・ストレージ・設定キャッシュを、rootだけが読める別ディレクトリに移動します。練習DBは `mailform_stage` のまま保全します。下記はrootのBashで実行する例です。稼働中の旧サイトには操作しません。

   ```bash
   install -m 0644 /dev/null /etc/nginx/mailform/maintenance
   restorecon /etc/nginx/mailform/maintenance
   systemctl stop mailform-schedule.timer
   systemctl show -p ActiveState --value mailform-schedule.service
   # 上が inactive、処理中リクエストもゼロになった専用マシンで実行
   systemctl stop php-fpm
   release=$(readlink -e /srv/mailform/current)
   test -f "$release/artisan" || exit 1
   umask 077
   hold=$(mktemp -d /srv/mailform-stage.XXXXXXXX)
   mv /srv/mailform/shared "$hold/shared"
   mv "$release/bootstrap/cache" "$hold/bootstrap-cache"
   install -d -o mailform -g mailform -m 0700 "$release/bootstrap/cache"
   install -d -o root -g mailform -m 0750 /srv/mailform/shared
   install -d -o mailform -g mailform -m 0700 /srv/mailform/shared/storage{,/app,/app/private,/framework,/framework/cache,/framework/cache/data,/framework/cache/locks,/framework/sessions,/framework/testing,/framework/views,/logs}
   restorecon -RF /srv/mailform
   ```

3. AlmaLinux設置手順の第3節を使って本番用の**新しい空DB** `mailform` を作り、実行ユーザーへそのDBの権限を付与します。既に作成したOSユーザー・DBユーザーは再利用できます。第5節の手順で、新しい `shared/.env` と本番用の鍵を作成します。`DB_DATABASE=mailform`、本番用のCookie名、本来の `APP_URL`、`MAILFORM_DELIVERY_ENABLED=false` を確認し、`package:discover`、`migrate --force`、`config:cache`、管理者作成、`mailform:check --expect-paused` を実行します。組み込み済みの `current` は同じリリースを指しています。
4. 新本番で `php artisan mailform:handoff --require-empty` が0件、`mailform:check --expect-paused` で配信停止と表示されることを確認し、PHP-FPMを起動します。第4節の最終取込を行うまで公開と定期配信は停止したままです。練習用の受付・メールは退避した環境にのみ残ります。練習・本番を別VMに設置できる場合は、この入れ替え作業を省けます。

## 3. 最初のコピーとリハーサル

1. 旧DBのバックアップを新サーバーの専用のスナップショットDBに復元します。以下はMariaDBの例です。旧サーバーで実行するのは読み取り用のダンプのみです。新サーバーの空DBで復元権限を持つアカウントと、MailFormからそのDBを参照する `SELECT` 専用アカウントを分けます。パスワードは権限0600のクライアント設定ファイルまたは対話入力で渡してください。DBに旧EasyMail以外のサービスが同居している場合は対象テーブルを特定してから取得します。

   ```sh
   # 旧サーバー。DB名と保存先を実際の値に置き換える
   umask 077
   mariadb-dump --defaults-extra-file=/secure/old-read.cnf --single-transaction \
     --quick --skip-triggers --hex-blob --default-character-set=utf8mb4 easymail > /secure/first.sql

   # 新サーバー。暗号化した転送経路でコピー済みのダンプを、用意した空の複製DBに復元
   mariadb --defaults-extra-file=/secure/snapshot-restore.cnf --binary-mode --local-infile=0 \
     easymail_first < /secure/first.sql
   ```

   バックアップのDB製品・版に対応するダンプツールを使用します。MySQLの場合は元に合う `mysqldump` で取得し、複製を復元して内容を検証します。追加プログラム、イベント、トリガーの実行は本移行の要件に含みません。DBのスナップショットと同じ回の設置ファイル・添付のコピーを公開領域の外に保存します。実施中も受付を続ける初回コピーでは、書込み中の添付が不足することがあります。最終回は受付停止後に揃えます。
2. MailFormの `.env` の `DB_*` は新DB、`LEGACY_DB_*` は複製した旧DBの読み取り専用接続に設定します。`php artisan config:cache` を実行します。以下の `php artisan` はすべて新版のディレクトリでアプリ実行ユーザーとして実行します。レポートの出力先は担当者だけが読める場所にします。

   ```sh
   umask 077
   report_dir=$(mktemp -d)
   php artisan easymail:import --dry-run --strict --source-root=/backup/first/EasyMail \
     --source-base=https://www.example.jp/contact --json > "$report_dir/preflight.json"
   php artisan easymail:import --source-root=/backup/first/EasyMail --json > "$report_dir/import.json"
   php artisan easymail:import --verify-only --source-root=/backup/first/EasyMail --json > "$report_dir/verify.json"
   ```

   コマンドの終了状態は各行で確認します。結果は保護した運用記録に移し、最終取込・切り戻しを別のSSHセッションで行う場合は `report_dir` にその回の保存先を再指定します。`--dry-run --strict` の終了コード2は、URL 衝突、必須列の不足、添付欠損などを示します。フォーム別の確認事項は該当フォームを下書きにして取込と照合を続けます。`--verify-only` の0は対象データの照合成功、2は不足や差異、1は設定や実行エラーです。診断に出た保留事項は照合結果とは別に解消します。旧側で消えた履歴が新版に残る場合は利用目的・保存方針と照合し、必要な消去を済ませます。新版で消去済みの内容は再取込しても復元されません。[診断・照合の項目](IMPORT.md)
3. 練習環境で同じURLを開き、入力→確認→戻る→添付→送信→完了、管理画面の履歴、CSV、捕捉した管理者通知・自動返信を照合します。新本番に本番ドメインの有効な証明書を用意しておけば、公開DNSの変更前でも管理用端末から実際の名前・パスで表示確認できます。次の値は例です。

   ```sh
   curl --resolve 'www.example.jp:443:192.0.2.10' \
     -sS -o /dev/null -w '%{http_code}\n' 'https://www.example.jp/contact/recruit?utm_source=print'
   ```

   公開フォームが200になり、フォーム名・入力内容が合うことをブラウザーでも確認します。IP制限、公開期間中だけ表示、停止中などのフォームは、記録した条件のとおりに動くことを確認します。設置先以外の既存ページも表示確認します。試験端末の名前解決を元に戻し、切替操作・確認担当・戻す手順・停止時間を決めます。新本番へ初回コピーを先行取込しておく場合も、最終取込までフォームの編集・新規受付を開始しません。

## 4. 受付停止と最終取込

1. 旧サイトでフォームの編集を止め、利用者に停止時間と再入力の可能性を案内します。既に入力中の利用者に送信を終える時間を設けます。停止時刻になったら旧フォームのGET・POSTを全経路でメンテナンス応答（503）にし、処理中のリクエスト・旧側の配信処理の完了を待ちます。旧URLのPOSTを新版へリダイレクトすると、入力中の人のセッションと受付状態の整合が取れません。
2. 旧側の最終受付IDとフォーム別件数を記録します。旧DBに履歴を残さない設定のフォームは旧履歴件数に現れないため、送信記録を別途確認します。DBの最終ダンプ、同じ停止期間の添付と設置ファイル、旧Web設定を一組として保全します。最終ダンプは `easymail_final` など別の空の複製DBへ復元します。初回の複製も保持します。実行ユーザーの `LEGACY_DB_DATABASE` をこの複製へ向けて設定を再キャッシュします。
3. 新本番の流入と配信を止めた状態で、全フォームを最終取込して照合します。受付停止期間は履歴件数、ファイルサイズ、ネットワーク速度で変わります。初回リハーサルの計測値に合わせます。1フォームに限定する `--form` はこの全件照合では省きます。

   ```sh
   php artisan easymail:import --dry-run --strict --source-root=/backup/final/EasyMail \
     --source-base=https://www.example.jp/contact --json > "$report_dir/final-urls.json"
   php artisan easymail:import --source-root=/backup/final/EasyMail --json > "$report_dir/final-import.json"
   php artisan easymail:import --verify-only --source-root=/backup/final/EasyMail \
     --source-base=https://www.example.jp/contact --json > "$report_dir/final-verify.json"
   php artisan mailform:check --expect-paused
   php artisan mailform:handoff --require-empty > "$report_dir/before-open.json"
   ```

   `final-verify.json` の `forms` で、公開を引き継ぐフォームの `same_public_url` が `true`、`target_entry_url` が案内済みの URL と一致することを確認します。旧公開フォームが確認事項で下書きのときは `target_entry_url` が空になり、`planned_entry_url` に旧 URL が確保されます。切替時に開く必要があるフォームを一覧にして、個別の確認と公開を済ませます。公開状態・項目、履歴・添付の照合結果も確認します。最後のコマンドは旧版から取り込んだ履歴を除き、新版本来の受付数を数えます。空の移行先に試験受付が混ざっていれば終了コード2です。この段階で取り込んだ既存メールが送信待ちに並んでいないことも「配信状況」で確認します。`before-open.json` の `last_native_id` は切り戻しの基準点として保管します。新規構築で練習DBを分けた場合は0です。
4. 新DB、非公開ファイル、当該DBに対応する `APP_KEY` と設定、利用するコードのコミット、切替前のルーティング設定を保全します。保全したファイルはアクセス制限・暗号化した保管先に置きます。

## 5. 現行URLで受付を開始する

1. 新版の本番SMTPを設定し `php artisan config:cache` と `php artisan mailform:check --smtp` を実行します。接続試験はメールを送信しません。`MAILFORM_DELIVERY_ENABLED=true` に変更し、再度 `php artisan config:cache` を実行します。タイマーの起動後、`php artisan mailform:check` の「メール配信　有効」と定期実行時刻を確認します。AlmaLinux手順のシステムでは `sudo systemctl enable --now mailform-schedule.timer` を使います。
2. 新版でメンテナンスモードを使用していた場合は、運営者だけがアクセスできる状態で `php artisan up` を実行します。AlmaLinuxテンプレートの `/etc/nginx/mailform/maintenance` を作成した場合は、公開直前にこのファイルを取り除きます。公開入口のWebサーバー／ロードバランサーで、当該ドメイン・パスの受付先を新版だけに切り替えます。旧URLの文字列を維持し、無関係な既存サイトのルートは従来のままにします。DNSで移設する場合は事前にTTLを短縮し、旧アドレスを使う利用者が残る間、旧入口はメンテナンス応答または移行済みバックエンドへの同一経路とします。複数の旧新サーバーが別々のDBへ受付を書き込む期間を作ると照合が困難になります。
3. 管理用端末の上書きを外して一般経路から代表URL・CSS・管理ログインを確認します。担当者が用意した試験フォームと受信先で1件通し、受付ID、履歴・添付、配信結果、メールの到達を記録します。営業中に旧版で開いた画面がセッション期限エラーになった場合は、受付完了の有無を確認し、未受付なら同じ入口URLを開き直して再入力します。
4. 旧環境の最終受付IDが増えていないことと、新側の受付・配信の増加を確認します。旧DBとバックアップは保存方針に沿って読み取り専用で保管します。安定稼働後は新版の移行元DB資格情報を取り除き設定を再キャッシュし、以後の新規フォーム作成・編集を新版で開始します。

## 6. 切り戻す場合

1. 公開入口で新版への流入を止め、新版で `php artisan down --retry=60` を実行します。処理中リクエストの完了を確認し、AlmaLinux手順の場合は `sudo systemctl stop mailform-schedule.timer` で次回の配信を止めます。`systemctl is-active mailform-schedule.service` が `inactive` になるまで待ちます。配信設定を停止して `php artisan config:cache` を実行し、手動の配信も止めます。途中でSMTPを打ち切った場合は結果不明として個別照合します。新DB、非公開添付、鍵、SMTPログをその時点で退避します。
2. 切替前に保存した `last_native_id` から、切替後に新版だけが受けた受付を抽出します。以下の0は保存した番号に置き換えます。

   ```sh
   php artisan mailform:handoff --after-id=0 --receipts --require-empty > "$report_dir/rollback.jsonl"
   ```

   最終行の `native_receipts` が件数です。その手前に受付番号、フォーム番号、保存・消去の状態、添付数、配信ID・種類・状態を1件ずつ出します。回答本文・宛先・添付名は出力しません。受付と配信の最終判定に使うときは、流入と配信を止めてから実行してください。
3. **切替後の新版受付が0件（終了コード0）**なら、保全した旧サイトがそのまま動作可能かを確認し、旧側を唯一の受付先に戻します。旧側の受付再開はルーティング切替と対で行います。
4. **1件以上（終了コード2）**なら、新版のDBを切替前へ巻き戻すとその受付が失われます。旧側へ入口を戻す前に、受付番号ごとに業務担当へ引き継ぎ、旧側での新規受付と識別できるように記録します。保存対象の回答・添付は権限者が新版の履歴・CSV・添付から取得します。保存対象外で消去済みなら、既に届いた通知や業務側の対応記録と照合します。MailFormから旧EasyMailへの逆方向の自動移行機能はありません。
5. 通知・自動返信の担当を各配信IDで一本化します。`sent` は再送せず、`pending` は隔離した新版で送る担当と時刻を決めます。`failed`・`unknown` または中断した `sending` はSMTP側の記録と付き合わせます。結果が確定したものだけを個別に解決します。履歴からの再送や旧版への擬似投稿は同じ人にメールを重ねる原因になります。[配信結果の確認と解決](../README.md#設置)
6. 後日再切替する場合は、新旧それぞれの受付簿と正本を確定し、旧側で再開後に増えた履歴を新しいスナップショットから再取込します。新版側で発生した受付と鍵・添付を保全し、再取込の対象フォーム・設定変更・消去状態を再び照合します。

## リハーサルで照合した例

AlmaLinux 10.3・MariaDB 11.8で、合成データを使って次の結果を確認しました。実際の移行では、ご自身の旧サイトで控えた件数と照合してください。

| 時点 | 照合結果 |
| --- | --- |
| 初回コピー | 旧履歴1件・添付1件が一致。旧側で履歴と添付を1件ずつ増やすと、最終取込前の照合が未取込1件で停止。 |
| 受付を止めた最終コピー | 全件取込後は旧履歴2件・添付2件のSHA-256が一致。同じコピーを再取込しても2件のままで、旧メールの送信待ちは0件。 |
| 新版での受付と切り戻し | 新版で添付付きの1件を送信し、同じ確定操作を再実行しても受付は1件。受付停止後の台帳に添付1件・配信待ち2件が残り、別DBとストレージへの復元後にも回答の復号と添付内容が一致。 |
| 新サーバー1台での入れ替え | 練習用の受付3件を保全し、新しい鍵・DB・ストレージを用意。本番用の模擬DBでは旧履歴2件・添付2件、新規受付0件。VMを停止・起動した後も、同じHTTPSのフォームと管理入口が開き、配信停止設定・定期実行が維持された。 |
