{{-- Since v1.5: while Settings closes the console to members and viewers, the dashboard points them to the apps. --}}
@if (auth()->check() && app(\App\Services\Workspaces\ConsoleAccess::class)->level() === \App\Services\Workspaces\ConsoleAccess::CLOSED && request()->routeIs('filament.app.pages.dashboard'))
    <x-filament::section heading="Use the EchoLedger apps" icon="heroicon-o-device-phone-mobile">
        <p style="margin: 0 0 .75rem">This server keeps its console for owners and admins. Your recordings, what is shared with you and your devices are in the EchoLedger apps for your phone and computer.</p>
        <p style="margin: 0">
            <a href="{{ route('downloads') }}" style="text-decoration: underline">Get the apps</a>
            · <a href="{{ \App\Filament\Auth\EditProfile::settingsUrl() }}" style="text-decoration: underline">Settings</a>
        </p>
    </x-filament::section>
@endif
