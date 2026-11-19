<?php

namespace App\Http\Controllers\Admin;

use App\Models\Attachment;
use App\Models\Delivery;
use App\Models\Form;
use App\Models\Submission;
use App\Services\Admin\HistoryRows;
use App\Services\ReceiptPurge;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HistoryController
{
    public function index(Request $request)
    {
        $id = $request->validate(['form_id' => 'nullable|integer|exists:forms,id'])['form_id'] ?? null;
        $history = Submission::where('retain', true)->when($id, fn ($q) => $q->where('form_id', $id))
            ->with(['form:id,name', 'deliveries:id,submission_id,kind,status'])->withCount('attachments')
            ->latest('created_at')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('admin.history', ['history' => $history, 'forms' => Form::orderBy('name')->get(['id', 'name']), 'formId' => $id]);
    }

    public function show(Submission $submission, HistoryRows $rows)
    {
        abort_unless($submission->retain, 404);
        $submission->load(['form', 'deliveries', 'attachments']);

        return view('admin.submission', ['submission' => $submission, 'cells' => $rows->cells($submission, true)]);
    }

    public function download(Submission $submission, Attachment $attachment)
    {
        abort_unless($submission->retain && $attachment->submission_id == $submission->id, 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($attachment->path), 404);
        $filename = preg_replace('/[\x00-\x1F\x7F\\\\\/]/u', '_', $attachment->name) ?: 'download';

        return $disk->download($attachment->path, $filename, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, Submission $submission, ReceiptPurge $purge)
    {
        $request->validate(['confirmed' => 'accepted'], ['confirmed.accepted' => '削除の対象と内容をご確認ください。']);
        DB::transaction(function () use ($submission) {
            $receipt = Submission::lockForUpdate()->findOrFail($submission->id);
            abort_unless($receipt->retain, 404);
            if ($receipt->deliveries()->lockForUpdate()->pluck('status')->contains(fn ($state) => $state !== 'sent')) {
                throw ValidationException::withMessages(['delivery' => '配信が完了していないメールがあります。配信状況を確認してから削除してください。']);
            }
            // Revoke reads first. If cleanup is interrupted the scheduled prune retries using the remaining metadata.
            $receipt->update(['answers' => [], 'snapshot' => ['retain' => false], 'retain' => false]);
        });
        $notice = '受信履歴の回答と非公開添付を削除しました。';
        try {
            $purge->purge($submission->id);
        } catch (\Throwable $error) {
            report($error);
            $notice = '回答を削除しました。添付の削除は定期処理で再試行します。';
        }

        return redirect()->route('admin.history', ['form_id' => $submission->form_id])->with('notice', $notice);
    }

    public function deliveries()
    {
        $deliveries = Delivery::select(['id', 'submission_id', 'kind', 'status', 'attempts', 'updated_at'])->with(['submission:id,form_id,retain', 'submission.form:id,name'])
            ->orderByDesc('id')->paginate(40);

        return view('admin.deliveries', compact('deliveries'));
    }

    public function csv(Form $form, HistoryRows $rows)
    {
        $query = $form->submissions()->where('retain', true);
        $last = (clone $query)->max('id') ?? 0;
        $query->where('id', '<=', $last)->latest('created_at')->orderByDesc('id');

        return response()->streamDownload(function () use ($query, $rows) {
            $headers = [];
            foreach ((clone $query)->cursor() as $submission) {
                foreach ($rows->cells($submission) as $cell) {
                    $headers[$cell['key']] = $cell['title'];
                }
            }
            echo "\xEF\xBB\xBF".$rows->csvLine(['日時', ...array_values($headers)]);
            foreach ((clone $query)->cursor() as $submission) {
                $values = array_fill_keys(array_keys($headers), '');
                foreach ($rows->cells($submission) as $cell) {
                    $values[$cell['key']] = $cell['value'];
                }
                echo "\r\n".$rows->csvLine([$submission->created_at?->format('Y-m-d H:i:s'), ...array_values($values)]);
            }
        }, 'data.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }
}
