@extends('public.layout', ['stage' => 'input'])
@section('step', __('入力'))
@section('content')
@php
    $settings = $form->definition['settings'];
    $hasErrors = $problems->isNotEmpty();
    $position = $skin->buttonsPosition();
    $formAttrs = $preview ? ['method' => 'dialog'] : [
        'method' => 'post', 'action' => $form->publicPath('confirm'), 'enctype' => 'multipart/form-data',
        'data-entry' => true, 'data-messages' => json_encode(\App\Services\PublicLocale::browserMessages(), JSON_UNESCAPED_UNICODE),
        'data-warn' => (int) ($settings['beforeunload_flag'] ?? 0), 'data-no-enter' => (int) ($settings['enter_forbidden_flag'] ?? 0),
    ];
@endphp
@if($hasErrors)
<section class="errors" role="alert"><h2>{{ __('入力内容をご確認ください') }}</h2><ul>
@foreach($problems->all() as $problem)<li>{{ $problem }}</li>@endforeach
</ul></section>
@endif
{{ $skin->open('form_outer') }}
@if($intro = ($settings[($hasErrors ? 'error' : 'input').'_form_header_message'] ?? ''))
{{ $skin->open('description') }}{{ \App\Services\SafeMarkup::render($intro) }}{{ $skin->close('description') }}
@endif
{{ $skin->open('form', $formAttrs + ['id' => $skin->formId], 'form') }}
@unless($preview)
@csrf
<input type="hidden" name="receipt_token" value="{{ $draft['token'] }}">
@endunless
{{ $skin->open('rows') }}
@foreach($controls as $group)
@if($skin->separateKana() && $group->first()['source']['form_type'] === 'name_and_furigana')
@foreach($group as $part)
@include('public.field', ['group' => collect([$part]), 'kanaPart' => $part['key']])
@endforeach
@else
@include('public.field')
@endif
@endforeach
{{ $skin->close('rows') }}
@if($outro = ($settings[($hasErrors ? 'error' : 'input').'_form_footer_message'] ?? ''))
{{ $skin->open('description') }}{{ \App\Services\SafeMarkup::render($outro) }}{{ $skin->close('description') }}
@endif
@include('public.form-end', ['stage' => 'input'])
@endsection
