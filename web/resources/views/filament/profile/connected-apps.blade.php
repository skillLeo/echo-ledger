{{-- Profile → Connected apps (since v1.5): each app with when it connected and when it last read, and Disconnect. --}}
<div class="el-profile-devices" id="connected-apps">
    <style>
        #connected-apps .row { display: flex; align-items: center; gap: .75rem; padding: .6rem 0; border-bottom: 1px solid rgba(127,127,127,.15); }
        #connected-apps .row:last-of-type { border-bottom: 0; }
        #connected-apps .grow { flex: 1; min-width: 0; overflow-wrap: anywhere; }
        #connected-apps .muted { opacity: .7; font-size: .85rem; }
    </style>
    @forelse ($apps as $app)
        <div class="row">
            <div class="grow">
                <div>{{ $app['name'] }}</div>
                <div class="muted">Connected {{ $app['connected'] }} · {{ $app['last_used'] ? 'last used '.$app['last_used'] : 'not used yet' }}</div>
            </div>
            <x-filament::button size="sm" color="gray" wire:click="disconnectApp({{ (int) $app['id'] }})" wire:confirm="Disconnect {{ $app['name'] }}? It can no longer read your recordings.">Disconnect</x-filament::button>
        </div>
    @empty
        <p class="muted">No app is connected. Add EchoLedger in Claude or ChatGPT to ask them about your recordings.</p>
    @endforelse
</div>
