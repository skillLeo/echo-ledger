{{-- Admin panel → a customer (since v1.6, P2.2): metadata and usage only; recordings only while Support access is on. --}}
@php
    $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);';
    $head = 'padding:.5rem .75rem;text-align:left;opacity:.7;font-weight:500';
    $kpi = 'display:flex;flex-direction:column;gap:.25rem;padding:1rem;border:1px solid var(--gray-200);border-radius:.75rem';
    $percent = $stats['plan_percent'];
@endphp
<div style="display:flex;flex-direction:column;gap:1.5rem">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:.75rem" data-customer-kpis>
        <div style="{{ $kpi }}"><span style="font-size:.8rem;opacity:.7">Minutes this month</span><span style="font-size:1.4rem;font-weight:600;font-variant-numeric:tabular-nums">{{ number_format($stats['minutes'], 1) }}</span></div>
        <div style="{{ $kpi }}"><span style="font-size:.8rem;opacity:.7">Cost this month</span><span style="font-size:1.4rem;font-weight:600;font-variant-numeric:tabular-nums">${{ number_format($stats['cost_usd'], 2) }}</span></div>
        <div style="{{ $kpi }}"><span style="font-size:.8rem;opacity:.7">Of the plan's minutes</span><span style="font-size:1.4rem;font-weight:600;font-variant-numeric:tabular-nums">{{ $percent === null ? 'No limit' : rtrim(rtrim(number_format($percent, 1), '0'), '.').'%' }}</span></div>
        <div style="{{ $kpi }}"><span style="font-size:.8rem;opacity:.7">Storage</span><span style="font-size:1.4rem;font-weight:600;font-variant-numeric:tabular-nums">{{ \Illuminate\Support\Number::fileSize($stats['storage_bytes'], precision: 1) }}</span></div>
        <div style="{{ $kpi }}"><span style="font-size:.8rem;opacity:.7">Devices signed in</span><span style="font-size:1.4rem;font-weight:600;font-variant-numeric:tabular-nums">{{ $stats['devices'] }}</span></div>
        <div style="{{ $kpi }}"><span style="font-size:.8rem;opacity:.7">Recordings</span><span style="font-size:1.4rem;font-weight:600;font-variant-numeric:tabular-nums">{{ number_format($stats['recordings']) }}</span></div>
    </div>

    @if ($stats['providers'] !== [])
        <x-filament::section heading="Cost by provider this month" compact>
            <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                <thead><tr><th style="{{ $head }}">Provider</th><th style="{{ $head }};text-align:right">Minutes</th><th style="{{ $head }};text-align:right">Cost</th></tr></thead>
                <tbody>
                    @foreach ($stats['providers'] as $provider)
                        <tr><td style="{{ $cell }}">{{ $provider['label'] }}</td><td style="{{ $cell }}text-align:right;font-variant-numeric:tabular-nums">{{ number_format($provider['minutes'], 1) }}</td><td style="{{ $cell }}text-align:right;font-variant-numeric:tabular-nums">${{ number_format($provider['cost_usd'], 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif

    <x-filament::section heading="Plan" compact>
        <p style="font-size:.9rem">
            {!! \App\Filament\Support\PlanLock::badgeFor($plan, locked: false)->toHtml() !!} {{ $plan->name }}
            @if ($planUntil) · until {{ $planUntil->format('M j, Y') }}, then the default plan @endif
        </p>
        <p style="font-size:.85rem;opacity:.8;margin-top:.5rem">Their own features and limits: {{ $overrides }}</p>
    </x-filament::section>

    <x-filament::section heading="Devices" compact>
        @if ($devices->isEmpty())
            <p style="font-size:.85rem;opacity:.7">No devices.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                    <thead><tr><th style="{{ $head }}">Device</th><th style="{{ $head }}">Status</th><th style="{{ $head }}">App</th><th style="{{ $head }}">Last seen</th><th style="{{ $head }}"></th></tr></thead>
                    <tbody>
                        @foreach ($devices as $device)
                            <tr>
                                <td style="{{ $cell }}">{{ $device->readableName() }}</td>
                                <td style="{{ $cell }}">{{ \App\Models\Device::STATUS_LABELS[$device->status()] }}</td>
                                <td style="{{ $cell }}">{{ $device->app_version ?? '—' }}</td>
                                <td style="{{ $cell }}">{{ $device->last_seen_at?->diffForHumans() ?? 'never' }}</td>
                                <td style="{{ $cell }}text-align:right">
                                    @if ($canSupport && $device->signed_out_at === null)
                                        <x-filament::button size="xs" color="danger" outlined wire:click="mountAction('revokeDevice', { device: '{{ $device->id }}' })">Revoke</x-filament::button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Workspaces" compact>
        <table style="width:100%;font-size:.85rem;border-collapse:collapse">
            <thead><tr><th style="{{ $head }}">Workspace</th><th style="{{ $head }}">Role</th><th style="{{ $head }}">Plan</th><th style="{{ $head }};text-align:right">People</th></tr></thead>
            <tbody>
                @forelse ($workspaces as $workspace)
                    <tr><td style="{{ $cell }}">{{ $workspace['name'] }}</td><td style="{{ $cell }}">{{ $workspace['role'] }}</td><td style="{{ $cell }}">{{ $workspace['plan'] }}</td><td style="{{ $cell }}text-align:right">{{ $workspace['members'] }}</td></tr>
                @empty
                    <tr><td style="{{ $cell }}" colspan="4">None.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Recordings" compact data-customer-recordings>
        @if (! $support)
            <p style="font-size:.85rem;opacity:.8">Recordings and transcripts are private. The person can let support see them for a while from their profile (Support access); each look is then in the activity log.</p>
        @else
            <p style="font-size:.85rem;margin-bottom:.5rem">Support access is on until {{ $supportUntil?->format('M j, Y H:i') }}. Every recording you open is in the activity log.</p>
            @if ($recordings->isEmpty())
                <p style="font-size:.85rem;opacity:.7">No recordings.</p>
            @else
                <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                    <tbody>
                        @foreach ($recordings as $recording)
                            <tr>
                                <td style="{{ $cell }}" dir="auto">{{ $recording->displayTitle() }}</td>
                                <td style="{{ $cell }}">{{ $recording->started_at?->format('M j, Y H:i') }}</td>
                                <td style="{{ $cell }}text-align:right"><x-filament::button size="xs" color="gray" wire:click="mountAction('openRecording', { session: '{{ $recording->id }}' })">Open</x-filament::button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </x-filament::section>

    <x-filament::section heading="Notes" description="For staff only." compact>
        @forelse ($notes as $note)
            <div style="{{ $loop->first ? '' : 'border-top:1px solid var(--gray-200);' }}padding:.5rem 0">
                <p style="font-size:.8rem;opacity:.7">{{ $note->author?->name ?? 'Someone' }} · {{ $note->created_at->format('M j, Y H:i') }}</p>
                <p style="font-size:.9rem;white-space:pre-wrap" dir="auto">{{ $note->body }}</p>
            </div>
        @empty
            <p style="font-size:.85rem;opacity:.7">No notes yet.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="Activity log" description="What happened on this account, newest first." compact>
        <table style="width:100%;font-size:.85rem;border-collapse:collapse">
            <tbody>
                @forelse ($audit as $event)
                    <tr><td style="{{ $cell }}white-space:nowrap">{{ $event->created_at->format('M j, H:i') }}</td><td style="{{ $cell }}">{{ \App\Services\AuditLog::forStaff($event) }}</td><td style="{{ $cell }}opacity:.7">{{ $event->actor }}</td></tr>
                @empty
                    <tr><td style="{{ $cell }}">Nothing yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</div>
