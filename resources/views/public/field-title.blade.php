@if($requiredMark && $skin->requiredFirst())
{{ $skin->open('required') }}{{ __('必須') }}{{ $skin->close('required') }}
@endif
{{ $label }}
@if($requiredMark && !$skin->requiredFirst())
{{ $skin->open('required') }}{{ __('必須') }}{{ $skin->close('required') }}
@endif
