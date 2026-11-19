# 旧サーバーのEasyMailの版を調べる

移行時は `2.00.133` のように末尾まで記録します。フォームの内容や管理パスワードを共有する必要はありません。

## 管理画面が開ける場合

ブラウザーで **EasyMailの設置URLに `/admin` を付けて**開きます。ドメイン直下なら `https://www.example.jp/admin`、`/contact/` に設置した場合は `https://www.example.jp/contact/admin` です。公開フォームが `/contact/recruit` で `recruit` が個別フォーム名なら、管理入口は `/contact/admin` です。[公式サイトの管理画面URL案内](https://www.mubag.com/download/)

取込対応の4版では、ログイン後の管理トップに製品名と版番号が並びます。2.01.004ではログイン画面の下部にも `version:` と番号が表示されます。画面が表示できない場合は、サーバーから取得した設置ファイルで確認できます。[2.00.124・2.00.125の配布物と照合一覧](IMPORT.md#easymail-200125)・[2.00.133の公式配布物](https://www.mubag.com/download/2.00.133/EasyMail_2.00.133.zip)・[2.01.004の公式配布物](https://www.mubag.com/download/2.01.004/EasyMail_2.01.004.zip)

## SSHまたはファイルのバックアップで調べる場合

旧サイトの設置ディレクトリに移動して、次を実行します。SFTPや管理パネルのファイルマネージャーでも、同じファイルをテキストとして開いて `APP_VERSION` を検索できます。旧PHPやDBの起動は不要です。

```sh
for f in config/app.php em_laravel/config/app.php; do
  if [ -r "$f" ]; then
    printf '%s\n' "$f"
    grep -n 'APP_VERSION' "$f"
  fi
done
```

| 公式配布版 | 配布時のファイル | 記載例 |
| --- | --- | --- |
| 2.00.124 | `em_laravel/config/app.php` | `'version' => env( 'APP_VERSION', '2.00.124' ),` |
| 2.00.125 | `em_laravel/config/app.php` | `'version' => env( 'APP_VERSION', '2.00.125' ),` |
| 2.00.133 | `em_laravel/config/app.php` | `'version' => env( 'APP_VERSION', '2.00.133' ),` |
| 2.01.004 | `config/app.php` | `'version' => env('APP_VERSION', '2.01.004'),` |

この値は配布コードの既定版です。表示された番号と違う場合は、旧サイトの運用担当者が `.env` やサーバーの環境変数で `APP_VERSION` を指定した可能性があります。ローカルで `.env` の該当行だけを調べるには次を使います。DB・SMTPのパスワードや `APP_KEY` も入るため、ファイル全体の掲載は避けてください。

```sh
for f in .env em_laravel/.env; do
  if [ -r "$f" ]; then
    grep -H -E '^[[:space:]]*APP_VERSION[[:space:]]*=' "$f"
  fi
done
```

何も出なければ、設置場所またはバックアップに `config/app.php` があるか確認してください。上段のファイルで版が確認でき、下段の `.env` だけ出力が空なら、そのファイルには上書き指定がありません。`php artisan --version` が表示するのはLaravelの版です。

## 移行先での照合

MailFormの設置と移行用のDB復元後、**新サーバーのMailFormディレクトリ**で次を実行します。`--source-root` には公式ZIPで上書きしていない、実際に運用していた設置ファイルのコピーを指定します。

```sh
php artisan easymail:import --dry-run --source-root=/backup/EasyMail --json
```

出力の `version` がコードから読み取った版です。`forms` の `issues` と `warnings` に、標準ファイルとの差分、独自テーマ、入力条件などの確認事項が出ます。旧コードを実行せずに照合します。複数の版で取込必須列が共通するため、DBだけでは版を断定できません。表示とファイルが食い違う、番号が対応版以外、版の記載が見つからない場合は、該当番号・ファイルの位置・バックアップの取得日を控え、実データを取り込む前に対応状況を確認してください。[DBと添付の移行手順](IMPORT.md)
