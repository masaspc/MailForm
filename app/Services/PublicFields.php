<?php

namespace App\Services;

use App\Services\EasyMail\SourceRelease;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\Mime\MimeTypes;

class PublicFields
{
    public const TYPES = ['text', 'number', 'email', 'tel', 'date', 'datetime', 'month', 'time', 'textarea', 'url', 'select', 'multi_select', 'radio', 'checkbox', 'file', 'first_last_name', 'zp_address', 'name_and_furigana'];

    private const PATTERNS = [
        'katakana' => '/^[ァ-ヶｦ-ﾟー　 ]+$/uD',
        'hiragana' => '/^[ぁ-んー　 ]+$/uD',
        'num' => '/^[0-9]+$/D',
        'alphabet_num' => '/^[A-Za-z0-9]+$/D',
        'alphabet' => '/^[A-Za-z]+$/D',
        'alphabet_num_mix' => '/^(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]+$/D',
        'num_hyphen' => '/^[0-9-]+$/D',
        'tel' => '/^(?:[0-9]{8,11}|[0-9]{2,4}-[0-9]{2,4}-[0-9]{3,4})$/D',
        'tel_hyphen' => '/^[0-9]{2,4}-[0-9]{2,4}-[0-9]{3,4}$/D',
        'zip' => '/^[0-9]{3}-?[0-9]{4}$/D',
        'zip_hyphen' => '/^[0-9]{3}-[0-9]{4}$/D',
    ];

    public const FILE_LIMIT_KB = 10240;
    public const TOTAL_FILE_BYTES = 20971520;
    public const MAX_FILES = 10;

    private const PREFECTURES = '北海道 青森県 岩手県 宮城県 秋田県 山形県 福島県 茨城県 栃木県 群馬県 埼玉県 千葉県 東京都 神奈川県 新潟県 富山県 石川県 福井県 山梨県 長野県 岐阜県 静岡県 愛知県 三重県 滋賀県 京都府 大阪府 兵庫県 奈良県 和歌山県 鳥取県 島根県 岡山県 広島県 山口県 徳島県 香川県 愛媛県 高知県 福岡県 佐賀県 長崎県 熊本県 大分県 宮崎県 鹿児島県 沖縄県';

