<?php

namespace App\Services;

/** Only these presentation slots, tags and literal identifiers may cross from a theme into a view. */
final class ThemeStructure
{
    public const MAX_DEPTH = 12;
    public const WRAPPERS = ['div', 'section', 'main', 'article', 'header', 'footer', 'nav', 'aside', 'fieldset',
        'dl', 'dt', 'dd', 'ol', 'ul', 'li', 'p', 'span', 'table', 'thead', 'tbody', 'tr', 'th', 'td'];
    public const ADDRESS_PARTS = ['zip' => '郵便番号', 'pref' => '都道府県', 'address' => '住所', 'building' => '建物名'];
    public const BUTTON_POSITIONS = ['inside_form' => 'フォームの中', 'outside_form' => 'フォームの直後',
        'after_form_outer' => 'フォームを囲む枠の直後'];
    public const STEP_VISIBILITY = ['visible' => '画面に表示', 'accessible' => '読み上げ用に表示'];
    public const REQUIRED_POSITIONS = ['before' => '項目名の前', 'after' => '項目名の後'];
    public const KANA_ROWS = ['together' => '氏名と読みを同じ行に表示', 'separate' => '氏名と読みを別の行に表示'];
    public const BUTTON_ATTRIBUTES = ['confirm' => 'data-mailform-confirm', 'back' => 'data-mailform-back', 'send' => 'data-mailform-send'];

    private const LABELS = [
        'page' => 'ページ外枠', 'title' => '表題', 'body' => '本文の枠', 'form_outer' => 'フォームを囲む枠',
        'form' => 'フォーム本体', 'description' => 'フォームの説明文', 'steps' => 'ステップ全体',
        'step' => '各ステップ', 'step_current' => '現在のステップ', 'steps_visibility' => 'ステップの表示',
        'rows' => '項目一覧の枠', 'row' => '項目の行', 'label' => '項目名', 'required' => '必須マーク',
        'required_position' => '必須マークの位置', 'field' => '入力側の枠',
        'above' => '項目の上の補足', 'below' => '項目の下の補足', 'error' => '項目のエラー',
        'text' => 'テキスト欄', 'email' => 'メール欄', 'tel' => '電話番号欄', 'number' => '数値欄',
        'url' => 'URL欄', 'date' => '日付欄', 'datetime' => '日時欄', 'month' => '年月欄', 'time' => '時刻欄',
        'textarea' => '複数行テキスト欄', 'select' => '選択欄', 'multi_select' => '複数選択欄',
        'radio' => 'ラジオボタン', 'checkbox' => 'チェックボックス', 'file' => '添付ファイル欄',
        'last_name' => '姓名を分ける欄の姓', 'first_name' => '姓名を分ける欄の名',
        'your_name' => 'ふりがな付き氏名欄の氏名', 'name_and_furigana' => 'ふりがな付き氏名欄の読み',
        'kana_rows' => '氏名と読みの並び',
        'choices' => '選択肢全体', 'choice' => '選択肢の枠', 'choice_label' => '選択肢のラベル', 'choice_text' => '選択肢の文言',
        'zip' => '住所組の郵便番号', 'pref' => '住所組の都道府県', 'address' => '住所組の住所',
        'building' => '住所組の建物名', 'address_order' => '住所の並び順',
        'buttons' => '操作ボタン全体', 'buttons_position' => '操作ボタンを置く位置',
        'confirm_wrap' => '確認ボタンの枠', 'back_wrap' => '戻るボタンの枠', 'send_wrap' => '送信ボタンの枠',
        'confirm_button' => '確認ボタン本体', 'back_button' => '戻るボタン本体', 'send_button' => '送信ボタン本体',
        'confirm_rows' => '確認画面の項目一覧', 'confirm_row' => '確認画面の行', 'confirm_label' => '確認画面の項目名', 'confirm_field' => '確認画面の回答側',
        'confirm_value' => '確認画面の回答', 'thanks_page' => '完了画面の外枠', 'thanks_message' => '完了メッセージ',
    ];

    public static function labels(): array
    {
        $labels = self::LABELS;
        foreach (['radio', 'checkbox'] as $type) {
            foreach (['choices', 'choice', 'choice_label', 'choice_text'] as $part) {
                $labels[$type.'_'.$part] = $labels[$type].'の'.$labels[$part];
            }
        }
        return $labels;
    }

