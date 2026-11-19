@php
    $attrs = (new \Illuminate\View\ComponentAttributeBag())->merge([
        'id' => $id,
        'name' => $key,
        'class' => $field['html_class'] ?? '',
        'aria-label' => $control['label'],
        'placeholder' => $field['placeholder'] ?? '',
        'required' => $required,
        'minlength' => empty($field['min_length']) ? null : (int)$field['min_length'],
        'maxlength' => empty($field['max_length']) ? null : (int)$field['max_length'],
        'data-match' => empty($field['re_enter_html_id']) ? null : ltrim($field['re_enter_html_id'], '#'),
        'data-realtime' => !empty($field['real_time_validation']),
        'aria-invalid' => $error ? 'true' : null,
        'aria-describedby' => $error ? 'err-'.$id : null,
        'data-pattern' => $control['pattern'] ?: null,
        'data-format-error' => ($field['restriction_error_msg'] ?? '') ?: null,
        'data-match-error' => ($field['re_enter_error_msg'] ?? '') ?: null,
        'data-forbidden' => ($field['forbidden_characters'] ?? '') ?: null,
        'data-width' => $field['hankaku_zenkaku_automatic_conversion'] ?? 'default',
    ]);
@endphp
{{ $attrs }}
