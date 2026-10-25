{{-- Settings → Security: browsers signed in to the console (B16). --}}
<div class="el-security-browsers">
    <style>
        .el-security-browsers .row { display: flex; align-items: center; gap: .75rem; padding: .6rem 0; border-bottom: 1px solid rgba(127,127,127,.15); }
        .el-security-browsers .row:last-of-type { border-bottom: 0; }
        .el-security-browsers .grow { flex: 1; min-width: 0; }
        .el-security-browsers .muted { opacity: .7; font-size: .85rem; }
        .el-security-browsers .badge { font-size: .75rem; padding: .1rem .5rem; border-radius: 999px; background: rgba(139,92,246,.15); }
    </style>
    @if (! $available)
        <p class="muted">Browser sign-ins are listed when sessions are stored in the database (SESSION_DRIVER=database).</p>
    @else
        @foreach ($browsers as $browser)
            <div class="row">
                <div class="grow">
                    <div>{{ $browser['browser'] }} @if ($browser['current'])<span class="badge">This browser</span>@endif</div>
                    <div class="muted">{{ $browser['ip'] ?? 'Unknown address' }} · active {{ $browser['last_active']->diffForHumans() }}</div>
                </div>
                @unless ($browser['current'])
                    <x-filament::button size="sm" color="gray" wire:click="signOutBrowser(@js($browser['id']))">Sign out</x-filament::button>
                @endunless
            </div>
        @endforeach
        @if (count($browsers) > 1)
            <div style="margin-top:.75rem"><x-filament::button size="sm" color="danger" wire:click="signOutOtherBrowsers">Sign out all other browsers</x-filament::button></div>
        @endif
    @endif
    <p class="muted" style="margin-top:.75rem">{{ $devices === 1 ? '1 paired device' : $devices.' paired devices' }} · <a href="{{ $devicesUrl }}" style="text-decoration:underline">Manage devices</a></p>
</div>
