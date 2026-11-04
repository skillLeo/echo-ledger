<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="{{ config('echoledger.site_indexable') ? 'index, follow' : 'noindex, nofollow' }}">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta name="description" content="EchoLedger records calls and meetings on your phone and computer and turns them into accurate transcripts, including Urdu and Roman Urdu.">
    <title>@yield('title', 'EchoLedger')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}">
    <style>
        /* The apps' design system (docs/DESIGN_SYSTEM.md): Radix slate and indigo. */
        :root { --text: #1C2024; --muted: #60646C; --line: #D9D9E0; --bg: #F9F9FB; --card: #FFFFFF; --brand: #3E63DD; --accent: #3A5BC7; --accent-soft: #EDF2FE; --on-accent: #FFFFFF; }
        @media (prefers-color-scheme: dark) {
            :root { --text: #EDEEF0; --muted: #B0B4BA; --line: #363A3F; --bg: #111113; --card: #18191B; --brand: #3E63DD; --accent: #9EB1FF; --accent-soft: #182449; --on-accent: #FFFFFF; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.6 Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", sans-serif; }
        a { color: var(--accent); }
        header.site, main, footer.site { max-width: 960px; margin: 0 auto; padding: 0 16px; }
        header.site { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding-top: 20px; padding-bottom: 20px; }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 18px; color: var(--text); text-decoration: none; }
        nav.site { display: flex; gap: 16px; flex-wrap: wrap; font-size: 15px; }
        nav.site a { color: var(--muted); text-decoration: none; }
        nav.site a:hover, nav.site a:focus-visible { color: var(--text); }
        .button { display: inline-block; padding: 10px 18px; border-radius: 999px; background: var(--brand); color: var(--on-accent); font-weight: 600; text-decoration: none; }
        .button.secondary { background: var(--accent-soft); color: var(--accent); }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 20px; }
        .muted { color: var(--muted); }
        h1 { font-size: clamp(28px, 5vw, 40px); line-height: 1.15; margin: 24px 0 12px; }
        h2 { font-size: 20px; margin: 0 0 8px; }
        .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        code, .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; overflow-wrap: anywhere; }
        footer.site { padding-top: 32px; padding-bottom: 40px; font-size: 14px; color: var(--muted); display: flex; gap: 16px; flex-wrap: wrap; }
        footer.site a { color: var(--muted); }
        @media (prefers-reduced-motion: no-preference) { .button { transition: transform 120ms ease; } .button:active { transform: scale(.96); } }
    </style>
</head>
<body>
<header class="site">
    <a class="brand" href="{{ route('site.home') }}"><img src="{{ asset('favicon.svg') }}" width="28" height="28" alt="">EchoLedger</a>
    <nav class="site" aria-label="Site">
        <a href="{{ route('downloads') }}">Downloads</a>
        <a href="{{ url('/admin') }}">Sign in</a>
    </nav>
</header>
<main>
    @yield('content')
</main>
<footer class="site">
    <span>EchoLedger</span>
    @if (\Illuminate\Support\Facades\Route::has('legal.terms'))<a href="{{ route('legal.terms') }}">Terms</a>@endif
    @if (\Illuminate\Support\Facades\Route::has('legal.privacy'))<a href="{{ route('legal.privacy') }}">Privacy</a>@endif
    @if (\Illuminate\Support\Facades\Route::has('account.delete'))<a href="{{ route('account.delete') }}">Delete your account</a>@endif
</footer>
</body>
</html>
