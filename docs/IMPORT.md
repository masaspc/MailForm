# EasyMail 2.00.133・2.01.004 からの移行

公式配布版 2.00.133・2.01.004 のテーブル構成と、同じ版から取得した設置ファイルのバックアップが対象です。旧アプリの PHP を起動せずに取り込めます。別の版や独自改修のある場合は、まず複製環境で差分を確認してください。[公式 2.00.133](https://www.mubag.com/download/2.00.133/EasyMail_2.00.133.zip)・[公式 2.01.004](https://www.mubag.com/download/2.01.004/EasyMail_2.01.004.zip)

設置版の番号は管理画面かバックアップで確認できます。[旧サーバーで版番号を調べる](EASYMAIL-VERSION.md)

## 簡単設置で使うバックアップを用意する

初回は旧サイトで受付を続けた状態のコピーを使います。切替当日は受付・編集と処理中の送信を止め、同じ手順で新しく `final` を作ります。以下は SSH で両サーバーを管理できる場合の例です。管理会社に依頼する場合も、単一 DB の SQL と、下記のファイルを同じ時点で取得してください。

**旧サーバーで**、読み取り用のDBアカウントでダンプを取得します。`旧DB名`、`旧DBユーザー` と設置ディレクトリを実際の値に置き換えてください。古い MySQL では対応する `mysqldump` を使えます。SQL は対象DBのテーブルとデータだけを含む形式にします。DB選択・作成、トリガーやルーチンを含むダンプの場合は、元環境でエクスポートし直します。

```bash
umask 077
copy="$HOME/mailform-first"
mkdir -m 700 "$copy"
mariadb-dump -u 旧DBユーザー -p --single-transaction --quick --hex-blob --skip-triggers 旧DB名 > "$copy/backup.sql"
cd /旧EasyMailの設置ディレクトリ
files=(config/app.php config/append.php app/Http/Controllers/IndexController.php app/Library/Common.php app/Mail/ContactMail.php routes/web.php resources/views/theme resources/lang)
if test -d storage/app/attach_file; then files+=(storage/app/attach_file); fi
tar -czf "$copy/EasyMail.tar.gz" -- "${files[@]}"
```

`config/app.php` が `em_laravel` の下にあれば、その内側で実行します。各コマンドの成功を確認し、2ファイルを SFTP または次の例で新サーバーの管理者へ転送します。

```bash
ssh 管理者@新サーバーのIP 'umask 077; mkdir "$HOME/mailform-first"'
scp "$copy/backup.sql" "$copy/EasyMail.tar.gz" 管理者@新サーバーのIP:mailform-first/
```

**新サーバーで**、練習環境の設置後に実行します。展開は SSH で入った一般ユーザーが行います。専用の非公開領域には通常のファイルとディレクトリをコピーします。

```bash
cd ~/mailform-first
mkdir -m 0700 EasyMail && tar --no-same-owner --no-same-permissions -xzf EasyMail.tar.gz -C EasyMail
dest=/srv/mailform-import/first
sudo dnf install -y rsync
(
  set -e
  sudo install -d -o root -g mailform -m 0750 /srv/mailform-import
  sudo mkdir -m 0700 "$dest"
  sudo chgrp mailform "$dest" && sudo chmod 0750 "$dest"
  sudo install -d -o root -g mailform -m 0750 "$dest/EasyMail"
  sudo install -o root -g root -m 0600 backup.sql "$dest/backup.sql"
  sudo rsync -rpog --chown=root:mailform --chmod=D750,F640 --exclude='.env*' EasyMail/ "$dest/EasyMail/"
)
```

取り込む回ごとに空の保存先を使います。最終コピーはすべての `first` を `final` に替えます。新サーバーで `cd ~` して[簡単設置の取込手順](ALMALINUX10.md#3-旧データのコピーで練習する)へ戻ります。

`import-backup.sh` は実行ごとに別名の複製DBを作り、読み取り専用アカウントで診断・取込・全件照合を行います。添付欠損や公開保留で停止した場合は、表示された警告を確認し、コピーの不足やフォームの差異を解消してください。複製DB名と管理者用の照合記録の場所は実行結果に表示されます。転送ファイルと複製DBの保持期限も、元のバックアップと合わせて管理してください。

## 準備

1. 旧データベースのバックアップを専用の DB に復元し、対象テーブルを SELECT できる読み取り専用ユーザーを用意します。旧サイトの添付・テーマ・設置ファイルは公開ディレクトリの外に展開します。
2. MailForm の新 DB を用意し、通常のインストール手順に従って `php artisan migrate --force` を実行します。移行元の接続先を MailForm の `.env` に設定します。下の DB 名とユーザー名は例です。
3. バックアップ内の `resources/views/theme` と `storage/app/attach_file` の位置を確認します。2.00.133 は EasyMail 直下の em_laravel 内に、2.01.004 は EasyMail 直下に両方があります。EasyMail のルートディレクトリを `--source-root` に渡します。添付を別の場所に復元した場合は `--attachments` で上書きできます。運用時は認証情報、旧版コード、診断 JSON を公開領域外で保管してください。

```dotenv
LEGACY_DB_DRIVER=mariadb
LEGACY_DB_HOST=127.0.0.1
LEGACY_DB_PORT=3306
LEGACY_DB_DATABASE=easymail_snapshot
LEGACY_DB_USERNAME=easymail_reader
LEGACY_DB_PASSWORD=
```

MySQL は `LEGACY_DB_DRIVER=mysql` に設定します。ローカルソケットは `LEGACY_DB_SOCKET` で指定できます。設定キャッシュを使用している環境では、変更後に `php artisan config:cache` を実行してください。

移行コマンドは接続中のDBを確認します。同じサーバー・同じDB名を指す接続は、ユーザーやホストの別名が違っても終了コード1になります。同一の新DBに対する取込・事前診断・照合は同時に1本だけ実行できます。二重起動は待機せず終了コード1になります。

## 事前診断

```sh
php artisan easymail:import --dry-run --source-root=/backup/EasyMail
php artisan easymail:import --dry-run --source-version=2.00.133
php artisan easymail:import --dry-run --form=5 --source-root=/backup/EasyMail --json
```

`--dry-run` は既存の新 DB も参照して URL 衝突を調べ、DB と添付ストレージへの書き込みを省きます。JSON はフォームごとの元 ID・公開パス・可否・項目数・履歴件数・照合できた添付件数・理由を返します。`--strict` を追加すると、要確認事項や警告があれば終了コード 2、診断を完了できなければ 1 を返します。通常は完了時に 0 を返します。

- 5 種類の標準テーマを認識します。設置ファイル指定時は選択テーマの配布ファイル、本体の公開処理・ルート・拡張ポイント設定等の SHA-256 を公式 ZIP 基準で照合し、欠損・追加・変更の相対パスを出します。標準テーマ名で上書きされたテンプレートも検出対象です。旧 Blade・PHP・JavaScript は実行しません。
- 設置ファイル指定時は config/app.php の版表記を静的に読み、版ごとの指紋と照合します。DB のみでは両版のテーブル列名が共通するため、設置版を確認し --source-version=2.00.133 または --source-version=2.01.004 を渡してください。設置ファイルを省略した場合は、DB で確認できる範囲の判定になります。既知の公開保留理由がなければ公開設定を引き継ぎ、ファイル未照合を警告します。一度判明した設置ファイルの問題は、その後ファイル指定を省略しても保留理由に残ります。本番切替前にはファイル付きで再実行してください。
- 有効なプラグイン、独自テーマ、未対応の種類・入力制限・アクセス条件・置換タグ、設定未完了の reCAPTCHA、未対応の HTML 等があるフォームは下書きにします。旧プラグインのコード・設定データの移植は対象外です。無効なものも含めてプラグイン一覧と標準外テーブル名を表示します。旧 reCAPTCHA の有効状態を引き継ぎます。利用している場合は新環境の RECAPTCHA_SITE_KEY と RECAPTCHA_SECRET を設定してください。旧キー、シークレット、独自列の値は新 DB に保存しません。
- 欠損した項目、重複・予約済み URL、公開システムと競合する入力名も診断します。元 URL を割り当てられないフォーム、元のフォーム定義が削除された履歴には下書き用の仮 URL が付きます。元データの位置を記録した上で、既存フォームとの衝突を避けます。
- 公開保留事項は各フォーム定義の `issues` に保存されます。移行用複製の設定や移植したコードを修正し、再診断で該当項目が解消したことを確認してください。公開保留のないフォームでも、運用で使っていた URL・文章・画面・メール・CSV を見本と照合してください。

旧公開URLの設置先を `--source-base` で指定すると、新旧の入口をフォームごとに照合できます。[公開URLの引き継ぎ](URLS.md)

## 取込と再実行

```sh
php artisan easymail:import --source-root=/backup/EasyMail
php artisan easymail:import --source-root=/backup/EasyMail --attachments=/private-backup/old-files --json
```

フォームで現在使っていない共通項目も、旧版で削除されていなければライブラリへ移します。同梱標準版と同じ項目は重複登録を省きます。`--form` では指定フォームに使われる共通項目だけを対象にします。

旧 ID と新 ID を対応付けます。同じ移行元の再取込では旧 ID が同じレコードを更新し、変更されたフォーム定義には新しい版を記録します。運用元の異なるサイトは別の MailForm DB を用意してください。再取込では旧側のフォーム設定・公開状態を再適用するため、最終取込が完了してから新側での編集を開始します。全件取込では、旧側で削除されたフォームを公開停止にして旧 ID を報告します。新環境でアーカイブしたフォームの公開停止、消去済み履歴の消去状態を維持します。旧側から消えた受付を新側で物理削除する処理はありません。

履歴には受付日時、当時のタイトル・順序・回答、メール件名・宛先などの標準履歴列を保持します。両版の `histories.content` は項目名を持たない JSON 配列です。現行項目に一意に対応する回答にはその項目キーを使い、削除済みの項目や同名項目には履歴内の順序からキーを割り当てます。元 JSON は常に `snapshot.legacy_history.content` に保存します。独自形式や壊れた JSON には警告を出し、解釈できた列だけを展開します。旧標準版が保存していない送信済みメール本文は復元できません。履歴取込では配信用のレコードを作成しません。

添付は実ファイルを SHA-256 で照合して非公開の `local` ディスクへ取り込みます。旧版の 40 文字の乱数名の連結とカンマ区切りに対応します。元の利用者ファイル名や入力欄との対応を確定できない場合は旧保存名を使用し、履歴単位で紐づけます。欠損・不正パスの件数はフォームの警告で確認できます。欠損を復元して再実行すれば、すでに取り込んだ履歴に添付が追加されます。

再取込は全旧IDを読み直します。更新日時が変わっていない修正や、その間に追加された受付も取り込めます。同じ旧履歴の添付が差し替えられた場合は新しい内容に更新し、旧履歴から参照が外れた添付は移行先の紐付けを外します。新しい紐付けを確定した後、参照のなくなった移行用コピーを非公開ストレージから削除します。旧DBに名前が残っていて複製の実ファイルだけが欠けた場合は、前回のコピーを保持します。不正な添付参照を含む履歴は以前の紐付けを保持して照合に出します。

## 初回取込と最終取込の照合

`--verify-only` は固定した旧DB・添付複製を、新DBと新側の実ファイルに突き合わせます。取込前と取込後で同じオプションを使い、JSONを公開領域外に保存してください。例では担当者だけが読める記録用ディレクトリに移動してから、MailFormの `artisan` を絶対パスで実行します。

```sh
umask 077
php /srv/mailform/current/artisan easymail:import --source-root=/backup/EasyMail --verify-only --json > before.json
php /srv/mailform/current/artisan easymail:import --source-root=/backup/EasyMail --json > import.json
php /srv/mailform/current/artisan easymail:import --source-root=/backup/EasyMail --verify-only --json > after.json
```

初回や新着分がある取込前は終了コード2が想定されます。各コマンドの終了コードを個別に記録します。取込レポートの `history_count` と `resolved_files` は処理時に読めた旧履歴・旧添付の件数です。取込後は照合の終了コード0と `reconciliation.ok` が `true` であることを確認します。終了コード2は要照合、1は接続・設定・排他などの実行エラーです。`--strict` を併用すると、公開保留や設置ファイル未指定などの診断警告も2になります。照合の間は複製を固定し、新側での編集・試験送信・消去を止めてください。最終照合は `--form` を省いて全件で行います。

`source_history_count` は旧DBの対象履歴の総件数です。フォーム別件数との一致を確認し、フォームIDを特定できない履歴がある場合は全件取込を停止します。`reconciliation.forms[]` の各行には旧フォームID、対応する新フォームID、フォーム定義・公開状態の比較結果、次の集計があります。

| JSON項目 | 数え方と確認する内容 |
| --- | --- |
| `source` | 旧履歴をID単位で `matched`（一致）、`changed`（更新が必要）、`missing`（未取込）、`pending_purge`（新側で消去待ち）、`purged`（新側で消去済み）に分けます。合計が `histories` です。`last_source_id` はこのフォームで読んだ最大の旧履歴IDです。 |
| `absent_from_source` | このフォームで以前に取り込んだ履歴のうち、今回の複製に同じフォーム・旧履歴IDの組がない件数です。`retained` と `pending_purge` は要照合です。複製の取り違え・欠落・旧側の削除を確認します。消去対象と確認できた分は新側の通常の履歴削除を使い、消去済みの記録を再照合できます。 |
| `new_only` | 旧IDを持たない新側独自の受付です。`retained`、`pending_purge`、`purged` を別々に示します。全件照合では新側で作ったフォームも `form_state` が `new_only` の行に出ます。ステージングの試験分や切り戻し時の受付を数える際に使えます。 |
| `files` | 保持対象の旧履歴にある有効な保存名を履歴内で重複を除いて `expected` に数えます。`verified` は保存名、リンク先、サイズ、旧・新ファイルのSHA-256が一致した件数です。`source_unavailable` は旧複製の欠損、`unsafe` は不正参照、`target_missing` は新側のリンク・実体の欠損、`changed` は内容・保存先等の相違です。`extra_links` は余分な紐付け、`unlinked_copies` は対象履歴の専用ディレクトリにある参照のない移行用コピーです。 |
| `differences`、`examples` | 差異の種類ごとに該当する履歴件数と、最大20件の旧履歴IDを示します。 |

旧側で消えた履歴を保存方針に従って新側に保全する場合、照合は引き続き2になります。保全対象の旧ID・件数・理由を記録し、未取込や添付欠損など他の差異がすべて解消したか確認して、担当者が切替可否を判断します。

一致の判定では履歴の所属、受付・更新日時、保存した旧標準列、当時の項目順・タイトル・型と回答を比較します。消去済みの履歴は回答や添付が復活していないことを確認します。消去待ちは通常の削除処理の完了後に再照合してください。新側の試験受付や過去に消去済みの旧IDは件数に残ります。バックアップの識別子、取得時刻と停止時刻、実行オプション、各終了コードをレポートと一緒に記録してください。

最終取込でも全履歴を読み、照合では添付を読み直します。事前に本番相当の複製でバックアップ取得・復元・取込・照合にかかる時間を計り、受付停止時間を見積もってください。

## 切替

1. 代表フォームを取り込み、旧 URL・項目順序・公開条件・必須エラー・メール内容・履歴 CSV と添付を比較します。試験送信時の SMTP はテスト用の宛先に限定します。
2. 旧サイトの受付・編集を停止し、処理中の送信が終わってからDBと添付を同じ時点で退避します。複製を更新し、同じ接続先・ファイル指定で取込前照合、全件取込、取込後照合を実行します。旧側の件数と各フォームの照合結果、公開保留と警告の対応を確認してからURLを切り替えます。
3. 切り戻し時は新側の受付と送信処理を止め、切替後の受付・添付・配信状態を保全し、旧側へ戻す分を照合します。旧システムは検証完了まで読み取り専用で保管してください。
