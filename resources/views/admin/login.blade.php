@extends('admin.layout')
@section('title','管理画面にログイン')
@section('main')
<div class="login">
    <div class="kicker">MAILFORM ADMIN</div>
    <h1>管理画面にログイン</h1>
    <form method="post" action="{{ route('admin.login') }}" class="panel stack">
        @csrf
        <label>メールアドレス<input type="email" name="email" autocomplete="username" value="{{ old('email') }}" required autofocus></label>
        <label>パスワード<input type="password" name="password" autocomplete="current-password" required></label>
        <button class="primary" type="submit">ログイン</button>
    </form>
</div>
@endsection
