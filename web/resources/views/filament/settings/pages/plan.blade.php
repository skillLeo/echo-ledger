{{--
    Settings › Plan & usage: the current plan (one filled button: Upgrade), the request while one is open or just
    decided (its button is then the only filled one), this month's use, what the plan includes, the plans above it as
    plain cards, and Payments with the promo code.
--}}
@php
    use App\Support\Formats;

    /** @var \App\Models\Plan $plan */
@endphp

{{-- The current plan --}}
<section class="el-set-section">
    <div class="el-set-box el-plan-card-current" id="setting-plan.current" tabindex="-1" data-setting="plan.current">
        <h2 class="el-plan-name">{{ $plan->name }} plan</h2>
        @if ($managed)
            <p class="el-plan-line">{{ $managed }}</p>
        @else
            <p class="el-plan-line">{{ $priceLine }}</p>
            @if ($untilLine)<p class="el-plan-line">{{ $untilLine }}</p>@endif
        @endif
        @if ($trial)<p class="el-plan-line">{{ $trial }}</p>@endif
        @if ($buyer && ! $open)
            @if ($next)
                <div class="el-btn-row">
                    <a href="{{ $upgradeUrl }}" class="el-btn el-btn-primary {{ $free ? 'el-btn-block' : '' }}" id="setting-plan.upgrade" data-setting="plan.upgrade">{{ $free ? 'Upgrade' : 'Upgrade to '.$next->name }}</a>
                </div>
                @if ($free)
                    <div class="el-btn-row" style="margin-top:4px;justify-content:center"><a href="#el-plans" class="el-btn el-btn-text">Compare plans</a></div>
                @endif
            @else
                <p class="el-plan-line" style="margin-top:12px">You're on our top plan.</p>
            @endif
        @endif
    </div>
    @if ($footer)<p class="el-set-foot">{{ $footer }}</p>@endif
</section>

{{-- The request, while it is open or just decided --}}
@if ($request)
    <section class="el-set-section" id="setting-plan.request" tabindex="-1" data-setting="plan.request" data-stage="{{ $request['stage'] }}">
        <div class="el-set-box" style="padding:16px">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
                <a href="{{ $requestUrl }}" class="el-plan-card-name" style="font-size:14px">{{ $request['title'] }}</a>
                <span @class(['el-tag', 'is-warning' => $request['tone'] === 'warning', 'is-success' => $request['tone'] === 'success'])>{{ $request['tag'] }}</span>
            </div>
            @foreach ($request['body'] as $line)
                <p class="el-plan-line el-pre" style="color:var(--text)">{{ $line }}</p>
            @endforeach
            @if ($request['primary'] || $request['secondary'])
                <div class="el-btn-row">
                    @if ($request['primary'])
                        <a href="{{ $request['primary']['url'] }}" class="el-btn el-btn-primary"
                            @if ($request['primary']['label'] === 'Payment details') id="setting-plan.payment_details" data-setting="plan.payment_details" @endif
                            @if ($request['primary']['label'] === 'Add receipt') id="setting-plan.receipt" data-setting="plan.receipt" @endif>{{ $request['primary']['label'] }}</a>
                    @endif
                    @if ($request['secondary'])
                        @if (isset($request['secondary']['url']))
                            <a href="{{ $request['secondary']['url'] }}" class="el-btn el-btn-text"
                                @if ($request['secondary']['label'] === 'Payment details') id="setting-plan.payment_details" data-setting="plan.payment_details" @endif>{{ $request['secondary']['label'] }}</a>
                        @elseif (($request['secondary']['action'] ?? null) === 'cancelRequest')
                            <button type="button" class="el-btn el-btn-text is-danger" wire:click="mountAction('cancelRequest')">{{ $request['secondary']['label'] }}</button>
                        @else
                            <button type="button" class="el-btn el-btn-text" wire:click="dismissRequest">{{ $request['secondary']['label'] }}</button>
                        @endif
                    @endif
                </div>
            @endif
        </div>
    </section>
@endif

{{-- This month --}}
<x-el.section title="This month" :footer="$resetsFooter">
    @foreach ($meters as $meter)
        @include('filament.settings.meter', ['meter' => $meter])
    @endforeach
</x-el.section>

