<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __("site.titles.$page") }}</title>
    <meta name="description" content="{{ __('site.meta_description') }}">
    <meta name="theme-color" content="#1A2AE6">
    <meta property="og:title" content="{{ __("site.titles.$page") }}">
    <meta property="og:description" content="{{ __('site.meta_description') }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $canonical }}">
    <link rel="canonical" href="{{ $canonical }}">
    <link rel="alternate" hreflang="en" href="{{ $enUrl }}">
    <link rel="alternate" hreflang="ar" href="{{ $arUrl }}">
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <link rel="preload" href="/vendor/fonts/alexandria-{{ $lang === 'ar' ? 'arabic' : 'latin' }}.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/site.css?v={{ @filemtime(public_path('site.css')) ?: '1' }}">
    @stack('head')
</head>
<body class="site site--{{ $page }}">
<a class="skip" href="#content">{{ $lang === 'ar' ? 'انتقل إلى المحتوى' : 'Skip to content' }}</a>
<header class="bar">
    <a class="bar__brand" href="{{ $lang === 'ar' ? $href('') : $href('/') }}">
        <img src="/brand/logo.svg" alt="" width="36" height="36">
        <span>{{ __('site.brand') }}</span>
    </a>
    <nav class="bar__nav" aria-label="{{ __('site.nav.main') }}">
        <a class="bar__optional" href="{{ $lang === 'ar' ? $href('') : $href('/') }}#rule">{{ __('site.nav.how') }}</a>
        <a href="{{ $href('/pricing') }}">{{ __('site.nav.pricing') }}</a>
        <a href="{{ $href('/docs') }}">{{ __('site.nav.docs') }}</a>
        <a href="{{ $switchUrl }}" lang="{{ $lang === 'ar' ? 'en' : 'ar' }}" dir="{{ $lang === 'ar' ? 'ltr' : 'rtl' }}">{{ __('site.nav.language') }}</a>
    </nav>
</header>
<main id="content">
    @yield('main')
</main>
<footer class="foot">
    <p class="foot__line">
        <img src="/brand/logo.svg" alt="" width="28" height="28">
        <span>{{ __('site.footer.line') }}</span>
    </p>
    <nav aria-label="{{ __('site.footer.legal_nav') }}">
        <a href="{{ $href('/pricing') }}">{{ __('site.nav.pricing') }}</a>
        <a href="{{ $href('/docs') }}">{{ __('site.nav.docs') }}</a>
        <a href="{{ $href('/privacy') }}">{{ __('site.footer.privacy') }}</a>
        <a href="{{ $href('/terms') }}">{{ __('site.footer.terms') }}</a>
        <a href="{{ $switchUrl }}" lang="{{ $lang === 'ar' ? 'en' : 'ar' }}">{{ __('site.nav.language') }}</a>
    </nav>
</footer>
</body>
</html>