    /** Allowed final element; preceding nodes can only be inert wrappers. */
    public static function tags(string $slot): array
    {
        $slot = preg_replace('/^(radio|checkbox)_(?=choice)/', '', $slot);
        return match ($slot) {
            'form' => ['form'],
            'select', 'multi_select', 'pref' => ['select'],
            'textarea' => ['textarea'],
            'text', 'email', 'tel', 'number', 'date', 'datetime', 'month', 'time', 'url', 'file',
            'radio', 'checkbox', 'zip', 'address', 'building', 'first_name', 'last_name', 'your_name', 'name_and_furigana' => ['input'],
            'back_button', 'send_button', 'confirm_button' => ['button', 'input'],
            'title', 'thanks_message' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'span', 'strong'],
            'label', 'confirm_label', 'choice_label' => ['label', 'legend', 'dt', 'th', 'td', 'div', 'p', 'span', 'strong'],
            'required', 'choice_text', 'description', 'above', 'below', 'error', 'confirm_value' =>
                ['div', 'p', 'span', 'small', 'em', 'strong', 'b', 'i', 'abbr'],
            'address_order', 'steps_visibility', 'buttons_position', 'required_position', 'kana_rows' => [],
            default => isset(self::LABELS[$slot]) ? self::WRAPPERS : [],
        };
    }

    public static function classes(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 1024) {
            return '';
        }
        $tokens = preg_split('/[ \t\n\r\f]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        return implode(' ', array_unique(array_filter($tokens, self::identifier(...))));
    }

    public static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A(?!js[-_])[-_\p{L}][\p{L}\p{M}\p{N}_-]{0,63}\z/iu', $value) === 1;
    }

    public static function hasBuilding(?array $structure): bool
    {
        return !empty($structure['building']) || in_array('building', (array) ($structure['address_order'] ?? []), true);
    }

    public static function clean(array $structure): array
    {
        $result = [];
        foreach (array_intersect_key($structure, self::labels()) as $slot => $value) {
            $item = self::normalizeSlot($slot, $value, false);
            if ($item !== null) {
                $result[$slot] = $item;
            }
        }
        return $result;
    }

    public static function valid(array $structure): bool
    {
        $labels = self::labels();
        foreach ($structure as $slot => $value) {
            if (!is_string($slot) || !isset($labels[$slot]) || self::normalizeSlot($slot, $value, true) === null) {
                return false;
            }
        }
        return true;
    }

    public static function options(string $slot): array
    {
        return match ($slot) {
            'steps_visibility' => self::STEP_VISIBILITY, 'buttons_position' => self::BUTTON_POSITIONS,
            'required_position' => self::REQUIRED_POSITIONS, 'kana_rows' => self::KANA_ROWS, default => [],
        };
    }

    private static function normalizeSlot(string $slot, mixed $value, bool $strict): array|string|null
    {
        if ($options = self::options($slot)) {
            return is_string($value) && isset($options[$value]) ? $value : null;
        }
        if (!is_array($value) || !$value || !array_is_list($value) || count($value) > self::MAX_DEPTH) {
            return null;
        }
        if ($slot === 'address_order') {
            $order = [];
            foreach ($value as $part) {
                if (!is_string($part) || !isset(self::ADDRESS_PARTS[$part]) || in_array($part, $order, true)) {
                    return null;
                }
                $order[] = $part;
            }
            return $order;
        }
        $chain = [];
        foreach ($value as $index => $node) {
            $allowed = $index === array_key_last($value) ? self::tags($slot) : self::WRAPPERS;
            if (!is_array($node) || !in_array($node['tag'] ?? null, $allowed, true)
                || ($strict && array_diff(array_keys($node), ['tag', 'class', 'id']))) {
                return null;
            }
            $safe = ['tag' => $node['tag']];
            if (isset($node['class'])) {
                $classes = self::classes($node['class']);
                if ($strict && (!is_string($node['class']) || strlen($node['class']) > 1024
                    || array_diff(preg_split('/\s+/', trim($node['class']), -1, PREG_SPLIT_NO_EMPTY), explode(' ', $classes)))) {
                    return null;
                }
                if ($classes !== '') {
                    $safe['class'] = $classes;
                }
            }
            if (isset($node['id'])) {
                if (self::identifier($node['id'])) {
                    $safe['id'] = $node['id'];
                } elseif ($strict && $node['id'] !== '') {
                    return null;
                }
            }
            $chain[] = $safe;
        }
        return $chain;
    }
}
