# 旧サイトの公開URLを引き継ぐ

配布済みリンクをそのまま使うには、旧サイトと同じスキーム、ホスト、ポート、設置パスを新しい `public/` に割り当てます。例えば旧フォームが `https://www.example.jp/support/contact` なら、`APP_URL=https://www.example.jp/support`、公開パスは `contact` です。ドメイン直下の場合は `APP_URL=https://www.example.jp` とします。旧サイトが受け付けている間は、検証用ホストまたは検証端末からだけ到達できる新サーバーで試験できます。

## 旧版が使っていた入口

公式 2.01.004 の `routes/web.php`、`IndexController::index`、管理画面の `resources/views/admin/form/list.blade.php` では、次のようにフォームを選びます。管理画面には旧 `APP_URL` と `forms.url` を結んだリンクが表示されます。[公式 2.01.004 配布物](https://www.mubag.com/download/2.01.004/EasyMail_2.01.004.zip)

| 旧DBの `forms.url` | ブラウザーで開く入口の例 |
| --- | --- |
| NULL／空欄のルートフォーム | `https://www.example.jp/support/` |
| `contact` | `https://www.example.jp/support/contact` |
| `春 申込+50%~.html` | `https://www.example.jp/support/%E6%98%A5%20%E7%94%B3%E8%BE%BC%2B50%25~.html` |
| 文字どおりの `A%2FB` | `https://www.example.jp/support/A%252FB` |

標準の入口は GET で設置先のトップまたはその直下の1区間です。URL内の `+` はパスではプラス、`%20` は空白です。フォーム名をパーセントデコードするのはルーティング時の1回です。DBに日本語で保存された名前に、日本語表示とパーセント表記の両方でアクセスできます。

`/support/contact/?utm_source=qr` も同じフォームを表示します。同梱の Apache 設定では個々のフォームの末尾スラッシュを保ったまま表示します。設置ディレクトリ自体の `/support` はWebサーバーが `/support/` に補完する場合があります。クエリ付きのリンクでは、この転送後もクエリが残ることを確認してください。標準のフォーム選択はパスに従います。例えば `/support/?id=5` で表示するのはルートフォームです。入力後の各画面へ進む際は、新しいフォームが発行した送信先を使用します。

旧配布物には設置先直下と `public/` 内にフロントコントローラーがありました。`/support/public/` が従来の設置URLなら、そのパスに新しい `public/` を割り当てます。`/support/index.php/contact` が配布されていた場合は、Webサーバーが `/support/index.php` をフロントコントローラーとして扱う構成で確認してください。この表記から開いたフォームも、画像・CSS・JavaScript は `/support/assets/` から読み込みます。新サイトでWeb公開するディレクトリは MailForm の `public/` だけです。

## フォームごとのURLを照合する

旧 `.env` の `APP_URL` と、ホームページ・QRコード・案内メール・アクセスログにある実際の入口を集めます。旧DBの `forms.url` が示すのは設置先より後ろの名前です。

```sh
php artisan easymail:import --dry-run --source-root=/backup/EasyMail \
  --source-base=https://www.example.jp/support --json > /private-backup/form-urls.json
jq -r '.forms[] | select(.source_published) |
  [.legacy_id, .source_url, .source_entry_url, .target_entry_url, .same_public_url,
   (.issues | join(" / "))] | @tsv' /private-backup/form-urls.json
```

`source_url` はDBの原文です。`source_entry_url` は旧公開設定から組み立てた標準入口、`target_entry_url` は診断時には今回の取込で公開できる入口です。旧非公開／標準外のパスや、新側で下書きとなる場合の該当欄は `null` になります。`same_public_url` は両URLの文字列比較です。別ホストでの並行検証中は `false`、本番と同じ設置先とフォームパスなら `true` です。取込後、同じ引数の `--dry-run` を `--verify-only` に替えると、`target_entry_url` は現在の新DBにある公開入口を示します。入力画面の疎通は下記のHTTP試験で確認します。

日本語、空白、ドット、チルダ、プラスなどを含む1区間の名前は引き継げます。`admin`、`up`、`assets`、`thanks`、`index.php` などの予約名、ドットから始まる名前、公開先の実ファイル名、別フォームとの重複、スラッシュ・バックスラッシュ・制御文字を含む名前は取込時に理由を表示し、仮パスの下書きにします。元のフォームの公開停止を確認し、対応する代替パスと必要なWebサーバーの設定を決めてから切り替えてください。

予約名と衝突した場合は、旧入口と旧IDの対応表と未加工のバックアップを保存します。作業用の旧DB複製で該当する `forms.id` の `url` を空きパスに変更し、その複製への読み取り接続で再診断します。最終バックアップの複製にも同じ対応表を適用します。旧入口に個別の転送を設ける場合、同じパスを必要とする管理画面・ヘルスチェックも配置方針を決め、両方の利用者の経路を検証してください。

各フォームについて、実際に配布したURLをそのまま新サーバーで開きます。検証端末だけ接続先を変える場合、次の例はURL・Host・TLSのホスト名を保ったまま、指定したIPへ接続します。新サーバーに対象ホストの有効な証明書を用意します。

```sh
curl --resolve www.example.jp:443:192.0.2.20 --path-as-is --silent --show-error \
  --dump-header /tmp/mailform-entry.headers --output /tmp/mailform-entry.html \
  'https://www.example.jp/support/contact/?utm_source=qr'
```

1. HTTPステータス、転送先、フォームのタイトル・項目を確認します。200でも旧版の「フォームが見つかりません」やサイト共通ページの場合があります。新しい入力画面には `data-entry` を持つフォームと `receipt_token` があります。
2. ブラウザーで開き、CSS・JavaScript・郵便番号検索用データが同じホストの設置ディレクトリ内で読み込めることを確認します。
3. 隔離した新環境で入力 → 確認 → 修正 → 再確認 → 送信を行い、303で完了画面へ戻ること、受付・配信予定件数を照合します。試験メールには隔離した送信先を使います。検証が終わったURLは旧ID・新ID・実際に開いたURL・結果を記録します。

## 手作業のページ・別名・クエリ分岐

独自の `/company/inquiry.html`、旧パッケージの別の公開ルート、`/entry.php?id=12`、印刷物の短縮URLなどはWebサーバーやCMSが所有している場合があります。これらの対応はDBから自動復元できません。既存の転送設定、テンプレート、実アクセスの記録から「旧入口・HTTPメソッド・クエリ条件・旧フォームID・新入口」を対応付けます。旧テーマのURL別テンプレートを検出したフォームは移行診断に表示されます。

旧リンクから固定の新入口へ案内する例です。Nginx の公開サイトの `server` に、確認済みのURLだけを登録します。検証中は302を使うと設定の変更がブラウザーの永続キャッシュに残りません。

```nginx
location = /company/inquiry.html {
    if ($request_method !~ ^(GET|HEAD)$) { return 405; }
    return 302 https://www.example.jp/support/contact$is_args$args;
}

location = /entry.php {
    if ($request_method !~ ^(GET|HEAD)$) { return 405; }
    if ($arg_id != 12) { return 404; }
    return 302 https://www.example.jp/support/contact$is_args$args;
}
```

独自ページがクエリで回答を初期表示したり、別のフォームを選んだりする場合は、その条件と動作を個別に移植します。リダイレクト先のホストとパスは固定し、転送対象のパス・メソッド・クエリ値を絞ります。旧アドレスをアドレスバーにも残す必要がある場合は、該当するGETだけをWebサーバーで内部転送し、PHP-FPMに渡す `REQUEST_URI` を新フォームの固定パス＋元のクエリ、`SCRIPT_NAME` を新設置先の `index.php` に設定します。公開フォームの後続操作には画面が生成した設置先のURLを使います。

例えば同梱のAlmaLinux向け設定では、`/etc/nginx/mailform/fastcgi.conf` を `fastcgi-inquiry.conf` に複製し、その中の `fastcgi_param REQUEST_URI $request_uri;` の1行を `fastcgi_param REQUEST_URI /support/contact$is_args$args;` に置き換えます。上の `/company/inquiry.html` の転送設定の代わりに次の設定を使うと、ブラウザーは旧アドレスのまま入力画面を受け取ります。[FastCGI設定の原本](../deploy/almalinux10/fastcgi.conf)

```nginx
location = /company/inquiry.html {
    if ($request_method !~ ^(GET|HEAD)$) { return 405; }
    fastcgi_param SCRIPT_NAME /support/index.php;
    include /etc/nginx/mailform/fastcgi-inquiry.conf;
}
```

## 切替時に旧画面を開いている人

旧版では `/` と `/{url}` の POST、各入口の `send` と `thanks` への POST も定義されています。旧画面と新画面ではセッション、CSRF、添付一時領域、受付トークンが異なります。旧版で開いたままの入力・確認画面から切替後に送信した場合、新側は受付を作りません。入力・送信のPOSTは通常419になります。旧完了画面は新側の受付記録がなければ表示できません。

切替時刻を案内し、期限までは旧画面での送信を完了できるようにします。旧受付を止めて最後の受付・添付を取り込み、照合してから公開入口を切り替えます。旧画面が残った利用者には、同じ公開URLを新しく開き、入力と添付をやり直すよう案内してください。送信済みか不明な場合は、再入力の前に担当者が旧・新の受付履歴を確認します。並行検証時は新旧それぞれのDB・アプリキー・セッションCookie名を分けます。[移行手順](IMPORT.md)
