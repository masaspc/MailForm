@php($buttonAttrs = ['form' => $position === 'inside_form' ? null : $skin->formId, 'disabled' => $preview])
{{ $skin->open('buttons') }}
@if($stage === 'input')
{{ $skin->open('confirm_wrap') }}
{{ $skin->button('confirm_button', __('入力内容を確認する'), $buttonAttrs) }}
{{ $skin->close('confirm_wrap') }}
@else
{{ $skin->open('back_wrap') }}
{{ $skin->button('back_button', __('修正する'), $buttonAttrs + ['formaction' => $preview ? null : $form->publicPath('back')]) }}
{{ $skin->close('back_wrap') }}
{{ $skin->open('send_wrap') }}
{{ $skin->button('send_button', __('送信する'), $buttonAttrs) }}
{{ $skin->close('send_wrap') }}
@endif
{{ $skin->close('buttons') }}
