@php
    $choicesSlot = $skin->choiceSlot($type, 'choices');
    $choiceSlot = $skin->choiceSlot($type, 'choice');
    $labelSlot = $skin->choiceSlot($type, 'choice_label');
    $textSlot = $skin->choiceSlot($type, 'choice_text');
    $nativeLabel = $skin->tag($labelSlot) === 'label';
    $groupAttrs = ['id' => $id, 'aria-label' => $control['label'], 'role' => $type === 'radio' ? 'radiogroup' : 'group',
        'aria-required' => $required && $type === 'radio' ? 'true' : null, 'aria-describedby' => $attrs['aria-describedby']];
    if (!$skin->has($choicesSlot)) {
        $groupAttrs['class'] = 'cols-'.max(1, min(6, (int) ($field['columns'] ?? 1)));
    }
    $attrs['name'] = $key.($type === 'checkbox' ? '[]' : '');
    $attrs['required'] = $required && $type === 'radio';
@endphp
{{ $skin->open($choicesSlot, $groupAttrs) }}
@foreach($control['options'] as $index => $option)
@php($attrs['id'] = $skin->optionId($key, $index))
{{ $skin->open($choiceSlot) }}
{{ $skin->open($labelSlot, ['for' => $nativeLabel ? $attrs['id'] : null]) }}
@php($attrs['aria-label'] = $nativeLabel ? null : $option)
{{ $skin->open($slot, $attrs + ['type' => $type, 'value' => $option, 'checked' => in_array($option, (array) $value, true)], 'input') }}{{ $skin->close($slot, 'input') }}
@unless($nativeLabel)<label for="{{ $attrs['id'] }}">@endunless
{{ $skin->open($textSlot) }}{{ $option }}{{ $skin->close($textSlot) }}
@unless($nativeLabel)</label>@endunless
{{ $skin->close($labelSlot) }}
{{ $skin->close($choiceSlot) }}
@endforeach
{{ $skin->close($choicesSlot) }}
