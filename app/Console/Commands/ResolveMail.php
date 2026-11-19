<?php

namespace App\Console\Commands;

use App\Models\Delivery;
use Illuminate\Console\Command;

class ResolveMail extends Command
{
    protected $signature = 'mailform:resolve {id} {action : retry または sent}';
    protected $description = 'SMTPログの確認後に個別の要確認メールを再配信待ちまたは配信済みにする';

    public function handle(): int
    {
        $action = $this->argument('action');
        if (!in_array($action, ['retry', 'sent'], true)) {
            $this->error('retry または sent を指定してください。');
            return self::INVALID;
        }
        $changes = ['status' => 'pending', 'last_error' => null];
        if ($action === 'sent') {
            $changes = ['status' => 'sent', 'payload' => encrypt('[]', false), 'last_error' => null];
        }
        $updated = Delivery::whereKey($this->argument('id'))->whereIn('status', ['unknown', 'failed'])->update($changes);
        $this->line($updated ? '配信状態を更新しました。' : '指定した要確認メールが見つかりません。');
        return $updated ? self::SUCCESS : self::FAILURE;
    }
}
