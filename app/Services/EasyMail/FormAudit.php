<?php

namespace App\Services\EasyMail;

use App\Services\PublicAccess;
use App\Services\PublicFields;
use App\Services\SafeMarkup;
use App\Services\SpamProtection;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class FormAudit
{
    public const SETTINGS = [
        'id', 'created_at', 'updated_at', 'view_flag', 'url', 'name', 'description', 'mail_title', 'theme_name', 'admin_email', 'bcc_email', 'cc_email',
        'conf_mail_flag', 'include_submissions', 'include_submissions_admin_email', 'save_data', 'denied_ip', 'denied_ip_host_error_msg', 'language',
        'reply_mail_header_message', 'reply_mail_footer_message', 'input_form_header_message', 'input_form_footer_message', 'conf_form_header_message',
        'conf_form_footer_message', 'error_form_header_message', 'error_form_footer_message', 'thanks_message', 'google_re_captcha_site_key',
        'google_re_captcha_secret_key', 'google_re_captcha_bottom_px', 'beforeunload_flag', 'enter_forbidden_flag', 'submit_disabled_flag',
    ];

    public function inspect(array $settings, array $fields, array &$warnings, ?string $sourceVersion = null): array
    {
        $issues = [];
        foreach (array_diff(array_keys($settings), self::SETTINGS) as $key) {
            if ($settings[$key] !== null && $settings[$key] !== '') {
                $issues[] = "独自のフォーム設定: {$key}";
            }
        }
        if ((!empty($settings['google_re_captcha_secret_key']) || !empty($settings['google_re_captcha_site_key'])) && !SpamProtection::configured()) {
            $issues[] = 'reCAPTCHA の新しいサイトキー・シークレットを設定してください。';
        }
        if (!in_array($settings['language'] ?? '', ['', 'ja', 'en', 'cn'], true)) {
            $issues[] = '未対応の表示言語です。対応言語は日本語・英語・中国語です。';
        }
        foreach (preg_split('/\R/u', (string) ($settings['denied_ip'] ?? ''), flags: PREG_SPLIT_NO_EMPTY) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            if (!PublicAccess::supports($rule)) {
                $issues[] = 'IP・ホスト制限に未対応のパターンがあります。';
            } elseif (PublicAccess::isHostRule($rule)) {
                $warnings[] = 'ホスト制限にはWebサーバーで確認済みの逆引き名（REMOTE_HOST）が必要です。';
            }
        }
        foreach (['admin_email', 'cc_email', 'bcc_email'] as $key) {
            $list = trim((string) ($settings[$key] ?? ''));
            if ($list === '' && $key !== 'admin_email') {
                continue;
            }
            foreach (explode(',', $list) as $address) {
                if (!filter_var(trim($address), FILTER_VALIDATE_EMAIL)) {
                    $issues[] = "メールアドレス設定を確認してください: {$key}";
                    break;
                }
            }
        }

        $tags = array_merge(array_column($fields, 'name'), ['YYYY', 'YY', 'MM', 'DD', 'HH', 'MI', 'SS', 'KWE', 'AWE', 'IP', 'HOST', 'UA']);
        foreach (['mail_title', 'reply_mail_header_message', 'reply_mail_footer_message', 'thanks_message'] as $key) {
            preg_match_all('/#([^#\s]+)#/u', (string) ($settings[$key] ?? ''), $matches);
            foreach (array_diff($matches[1], $tags) as $tag) {
                $issues[] = "未対応の置換タグ: {$key} #{$tag}#";
            }
        }
        foreach (self::SETTINGS as $key) {
            if (($key === 'description' || str_contains($key, 'message')) && $this->hasMarkup($settings[$key] ?? '')) {
                $this->checkMarkup($settings[$key], $key, $issues, $warnings);
            }
        }
        if (!$fields) {
            $issues[] = '入力項目がありません。';
        }
        $fileFields = array_filter($fields, fn ($f) => $f['form_type'] === 'file');
        if (count($fileFields) > 10) {
            $issues[] = '添付欄の数が1受付につき10件の上限を超えます。';
        }
        $names = [];
        $ids = [];
        $controls = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['form_type'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_-]*$/iD', $name) || isset($names[$name]) || in_array($name, ['token','submit','action','method','elements','receipt_token','remove_files'], true)) {
                $issues[] = "項目 #{$field['id']}: 項目名の形式・重複・予約語を確認してください。";
            }
            $names[$name] = true;
            if (!in_array($type, PublicFields::TYPES, true)) {
                $issues[] = "未対応の項目形式: {$name} ({$type})";
                continue;
            }
            if ($type === 'file') {
                if (($field['file_capacity_limit'] ?? 0) > 10240) {
                    $issues[] = "添付の上限が1ファイル10 MiBを超えます: {$name}";
                }
                if (preg_match('~(?:^|,)\s*(?:\.(?:php\d*|phtml|phar|htm[l]?|svgz?|js|exe|sh|bat|cmd)|text/html|application/(?:javascript|x-httpd-php|x-msdownload)|image/svg\+xml)\s*(?:,|$)~i', (string) ($field['file_type'] ?? ''))) {
                    $issues[] = "実行可能なファイル形式が指定されています: {$name}";
                }
            }
            if (($field['max_length'] ?? 0) > 65536) {
                $issues[] = "文字数設定が入力欄の64 KiB制限を超えます: {$name}";
            }
            if (!in_array($field['restriction'] ?? '', ['', 'katakana','hiragana','num','alphabet_num','alphabet','alphabet_num_mix','num_hyphen','email','tel','tel_hyphen','zip','zip_hyphen'], true)) {
                $issues[] = "未対応の入力制限: {$name}";
            }
            if (!in_array($field['hankaku_zenkaku_automatic_conversion'] ?? '', ['', 'default', 'hankaku', 'zenkaku'], true)) {
                $issues[] = "未対応の全角・半角変換: {$name}";
            }
            foreach (['description_above', 'description_below'] as $key) {
                if ($this->hasMarkup($field[$key] ?? '')) {
                    $this->checkMarkup($field[$key], "{$name}.{$key}", $issues, $warnings);
                }
            }
            $choiceType = $type === 'multi_select' ? 'select' : $type;
            $activeChoices = array_filter($field['choices'] ?? [], fn ($c) =>
                ($c['choice_type'] ?? $choiceType) === $choiceType && (isset($field['form_item_id']) || empty($c['delete_flag'])));
            if (in_array($type, ['multi_select', 'select', 'radio', 'checkbox'], true) && !$activeChoices) {
                $issues[] = "選択肢がありません: {$name}";
            }
            foreach ($activeChoices as $choice) {
                if (!empty($choice['image']) || $this->hasMarkup($choice['label_text'] ?? '')) {
                    $issues[] = "画像・HTML付きの選択肢: {$name}";
                }
            }
            try {
                foreach (app(PublicFields::class)->controls([$field], $sourceVersion) as $key => $control) {
                    if (isset($controls[$key]) || isset($ids[$control['id']])) {
                        $issues[] = "入力欄の名前またはHTML IDが重複しています: {$key}";
                    }
                    $controls[$key] = true;
                    $ids[$control['id']] = true;
                }
            } catch (HttpExceptionInterface) {
                $issues[] = "公開時に利用できない入力欄: {$name}";
            }
        }
        if (!empty($settings['conf_mail_flag']) && !isset($names['email'])) {
            $warnings[] = 'email 項目がないため、旧版と同じく利用者への自動返信を省きます。';
        }
        foreach ($fields as $field) {
            $peer = $field['re_enter_html_id'] ?? '';
            if ($peer && (!isset($ids[$peer]) || $peer === (($field['html_id'] ?? '') ?: $field['name']))) {
                $issues[] = "再入力確認先が不正です: {$field['name']}";
            }
        }
        return array_values(array_unique($issues));
    }

    private function checkMarkup(string $value, string $location, array &$issues, array &$warnings): void
    {
        if (SafeMarkup::hasUnsupported($value) || $this->templateCode($value)) {
            $issues[] = "未対応のHTML・テンプレート: {$location}";
        } else {
            $warnings[] = "HTMLの装飾を公開画面で照合してください: {$location}";
        }
    }

    private function templateCode(string $value): bool
    {
        return str_contains($value, '<?') || str_contains($value, '{{') || str_contains($value, '{!!')
            || preg_match('/@(?:php|include|extends|if|foreach|inject|component)\b/i', $value);
    }

    private function hasMarkup(mixed $value): bool
    {
        return is_string($value) && (strip_tags($value) !== $value || $this->templateCode($value));
    }
}
