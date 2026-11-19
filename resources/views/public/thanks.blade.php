@extends('public.layout', ['stage' => 'thanks'])
@section('step', __('完了'))
@section('content')
<h2>{{ __('送信が完了しました') }}</h2>
<p class="message">{{ $message ?: __('お問い合わせを受け付けました。') }}</p>
@endsection
