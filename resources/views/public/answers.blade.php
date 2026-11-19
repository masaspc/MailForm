@if($themedRows)
{{ $skin->open('confirm_rows') }}
@else
<dl class="answers">
@endif
@foreach($form->definition['fields'] as $field)
@php
    $title = \App\Services\PublicLocale::label($field);
    $answer = $answers[$field['name']];
    $rows = $skin->separateKana() && $field['form_type'] === 'name_and_furigana'
        ? [[$title, $answer['name']], [__(($field['restriction'] ?? '') === 'hiragana' ? 'ふりがな' : 'フリガナ'), $answer['kana']]]
        : [[$title, $answer]];
@endphp
@foreach($rows as [$label, $answer])
@if($themedRows){{ $skin->open('confirm_row') }}@endif
{{ $skin->open('confirm_label', ['scope' => $skin->tag('confirm_label') === 'th' ? 'row' : null]) }}
@include('public.field-title', ['requiredMark' => $themedRows && $skin->has('required') && !empty($field['required'])])
{{ $skin->close('confirm_label') }}
{{ $skin->open('confirm_field', ['data-answer' => true]) }}
{{ $skin->open('confirm_value') }}{{ $preview && !array_filter((array) $answer, fn ($part) => $part !== '' && $part !== null) ? '—' : \App\Services\PublicFields::display($answer) }}{{ $skin->close('confirm_value') }}
{{ $skin->close('confirm_field') }}
@if($themedRows){{ $skin->close('confirm_row') }}@endif
@endforeach
@endforeach
@if($themedRows)
{{ $skin->close('confirm_rows') }}
@else
</dl>
@endif
@if(!empty($settings['conf_form_footer_message']))
{{ $skin->open('description') }}{{ \App\Services\SafeMarkup::render($settings['conf_form_footer_message']) }}{{ $skin->close('description') }}
@endif
