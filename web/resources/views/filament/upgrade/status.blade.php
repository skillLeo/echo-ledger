{{--
    The request (since v1.7): its card, its steps, the details, the receipts sent, and what you can do next. An approved
    request says "You're on {Pro}". The card's action labels are English keys (they tell the actions apart), shown
    through the translator.
--}}
<x-filament-panels::page class="el-settings-page">
    <div class="el-flow" data-flow="status" data-stage="{{ $stage }}">
        <a href="{{ $backUrl }}" class="el-set-back">@svg(...\App\Filament\Support\RtlIcons::of('heroicon-o-chevron-left', ['aria-hidden' => 'true'])) {{ __('Plan & usage') }}</a>
        @if ($announce)<p class="sr-only" role="status" aria-live="polite">{{ $announce }}</p>@endif

        @if ($stage === 'approved')
            <section class="el-set-section">
                <div class="el-set-box el-approved">
                    @svg('heroicon-o-check-circle', ['aria-hidden' => 'true'])
                    <h1 class="el-set-h1" style="margin-bottom:4px">{!! __("You're on :plan", ['plan' => e(\App\Filament\Support\UpgradeFlow::planName($request->approved_plan ?? $request->plan))]) !!}</h1>
                    <p class="el-prose" style="margin:0">{{ $approvedLine }}</p>
                    <a href="{{ $backUrl }}" class="el-btn el-btn-primary" style="margin-top:16px;min-width:160px">{{ __('Done') }}</a>
                </div>
            </section>
        @else
            <h1 class="el-set-h1">{{ __(':plan request', ['plan' => $plan]) }}</h1>
            <section class="el-set-section">
                <div class="el-set-box" style="padding:16px">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
                        <span class="el-plan-card-name">{{ $card['title'] }}</span>
                        <span @class(['el-tag', 'is-warning' => $card['tone'] === 'warning', 'is-success' => $card['tone'] === 'success'])>{{ $card['tag'] }}</span>
                    </div>
                    @foreach ($card['body'] as $line)
                        <p class="el-plan-line el-pre" style="color:var(--text)">{{ $line }}</p>
                    @endforeach
                    @if ($card['primary'] && $card['primary']['label'] !== 'View request')
                        <div class="el-btn-row"><a href="{{ $card['primary']['url'] }}" class="el-btn el-btn-primary">{{ __($card['primary']['label']) }}</a></div>
                    @endif
                </div>
            </section>
        @endif

        <x-el.section :title="__('Progress')">
            <ol class="el-timeline">
                @foreach ($steps as $step)
                    <li @class(['el-tl-step', 'is-'.$step['state']]) aria-label="{{ match ($step['state']) {
                        'done' => $step['at'] ? __(':step, done, :at', ['step' => $step['label'], 'at' => $step['at']]) : __(':step, done', ['step' => $step['label']]),
                        'current' => $step['at'] ? __(':step, current, :at', ['step' => $step['label'], 'at' => $step['at']]) : __(':step, current', ['step' => $step['label']]),
                        default => $step['at'] ? __(':step, not yet, :at', ['step' => $step['label'], 'at' => $step['at']]) : __(':step, not yet', ['step' => $step['label']]),
                    } }}">
                        <span class="el-tl-step-mark" aria-hidden="true">@if ($step['state'] === 'done')@svg('heroicon-m-check')@endif</span>
                        <span class="el-set-text">
                            <span class="el-set-title">{{ $step['label'] }}</span>
                            @if ($step['at'])<span class="el-set-sub">{{ $step['at'] }}</span>@endif
                            @if ($step['note'])<span class="el-set-sub el-pre" style="color:var(--text)">{{ $step['note'] }}</span>@endif
                        </span>
                    </li>
                @endforeach
            </ol>
        </x-el.section>

        <x-el.section :title="__('Details')">
            @foreach ($details as [$label, $value, $copy])
                <div class="el-set-row" @if ($copy) x-data="{ copied: false }" @endif>
                    <span class="el-set-text"><span class="el-set-title">{{ $label }}</span></span>
                    <span class="el-set-value" style="max-width:60%;white-space:normal;overflow-wrap:anywhere">{{ $value }}</span>
                    @if ($copy)
                        <button type="button" class="el-copy-btn" style="margin:-10px -8px -10px 0" aria-label="{{ __('Copy the reference') }}"
                            x-on:click="navigator.clipboard?.writeText(@js($copy)); copied = true; setTimeout(() => copied = false, 2000); new FilamentNotification().title(@js(__('Copied'))).success().send()">
                            <span x-show="! copied">@svg('heroicon-o-document-duplicate', ['aria-hidden' => 'true'])</span>
                            <span x-show="copied" x-cloak>@svg('heroicon-o-check', ['aria-hidden' => 'true'])</span>
                        </button>
                    @endif
                </div>
            @endforeach
        </x-el.section>

        @if ($proofs !== [] || $erased)
            <x-el.section :title="__('Receipts')">
                @if ($erased)
                    <p class="el-prose is-muted" style="margin:0;padding:16px">{{ __('Receipts were deleted 90 days after our decision.') }}</p>
                @else
                    <div class="el-rtiles">
                        @foreach ($proofs as $proof)
                            @include('filament.upgrade._tile', ['proof' => $proof, 'number' => $loop->iteration])
                        @endforeach
                    </div>
                @endif
            </x-el.section>
        @endif

        @if ($open)
            @php($primary = $card['primary']['label'] ?? null)
            @if ($primary !== 'Payment details' || ($canAdd && $primary !== 'Add receipt'))
                <x-el.section>
                    @if ($primary !== 'Payment details')
                        <x-el.row :title="__('Payment details')" :href="$payUrl" chevron />
                    @endif
                    @if ($canAdd && $primary !== 'Add receipt')
                        <x-el.row :title="__('Add receipt')" :href="$receiptUrl" chevron />
                    @endif
                </x-el.section>
            @endif
            <x-el.section>
                <x-el.row :title="__('Cancel request')" click="mountAction('cancelRequest')" danger />
            </x-el.section>
        @endif
    </div>
</x-filament-panels::page>
