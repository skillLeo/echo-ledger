{{--
    Admin panel → Customers → a row (since v1.6, P4.4 on the admin panel): a quick look in a drawer on the right, the
    numbers, the plan and the latest devices; metadata only. Open shows the full page with every action.
--}}
<div class="el-peek" data-customer-peek="{{ $user->id }}">
    <dl class="el-peek-kpis">
        <div><dt>Minutes this month</dt><dd>{{ number_format($stats['minutes'], 1) }}</dd></div>
        <div><dt>Cost this month</dt><dd>${{ number_format($stats['cost_usd'], 2) }}</dd></div>
        <div><dt>Of the plan's minutes</dt><dd>{{ $stats['plan_percent'] === null ? 'No limit' : rtrim(rtrim(number_format($stats['plan_percent'], 1), '0'), '.').'%' }}</dd></div>
        <div><dt>Storage</dt><dd>{{ \Illuminate\Support\Number::fileSize($stats['storage_bytes'], precision: 1) }}</dd></div>
        <div><dt>Devices signed in</dt><dd>{{ $stats['devices'] }}</dd></div>
        <div><dt>Recordings</dt><dd>{{ number_format($stats['recordings']) }}</dd></div>
    </dl>
    <p class="el-peek-line"><span class="el-peek-label">Plan</span> {{ $plan->name }}@if ($until) · until {{ \Illuminate\Support\Carbon::parse((string) $until)->format('M j, Y') }}@endif</p>
    <p class="el-peek-line"><span class="el-peek-label">Signed up</span> {{ $user->created_at?->format('M j, Y') }}</p>
    @if ($devices->isNotEmpty())
        <p class="el-peek-label" style="margin-top: var(--space-3)">Latest devices</p>
        <ul class="el-peek-list">
            @foreach ($devices as $device)
                <li><span>{{ $device->readableName() }}</span><span class="el-muted">{{ $device->last_seen_at?->diffForHumans() ?? 'Never seen' }}</span></li>
            @endforeach
        </ul>
    @endif
</div>
