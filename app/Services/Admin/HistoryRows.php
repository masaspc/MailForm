<?php

namespace App\Services\Admin;

use App\Models\Submission;

class HistoryRows
{
    public function cells(Submission $submission, bool $includeFiles = false): array
    {
        $result = [];
        $occurrences = [];
        $answers = $submission->answers ?? [];
        $fields = data_get($submission->snapshot, 'definition.fields', []);
        $imported = isset($submission->snapshot['legacy_history']);
        foreach ($fields as $i => $field) {
            $type = $field['form_type'] ?? '';
            if (! $includeFiles && $type === 'file') {
                continue;
            }
            $name = $field['name'] ?? 'legacy_field_'.$i;
            $title = $field['title'] ?? $name;
            $value = $answers[$name] ?? '';
            if (! $imported && $type === 'name_and_furigana' && is_array($value)) {
                $this->append($result, $occurrences, $name === 'name_and_furigana' ? 'お名前' : $title, $value['name'] ?? '');
                $this->append($result, $occurrences, ($field['restriction'] ?? '') === 'hiragana' ? 'ふりがな' : 'フリガナ', $value['kana'] ?? '');
                continue;
            }
            if (! $imported && $type === 'zp_address') {
                $title = '郵便番号付き住所';
                $value = str_replace(["\r", "\n"], '', (string) $value);
            } elseif (! $imported && $name === 'first_last_name') {
                $title = '氏名';
            }
            $this->append($result, $occurrences, $title, $this->format($value));
        }

        return $result;
    }

    private function append(array &$result, array &$occurrences, string $title, string $value): void
    {
        // The historical JSON identifies columns by displayed title and position, without a stable field key.
        $position = ($occurrences[$title] ?? 0) + 1;
        $occurrences[$title] = $position;
        $result[] = ['key' => serialize([$title, $position]), 'title' => $title, 'value' => $value];
    }

    public function format(mixed $value): string
    {
        if (is_array($value)) {
            return implode("\u{00A0}", array_map(fn ($item) => $this->format($item), $value));
        }

        return (string) $value;
    }

    public function csvLine(array $cells): string
    {
        $escaped = [];
        foreach ($cells as $cell) {
            $cell = (string) $cell;
            if (preg_match('/^(?:[\t\r\n]|\s*[=+@＝＋＠－-])/u', $cell)) {
                $cell = "'".$cell;
            }
            $escaped[] = '"'.str_replace('"', '""', $cell).'"';
        }

        return implode(',', $escaped);
    }
}