{{-- Included in your plan --}}
<x-el.section title="Included in your plan" :footer="$free ? 'Every plan can record, organise, search, share links and export.' : null">
    @foreach ($included as $fact)
        <x-el.row :title="$fact['title']" :value="$fact['value']" />
    @endforeach
    @if ($usageUrl)
        <x-el.row title="Usage details" :href="$usageUrl" chevron />
    @endif
    <details class="el-set-group" id="setting-plan.compare" tabindex="-1" data-setting="plan.compare">
        <summary class="el-set-row">
            <span class="el-set-text"><span class="el-set-title">See every feature</span></span>
            @svg('heroicon-o-chevron-right', ['class' => 'el-set-chevron', 'aria-hidden' => 'true'])
        </summary>
        <div class="el-matrix-wrap">
            <table class="el-matrix">
                <caption class="sr-only">Every feature of every plan</caption>
                <thead>
                    <tr>
                        <th scope="col"><span class="sr-only">Feature</span></th>
                        @foreach ($matrixPlans as $column)
                            <th scope="col">{{ $column['name'] }}@if ($column['yours']) <span>(yours)</span>@endif</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($features as $feature)
                        <tr id="setting-plan.feature.{{ $feature['key'] }}" data-setting="plan.feature.{{ $feature['key'] }}" tabindex="-1">
                            <td>{{ $feature['name'] }}</td>
                            @foreach ($matrixPlans as $column)
                                <td>
                                    @if (in_array($feature['key'], $column['features'], true))
                                        @svg('heroicon-o-check', ['aria-label' => 'Included', 'role' => 'img'])
                                    @else
                                        <span class="sr-only">Not included</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr class="el-matrix-group"><td colspan="{{ count($matrixPlans) + 1 }}">Limits</td></tr>
                    @foreach ([['monthly_minutes', 'Transcription minutes a month'], ['storage_bytes', 'Storage'], ['retention_days', 'Recordings kept'], ['devices', 'Devices'], ['max_file_minutes', 'Longest recording'], ['uploads_per_day', 'Uploads a day'], ['seats', 'Team members']] as [$key, $label])
                        <tr>
                            <td>{{ $label }}</td>
                            @foreach ($matrixPlans as $column)
                                @php($v = $column['plan']->limit($key))
                                <td>{{ match (true) {
                                    $v === null => 'No limit',
                                    $key === 'monthly_minutes' => $v > 0 ? number_format((int) $v) : 'Your key',
                                    $key === 'storage_bytes' => Formats::bytes((int) $v),
                                    $key === 'retention_days' => Formats::days((int) $v),
                                    $key === 'max_file_minutes' => Formats::fileLength((int) $v),
                                    $key === 'seats' => (int) $v <= 1 ? 'Just you' : number_format((int) $v),
                                    default => number_format((int) $v),
                                } }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</x-el.section>

{{-- The plans above yours --}}
@if ($cards !== [])
    <section class="el-set-section" id="el-plans">
        <h2 class="el-set-head">Plans</h2>
        <div class="el-plan-cards">
            @foreach ($cards as $card)
                <article class="el-plan-card" data-plan-card="{{ $card['key'] }}">
                    <div class="el-plan-card-head">
                        <h3 class="el-plan-card-name">{{ $card['name'] }}</h3>
                        <span class="el-plan-card-price">{{ $card['price'] }}</span>
                    </div>
                    <ul class="el-plan-lines">
                        @if ($card['everything'])<li class="is-muted">Everything in {{ $card['everything'] }}, plus:</li>@endif
                        @foreach ($card['lines'] as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                    @if ($buyer && ! $open)
                        <a href="{{ $card['url'] }}" class="el-btn el-btn-outline el-btn-block" style="margin-top:16px">Choose {{ $card['name'] }}</a>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
@endif

{{-- Payments --}}
@if ($buyer && ($history !== [] || ! $open))
    <x-el.section title="Payments" setting="plan.history" :footer="'Payments are by bank transfer. We switch your plan on after we see the money in our account.'">
        @if ($history !== [])
        <div class="el-set-group">
            @foreach ($history as $row)
                <a href="{{ $row['url'] }}" class="el-set-row">
                    <span class="el-set-text"><span class="el-set-title">{{ $row['title'] }}</span><span class="el-set-sub">{{ $row['line'] }}</span></span>
                    <span class="el-set-value">{{ $row['value'] }}</span>
                </a>
            @endforeach
        </div>
        @endif
        @if (! $open && $next)
            <x-el.row setting="plan.promo" title="Promo code" value="Add" click="mountAction('promoCode')" />
        @endif
    </x-el.section>
@endif
