{{-- Profile → Devices (since v1.5): the account's phones and computers in every workspace, and its console browsers. --}}
<div class="el-profile-devices" id="devices">
    <style>
        .el-profile-devices .row { display: flex; align-items: center; gap: .75rem; padding: .6rem 0; border-bottom: 1px solid rgba(127,127,127,.15); }
        .el-profile-devices .row:last-of-type { border-bottom: 0; }
        .el-profile-devices .grow { flex: 1; min-width: 0; overflow-wrap: anywhere; }
        .el-profile-devices .muted { opacity: .7; font-size: .85rem; }
        .el-profile-devices h4 { font-weight: 600; margin: .25rem 0; }
        .el-profile-devices .badge { font-size: .75rem; padding: .1rem .5rem; border-radius: 999px; background: rgba(62,99,221,.15); }
    </style>
    <h4>Phones and computers</h4>
    @forelse ($devices as $device)
        <div class="row">
            <div class="grow">
                <div>{{ $device['name'] }} <span class="muted">· {{ $device['platform'] }}</span></div>
                <div class="muted">{{ $device['workspace'] }} · {{ $device['last_seen'] ? 'last seen '.$device['last_seen'] : 'never seen' }}@if ($device['country']) · {{ $device['country'] }}@endif</div>
            </div>
            <x-filament::button size="sm" color="gray" wire:click="revokeDevice(@js($device['id']))" wire:confirm="Revoke {{ $device['name'] }}? It loses access at once; its recordings stay.">Revoke</x-filament::button>
        </div>
    @empty
        <p class="muted">No phone or computer is signed in. Use "Sign in a device" below, or sign in from the app.</p>
    @endforelse

    <h4 style="margin-top:1rem">Browsers signed in to the console</h4>
    @if (! $browsersAvailable)
        <p class="muted">Browsers are listed when sessions are stored in the database (SESSION_DRIVER=database).</p>
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
    @endif
</div>
