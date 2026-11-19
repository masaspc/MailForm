@php
    $themeName = $form->definition['settings']['theme_name'] ?? 'one_column';
    $theme = $form->getRelation('theme');
    $layout = $theme?->layout ?? $themeName;
    $imported = $theme?->import_report !== null;
    $stylesheets = \App\Services\ThemeCatalog::stylesheets($theme);
    $gradation = $imported && $layout === 'gradation';
    $columns = $imported && in_array($layout, ['one_column', 'two_column01', 'two_column02'], true);
    $split = $columns && $layout === 'two_column02';
    $controlIds ??= [];
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() === 'cn' ? 'zh-CN' : app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>@yield('step') | {{ $form->name }}</title>
@if($stylesheets)
<link rel="stylesheet" href="{{ \App\Services\PublicFormUrl::asset('themes/base.css') }}">
@else
<link rel="stylesheet" href="{{ \App\Services\PublicFormUrl::asset('form.css') }}">
<link rel="stylesheet" href="{{ \App\Services\PublicFormUrl::asset('themes/stock.css') }}">
@endif
@foreach($stylesheets as $path)
<link rel="stylesheet" href="{{ \App\Services\ThemeAssets::url($theme->slug, $path) }}">
@endforeach
<script defer src="{{ \App\Services\PublicFormUrl::asset('form.js') }}"></script>
</head>
<body class="theme-{{ $layout }}" @if($imported && $stylesheets) data-imported-css @endif>
@if($gradation)<div @if(!in_array('wrapper', $controlIds, true)) id="wrapper" @endif class="formPage">@endif
<header @class(['site-banner', 'header' => $columns]) @if($gradation && !in_array('header', $controlIds, true)) id="header" @endif>{{ \App\Services\SafeMarkup::themeHeader($theme, config('app.name', 'MailForm')) }}</header>
<main @class(['content' => $columns, 'container' => $imported && $layout === 'gray']) @if($gradation && !in_array('container', $controlIds, true)) id="container" @endif>
<header @class(['leftColumn' => $split])>
@if($columns)<div class="contentHead">@endif
<h1 @class(['contentHead__ttl' => $columns, 'partsWrap partsTitle01' => $gradation])>{{ $form->name }}</h1>
@if($columns)</div>@endif
</header>
@if($split)<div class="rightColumn">@endif
@if($columns)<div class="formWrap">@endif
<ol class="steps" aria-label="{{ __('受付手順') }}">
@foreach(['input' => '入力', 'confirm' => '確認', 'thanks' => '完了'] as $key => $label)
<li @if($stage === $key) aria-current="step" @endif><span aria-hidden="true">{{ $loop->iteration }}</span>{{ __($label) }}</li>
@endforeach
</ol>
@yield('content')
@if($columns)</div>@endif
@if($split)</div>@endif
</main>
<footer @class(['site-footer', 'footer' => $columns]) @if($gradation && !in_array('footer', $controlIds, true)) id="footer" @endif>{{ \App\Services\SafeMarkup::render($theme?->footer ?: config('app.name', 'MailForm'), $theme) }}</footer>
@if($gradation)</div>@endif
</body>
</html>
