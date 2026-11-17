{{--
    The v2.0 website's frame (ui_v2.website): the name, Pricing, Download, Sign in and one primary action, then the page,
    then Terms and Privacy. The design values from tokens/ (resources/css/v2/site.css); light and dark follow the system.
--}}
@php
    $keep = \App\Support\WebsiteV2::keep(request());
    $here = fn (string $route): bool => request()->routeIs($route);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="{{ config('echoledger.site_indexable') && $keep === [] ? 'index, follow' : 'noindex, nofollow' }}">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta name="description" content="@brand records calls and meetings on your phone and computer and turns them into accurate transcripts, including Urdu and Roman Urdu.">
    <title>@yield('title', \App\Support\Brand::name())</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}">
    @vite('resources/css/v2/site.css')
</head>
<body class="s-body">
<a class="s-skip" href="#main">Skip to the content</a>
<header class="s-header">
    <div class="s-wrap s-header-row">
        <a class="s-brand" href="{{ route('site.home', $keep) }}">@brand</a>
        <nav class="s-nav" aria-label="Site">
            <a href="{{ route('site.pricing', $keep) }}" @if ($here('site.pricing')) aria-current="page" @endif>Pricing</a>
            <a href="{{ route('downloads', $keep) }}" @if ($here('downloads')) aria-current="page" @endif>Download</a>
            <a href="{{ url('/app') }}">Sign in</a>
        </nav>
        <a class="s-btn s-btn-primary s-header-action" href="{{ route('sign-up') }}">Get started</a>
    </div>
</header>
<main id="main" class="s-wrap s-main">
    @yield('content')
</main>
<footer class="s-footer">
    <div class="s-wrap s-footer-row">
        <span>@brand</span>
        @if (\Illuminate\Support\Facades\Route::has('legal.terms'))<a href="{{ route('legal.terms') }}">Terms</a>@endif
        @if (\Illuminate\Support\Facades\Route::has('legal.privacy'))<a href="{{ route('legal.privacy') }}">Privacy</a>@endif
        @if (\Illuminate\Support\Facades\Route::has('account.delete'))<a href="{{ route('account.delete') }}">Delete your account</a>@endif
    </div>
</footer>
</body>
</html>
