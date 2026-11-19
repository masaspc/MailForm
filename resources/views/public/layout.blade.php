@php
    $themeName = $form->definition['settings']['theme_name'] ?? 'one_column';
    $theme = \App\Models\Theme::where('slug', $themeName)->first();
@endphp
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>@yield('step') | {{ $form->name }}</title>
<link rel="stylesheet" href="{{ asset('assets/form.css') }}">
<link rel="stylesheet" href="{{ asset('assets/themes/stock.css') }}">
<script defer src="{{ asset('assets/form.js') }}"></script>
</head>
<body class="theme-{{ $theme?->layout ?? $themeName }}">
<div class="site-banner">{{ \App\Services\SafeMarkup::render($theme?->header ?: config('app.name', 'MailForm')) }}</div>
<main>
<header><h1>{{ $form->name }}</h1></header>
<ol class="steps" aria-label="受付手順">
@foreach(['input' => '入力', 'confirm' => '確認', 'thanks' => '完了'] as $key => $label)
<li @if($stage === $key) aria-current="step" @endif><span aria-hidden="true">{{ $loop->iteration }}</span>{{ $label }}</li>
@endforeach
</ol>
@yield('content')
</main>
<footer class="site-footer">{{ \App\Services\SafeMarkup::render($theme?->footer ?: config('app.name', 'MailForm')) }}</footer>
</body>
</html>
