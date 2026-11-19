<?php

namespace App\Services\EasyMail;

use App\Models\Attachment;
use App\Models\Submission;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class HistoryReader
{
    public const COLUMNS = ['id','created_at','updated_at','form_id','form_name','admin_email','user_email','mail_title','content','theme_name','bcc_email','cc_email','conf_mail_flag','include_submissions','include_submissions_admin_email','url','template','attach_files'];

    public function matches(Submission $receipt, array $row, array $fields, array $answers): bool
    {
        $snapshot = $receipt->snapshot;
        $stored = $snapshot['legacy_history'] ?? null;
        $storedFields = $snapshot['definition']['fields'] ?? null;
        if (!is_array($stored) || !is_array($storedFields) || !array_is_list($storedFields)
            || count($storedFields) !== count($fields) || ($snapshot['name'] ?? null) !== $row['form_name']) {
            return false;
        }
        $row = array_intersect_key($row, array_flip(self::COLUMNS));
        ksort($row);
        ksort($stored);
        if ($stored !== $row) {
            return false;
        }
        // A later form edit may rename a current field. Check the values under the keys saved at import.
        $expected = [];
        foreach ($fields as $i => $field) {
            $actual = $storedFields[$i];
            $key = $actual['name'] ?? null;
            if (!is_string($key) || array_key_exists($key, $expected) || ($actual['title'] ?? null) !== $field['title']
                || ($actual['form_type'] ?? null) !== $field['form_type']) {
                return false;
            }
            $expected[$key] = $answers[$field['name']];
        }
        $saved = $receipt->answers;
        if (!is_array($saved)) {
            return false;
        }
        ksort($saved);
        ksort($expected);
        return $expected === $saved;
    }

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

    /** Keep missing but valid references so an incomplete snapshot cannot unlink an earlier copy. */
    public function files(array $history, ?string $directory, array &$warnings, int &$unsafe = 0, ?string $version = null, array $linkedNames = []): array
    {
        $allowBareHashes = in_array($version, ['2.00.124', '2.00.125'], true);
        $linked = array_fill_keys($linkedNames, true);
        $known = static function (string $name) use ($linked, $directory): bool {
            if (isset($linked[$name])) {
                return true;
            }
            if ($directory === null || strlen($name) > 255) {
                return false;
            }
            $path = $directory.'/'.$name;
            return is_link($path) || is_file($path);
        };
        $files = [];
        foreach (explode(',', (string) $history['attach_files']) as $fragment) {
            if ($fragment === '') {
                continue;
            }
            if (str_contains($fragment, '/') || str_contains($fragment, '\\') || preg_match('/[[:cntrl:]]/', $fragment)) {
                $unsafe++;
                $warnings[] = "履歴 #{$history['id']}: 安全なファイル名ではない添付参照を除外しました。";
                continue;
            }
            $parts = $this->fileNames($fragment, $allowBareHashes, $known);
            if ($parts === null) {
                $unsafe++;
                $warnings[] = "履歴 #{$history['id']}: 連結された添付参照を一意に判別できません。複製のファイル名を確認してください。";
                continue;
            }
            foreach ($parts as $name) {
                if (strlen($name) > 255 || preg_match('~[\\\\/\x00-\x1f\x7f]~', $name) || $name === '.' || $name === '..') {
                    $unsafe++;
                    $warnings[] = "履歴 #{$history['id']}: 安全なファイル名ではない添付参照を除外しました。";
                    continue;
                }
                $path = $directory === null ? false : realpath($directory.'/'.$name);
                if (!$path || $path !== $directory.'/'.$name || !is_file($path) || !is_readable($path)) {
                    $warnings[] = "履歴 #{$history['id']}: 添付を確認できません: {$name}";
                    $files[$name] = null;
                    continue;
                }
                $files[$name] = $path;
            }
        }
        return $files;
    }

    private function fileNames(string $fragment, bool $allowBareHashes, callable $known): ?array
    {
        if (strlen($fragment) < 80) {
            return [$fragment];
        }
        // Each 40-character hash immediately followed by a dot starts a new name.
        $runs = preg_split('/(?=[A-Za-z0-9]{40}\.)/', $fragment, flags: PREG_SPLIT_NO_EMPTY);
        if ($runs === false) {
            return null;
        }
        if (!$allowBareHashes) {
            return preg_match('/\A[A-Za-z0-9]{40}\./', $fragment)
                ? $this->resolveLiteralName($fragment, $runs, $known) : [$fragment];
        }
        $names = [];
        foreach ($runs as $run) {
            if (preg_match('/\A[A-Za-z0-9]{40}\./', $run)) {
                // In 2.00.124/125 the MIME extension, when present, is 1–24 bytes (including tar.gz).
                // Removing whole 40-byte hashes from its right leaves the exact extension.
                $length = (strlen($run) - 41) % 40;
                $end = 41 + $length;
                $suffix = substr($run, $end);
                if ($suffix === '' || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\[\]-]{0,23}\z/', substr($run, 41, $length))
                    || preg_match('/[^A-Za-z0-9]/', $suffix)) {
                    $names[] = $run;
                    continue;
                }
                $parts = [substr($run, 0, $end), ...str_split($suffix, 40)];
            } elseif (strlen($run) % 40 === 0 && !preg_match('/[^A-Za-z0-9]/', $run)) {
                $parts = str_split($run, 40);
            } else {
                return [$fragment];
            }
            $parts = $this->resolveLiteralName($run, $parts, $known);
            if ($parts === null) {
                return null;
            }
            array_push($names, ...$parts);
        }
        return count($runs) === 1 ? $names : $this->resolveLiteralName($fragment, $names, $known);
    }

    /** A literal custom filename or long original extension may also fit the hash format. */
    private function resolveLiteralName(string $literal, array $parts, callable $known): ?array
    {
        if (count($parts) === 1 || !$known($literal)) {
            return $parts;
        }
        foreach ($parts as $name) {
            if ($known($name)) {
                return null;
            }
        }
        return [$literal];
    }

    public function attachmentKey(array $fields, int $referenceCount): string
    {
        $fileFields = array_values(array_filter($fields, fn ($f) => ($f['form_type'] ?? null) === 'file'));
        $name = $fileFields[0]['name'] ?? null;
        return $referenceCount === 1 && count($fileFields) === 1 && is_string($name) && $name !== '' ? $name : 'legacy_attachment';
    }

    public function copy(string $source, string $name, int $historyId): array
    {
        $digest = $this->digest($source, $historyId);
        $disk = Storage::disk('local');
        $path = "easymail/{$historyId}/{$digest}";
        if (!$disk->exists($path) || hash_file('sha256', $disk->path($path)) !== $digest) {
            $stream = @fopen($source, 'rb');
            if (!$stream) {
                throw new IntegrityException("履歴 #{$historyId} の旧添付を読み込めません: {$name}");
            }
            $temp = $path.'.'.bin2hex(random_bytes(12)).'.tmp';
            try {
                if (!$disk->put($temp, $stream, 'private')) {
                    throw new RuntimeException("履歴 #{$historyId} の添付の保存に失敗しました。");
                }
                if (hash_file('sha256', $disk->path($temp)) !== $digest) {
                    throw new IntegrityException("履歴 #{$historyId} の旧添付とコピーの SHA-256 が一致しません: {$name}");
                }
                if (!$disk->move($temp, $path)) {
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

    public function digest(string $source, int $historyId): string
    {
        return @hash_file('sha256', $source) ?: throw new IntegrityException("履歴 #{$historyId} の旧添付を読み込めません: ".basename($source));
    }

    /** Only importer-owned digest paths can be retired, after the new DB references have committed. */
    public function unusedCopies(int $historyId, array $linkedPaths): array
    {
        $directory = "easymail/{$historyId}";
        $unused = [];
        foreach (Storage::disk('local')->files($directory) as $path) {
            if (preg_match('/^[a-f0-9]{64}(?:\.[a-f0-9]{24}\.tmp)?$/D', basename($path))
                && !in_array($path, $linkedPaths, true) && !Attachment::where('path', $path)->exists()) {
                $unused[] = $path;
            }
        }
        return $unused;
    }
}
