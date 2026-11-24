{{-- W-INC-1124: the admin dashboard's banner while a PHP of this server lacks what it needs (App\Services\Health\ServerHealth). --}}
<div data-server-health role="alert">
    <x-filament::callout color="danger" icon="heroicon-o-exclamation-triangle"
        heading="The server's PHP is missing something it needs"
        description="Uploads and the queue can fail until it is fixed. Recordings that fail meanwhile keep their parts and are finished once it is. Turn the extensions on for both the web PHP and the CLI PHP (web/DEPLOY.md, Requirements), then run php artisan echoledger:health.">
        <x-slot name="footer">
            <ul style="margin:0;padding-inline-start:1.1rem;list-style:disc;font-size:.875rem">
                @foreach ($problems as $problem)
                    <li><strong>{{ $problem['where'] }}</strong> (PHP {{ $problem['php'] }}@if ($problem['checked_at'] !== null), checked {{ $problem['checked_at'] }}@endif): {{ $problem['summary'] }}</li>
                @endforeach
            </ul>
        </x-slot>
    </x-filament::callout>
</div>
