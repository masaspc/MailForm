@php
    $key = $control['key'];
    $id = $skin->controlId($key);
    $type = $control['type'];
    $part = $control['address_part'] ?? null;
    $slot = $skin->controlSlot($control);
    $field = $control['source'];
    $value = array_key_exists($key, $draft['values']) ? $draft['values'][$key] : ($field['initial_value'] ?? '');
    $required = !empty($field['required']) && empty($draft['files'][$key]);
    $error = $problems->first($key) ?: $problems->first($key.'.*');
    $errorId = $error ? $skin->errorId($key) : null;
    $describedBy = implode(' ', array_filter([...array_values($hintIds), $errorId]));
    $attrs = [
        'id' => $id, 'name' => $key, 'class' => $field['html_class'] ?? '',
        'aria-label' => ($kanaPart ?? null) === 'your_name' ? $field['title'] : $control['label'],
        'required' => $required, 'disabled' => $preview, 'aria-invalid' => $error ? 'true' : null,
        'aria-describedby' => $describedBy !== '' ? $describedBy : null, 'data-address-part' => $part,
        'data-kana-for' => $field['form_type'] === 'name_and_furigana' && $key === 'your_name' ? $skin->controlId('name_and_furigana') : null,
    ];
    $wrapped = !$skin->tag('field') && !$skin->has($slot);
@endphp
@if($wrapped)<div class="control">@endif
@if($compound)
<label for="{{ $id }}" @class(['mailform-visually-hidden' => $slot !== $type && $part !== 'zip'])>{{ $control['label'] }}</label>
@endif
@if(in_array($type, ['radio', 'checkbox'], true))
@include('public.choices')
@elseif(in_array($type, ['select', 'multi_select'], true))
@php
    $attrs['name'] = $type === 'multi_select' ? $key.'[]' : $key;
    $attrs['multiple'] = $type === 'multi_select';
@endphp
{{ $skin->open($slot, $attrs, 'select') }}
@if($type === 'select')<option value="">{{ __('選択してください') }}</option>@endif
@foreach($control['options'] as $option)
<option value="{{ $option }}" @selected(in_array($option, (array) $value, true))>{{ $option }}</option>
@endforeach
{{ $skin->close($slot, 'select') }}
@else
@php
    $attrs += [
        'placeholder' => $field['placeholder'] ?? '',
        'minlength' => empty($field['min_length']) ? null : (int) $field['min_length'],
        'maxlength' => empty($field['max_length']) ? null : (int) $field['max_length'],
        'data-match' => $skin->matchId($field['re_enter_html_id'] ?? ''), 'data-realtime' => !empty($field['real_time_validation']),
        'data-pattern' => $control['pattern'] ?: null, 'data-format-error' => ($field['restriction_error_msg'] ?? '') ?: null,
        'data-match-error' => ($field['re_enter_error_msg'] ?? '') ?: null, 'data-forbidden' => ($field['forbidden_characters'] ?? '') ?: null,
        'data-width' => $field['hankaku_zenkaku_automatic_conversion'] ?? 'default',
    ];
@endphp
@if($type === 'textarea')
{{ $skin->open($slot, $attrs + ['rows' => 5], 'textarea') }}{{ $value }}{{ $skin->close($slot, 'textarea') }}
@elseif($type === 'file')
{{ $skin->open($slot, $attrs + ['type' => 'file', 'accept' => $field['file_type'] ?? ''], 'input') }}{{ $skin->close($slot, 'input') }}
@if(isset($draft['files'][$key]))
<p>{{ __('選択済み') }}　{{ $draft['files'][$key]['name'] }}</p>
<label class="choice"><input type="checkbox" name="remove_files[]" value="{{ $key }}" @disabled($preview)> {{ __('添付を取り消す') }}</label>
@endif
@if(!empty($field['file_capacity_limit']))<p class="hint">{{ __('上限') }} {{ number_format($field['file_capacity_limit']) }} KB</p>@endif
@else
{{ $skin->open($slot, $attrs + ['type' => $type === 'datetime' ? 'datetime-local' : $type, 'value' => $value, 'step' => $type === 'number' ? 'any' : null], 'input') }}{{ $skin->close($slot, 'input') }}
@endif
@endif
@if($error)
{{ $skin->open('error', ['id' => $errorId]) }}{{ $error }}{{ $skin->close('error') }}
@endif
@if($wrapped)</div>@endif
