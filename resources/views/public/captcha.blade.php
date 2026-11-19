@if(!empty($form->definition['settings']['recaptcha_enabled']))
<input type="hidden" name="g-recaptcha-token" data-sitekey="{{ config('mailform.recaptcha_site_key') }}">
<script defer src="https://www.google.com/recaptcha/api.js?render={{ rawurlencode((string) config('mailform.recaptcha_site_key')) }}"></script>
<script defer src="{{ \App\Services\PublicFormUrl::asset('captcha.js') }}"></script>
<small data-captcha-status data-wait="{{ __('送信を確認しています…') }}" data-error="{{ __('送信を確認できません。もう一度お試しください。') }}" role="status"></small>
@endif
