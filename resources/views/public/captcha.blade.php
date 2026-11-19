@if(!empty($form->definition['settings']['recaptcha_enabled']))
<input type="hidden" name="g-recaptcha-token" data-sitekey="{{ config('mailform.recaptcha_site_key') }}">
<script defer src="https://www.google.com/recaptcha/api.js?render={{ rawurlencode((string) config('mailform.recaptcha_site_key')) }}"></script>
<script defer src="{{ asset('assets/captcha.js') }}"></script>
<small data-captcha-status role="status"></small>
@endif
