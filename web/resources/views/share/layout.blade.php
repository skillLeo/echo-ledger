<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title', 'Shared transcript') · EchoLedger</title>
    <style>
        /* Urdu in Nastaliq, Arabic in Naskh (Noto, OFL: public/fonts); only Arabic-script letters use them. */
        @font-face { font-family: "Noto Nastaliq Urdu"; src: url("{{ asset('fonts/noto-nastaliq-urdu-arabic.woff2') }}") format("woff2"); font-weight: 400 700; font-display: swap; unicode-range: U+0600-06FF, U+0750-077F, U+0870-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC; }
        @font-face { font-family: "Noto Naskh Arabic"; src: url("{{ asset('fonts/noto-naskh-arabic-arabic.woff2') }}") format("woff2"); font-weight: 400 700; font-display: swap; unicode-range: U+0600-06FF, U+0750-077F, U+0870-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC; }
        [lang="ur"] .line { font-family: "Noto Nastaliq Urdu", inherit; line-height: 2.1; }
        [lang="ar"] .line { font-family: "Noto Naskh Arabic", inherit; line-height: 1.8; }
        :root { --text: #111827; --muted: #6b7280; --line: #e5e7eb; --bg: #f8fafc; --card: #ffffff; --me: #6d28d9; --client: #0e7490; --accent: #6366f1; }
        @media (prefers-color-scheme: dark) {
            :root { --text: #f1f3f8; --muted: #9ba3b4; --line: #23283a; --bg: #0a0b10; --card: #13151f; --me: #a78bfa; --client: #22d3ee; --accent: #818cf8; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", "Noto Nastaliq Urdu", "Noto Sans Devanagari", sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 32px 16px 48px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 24px; }
        h1 { font-size: 22px; line-height: 1.3; margin: 0 0 6px; overflow-wrap: anywhere; }
        .muted { color: var(--muted); }
        .facts { color: var(--muted); margin: 0 0 18px; font-size: 14px; }
        .line { margin: 0 0 12px; overflow-wrap: anywhere; }
        .at { color: var(--muted); font-variant-numeric: tabular-nums; margin-inline-end: 10px; font-size: 13px; }
        .who { font-weight: 600; margin-inline-end: 6px; }
        .spk-1 { color: var(--me); } .spk-2 { color: var(--client); }
        audio { width: 100%; margin: 0 0 20px; }
        form { display: grid; gap: 12px; max-width: 360px; }
        input[type=password] { font: inherit; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--line); background: var(--bg); color: var(--text); }
        button { font: inherit; font-weight: 600; padding: 10px 14px; border-radius: 10px; border: 0; background: var(--accent); color: #fff; cursor: pointer; }
        .error { color: #e11d48; margin: 0; }
        footer { margin-top: 18px; font-size: 13px; color: var(--muted); text-align: center; }
    </style>
</head>
<body>
<main>
    @yield('content')
    <footer>Shared with EchoLedger · view only</footer>
</main>
</body>
</html>
