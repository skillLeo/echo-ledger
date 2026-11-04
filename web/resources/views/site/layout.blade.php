<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="{{ config('echoledger.site_indexable') ? 'index, follow' : 'noindex, nofollow' }}">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta name="description" content="EchoLedger records calls and meetings on your phone and computer and turns them into accurate transcripts, including Urdu and Roman Urdu.">
    <title>@yield('title', 'EchoLedger')</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <style>
        :root { --text: #0f172a; --muted: #64748b; --line: #e2e8f0; --bg: #f8fafc; --card: #ffffff; --accent: #4f46e5; --accent-soft: #eef2ff; --on-accent: #ffffff; }
        @media (prefers-color-scheme: dark) {
            :root { --text: #f1f5f9; --muted: #94a3b8; --line: #1e293b; --bg: #0b1020; --card: #111827; --accent: #818cf8; --accent-soft: #1e1b4b; --on-accent: #0b1020; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.6 Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", sans-serif; }
        a { color: var(--accent); }
        header.site, main, footer.site { max-width: 960px; margin: 0 auto; padding: 0 16px; }
        header.site { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding-top: 20px; padding-bottom: 20px; }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 18px; color: var(--text); text-decoration: none; }
        .brand-mark { width: 28px; height: 28px; border-radius: 8px; background: var(--accent); display: grid; place-items: center; color: var(--on-accent); font-weight: 800; }
        nav.site { display: flex; gap: 16px; flex-wrap: wrap; font-size: 15px; }
        nav.site a { color: var(--muted); text-decoration: none; }
        nav.site a:hover, nav.site a:focus-visible { color: var(--text); }
        .button { display: inline-block; padding: 10px 18px; border-radius: 999px; background: var(--accent); color: var(--on-accent); font-weight: 600; text-decoration: none; }
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
    <a class="brand" href="{{ route('site.home') }}"><span class="brand-mark" aria-hidden="true">E</span>EchoLedger</a>
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
