{{--
    Settings › Devices: each phone and computer signed in (open one to rename it, sign it out, sign it in again or
    remove it from the list), Sign in a device, and the connected apps.
--}}
<x-el.section>
    @foreach ($devices as $device)
        <button type="button" id="setting-devices.item.{{ $device['id'] }}" data-setting="devices.item.{{ $device['id'] }}" class="el-set-row has-icon"
            wire:click="mountAction('device', { device: '{{ $device['id'] }}' })">
            @svg($device['computer'] ? 'heroicon-o-computer-desktop' : 'heroicon-o-device-phone-mobile', ['class' => 'el-set-icon', 'aria-hidden' => 'true'])
            <span class="el-set-text"><span class="el-set-title">{{ $device['name'] }}</span></span>
            <span class="el-set-value">{{ $device['value'] }}</span>
        </button>
    @endforeach
    <x-el.row setting="devices.pair" title="Sign in a device" click="mountAction('signInDevice')" />
</x-el.section>
@if ($devices === [])
    <p class="el-set-foot">No phone or computer is signed in yet.</p>
@endif

<x-el.section title="Connected apps">
    @if ($connectorLocked)
        <x-el.row setting="devices.connected" title="Claude and ChatGPT" :href="$connectorUrl" :tag="$connectorTag" />
    @elseif ($apps === [])
        <x-el.row setting="devices.connected" title="Claude and ChatGPT" value="Not connected" />
    @else
        @foreach ($apps as $app)
            <div class="el-set-row" @if ($loop->first) id="setting-devices.connected" tabindex="-1" data-setting="devices.connected" @endif>
                <span class="el-set-text"><span class="el-set-title">{{ $app['name'] }}</span><span class="el-set-sub">{{ $app['value'] }}</span></span>
                <button type="button" class="el-btn el-btn-text is-danger" wire:click="mountAction('disconnectApp', { app: {{ $app['id'] }} })">Disconnect</button>
            </div>
        @endforeach
    @endif
</x-el.section>
<p class="el-set-foot">Apps you allowed to read your recordings, such as Claude or ChatGPT. They can only read.</p>
