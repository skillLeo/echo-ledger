{{-- "Get the apps" after the web sign-up (since v1.6): the newest builds, then the console. --}}
<div style="display:grid;gap:1rem">
    <x-filament::section>
        <x-slot name="heading">Download</x-slot>
        @if ($releases === [])
            <p>The apps are on the <a class="underline" href="{{ route('downloads') }}">downloads page</a> as soon as they are published.</p>
        @else
            <ul style="display:grid;gap:.75rem">
                @foreach ($releases as $platform => $release)
                    <li style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
                        <span><strong>{{ $labels[$platform] ?? $platform }}</strong> · version {{ $release->version }}</span>
                        <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-arrow-down-tray" :href="route('downloads.file', ['name' => $release->file_name])">Download</x-filament::button>
                    </li>
                @endforeach
            </ul>
            <p style="margin-top:.75rem;font-size:.875rem;opacity:.75">Every build, with its size and fingerprint: <a class="underline" href="{{ route('downloads') }}">downloads page</a>.</p>
        @endif
    </x-filament::section>
    <x-filament::section>
        <x-slot name="heading">Then</x-slot>
        <ol style="display:grid;gap:.5rem;list-style:decimal;padding-left:1.25rem">
            <li>Open the app and enter your email.</li>
            <li>Type the code we email you. That is it: no password needed.</li>
            <li>On the Free plan, add your own free Groq key in Profile → Transcription key to get transcripts.</li>
        </ol>
        <div style="margin-top:1rem">
            <x-filament::button tag="a" :href="\App\Filament\Pages\Dashboard::getUrl()">Continue to EchoLedger</x-filament::button>
        </div>
    </x-filament::section>
</div>
