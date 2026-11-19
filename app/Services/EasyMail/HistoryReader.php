<?php

namespace App\Services\EasyMail;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class HistoryReader
{
    public function answers(array $history, array $currentFields, array &$warnings): array
    {
        $raw = (string) $history['content'];
        $rows = json_decode($raw, true);
        $fields = [];
        $answers = [];
        if (!is_array($rows) || !array_is_list($rows) || !str_starts_with(ltrim($raw), '[')) {
            $warnings[] = "履歴 #{$history['id']}: content が標準のJSON配列ではありません。原文を保存します。";
            return [$fields, $answers];
        }
        $keys = array_column($currentFields, 'name');
        foreach ($rows as $i => $row) {
            if (!is_array($row) || !isset($row['title'], $row['form_type']) || !is_string($row['title']) || !is_string($row['form_type']) || !array_key_exists('value', $row)) {
                $warnings[] = "履歴 #{$history['id']}: ".($i + 1).' 列目の構造を解釈できません。原文を保存します。';
                continue;
            }
            $matches = array_values(array_filter($currentFields, fn ($f) => ($f['title'] ?? null) === $row['title'] && $f['form_type'] === $row['form_type']));
            $name = count($matches) === 1 ? $matches[0]['name'] : null;
            if (!$name || array_key_exists($name, $answers)) {
                $name = '__legacy_field_'.($i + 1);
                while (in_array($name, $keys, true) || array_key_exists($name, $answers)) {
                    $name .= '_';
                }
            }
            $fields[] = ['name' => $name, 'title' => $row['title'], 'form_type' => $row['form_type']];
            $value = $row['value'];
            if (is_array($value) && (!array_is_list($value) || count(array_filter($value, 'is_array')) > 0)) {
                $warnings[] = "履歴 #{$history['id']}: ".($i + 1).' 列目の独自形式の回答はJSON文字列として表示します。';
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            $answers[$name] = $value;
        }
        return [$fields, $answers];
    }

    /** 2.01.004 writes hashes back-to-back, while its history screen splits on commas. */
    public function files(array $history, ?string $directory, array &$warnings): array
    {
        $files = [];
        foreach (explode(',', (string) $history['attach_files']) as $fragment) {
            if ($fragment === '') {
                continue;
            }
            if (str_contains($fragment, '/') || str_contains($fragment, '\\') || preg_match('/[[:cntrl:]]/', $fragment)) {
                $warnings[] = "履歴 #{$history['id']}: 安全なファイル名ではない添付参照を除外しました。";
                continue;
            }
            $parts = preg_split('/(?=[A-Za-z0-9]{40}\.)/', $fragment, flags: PREG_SPLIT_NO_EMPTY);
            foreach ($parts as $name) {
                if (strlen($name) > 255 || preg_match('~[\\\\/\x00-\x1f\x7f]~', $name) || $name === '.' || $name === '..') {
                    $warnings[] = "履歴 #{$history['id']}: 安全なファイル名ではない添付参照を除外しました。";
                    continue;
                }
                $path = $directory === null ? false : realpath($directory.'/'.$name);
                if (!$path || $path !== $directory.'/'.$name || !is_file($path) || !is_readable($path)) {
                    $warnings[] = "履歴 #{$history['id']}: 添付を確認できません: {$name}";
                    continue;
                }
                $files[$name] = $path;
            }
        }
        return $files;
    }

    public function copy(string $source, string $name, int $historyId): array
    {
        $digest = hash_file('sha256', $source);
        if ($digest === false) {
            throw new RuntimeException("履歴 #{$historyId} の添付を読み込めません。");
        }
        $disk = Storage::disk('local');
        $path = "easymail/{$historyId}/{$digest}";
        if (!$disk->exists($path) || hash_file('sha256', $disk->path($path)) !== $digest) {
            $stream = fopen($source, 'rb');
            if (!$stream) {
                throw new RuntimeException("履歴 #{$historyId} の添付を読み込めません。");
            }
            $temp = $path.'.'.bin2hex(random_bytes(12)).'.tmp';
            try {
                if (!$disk->put($temp, $stream, 'private') || hash_file('sha256', $disk->path($temp)) !== $digest || !$disk->move($temp, $path)) {
                    throw new RuntimeException("履歴 #{$historyId} の添付の保存に失敗しました。");
                }
            } finally {
                fclose($stream);
                $disk->delete($temp);
            }
        }
        return [
            'path' => $path,
            'name' => $name,
            'mime' => (new \finfo(FILEINFO_MIME_TYPE))->file($source) ?: 'application/octet-stream',
            'size' => filesize($source),
        ];
    }
}
