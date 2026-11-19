<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · MailForm</title>
    <link rel="stylesheet" href="{{ asset('assets/admin.css') }}">
    <script src="{{ asset('assets/admin.js') }}" defer></script>
</head>
<body @guest class="guest" @endguest>
<a class="skip" href="#main">本文へ移動</a>
<header class="topbar">
    <a class="brand" href="{{ route('admin.forms') }}"><span class="mark" aria-hidden="true"></span>MailForm</a>
    @auth
    <button class="nav-toggle" type="button" aria-label="管理メニューの切替" aria-expanded="true" aria-controls="admin-nav">☰</button>
    <div class="identity"><small>{{ auth()->user()->email }}</small><form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit" class="logout">ログアウト</button></form></div>
    @endauth
</header>
<div class="shell">
    <aside class="sidebar" id="admin-nav">
        @auth
        <nav class="nav" aria-label="管理メニュー">
            <span class="nav-caption">フォーム</span>
            <a @class(['current' => request()->routeIs('admin.forms','admin.edit','admin.create')]) href="{{ route('admin.forms') }}"><b aria-hidden="true">▤</b>フォーム一覧</a>
            @if(in_array(auth()->user()->role,['admin','editor']))
            <a href="{{ route('admin.create') }}"><b aria-hidden="true">＋</b>フォーム作成</a>
            <a href="{{ route('admin.fields') }}"><b aria-hidden="true">≡</b>項目一覧・登録</a>
            @endif
            <span class="nav-caption">受信・配信</span>
            <a @class(['current' => request()->routeIs('admin.history','admin.submission')]) href="{{ route('admin.history') }}"><b aria-hidden="true">▣</b>受信履歴</a>
            <a @class(['current' => request()->routeIs('admin.deliveries')]) href="{{ route('admin.deliveries') }}"><b aria-hidden="true">↗</b>配信状況</a>
            @if(auth()->user()->role === 'admin')
            <span class="nav-caption">設定</span>
            <a @class(['current' => request()->routeIs('admin.themes*')]) href="{{ url('admin/themes') }}"><b aria-hidden="true">◧</b>テーマ管理</a>
            <a @class(['current' => request()->routeIs('admin.users')]) href="{{ route('admin.users') }}"><b aria-hidden="true">⚙</b>利用者管理</a>
            @endif
        </nav>
        @endauth
    </aside>
    <main id="main" class="main">
        @if(session('notice'))<div class="notice" role="status">{{ session('notice') }}</div>@endif
        @if($errors->any())
            <div class="alert" role="alert"><strong>入力内容を確認してください</strong><ul>
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul></div>
        @endif
        @yield('main')
    </main>
</div>
</body></html>
