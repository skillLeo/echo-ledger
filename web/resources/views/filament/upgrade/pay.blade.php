{{--
    Pay for {Pro} (since v1.7): send exactly this amount to this account, with this reference, then add the receipt.
    Every detail has Copy; nothing important is in a toast.
--}}
@php
    $copy = fn (string $label, string $value): string => "navigator.clipboard?.writeText(".json_encode($value)."); copied = true; setTimeout(() => copied = false, 2000); new FilamentNotification().title('Copied').success().send()";
@endphp
<x-filament-panels::page class="el-settings-page">
    <div class="el-flow" data-flow="pay">
        <a href="{{ $statusUrl }}" class="el-set-back">@svg('heroicon-o-chevron-left', ['aria-hidden' => 'true']) {{ $plan }} request</a>
        <h1 class="el-set-h1">Pay for {{ $plan }}</h1>

        <section class="el-set-section">
            <div class="el-set-box el-amount" x-data="{ copied: false }">
                <span class="el-copy-label">Amount</span>
                <span class="el-amount-value">
                    <span>{{ $amount }}</span>
                    <button type="button" class="el-copy-btn" x-on:click="{{ $copy('amount', $amountDigits) }}" aria-label="Copy the amount">
                        <span x-show="! copied">@svg('heroicon-o-document-duplicate', ['aria-hidden' => 'true'])</span>
                        <span x-show="copied" x-cloak>@svg('heroicon-o-check', ['aria-hidden' => 'true'])</span>
                    </button>
                </span>
                <p class="el-set-sub" style="margin-top:4px">Send exactly this amount. A different amount can slow down your upgrade.</p>
            </div>
        </section>

        @foreach ($accounts as $account)
            <x-el.section :title="$loop->count > 1 ? 'Pay to, account '.$loop->iteration : 'Pay to'">
                @foreach ($account['rows'] as [$label, $value, $copies, $mono])
                    <div class="el-set-row el-copy-row" x-data="{ copied: false }">
                        <span class="el-set-text">
                            <span class="el-copy-label">{{ $label }}</span>
                            <span @class(['el-copy-value', 'is-mono' => $mono])>{{ $value }}</span>
                        </span>
                        @if ($copies !== null)
                            <button type="button" class="el-copy-btn" x-on:click="{{ $copy($label, $copies) }}" aria-label="Copy {{ $label === 'Account title' ? 'account title' : $label }}">
                                <span x-show="! copied">@svg('heroicon-o-document-duplicate', ['aria-hidden' => 'true'])</span>
                                <span x-show="copied" x-cloak>@svg('heroicon-o-check', ['aria-hidden' => 'true'])</span>
                            </button>
                        @endif
                    </div>
                @endforeach
            </x-el.section>
        @endforeach

        @if ($instructions)
            <x-el.section title="How to pay">
                <p class="el-prose el-pre" style="margin:0;padding:16px">{{ $instructions }}</p>
            </x-el.section>
        @endif

        @if ($reference)
            <x-el.section title="Reference" :footer="$referenceLine">
                <div class="el-set-row el-copy-row" x-data="{ copied: false }">
                    <span class="el-set-text">
                        <span class="el-copy-label">Reference</span>
                        <span class="el-copy-value is-mono">{{ $reference }}</span>
                    </span>
                    <button type="button" class="el-copy-btn" x-on:click="{{ $copy('reference', $reference) }}" aria-label="Copy the reference">
                        <span x-show="! copied">@svg('heroicon-o-document-duplicate', ['aria-hidden' => 'true'])</span>
                        <span x-show="copied" x-cloak>@svg('heroicon-o-check', ['aria-hidden' => 'true'])</span>
                    </button>
                </div>
            </x-el.section>
        @endif

        @if ($accounts !== [])
            <p class="el-prose" style="padding-inline:16px">Pay from any bank app, JazzCash, Easypaisa, NayaPay or SadaPay.</p>
            @if ($accountTitle)
                <p class="el-prose" style="padding-inline:16px">Your app shows the account title before you confirm. It should say {{ $accountTitle }}. If it doesn't, stop and contact us.</p>
            @endif
        @endif

        <div class="el-flow-bar">
            @if (in_array($stage, ['awaiting_payment', 'needs_receipt'], true))
                <span class="el-set-text"><span class="el-set-sub" style="font-size:14px;line-height:20px">Paid? Add a screenshot of the receipt so we can check it.</span></span>
                <a href="{{ $receiptUrl }}" class="el-btn el-btn-primary">Add payment receipt</a>
                <a href="{{ $backUrl }}" class="el-btn el-btn-text" style="flex-basis:100%;justify-content:flex-start;padding-inline:0">I'll pay later</a>
            @else
                <span class="el-set-text"><span class="el-set-sub" style="font-size:14px;line-height:20px">We have your receipt and are checking it.</span></span>
                <a href="{{ $statusUrl }}" class="el-btn el-btn-primary">View request</a>
            @endif
        </div>
    </div>
</x-filament-panels::page>
