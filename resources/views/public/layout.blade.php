@php
    $themeName = $form->definition['settings']['theme_name'] ?? 'one_column';
    $theme = $form->getRelation('theme');
    $layout = $theme?->layout ?? $themeName;
    $imported = $theme?->import_report !== null;
    $stylesheets = \App\Services\ThemeCatalog::stylesheets($theme);
    $gradation = $imported && $layout === 'gradation';
    $columns = $imported && in_array($layout, ['one_column', 'two_column01', 'two_column02'], true);
    $split = $columns && $layout === 'two_column02';
    $thanksPage = $stage === 'thanks' && $skin->has('thanks_page');
    $pageSlot = $thanksPage ? 'thanks_page' : 'page';
    $themedPage = $skin->has($pageSlot);
    $mainInside = !$thanksPage && ($skin->containsTag('body', 'main') || $skin->containsTag('form_outer', 'main'));
    $legacyBody = !$themedPage && !$skin->has('body');
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
<body class="theme-{{ $layout }}" @if($imported && $stylesheets) data-imported-css @endif @if($themedPage || $mainInside) data-theme-page @endif>
@if($gradation && !$themedPage)<div @if($skin->claimId('wrapper')) id="wrapper" @endif class="formPage">@endif
<header @class(['site-banner', 'header' => $columns]) @if($gradation && $skin->claimId('header')) id="header" @endif>{{ \App\Services\SafeMarkup::themeHeader($theme, config('app.name', 'MailForm')) }}</header>
@if($preview ?? false)
@include('public.preview')
@endif
@if($themedPage)
{{ $skin->open($pageSlot, ['role' => $skin->containsTag($pageSlot, 'main') || $mainInside ? null : 'main']) }}
@elseif($mainInside)
<div>
@else
<main @class(['content' => $columns, 'container' => $imported && $layout === 'gray']) @if($gradation && $skin->claimId('container')) id="container" @endif>
@endif
@if($thanksPage)
<h1 class="mailform-visually-hidden">{{ $form->name }}</h1>
@elseif($themedPage || $skin->has('title'))
@php($nativeHeading = in_array($skin->tag('title'), ['h1','h2','h3','h4','h5','h6'], true))
{{ $skin->open('title', ['role' => $nativeHeading ? null : 'heading', 'aria-level' => $nativeHeading ? null : 1]) }}{{ $form->name }}{{ $skin->close('title') }}
@else
<header @class(['leftColumn' => $split])>
@if($columns)<div class="contentHead">@endif
<h1 @class(['contentHead__ttl' => $columns, 'partsWrap partsTitle01' => $gradation])>{{ $form->name }}</h1>
@if($columns)</div>@endif
</header>
@endif
@if($legacyBody)
@if($split)<div class="rightColumn">@endif
@if($columns)<div class="formWrap">@endif
@elseif(!$thanksPage)
{{ $skin->open('body') }}
@endif
@include('public.steps')
@yield('content')
@if($legacyBody)
@if($columns)</div>@endif
@if($split)</div>@endif
@elseif(!$thanksPage)
{{ $skin->close('body') }}
@endif
@if($themedPage)
{{ $skin->close($pageSlot) }}
@elseif($mainInside)
</div>
@else
</main>
@endif
<footer @class(['site-footer', 'footer' => $columns]) @if($gradation && $skin->claimId('footer')) id="footer" @endif>{{ \App\Services\SafeMarkup::render($theme?->footer ?: config('app.name', 'MailForm'), $theme) }}</footer>
@if($gradation && !$themedPage)</div>@endif
</body>
</html>
