@if($position === 'inside_form')
@include('public.buttons')
@endif
{{ $skin->close('form', 'form') }}
@if($position === 'outside_form')
@include('public.buttons')
@endif
{{ $skin->close('form_outer') }}
@if($position === 'after_form_outer')
@include('public.buttons')
@endif
