<?php

namespace App\Console\Commands;

use App\Models\Submission;
use Illuminate\Console\Command;
use App\Services\ReceiptPurge;
use Illuminate\Support\Facades\Storage;

class PruneForms extends Command
{
    protected $signature = 'mailform:prune';
    protected $description = '配信が完了した保存対象外の個人情報と期限切れの一時添付を消去する';

    public function handle(ReceiptPurge $purge): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subMinutes(max(1440, (int) config('session.lifetime')) + 60)->timestamp;
        foreach ($disk->directories('form-drafts') as $dir) {
            $files = $disk->allFiles($dir);
            $recent = array_filter($files, fn ($file) => $disk->lastModified($file) >= $cutoff);
            if (!$recent && filemtime($disk->path($dir)) < $cutoff) {
                $disk->deleteDirectory($dir);
            }
        }
        Submission::where('retain', false)->whereNull('purged_at')
            ->whereDoesntHave('deliveries', fn ($query) => $query->where('status', '!=', 'sent'))
            ->select('id')->chunkById(100, function ($rows) use ($purge) {
                foreach ($rows as $row) {
                    $purge->purge($row->id);
                }
            });
        return self::SUCCESS;
    }
}
