@extends('public.layout', ['stage' => 'confirm'])
@section('step', __('確認'))
@section('content')
@php
    $settings = $form->definition['settings'];
    $position = $skin->buttonsPosition();
    $themedRows = $skin->hasFormStructure();
    $preview ??= false;
    $formAttrs = $preview ? ['method' => 'dialog'] : ['method' => 'post', 'action' => $form->publicPath('send'),
        'data-send' => (int) ($settings['submit_disabled_flag'] ?? 1)];
@endphp
{{ $skin->open('form_outer') }}
<h2 @class(['mailform-visually-hidden' => $themedRows])>{{ __('入力内容の確認') }}</h2>
@if(!empty($settings['conf_form_header_message']))
{{ $skin->open('description') }}{{ \App\Services\SafeMarkup::render($settings['conf_form_header_message']) }}{{ $skin->close('description') }}
@endif
@unless($themedRows)
@include('public.answers')
@endunless
{{ $skin->open('form', $formAttrs + ['id' => $skin->formId, 'class' => $themedRows ? null : 'actions'], 'form') }}
@unless($preview)
@csrf
@include('public.captcha')
<input type="hidden" name="receipt_token" value="{{ $draft['token'] }}">
@endunless
@if($themedRows)
@include('public.answers')
@endif
@include('public.form-end', ['stage' => 'confirm'])
@endsection
