<?php

namespace App\Services;

use App\Models\Form;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SpamProtection
{
    public static function configured(): bool
    {
        return filled(config('mailform.recaptcha_site_key')) && filled(config('mailform.recaptcha_secret'));
    }

    public function verify(Request $request, Form $form): void
    {
        if (empty($form->definition['settings']['recaptcha_enabled'])) {
            return;
        }
        abort_unless(self::configured(), 503, __('スパム対策の設定を確認してください。'));
        $token = $request->input('g-recaptcha-token');
        abort_unless(is_string($token) && strlen($token) <= 8192 && $token !== '', 422, __('送信の確認をやり直してください。'));
        try {
            $response = Http::asForm()->connectTimeout(3)->timeout(6)->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => config('mailform.recaptcha_secret'), 'response' => $token,
            ]);
        } catch (ConnectionException) {
            abort(503, __('送信の確認に時間がかかっています。再度お試しください。'));
        }
        abort_unless($response->successful(), 503, __('送信の確認サービスが応答しませんでした。'));
        $result = $response->json();
        $expectedHost = parse_url(config('app.url'), PHP_URL_HOST);
        abort_unless(($result['success'] ?? false) === true
            && ($result['action'] ?? '') === 'easy_mail_send'
            && strtolower($result['hostname'] ?? '') === strtolower((string) $expectedHost)
            && is_numeric($result['score'] ?? null)
            && $result['score'] >= max(0, min(1, config('mailform.recaptcha_score'))),
            422, __('送信を確認できませんでした。確認画面に戻って再度お試しください。'));
    }
}
