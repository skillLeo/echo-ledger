{{-- "Get the apps" after the web sign-up (since v1.6): the newest builds, then the console. --}}
<div style="display:grid;gap:1rem">
    <x-filament::section>
        <x-slot name="heading">{{ __('Download') }}</x-slot>
        @if ($releases === [])
            <p>{!! __('The apps are on the :link as soon as they are published.', ['link' => '<a class="underline" href="'.e(route('downloads')).'">'.e(__('downloads page')).'</a>']) !!}</p>
        @else
            <ul style="display:grid;gap:.75rem">
                @foreach ($releases as $platform => $release)
                    <li style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
                        <span><strong>{{ __($labels[$platform] ?? $platform) }}</strong> · {{ __('version :version', ['version' => $release->version]) }}</span>
                        <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-o-arrow-down-tray" :href="route('downloads.file', ['name' => $release->file_name])">{{ __('Download') }}</x-filament::button>
                    </li>
                @endforeach
            </ul>
            <p style="margin-top:.75rem;font-size:.875rem;opacity:.75">{!! __('Every build, with its size and fingerprint: :link.', ['link' => '<a class="underline" href="'.e(route('downloads')).'">'.e(__('downloads page')).'</a>']) !!}</p>
        @endif
    </x-filament::section>
    <x-filament::section>
        <x-slot name="heading">{{ __('Then') }}</x-slot>
        <ol style="display:grid;gap:.5rem;list-style:decimal;padding-left:1.25rem">
            <li>{{ __('Open the app and enter your email.') }}</li>
            <li>{{ __('Type the code we email you. That is it: no password needed.') }}</li>
            <li>{{ __('On the Free plan, add your own free Groq key in Account → Transcription key to get transcripts.') }}</li>
        </ol>
        <div style="margin-top:1rem">
            <x-filament::button tag="a" :href="\App\Filament\Pages\Dashboard::getUrl()">{{ __('Continue to :brand', ['brand' => \App\Support\Brand::name()]) }}</x-filament::button>
        </div>
    </x-filament::section>
</div>
