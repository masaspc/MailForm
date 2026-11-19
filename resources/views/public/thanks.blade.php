@extends('public.layout', ['stage' => 'thanks'])
@section('step', '完了')
@section('content')
<h2>送信が完了しました</h2>
<p class="message">{{ $message ?: 'お問い合わせを受け付けました。' }}</p>
@endsection
