@php
    $first = $group->first();
    $field = $first['source'];
    $compound = $group->count() > 1;
    $readingRow = ($kanaPart ?? null) === 'name_and_furigana';
    $id = $skin->controlId($first['key']);
    $choice = in_array($first['type'], ['radio', 'checkbox'], true);
    $for = $choice && $first['options'] ? $skin->optionId($first['key'], 0) : $id;
    $rowAttrs = ['data-kind' => $field['form_type'], 'data-kana' => $field['restriction'] ?? '', 'disabled' => $preview && $skin->tag('row') === 'fieldset'];
    if (($compound || ($kanaPart ?? null) === 'your_name') && ($groupId = $skin->groupId($field['name']))) {
        $rowAttrs['id'] = $groupId;
    }
    $hintIds = [];
    foreach (['above', 'below'] as $side) {
        if (!$readingRow && !empty($field['description_'.$side])) {
            $hintIds[$side] = $skin->targetId($side, 'hint-'.$id.'-'.$side);
        }
    }
@endphp
{{ $skin->open('row', $rowAttrs) }}
{{ $skin->open('label', ['for' => $skin->tag('label') === 'label' ? $for : null, 'scope' => $skin->tag('label') === 'th' ? 'row' : null]) }}
@include('public.field-title', ['label' => $readingRow ? $first['label'] : $field['title'], 'requiredMark' => !empty($field['required'])])
{{ $skin->close('label') }}
{{ $skin->open('field') }}
@isset($hintIds['above'])
{{ $skin->open('above', ['id' => $hintIds['above']]) }}{{ \App\Services\SafeMarkup::render($field['description_above']) }}{{ $skin->close('above') }}
@endisset
@foreach($field['form_type'] === 'zp_address' ? $skin->orderAddress($group) : $group as $control)
@include('public.control')
@endforeach
@if($field['form_type'] === 'zp_address')
<button class="secondary lookup" type="button" data-lookup="{{ \App\Services\PublicFormUrl::asset('form-postal') }}" @disabled($preview)>{{ __('郵便番号から住所を検索') }}</button>
<select data-address-choices hidden aria-label="{{ __('住所の候補') }}" @disabled($preview)></select>
<span class="hint" data-lookup-result aria-live="polite"></span>
@endif
@isset($hintIds['below'])
{{ $skin->open('below', ['id' => $hintIds['below']]) }}{{ \App\Services\SafeMarkup::render($field['description_below']) }}{{ $skin->close('below') }}
@endisset
{{ $skin->close('field') }}
{{ $skin->close('row') }}
