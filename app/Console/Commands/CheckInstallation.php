<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

class CheckInstallation extends Command
{
    protected $signature = 'mailform:check
        {--smtp : メールを送らずにSMTPサーバーに接続する}
        {--expect-paused : 配信停止の移行先を検査し、配信が有効なら失敗する}';
    protected $description = 'DB、非公開ストレージ、暗号化、定期実行の設定を確認する';

    public function handle(): int
    {
        $checks = [
            'DB とテーブル' => fn () => DB::connection()->getPdo() && Schema::hasTable('deliveries'),
            '暗号鍵' => fn () => decrypt(encrypt('test')) === 'test',
            '非公開ストレージ' => function () {
                $disk = Storage::disk('local');
                $path = '.check/'.Str::uuid();
                return $disk->put($path, 'test') && $disk->get($path) === 'test' && $disk->delete($path);
            },
        ];
        if ($this->option('smtp')) {
            $checks['SMTP接続'] = function () {
                $transport = Mail::mailer()->getSymfonyTransport();
                if (!$transport instanceof SmtpTransport) {
                    return false;
                }
                $transport->start();
                $transport->stop();
                return true;
            };
        }
        $success = true;
        foreach ($checks as $name => $check) {
            try {
                $ok = (bool) $check();
            } catch (Throwable) {
                $ok = false;
            }
            $this->line(($ok ? 'OK  ' : 'NG  ').$name);
            $success = $success && $ok;
        }
        $this->line('メール配信　'.(config('mailform.delivery_enabled') ? '有効' : '停止中'));
        if ($this->option('expect-paused') && config('mailform.delivery_enabled')) {
            $this->error('移行先の配信が有効です。MAILFORM_DELIVERY_ENABLED=false を設定し、設定キャッシュを更新してください。');
            $success = false;
        }
        try {
            $last = Cache::get('mailform.scheduler');
            $this->line('定期実行　'.($last ?: '直近5分の実行記録がありません'));
        } catch (Throwable) {
            $this->error('定期実行の記録を読み取れません。');
            $success = false;
        }
        return $success ? self::SUCCESS : self::FAILURE;
    }
}
