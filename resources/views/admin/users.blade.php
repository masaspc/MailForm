@extends('admin.layout')
@section('title','利用者管理')
@section('main')
<header class="head"><div><div class="kicker">ACCESS</div><h1>利用者管理</h1></div></header>
<div class="section-head"><h2>利用者一覧</h2><span class="count">{{ number_format($users->total()) }} 人</span></div>
<div class="table-wrap"><table><thead><tr><th>名前</th><th>ログイン用メールアドレス</th><th>権限</th></tr></thead><tbody>
@foreach($users as $user)<tr><td><strong>{{ $user->name }}</strong></td><td>{{ $user->email }}</td><td><form class="actions" method="post" action="{{ route('admin.users.update',$user) }}">@csrf @method('put')
    <select name="role" aria-label="{{ $user->name }}の権限">@foreach($roles as $key => $label)<option value="{{ $key }}" @selected($user->role === $key)>{{ $label }}</option>@endforeach</select>
    <button class="btn compact">変更</button>
</form></td></tr>@endforeach
</tbody></table></div>
@include('admin.pagination',['page'=>$users])
<section class="panel stack"><h2>利用者を追加</h2>
<p>閲覧者は受信履歴・添付・配信状況を確認できます。編集者はフォームの編集と公開、管理者は利用者の管理も行えます。</p>
<form class="stack" method="post" action="{{ route('admin.users.store') }}">@csrf
    <div class="field-grid"><label>名前<input name="name" required value="{{ old('name') }}"></label>
    <label>メールアドレス<input name="email" type="email" required value="{{ old('email') }}"></label>
    <label>パスワード<input name="password" type="password" minlength="12" required autocomplete="new-password"><small>12文字以上で設定してください。</small></label>
    <label>権限<select name="role">@foreach($roles as $key => $label)<option value="{{ $key }}" @selected(old('role','viewer') === $key)>{{ $label }}</option>@endforeach</select></label></div>
    <div><button class="primary">追加する</button></div>
</form></section>
@endsection
