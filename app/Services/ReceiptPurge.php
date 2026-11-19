<?php

namespace App\Services;

use App\Models\Submission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ReceiptPurge
{
    /** Only terminal deliveries can release their files. Leave a receipt token to deduplicate retries. */
    public function purge(int $id): void
    {
        DB::transaction(function () use ($id) {
            $receipt = Submission::lockForUpdate()->findOrFail($id);
            if ($receipt->purged_at || $receipt->retain || $receipt->deliveries()->where('status', '!=', 'sent')->exists()) {
                return;
            }

            $disk = Storage::disk('local');
            foreach ($receipt->attachments()->pluck('path')->unique() as $path) {
                if ($disk->exists($path) && ! $disk->delete($path)) {
                    throw new RuntimeException('非公開添付の削除に失敗しました。');
                }
            }
            $receipt->attachments()->delete();
            $receipt->update(['answers' => [], 'snapshot' => ['retain' => false], 'purged_at' => now()]);
        });
    }
}
