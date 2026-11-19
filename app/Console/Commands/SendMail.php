<?php

namespace App\Console\Commands;

use App\Models\Delivery;
use App\Services\Outbox;
use Illuminate\Console\Command;

class SendMail extends Command
{
    protected $signature = 'mailform:deliver {--limit=100 : 1回の処理件数}';
    protected $description = '保留中の管理者通知と自動返信を配信する';

    public function handle(Outbox $outbox): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if (!$limit) {
            $this->error('limit は1〜10000の整数です。');
            return self::INVALID;
        }
        if (!$outbox->enabled()) {
            $this->info('メール配信を停止しています。送信待ちの状態を維持します。');
            return self::SUCCESS;
        }
        Delivery::where('status', 'sending')->where('updated_at', '<', now()->subHour())
            ->update(['status' => 'unknown', 'last_error' => '配信処理が中断されました。']);
        $count = [];
        foreach (Delivery::where('status', 'pending')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $status = $outbox->send($id);
            $count[$status] = ($count[$status] ?? 0) + 1;
        }
        $this->info('配信済み '.($count['sent'] ?? 0).'件 / 要確認 '.(($count['failed'] ?? 0) + ($count['unknown'] ?? 0)).'件');
        return isset($count['failed']) || isset($count['unknown']) ? self::FAILURE : self::SUCCESS;
    }
}
