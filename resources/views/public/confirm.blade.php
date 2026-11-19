@extends('public.layout', ['stage' => 'confirm'])
@section('step', __('確認'))
@section('content')
@php
    $settings = $form->definition['settings'];
    $params = $form->slug === '' ? [] : ['slug' => $form->slug];
    $prefix = $form->slug === '' ? 'public.root.' : 'public.';
@endphp
<h2>{{ __('入力内容の確認') }}</h2>
@if(!empty($settings['conf_form_header_message']))<p class="message">{{ \App\Services\SafeMarkup::render($settings['conf_form_header_message']) }}</p>@endif
<dl class="answers">
@foreach($form->definition['fields'] as $field)
<dt>{{ \App\Services\PublicLocale::label($field) }}</dt>
<dd>{{ \App\Services\PublicFields::display($answers[$field['name']]) }}</dd>
@endforeach
</dl>
@if(!empty($settings['conf_form_footer_message']))<p class="message">{{ \App\Services\SafeMarkup::render($settings['conf_form_footer_message']) }}</p>@endif
<form method="post" action="{{ route($prefix.'send', $params) }}" class="actions" data-send="{{ (int)($settings['submit_disabled_flag'] ?? 1) }}">
@csrf
@include('public.captcha')
<input type="hidden" name="receipt_token" value="{{ $draft['token'] }}">
<button type="submit" formaction="{{ route($prefix.'back', $params) }}" class="secondary">{{ __('修正する') }}</button>
<button type="submit" class="primary">{{ __('送信する') }}</button>
</form>
@endsection
