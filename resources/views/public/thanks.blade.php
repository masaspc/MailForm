@extends('public.layout', ['stage' => 'thanks'])
@section('step', __('完了'))
@section('content')
<h2 @class(['mailform-visually-hidden' => $skin->has('thanks_message')])>{{ __('送信が完了しました') }}</h2>
{{ $skin->open('thanks_message', ['data-answer' => true]) }}{{ $message ?: __('お問い合わせを受け付けました。') }}{{ $skin->close('thanks_message') }}
@endsection
