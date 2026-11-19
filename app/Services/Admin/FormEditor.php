<?php

namespace App\Services\Admin;

use App\Models\Form;
use App\Models\Theme;
use App\Services\EasyMail\FormAudit;
use App\Services\FieldLibrary;
use App\Services\PublicFormUrl;
use App\Services\SpamProtection;
use App\Services\ThemeCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FormEditor
{
    public const TYPES = [
        'text' => '一行入力', 'textarea' => '複数行入力', 'email' => 'メールアドレス',
        'tel' => '電話番号', 'number' => '数値', 'url' => 'URL', 'date' => '日付',
        'datetime' => '日時', 'month' => '年月', 'time' => '時刻', 'select' => 'プルダウン',
        'radio' => '単一選択', 'checkbox' => '複数選択', 'multi_select' => '複数選択リスト',
        'file' => '添付ファイル', 'first_last_name' => '姓・名', 'name_and_furigana' => '氏名とふりがな',
        'zp_address' => '郵便番号と住所',
    ];

    public const THEMES = [
        'gray' => 'グレー', 'gradation' => 'グラデーション', 'one_column' => '1カラム',
        'two_column01' => '2カラム 1', 'two_column02' => '2カラム 2',
    ];

    public const MESSAGES = [
        'description' => 'フォームの説明', 'input_form_header_message' => '入力画面の冒頭',
        'input_form_footer_message' => '入力画面の末尾', 'conf_form_header_message' => '確認画面の冒頭',
        'conf_form_footer_message' => '確認画面の末尾', 'thanks_message' => '送信完了メッセージ',
        'error_form_header_message' => 'エラー画面の冒頭', 'error_form_footer_message' => 'エラー画面の末尾',
        'reply_mail_header_message' => 'メールの冒頭', 'reply_mail_footer_message' => 'メールの末尾',
    ];

    public const FLAGS = [
        'conf_mail_flag' => '回答者に自動返信を送る', 'include_submissions' => '自動返信に回答を含める',
        'include_submissions_admin_email' => '管理者通知に回答を含める', 'save_data' => '回答を受信履歴に保存する',
    ];

    public const BEHAVIOR = [
        'recaptcha_enabled' => 'reCAPTCHA v3 を使う',
        'beforeunload_flag' => '編集中にページを離れたときに確認する',
        'enter_forbidden_flag' => 'Enterキーによる誤送信を防ぐ',
        'submit_disabled_flag' => '送信後にボタンを無効化する',
    ];

    public const FIELD_TEXT = [
        'html_id' => 'HTML ID', 'html_class' => 'CSSクラス', 'initial_value' => '初期値',
        'forbidden_characters' => '禁止文字', 'required_error_msg' => '必須エラーの文言',
        'restriction_error_msg' => '形式エラーの文言', 'length_error_msg' => '文字数エラーの文言',
        're_enter_error_msg' => '再入力エラーの文言', 'description_above' => '項目の上の説明',
        'description_below' => '項目の下の説明', 'file_type' => '許可する拡張子・種類（例 .pdf,.jpg）',
    ];

    public const RESTRICTIONS = [
        '' => '指定なし', 'katakana' => 'カタカナ', 'hiragana' => 'ひらがな', 'num' => '数字',
        'alphabet' => '英字', 'alphabet_num' => '英数字', 'alphabet_num_mix' => '英字・数字を両方含む',
        'num_hyphen' => '数字とハイフン', 'email' => 'メールアドレス', 'tel' => '電話番号',
        'tel_hyphen' => '電話番号（ハイフンあり）', 'zip' => '郵便番号', 'zip_hyphen' => '郵便番号（ハイフンあり）',
    ];

    private const CHOICE_TYPES = ['select', 'multi_select', 'radio', 'checkbox'];

    public function preset(string $preset, string $email): array
    {
        $presets = FieldLibrary::presets();
        abort_unless(isset($presets[$preset]), 404);
        $definition = $presets[$preset];
        $definition['settings']['admin_email'] = $email;
        $definition['settings']['view_flag'] = 0;
        $definition['settings']['cc_email'] = '';
        $definition['settings']['bcc_email'] = '';

        return $definition;
    }

    public function draft(Form $form): array
    {
        return $form->draft_definition ?? $form->definition;
    }

    public function version(Form $form): string
    {
        return hash('sha256', serialize([$form->revision, $form->published, (bool) $form->awaiting_theme_publication, $form->archived_at, $this->draft($form)]));
    }

    public function checkVersion(Request $request, Form $form): void
    {
        if ($this->version($form) !== $request->input('version')) {
            abort(409, '編集中にフォームが更新されました。画面を開き直して、最新の内容をご確認ください。');
        }
    }

    public function read(Request $request, array $base, ?Form $form = null): array
    {
        $rules = [
            'name' => 'required|string|max:190',
            'slug' => 'nullable|string|max:255',
            'language' => ['nullable', Rule::in(['ja','en','cn'])],
            'theme_name' => ['required', Rule::in(array_unique([...array_keys(ThemeCatalog::choices()), $base['settings']['theme_name'] ?? 'gray']))],
            'admin_email' => 'nullable|string|max:2000', 'cc_email' => 'nullable|string|max:2000', 'bcc_email' => 'nullable|string|max:2000',
            'mail_title' => 'nullable|string|max:255|not_regex:/[\r\n]/',
            'fields' => 'present|array|max:160', 'fields.*' => 'array',
            'fields.*.id' => 'nullable|integer|min:1|distinct', 'fields.*.title' => 'required|string|max:255',
            'fields.*.name' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/D'],
            'fields.*.form_type' => 'required|string|max:80',
            'fields.*.required' => 'nullable|boolean', 'fields.*.choices_text' => 'nullable|string|max:30000',
            'fields.*.placeholder' => 'nullable|string|max:2000', 'fields.*.min_length' => 'nullable|integer|min:0|max:100000',
            'fields.*.max_length' => 'nullable|integer|min:0|max:100000', 'fields.*.re_enter_html_id' => 'nullable|string|max:190',
            'editor_complete' => 'required|accepted',
        ];
        foreach (self::MESSAGES as $key => $label) {
            $rules[$key] = 'nullable|string|max:30000';
        }
        foreach (self::FLAGS + self::BEHAVIOR as $key => $label) {
            $rules[$key] = 'nullable|boolean';
        }

        $rules += [
            'denied_ip' => 'nullable|string|max:10000', 'denied_ip_host_error_msg' => 'nullable|string|max:2000',
            'fields.*.restriction' => ['nullable', Rule::in(array_keys(self::RESTRICTIONS))],
            'fields.*.hankaku_zenkaku_automatic_conversion' => ['nullable', Rule::in(['default', 'hankaku', 'zenkaku'])],
            'fields.*.columns' => 'nullable|integer|min:1|max:6', 'fields.*.file_capacity_limit' => 'nullable|integer|min:1|max:1048576',
            'fields.*.real_time_validation' => 'nullable|boolean',
        ];
        foreach (self::FIELD_TEXT as $key => $label) {
            $rules['fields.*.'.$key] = 'nullable|string|max:5000';
        }

        $data = Validator::make($request->all(), $rules, [
            'required' => '「:attribute」を入力してください。', 'max' => '「:attribute」が入力の上限を超えています。',
            'fields.*.name.regex' => '項目キーは半角英字で始め、半角英数字・ハイフン・アンダースコアで入力してください。',
            'editor_complete.required' => '入力項目が送信上限を超えました。サーバーの max_input_vars を確認してください。',
        ], ['name' => 'フォーム名', 'fields.*.title' => '項目名', 'fields' => '入力項目'])->validate();

        $settings = $base['settings'];
        $settings['name'] = $data['name'];
        $settings['language'] = $data['language'] ?? ($settings['language'] ?? 'ja');
        $settings['url'] = $data['slug'] ?? '';
        $this->checkSlug($settings['url'], $form);
        foreach (['theme_name', 'admin_email', 'cc_email', 'bcc_email', 'mail_title', 'denied_ip', 'denied_ip_host_error_msg', ...array_keys(self::MESSAGES)] as $key) {
            $settings[$key] = $data[$key] ?? '';
        }
        foreach (self::FLAGS + self::BEHAVIOR as $key => $label) {
            $settings[$key] = (int) $request->boolean($key);
        }
        foreach (['admin_email', 'cc_email', 'bcc_email'] as $key) {
            $this->checkAddresses($settings[$key], $key);
        }

        $old = collect($base['fields'])->keyBy('id');
        $next = (int) $old->keys()->max();
        $fields = [];
        $used = [];
        foreach (array_values($data['fields']) as $i => $row) {
            $id = $row['id'] ?? null;
            if ($id && ! $old->has($id)) {
                throw ValidationException::withMessages(['fields' => '項目が変更されました。画面を開き直してください。']);
            }
            $field = $old->get($id, []);
            $id ??= ++$next;
            $type = $row['form_type'];
            if (! isset(self::TYPES[$type]) && $type !== ($field['form_type'] ?? null)) {
                throw ValidationException::withMessages(['fields' => '入力形式を確認してください。']);
            }
            $name = $row['name'] ?? ($type === 'email' && ! isset($used['email']) ? 'email' : 'field_'.$id);
            if (isset($used[$name]) || in_array($name, ['token', 'submit', 'action', 'method', 'elements'], true)) {
                throw ValidationException::withMessages(['fields' => "項目キー「{$name}」は重複しているか、使用できません。"]);
            }
            $used[$name] = true;
            $field = array_replace($field, [
                'id' => (int) $id, 'name' => $name, 'html_id' => ($field['html_id'] ?? '') ?: $name,
                'title' => $row['title'], 'form_type' => $type, 'view_no' => $i + 1, 'required' => (int) ($row['required'] ?? 0),
                'placeholder' => $row['placeholder'] ?? '', 'min_length' => $row['min_length'] ?? null,
                'max_length' => $row['max_length'] ?? null, 're_enter_html_id' => $row['re_enter_html_id'] ?? '',
            ]);
            foreach (array_keys(self::FIELD_TEXT) as $key) {
                $field[$key] = $row[$key] ?? '';
            }
            $field['html_id'] = $field['html_id'] ?: $name;
            $field['choices'] ??= [];
            $field['restriction'] = $row['restriction'] ?? '';
            $field['hankaku_zenkaku_automatic_conversion'] = $row['hankaku_zenkaku_automatic_conversion'] ?? 'default';
            $field['columns'] = $row['columns'] ?? null;
            $field['file_capacity_limit'] = $row['file_capacity_limit'] ?? null;
            $field['real_time_validation'] = (int) ($row['real_time_validation'] ?? 0);
            if (isset($field['min_length'], $field['max_length']) && $field['max_length'] < $field['min_length']) {
                throw ValidationException::withMessages(['fields' => "「{$field['title']}」の最大文字数は最小文字数以上にしてください。"]);
            }
            if (in_array($type, self::CHOICE_TYPES, true)) {
                $choiceType = $type === 'multi_select' ? 'select' : $type;
                $labels = array_values(array_filter(array_map('trim', preg_split('/\R/u', $row['choices_text'] ?? '')), 'strlen'));
                $visible = self::choiceLabels($old->get($id, []));
                if ($labels === $visible && ($old->get($id)['form_type'] ?? null) === $type) {
                    $fields[] = $field;
                    continue;
                }
                $existing = collect($field['choices'] ?? [])->keyBy('label_text');
                $field['choices'] = [];
                foreach ($labels as $j => $label) {
                    $field['choices'][] = array_replace($existing->get($label, []), [
                        'label_text' => $label, 'view_no' => $j + 1, 'block_id' => (int) $id, 'choice_type' => $choiceType, 'delete_flag' => 0,
                    ]);
                }
            }
            $fields[] = $field;
        }

        $draft = array_replace($base, ['settings' => $settings, 'fields' => $fields]);
        // Only re-evaluate issues attributed to definition validation by the importer.
        if (isset($base['validation_issues'], $base['installation_issues'])) {
            $other = array_diff($base['issues'], $base['validation_issues']);
            $draft['validation_issues'] = $this->audit($draft);
            $draft['issues'] = array_values(array_unique([...$base['installation_issues'], ...$other, ...$draft['validation_issues']]));
        }

        return $draft;
    }

    public static function choiceLabels(array $field): array
    {
        $type = $field['form_type'] ?? '';
        $choiceType = $type === 'multi_select' ? 'select' : $type;
        $visible = array_filter($field['choices'] ?? [], fn ($c) =>
            (isset($field['form_item_id']) || empty($c['delete_flag'])) && ($c['choice_type'] ?? $choiceType) === $choiceType
        );

        return array_column($visible, 'label_text');
    }

    public function checkSlug(string $slug, ?Form $form): void
    {
        if (($problem = PublicFormUrl::problem($slug)) !== null) {
            throw ValidationException::withMessages(['slug' => $problem]);
        }
        if (Form::whereRaw('LOWER(slug) = LOWER(?)', [$slug])->when($form, fn ($q) => $q->whereKeyNot($form->id))->exists()) {
            throw ValidationException::withMessages(['slug' => 'このURLは別のフォームで使用されています。']);
        }
    }

    public function checkPublish(array $definition, Form $form): void
    {
        if ($definition['issues'] ?? []) {
            throw ValidationException::withMessages(['definition' => '移行診断の要確認事項を解消してから公開してください。']);
        }
        $settings = $definition['settings'];
        $this->checkSlug((string) ($settings['url'] ?? $form->slug), $form);
        if (Theme::where('slug', $settings['theme_name'] ?? 'one_column')->first()?->pendingReview()) {
            throw ValidationException::withMessages(['definition' => '管理者が移行テーマの確認を完了してから公開してください。']);
        }
        $issues = $this->audit($definition);
        if ($issues) {
            throw ValidationException::withMessages(['definition' => $issues]);
        }
    }

    private function audit(array $definition): array
    {
        $settings = $definition['settings'];
        $missingCaptcha = ! empty($settings['recaptcha_enabled']) && ! SpamProtection::configured();
        unset($settings['recaptcha_enabled']);
        $warnings = [];
        $issues = app(FormAudit::class)->inspect($settings, $definition['fields'], $warnings, $definition['source_version'] ?? null);
        if ($missingCaptcha) {
            $issues[] = 'reCAPTCHAを使うには環境設定の RECAPTCHA_SITE_KEY と RECAPTCHA_SECRET を設定してください。';
        }

        return $issues;
    }

    private function checkAddresses(string $list, string $key): void
    {
        if ($list === '') {
            return;
        }
        foreach (explode(',', $list) as $address) {
            if (! filter_var(trim($address), FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([$key => '宛先にはメールアドレスを入力し、複数の場合は半角カンマで区切ってください。']);
            }
        }
    }
}
