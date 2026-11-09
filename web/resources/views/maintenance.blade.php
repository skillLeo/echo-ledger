<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Back soon - EchoLedger</title>
    <style>
        :root { --bg: #f9f9fb; --text: #18191b; --muted: #60646c; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111113; --text: #edeef0; --muted: #b0b4ba; } }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--text); font-family: Inter, system-ui, sans-serif; padding: 16px; }
        main { max-width: 32rem; text-align: center; }
        h1 { font-size: 1.4rem; margin: 0 0 .75rem; }
        p { color: var(--muted); line-height: 1.5; margin: 0 0 .5rem; }
    </style>
</head>
<body>
<main>
    <img src="{{ asset('mark.svg') }}" alt="" width="40" height="40">
    <h1>EchoLedger will be back soon</h1>
    <p dir="auto">{{ $message }}</p>
    @if ($until)
        <p>Expected back {{ \Illuminate\Support\Carbon::parse($until)->setTimezone(app(\App\Services\SettingsRepository::class)->displayTimezone())->format('M j, H:i') }}.</p>
    @endif
</main>
</body>
</html>
