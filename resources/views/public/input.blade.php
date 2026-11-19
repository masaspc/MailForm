@extends('public.layout', ['stage' => 'input'])
@section('step', __('入力'))
@section('content')
@php
    $settings = $form->definition['settings'];
    $params = $form->slug === '' ? [] : ['slug' => $form->slug];
    $prefix = $form->slug === '' ? 'public.root.' : 'public.';
    $hasErrors = $problems->isNotEmpty();
@endphp
@if($hasErrors)
<section class="errors" role="alert"><h2>{{ __('入力内容をご確認ください') }}</h2><ul>
@foreach($problems->all() as $problem)<li>{{ $problem }}</li>@endforeach
</ul></section>
@endif
@if($intro = ($settings[($hasErrors ? 'error' : 'input').'_form_header_message'] ?? ''))
<p class="message">{{ \App\Services\SafeMarkup::render($intro) }}</p>
@endif
<form method="post" action="{{ route($prefix.'confirm', $params) }}" enctype="multipart/form-data" data-entry data-messages="{{ json_encode(\App\Services\PublicLocale::browserMessages(), JSON_UNESCAPED_UNICODE) }}" data-warn="{{ (int)($settings['beforeunload_flag'] ?? 0) }}" data-no-enter="{{ (int)($settings['enter_forbidden_flag'] ?? 0) }}">
@csrf
<input type="hidden" name="receipt_token" value="{{ $draft['token'] }}">
@foreach($controls as $group)
@php
    $field = $group->first()['source'];
@endphp
<fieldset data-kind="{{ $field['form_type'] }}" data-kana="{{ $field['restriction'] ?? '' }}">
<legend>{{ $field['title'] }} @if(!empty($field['required']))<small>{{ __('必須') }}</small>@endif</legend>
@if(!empty($field['description_above']))<p class="hint">{{ \App\Services\SafeMarkup::render($field['description_above']) }}</p>@endif
@foreach($group as $control)
@php
    $key = $control['key'];
    $id = $control['id'];
    $type = $control['type'];
    $value = array_key_exists($key, $draft['values']) ? $draft['values'][$key] : ($field['initial_value'] ?? '');
    $required = !empty($field['required']) && empty($draft['files'][$key]);
    $error = $problems->first($key) ?: $problems->first($key.'.*');
@endphp
<div class="control">
@if($group->count() > 1)<label for="{{ $id }}">{{ $control['label'] }}</label>@endif
@if(in_array($type, ['radio', 'checkbox']))
<div class="choices cols-{{ max(1, min(6, (int)($field['columns'] ?? 1))) }}">
@foreach($control['options'] as $option)
<label class="choice"><input id="{{ $id }}-{{ $loop->index }}" type="{{ $type }}" name="{{ $key }}{{ $type === 'checkbox' ? '[]' : '' }}" value="{{ $option }}" @checked(in_array($option, (array)$value, true)) @required($required && $type === 'radio')> {{ $option }}</label>
@endforeach
</div>
@elseif(in_array($type, ['select', 'multi_select']))
<select id="{{ $id }}" name="{{ $key }}{{ $type === 'multi_select' ? '[]' : '' }}" aria-label="{{ $control['label'] }}" @if($type === 'multi_select') multiple @endif @required($required)>
@if($type === 'select')<option value="">{{ __('選択してください') }}</option>@endif
@foreach($control['options'] as $option)
<option value="{{ $option }}" @selected(in_array($option, (array)$value, true))>{{ $option }}</option>
@endforeach
</select>
@elseif($type === 'textarea')
<textarea @include('public.attributes') rows="5">{{ $value }}</textarea>
@elseif($type === 'file')
<input @include('public.attributes') type="file" accept="{{ $field['file_type'] ?? '' }}">
@if(isset($draft['files'][$key]))
<p>{{ __('選択済み') }}　{{ $draft['files'][$key]['name'] }}</p>
<label class="choice"><input type="checkbox" name="remove_files[]" value="{{ $key }}"> {{ __('添付を取り消す') }}</label>
@endif
@if(!empty($field['file_capacity_limit']))<p class="hint">{{ __('上限') }} {{ number_format($field['file_capacity_limit']) }} KB</p>@endif
@else
<input @include('public.attributes') type="{{ $type === 'datetime' ? 'datetime-local' : $type }}" value="{{ $value }}" @if($type === 'number') step="any" @endif>
@endif
@if($field['form_type'] === 'zp_address' && $loop->first)
<button class="secondary lookup" type="button" data-lookup="{{ asset('assets/form-postal') }}">{{ __('郵便番号から住所を検索') }}</button>
<select data-address-choices hidden aria-label="{{ __('住所の候補') }}"></select>
<span class="hint" data-lookup-result aria-live="polite"></span>
@endif
@if($error)<p class="problem" id="err-{{ $id }}">{{ $error }}</p>@endif
</div>
@endforeach
@if(!empty($field['description_below']))<p class="hint">{{ \App\Services\SafeMarkup::render($field['description_below']) }}</p>@endif
</fieldset>
@endforeach
@if($outro = ($settings[($hasErrors ? 'error' : 'input').'_form_footer_message'] ?? ''))
<p class="message">{{ \App\Services\SafeMarkup::render($outro) }}</p>
@endif
<button class="primary" type="submit">{{ __('入力内容を確認する') }}</button>
</form>
@endsection
