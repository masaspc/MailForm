<?php

namespace App\Console\Commands;

use App\Models\Submission;
use Illuminate\Console\Command;

class HandoffReceipts extends Command
{
    protected $signature = 'mailform:handoff
        {--after-id=0 : 切替前に記録した last_native_id}
        {--receipts : 各受付の照合情報を JSON Lines で出力する}
        {--require-empty : 指定位置より後に新版の受付があれば終了コード 2 を返す}';

    protected $description = '旧履歴の取込を除き、新版が受け付けた件数・受付番号と配信状態を照合する';

    public function handle(): int
    {
        $after = filter_var($this->option('after-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($after === false) {
            $this->error('--after-id には 0 以上の整数を指定してください。');
            return self::INVALID;
        }

        $native = Submission::whereNull('legacy_id');
        $last = (int) (clone $native)->max('id');
        if ($after > $last) {
            $this->error('指定位置がこのDBの最終受付番号を超えています。移行先と切替前の記録を確認してください。');
            return self::FAILURE;
        }
        $receipts = $native->where('id', '>', $after)->where('id', '<=', $last);
        $count = (clone $receipts)->count();
        if ($this->option('receipts')) {
            $rows = $receipts->select(['id', 'form_id', 'retain', 'purged_at'])->withCount('attachments')
                ->with(['deliveries' => fn ($query) => $query->select('id', 'submission_id', 'kind', 'status')->orderBy('id')]);
            foreach ($rows->lazyById(200) as $receipt) {
                $this->writeJson([
                    'type' => 'receipt',
                    'id' => $receipt->id,
                    'form_id' => $receipt->form_id,
                    'retain' => $receipt->retain,
                    'purged' => $receipt->purged_at !== null,
                    'attachments' => (int) $receipt->attachments_count,
                    'deliveries' => $receipt->deliveries->map->only(['id', 'kind', 'status'])->all(),
                ]);
            }
        }
        $this->writeJson(['type' => 'summary', 'after_id' => $after, 'last_native_id' => $last, 'native_receipts' => $count]);

        return $this->option('require-empty') && $count > 0 ? self::INVALID : self::SUCCESS;
    }

    private function writeJson(array $row): void
    {
        $this->output->writeln(json_encode($row, JSON_THROW_ON_ERROR), $this->output::OUTPUT_RAW);
    }
}
