<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\Submission;
use Illuminate\Http\Request;

class PublicMessage
{
    /** Use one timestamp for every template in this receipt. Replacements are not expanded again. */
    public function tags(array $fields, array $answers, Request $request): array
    {
        $at = now();
        $tags = [
            '#KWE#' => mb_substr('日月火水木金土', $at->dayOfWeek, 1),
            '#AWE#' => $at->format('D'),
            '#IP#' => $request->ip() ?? '',
            '#HOST#' => mb_scrub(substr((string) $request->server('REMOTE_HOST', ''), 0, 255), 'UTF-8'),
            '#UA#' => mb_scrub(substr((string) $request->userAgent(), 0, 1024), 'UTF-8'),
        ];
        foreach (['YYYY' => 'Y', 'YY' => 'y', 'MM' => 'm', 'DD' => 'd', 'HH' => 'H', 'MI' => 'i', 'SS' => 's'] as $tag => $format) {
            $tags['#'.$tag.'#'] = $at->format($format);
        }
        foreach ($fields as $field) {
            $key = $field['name'];
            $value = $answers[$key] ?? '';
            if ($field['form_type'] === 'name_and_furigana') {
                $value = $value['name'];
            }
            $tags['#'.$key.'#'] = is_array($value) ? implode(' ', $value) : (string) $value;
        }
        return $tags;
    }

    public function queue(Submission $submission, array $definition, array $answers, array $tags, array $attachments): void
    {
        $s = $definition['settings'];
        $subject = preg_replace('/[\r\n]+/u', ' ', strtr($s['mail_title'] ?? '', $tags));
        $header = e(strtr($s['reply_mail_header_message'] ?? '', $tags));
        $footer = e(strtr($s['reply_mail_footer_message'] ?? '', $tags));
        $admin = $this->emails($s['admin_email'] ?? '');
        $user = (string) ($answers['email'] ?? '');
        $rows = [];
        foreach ($definition['fields'] as $field) {
            $key = $field['name'];
            if ($key === 'conf_email' || $field['form_type'] === 'file') {
                continue;
            }
            $value = $answers[$key];
            if (in_array($field['form_type'], ['radio', 'checkbox', 'multi_select'], true) && ($value === '' || $value === [])) {
                continue;
            }
            $title = e($field['title'] ?? $key);
            if ($field['form_type'] === 'name_and_furigana') {
                $rows[] = $title.':'.$value['name'];
                $rows[] = (($field['restriction'] ?? '') === 'hiragana' ? 'ふりがな' : 'フリガナ').':'.$value['kana'];
            } elseif ($field['form_type'] === 'zp_address') {
                $rows[] = $title.":\n".$value;
            } else {
                $rows[] = $title.':'.(is_array($value) ? implode(',', $value) : $value);
            }
        }

        foreach (['admin', 'reply'] as $kind) {
            $isAdmin = $kind === 'admin';
            if (!$isAdmin && (empty($s['conf_mail_flag']) || $user === '')) {
                continue;
            }
            $include = !empty($s[$isAdmin ? 'include_submissions_admin_email' : 'include_submissions']);
            $body = $header."\n".($include ? "\n".implode("\n", $rows)."\n" : '')."\n".$footer."\n";
            Delivery::create(['submission_id' => $submission->id, 'kind' => $kind, 'status' => 'pending', 'payload' => [
                'to' => $isAdmin ? $admin : [$user],
                'cc' => $isAdmin ? $this->emails($s['cc_email'] ?? '') : [],
                'bcc' => $isAdmin ? $this->emails($s['bcc_email'] ?? '') : [],
                'subject' => $subject, 'body' => $body,
                'reply_to' => $isAdmin ? ($user ?: null) : ($admin[0] ?? null),
                'attachments' => $isAdmin ? $attachments : [],
            ]]);
        }
    }

    private function emails(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
