<?php

namespace App\Services\EasyMail;

use App\Models\Form;
use App\Models\Submission;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class Reconciliation
{
    private array $forms = [];
    private const PRIVACY = ['retained' => 0, 'pending_purge' => 0, 'purged' => 0];

    public function __construct(private Connection $source, private HistoryReader $reader, private ?string $directory) {}

    public function check(?int $legacyId, ?Form $form, string $state): array
    {
        $check = [
            'legacy_id' => $legacyId, 'form_id' => $form?->id, 'form_state' => $state,
            'ok' => in_array($state, ['matched', 'withdrawn', 'new_only'], true),
            'last_source_id' => null,
            'source' => ['histories' => 0, 'matched' => 0, 'changed' => 0, 'missing' => 0, 'pending_purge' => 0, 'purged' => 0],
            'absent_from_source' => self::PRIVACY, 'new_only' => self::PRIVACY,
            'files' => ['expected' => 0, 'verified' => 0, 'source_unavailable' => 0, 'unsafe' => 0, 'target_missing' => 0, 'changed' => 0, 'extra_links' => 0, 'unlinked_copies' => 0],
            'differences' => [], 'examples' => [],
        ];
        if ($legacyId !== null) {
            $this->source->table('histories')->where('form_id', $legacyId)->chunkById(200, function ($rows) use ($form, &$check) {
                $receipts = Submission::with('attachments')->whereIntegerInRaw('legacy_id', $rows->pluck('id'))->get()->keyBy('legacy_id');
                foreach ($rows as $row) {
                    $this->history($check, (array) $row, $receipts->get($row->id), $form);
                }
            });
        }
        if ($form) {
            $form->submissions()->select('id', 'legacy_id', 'retain', 'purged_at')->chunkById(500, function ($receipts) use (&$check, $legacyId) {
                $ids = $receipts->pluck('legacy_id')->filter()->all();
                $present = $ids && $legacyId !== null ? $this->source->table('histories')->where('form_id', $legacyId)
                    ->whereIntegerInRaw('id', $ids)->pluck('id')->flip() : collect();
                foreach ($receipts as $receipt) {
                    $privacy = $this->privacy($receipt);
                    if ($receipt->legacy_id === null) {
                        $check['new_only'][$privacy]++;
                    } elseif (!$present->has($receipt->legacy_id)) {
                        $check['absent_from_source'][$privacy]++;
                        if ($privacy === 'purged') {
                            $this->tombstone($check, $receipt->refresh()->load('attachments'));
                        } else {
                            $this->difference($check, 'absent_from_source', $receipt->legacy_id);
                        }
                    }
                }
            });
        }
        $this->forms[] = $check;
        return $check;
    }

    public function finish(?int $scopedId): array
    {
        $checked = array_filter(array_column($this->forms, 'form_id'));
        $others = Form::whereIntegerNotInRaw('id', $checked)->when($scopedId !== null, fn ($q) => $q->where('legacy_id', $scopedId))
            ->lazyById(100);
        foreach ($others as $form) {
            $state = $form->legacy_id === null ? 'new_only' : ($form->published ? 'still_published' : 'withdrawn');
            $this->check($form->legacy_id, $form, $state);
        }
        return ['ok' => !in_array(false, array_column($this->forms, 'ok'), true), 'forms' => $this->forms];
    }

    private function history(array &$check, array $row, ?Submission $receipt, ?Form $form): void
    {
        $id = (int) $row['id'];
        $check['last_source_id'] = $id;
        $check['source']['histories']++;
        $privacy = $receipt ? $this->privacy($receipt) : null;
        $belongs = $receipt && $form && (int) $receipt->form_id === (int) $form->id;
        if ($receipt && $privacy !== 'retained') {
            $check['source'][$privacy]++;
            if (!$belongs || $privacy === 'pending_purge') {
                $this->difference($check, $belongs ? 'pending_purge' : 'wrong_form', $id);
            }
            if ($privacy === 'purged') {
                $this->tombstone($check, $receipt);
            }
            return;
        }
        $status = 'missing';
        $warnings = [];
        if ($receipt) {
            [$fields, $answers] = $this->reader->answers($row, [], $warnings);
            $status = $belongs && $this->reader->matches($receipt, $row, $fields, $answers)
                && $receipt->getRawOriginal('created_at') === $row['created_at'] && $receipt->getRawOriginal('updated_at') === $row['updated_at']
                ? 'matched' : 'changed';
        }
        $check['source'][$status]++;
        if ($status !== 'matched') {
            $this->difference($check, $status, $id);
        }
        $unsafe = 0;
        $files = $this->reader->files($row, $this->directory, $warnings, $unsafe);
        $recorded = $receipt?->snapshot['definition']['fields'] ?? [];
        $key = $this->reader->attachmentKey(is_array($recorded) ? $recorded : [], count($files));
        $this->files($check, $id, $files, $unsafe, $receipt?->attachments ?? collect(), $key);
    }

    private function tombstone(array &$check, Submission $receipt): void
    {
        $id = (int) $receipt->legacy_id;
        $this->files($check, $id, [], 0, $receipt->attachments);
        if ($receipt->answers || !in_array($receipt->snapshot, [[], ['retain' => false]], true)) {
            $this->difference($check, 'purged_data', $id);
        }
    }

    private function files(array &$check, int $id, array $files, int $unsafe, Collection $links, ?string $key = null): void
    {
        $counts = &$check['files'];
        $counts['expected'] += count($files);
        $counts['unsafe'] += $unsafe;
        $groups = $links->groupBy('name');
        $disk = Storage::disk('local');
        $verified = 0;
        foreach ($files as $name => $source) {
            $link = $groups->get($name)?->shift();
            if ($source === null) {
                $counts['source_unavailable']++;
            } elseif (!$link) {
                $counts['target_missing']++;
            } else {
                $digest = $this->reader->digest($source, $id);
                if ($link->path !== "easymail/{$id}/{$digest}" || $link->field_key !== $key || $link->size !== filesize($source)) {
                    $counts['changed']++;
                } elseif (!$disk->exists($link->path)) {
                    $counts['target_missing']++;
                } elseif (hash_file('sha256', $disk->path($link->path)) !== $digest) {
                    $counts['changed']++;
                } else {
                    $verified++;
                }
            }
        }
        $counts['verified'] += $verified;
        $extra = $groups->sum(fn ($group) => $group->count());
        $unused = count($this->reader->unusedCopies($id, $links->pluck('path')->all()));
        $counts['extra_links'] += $extra;
        $counts['unlinked_copies'] += $unused;
        if ($unsafe || $extra || $unused || $verified !== count($files)) {
            $this->difference($check, 'files', $id);
        }
    }

    private function privacy(Submission $receipt): string
    {
        return $receipt->purged_at !== null ? 'purged' : ($receipt->retain ? 'retained' : 'pending_purge');
    }

    private function difference(array &$check, string $reason, int $id): void
    {
        $check['ok'] = false;
        $check['differences'][$reason] = ($check['differences'][$reason] ?? 0) + 1;
        if (count($check['examples'][$reason] ?? []) < 20) {
            $check['examples'][$reason][] = $id;
        }
    }
}
