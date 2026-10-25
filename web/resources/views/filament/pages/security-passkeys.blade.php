{{-- Settings → Security: passkeys (B16). --}}
@include('filament.passkey-script')
<div class="el-security-passkeys" x-data="{
        name: '',
        busy: false,
        error: null,
        supported: window.elPasskeys.supported(),
        async add() {
            this.busy = true; this.error = null;
            try {
                const options = await $wire.passkeyOptions();
                const credential = await window.elPasskeys.create(options);
                await $wire.savePasskey(credential, this.name);
                this.name = '';
            } catch (e) {
                this.error = e && e.name === 'NotAllowedError' ? 'Adding the passkey was cancelled.' : 'That passkey could not be added.';
            } finally { this.busy = false; }
        },
    }">
    <style>
        .el-security-passkeys .row { display: flex; align-items: center; gap: .75rem; padding: .6rem 0; border-bottom: 1px solid rgba(127,127,127,.15); }
        .el-security-passkeys .grow { flex: 1; min-width: 0; }
        .el-security-passkeys .muted { opacity: .7; font-size: .85rem; }
        .el-security-passkeys input { border: 1px solid rgba(127,127,127,.35); border-radius: .5rem; padding: .35rem .6rem; background: transparent; }
    </style>
    @forelse ($passkeys as $passkey)
        <div class="row">
            <div class="grow">
                <div>{{ $passkey->name }}</div>
                <div class="muted">Added {{ $passkey->created_at->diffForHumans() }} · {{ $passkey->last_used_at ? 'last used '.$passkey->last_used_at->diffForHumans() : 'not used yet' }}</div>
            </div>
            <x-filament::button size="sm" color="danger" outlined wire:click="removePasskey(@js($passkey->id))" wire:confirm="Remove this passkey? It will no longer sign you in.">Remove</x-filament::button>
        </div>
    @empty
        <p class="muted">No passkeys yet.</p>
    @endforelse
    @if (count($passkeys) < $max)
        <div style="display:flex;gap:.5rem;align-items:center;margin-top:.75rem;flex-wrap:wrap" x-show="supported">
            <label for="el-passkey-name" class="muted">Name</label>
            <input id="el-passkey-name" type="text" maxlength="80" placeholder="Work laptop" x-model="name">
            <x-filament::button size="sm" x-on:click="add()" x-bind:disabled="busy" icon="heroicon-o-finger-print">Add a passkey</x-filament::button>
        </div>
        <p class="muted" x-show="!supported" style="margin-top:.5rem">This browser cannot create passkeys.</p>
        <p x-show="error" x-text="error" role="alert" style="margin-top:.5rem;color:rgb(220 38 38);font-size:.875rem"></p>
    @endif
</div>
