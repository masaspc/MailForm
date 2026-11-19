<?php

namespace App\Services;

final class PublicLocale
{
    public static function activate(?string $locale): void
    {
        $locale = $locale ?: 'ja';
        abort_unless(in_array($locale, ['ja', 'en', 'cn'], true), 404);
        app()->setLocale($locale);
    }

    /** Preserve author-supplied labels. EasyMail has translations for its built-in field names. */
    public static function label(array $field): string
    {
        $label = (string) ($field['title'] ?? $field['name']);
        if (app()->getLocale() === 'ja') {
            return $label;
        }
        $key = 'stock.'.$field['name'];
        return app('translator')->hasForLocale($key, app()->getLocale()) ? __($key) : $label;
    }

    public static function compoundLabels(array $fields): array
    {
        $labels = [];
        foreach ($fields as $field) {
            $name = $field['name'];
            $type = $field['form_type'];
            if ($type === 'zp_address' || $type === 'first_last_name') {
                $labels[$name] = [self::label(['name' => $type, 'title' => $type === 'zp_address' ? '郵便番号付き住所' : '氏名'])];
            } elseif ($type === 'name_and_furigana') {
                $labels[$name] = [
                    self::label(['name' => 'name', 'title' => 'お名前']),
                    __((($field['restriction'] ?? '') === 'hiragana') ? 'ふりがな' : 'フリガナ'),
                ];
            }
        }
        return $labels;
    }

    public static function browserMessages(): array
    {
        if (app()->getLocale() === 'ja') {
            return [];
        }
        $messages = [];
        foreach ([
            '再入力した内容が一致しません。', '入力形式を確認してください。', '使用できない文字が含まれています。',
            '住所をご確認のうえ、番地・建物名を入力してください。', '7桁の郵便番号を入力してください。', '検索しています…',
            '入力内容が変わりました。再度検索できます。', '該当する住所がありません。手入力できます。', '住所を選択してください',
            '該当する住所が複数あります。', '住所を検索できませんでした。手入力できます。',
        ] as $key) {
            $messages[$key] = __($key);
        }

        return $messages;
    }
}