    /** Preserve the original HTTP names of compound EasyMail fields. */
    public function controls(array $fields, ?string $sourceVersion = null, ?array $structure = null): array
    {
        $result = [];
        $bareAddress = SourceRelease::usesUnsuffixedAddress($sourceVersion);
        $fieldNames = array_column($fields, 'name');
        foreach ($fields as $field) {
            $name = $field['name'];
            $type = $field['form_type'];
            abort_unless(in_array($type, self::TYPES, true), 404);
            $restriction = $field['restriction'] ?? '';
            abort_unless($restriction === '' || $restriction === 'email' || isset(self::PATTERNS[$restriction]), 404);
            abort_if($name === 'email' && !in_array($type, ['email', 'text'], true), 404);
            $field += ['title' => $name, 'html_id' => null, 'choices' => [], 'restriction' => '', 'required' => false];
            $field['title'] = PublicLocale::label($field);
            $choiceType = $type === 'multi_select' ? 'select' : $type;
            $choices = [];
            foreach ($field['choices'] as $choice) {
                $visible = isset($field['form_item_id']) || empty($choice['delete_flag']);
                if ($visible && ($choice['choice_type'] ?? $choiceType) === $choiceType) {
                    $choices[] = (string) $choice['label_text'];
                }
            }
            $base = ['field' => $name, 'key' => $name, 'label' => $field['title'], 'id' => $field['html_id'] ?: $name, 'type' => $type,
                'options' => $choices, 'restriction' => $field['restriction'], 'source' => $field];
            $suffix = $bareAddress ? '' : $name;
            $parts = match ($type) {
                'first_last_name' => [
                    ['key' => 'last_name', 'label' => '姓', 'type' => 'text'],
                    ['key' => 'first_name', 'label' => '名', 'type' => 'text'],
                ],
                'name_and_furigana' => [
                    ['key' => 'your_name', 'label' => 'お名前', 'type' => 'text', 'restriction' => ''],
                    ['key' => 'name_and_furigana', 'label' => $field['restriction'] === 'hiragana' ? 'ふりがな' : 'フリガナ', 'type' => 'text'],
                ],
                'zp_address' => [
                    ['key' => 'zip'.$suffix, 'address_part' => 'zip', 'label' => '郵便番号', 'type' => $bareAddress ? 'tel' : 'text', 'restriction' => 'zip'],
                    ['key' => 'pref'.$suffix, 'address_part' => 'pref', 'label' => '都道府県', 'type' => 'select', 'options' => $field['pref_choices'] ?? explode(' ', self::PREFECTURES)],
                    ['key' => 'address'.$suffix, 'address_part' => 'address', 'label' => '市区町村・番地・建物名', 'type' => 'text'],
                ],
                default => [[]],
            };
            if ($type === 'zp_address' && $this->separateBuilding($structure, $suffix, $fieldNames)) {
                $parts[2]['label'] = '市区町村・番地';
                $parts[] = ['key' => 'building'.$suffix, 'address_part' => 'building', 'label' => '建物名', 'type' => 'text',
                    'source' => array_replace($field, ['required' => false]), 'restriction' => ''];
            }
            foreach ($parts as $part) {
                $control = $part + $base;
                if (count($parts) > 1) {
                    $control['id'] = $control['key'];
                    $control['label'] = __($control['label']);
                }
                $key = $control['key'];
                abort_unless(preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $key) && ! isset($result[$key]) && !in_array($key, ['receipt_token', 'remove_files'], true), 404);
                $pattern = self::PATTERNS[$control['restriction'] ?? ''] ?? null;
                $control['pattern'] = $pattern ? substr($pattern, 1, strrpos($pattern, '/') - 1) : '';
                $result[$key] = $control;
            }
        }
        return $result;
    }

    /** Build a whitelist of validated inputs, preserving scalar '0' and multiple choices. */
    public function validate(Request $request, array $controls, array $files): array
    {
        $values = $rules = $messages = $labels = [];
        $totalBytes = 0;
        $ids = array_column($controls, 'key', 'id');
        foreach ($controls as $key => $c) {
            $field = $c['source'];
            $type = $c['type'];
            $required = !empty($field['required']);
            $multi = in_array($type, ['checkbox', 'multi_select'], true);
            $value = $request->input($key);
            $parts = is_array($value) ? $value : [$value];
            foreach ($parts as $part) {
                if (is_string($part)) {
                    $bytes = strlen($part);
                    $totalBytes += $bytes;
                    abort_if($bytes > 65536 || $totalBytes > 262144, 413, __('入力内容が長すぎます。'));
                    abort_unless(mb_check_encoding($part, 'UTF-8'), 422, __('入力内容の文字コードを確認してください。'));
                }
            }
            if ($type === 'file') {
                $value = $request->file($key);
                $required = $required && empty($files[$key]);
            } elseif (is_string($value)) {
                $mode = $field['hankaku_zenkaku_automatic_conversion'] ?? 'default';
                $value = match ($mode) {
                    'hankaku' => strtr(mb_convert_kana($value, 'as'), ['‐' => '-', 'ー' => '-', '―' => '-', '～' => '~', '〜' => '~']),
                    'zenkaku' => mb_convert_kana($value, 'ASKV'),
                    default => $value,
                };
            }

            $values[$key] = $value;
            $labels[$key] = $c['label'];
            $rules[$key] = ['bail', $required ? 'required' : 'nullable'];
            $messages[$key.'.required'] = ($field['required_error_msg'] ?? '') ?: __(':attributeを入力してください。', ['attribute' => $c['label']]);
            $formatMessage = ($field['restriction_error_msg'] ?? '') ?: __(':attributeの入力形式を確認してください。', ['attribute' => $c['label']]);
            foreach (['string', 'email', 'array', 'list', 'in', 'regex', 'date_format', 'url', 'file', 'uploaded'] as $rule) {
                $messages[$key.'.'.$rule] = $formatMessage;
            }
            if ($type === 'file') {
                $rules[$key][] = 'file';
                $configured = (int) ($field['file_capacity_limit'] ?? 0);
                $limit = $configured > 0 ? min($configured, self::FILE_LIMIT_KB) : self::FILE_LIMIT_KB;
                $rules[$key][] = 'max:'.$limit;
                $messages[$key.'.max'] = __('ファイルは:limit KB以内にしてください。', ['limit' => $limit]);
                $rules[$key][] = function ($attribute, $value, $fail) use ($field) {
                    if (!$this->allowedFile($value, $field['file_type'] ?? '')) {
                        $fail(__('指定されたファイル形式を選択してください。'));
                    }
                };
                continue;
            }
            if ($multi) {
                $rules[$key][] = 'array';
                $rules[$key][] = 'list';
                $rules[$key][] = 'max:'.count($c['options']);
                $rules[$key.'.*'] = ['bail', 'string', 'distinct:strict', Rule::in($c['options'])];
                $messages[$key.'.*.*'] = __('一覧から選んでください。');
                continue;
            }

            $rules[$key][] = 'string';
            foreach (['min', 'max'] as $bound) {
                if (($field[$bound.'_length'] ?? 0) > 0) {
                    $rules[$key][] = $bound.':'.(int) $field[$bound.'_length'];
                    $messages[$key.'.'.$bound] = ($field['length_error_msg'] ?? '') ?: __(':attributeの文字数を確認してください。', ['attribute' => $c['label']]);
                }
            }
            // A numeric rule would make Laravel measure min/max as numbers instead of characters.
            $typedRule = match ($type) {
                'number' => 'regex:/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D',
                'email' => 'email:rfc',
                'url' => 'url:http,https',
                'date' => 'date_format:Y-m-d',
                'datetime' => 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s',
                'month' => 'date_format:Y-m',
                'time' => 'date_format:H:i,H:i:s',
                'radio', 'select' => Rule::in($c['options']),
                default => null,
            };
            if ($typedRule) {
                $rules[$key][] = $typedRule;
            }
            $restriction = $c['restriction'] ?? '';
            if (isset(self::PATTERNS[$restriction])) {
                $rules[$key][] = 'regex:'.self::PATTERNS[$restriction];
            } elseif ($restriction === 'email' || $key === 'email') {
                $rules[$key][] = 'email:rfc';
            }
            foreach (explode(',', $field['forbidden_characters'] ?? '') as $word) {
                if ($word !== '') {
                    $rules[$key][] = function ($attribute, $value, $fail) use ($word) {
                        if (str_contains($value, $word)) {
                            $fail(__('使用できない文字が含まれています。'));
                        }
                    };
                }
            }
            if ($target = ($field['re_enter_html_id'] ?? '')) {
                $targetKey = $ids[ltrim($target, '#')] ?? null;
                abort_if(!$targetKey, 404);
                $rules[$key][] = 'same:'.$targetKey;
                $messages[$key.'.same'] = ($field['re_enter_error_msg'] ?? '') ?: __('再入力した内容が一致しません。');
                if (!$required) {
                    $rules[$key][] = 'required_with:'.$targetKey;
                    $messages[$key.'.required_with'] = $messages[$key.'.same'];
                }
            }
        }

        return [$values, Validator::make($values, $rules, $messages, $labels)];
    }

    private function allowedFile(UploadedFile $file, string $accepted): bool
    {
        $name = $file->getClientOriginalName();
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        if (!mb_check_encoding($name, 'UTF-8') || preg_match('/[[:cntrl:]]/u', $name) || str_contains($name, '/') || str_contains($name, chr(92))
            || preg_match('/\.(?:php[0-9]*|phtml|phar|cgi|pl|exe|com|msi|bat|cmd|ps1|sh|vbs|js|mjs|hta|html?|xhtml|svgz?)(?:\.|$)/i', $name)
            || in_array($mime, ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/x-php', 'application/x-httpd-php', 'application/x-executable', 'application/x-dosexec'], true)) {
            return false;
        }
        if (trim($accepted) === '') {
            return true;
        }
        $extensions = MimeTypes::getDefault()->getExtensions($mime);
        if ($mime === 'text/plain') {
            $extensions[] = 'csv';
        }
        foreach (array_map('trim', explode(',', strtolower($accepted))) as $option) {
            if ($option === $mime || (str_ends_with($option, '/*') && str_starts_with($mime, substr($option, 0, -1)))) {
                return true;
            }
            if (str_starts_with($option, '.') && substr($option, 1) === strtolower($file->getClientOriginalExtension()) && in_array(substr($option, 1), $extensions, true)) {
                return true;
            }
        }
        return false;
    }

    private function separateBuilding(?array $structure, string $suffix, array $fieldNames): bool
    {
        // A separately defined form item owns its original HTTP key.
        return ThemeStructure::hasBuilding($structure) && !in_array('building'.$suffix, $fieldNames, true);
    }

    public function answers(array $fields, array $values, array $files, ?string $sourceVersion = null, ?array $structure = null): array
    {
        $answers = [];
        $bareAddress = SourceRelease::usesUnsuffixedAddress($sourceVersion);
        $fieldNames = array_column($fields, 'name');
        foreach ($fields as $field) {
            $name = $field['name'];
            $suffix = $bareAddress ? '' : $name;
            $answers[$name] = match ($field['form_type']) {
                'first_last_name' => ($values['last_name'] ?? '').($values['first_name'] ?? ''),
                'name_and_furigana' => ['name' => $values['your_name'] ?? '', 'kana' => $values['name_and_furigana'] ?? ''],
                'zp_address' => trim(implode("\n", [$values['zip'.$suffix] ?? '', $values['pref'.$suffix] ?? '', $values['address'.$suffix] ?? '',
                    $this->separateBuilding($structure, $suffix, $fieldNames) ? ($values['building'.$suffix] ?? '') : ''])),
                'checkbox', 'multi_select' => $values[$name] ?? [],
                'file' => $files[$name]['name'] ?? '',
                default => $values[$name] ?? '',
            };
        }
        return $answers;
    }

    public static function display($value): string
    {
        return is_array($value) ? implode(', ', $value) : (string) $value;
    }
}
