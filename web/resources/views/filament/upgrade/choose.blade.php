{{--
    Upgrade (since v1.7): the plans above yours as radio cards, a promo code, a note, and "Request {Pro}" with what you
    will pay. The bank details come on the next screen.
--}}
<x-filament-panels::page class="el-settings-page">
    <div class="el-flow" data-flow="choose">
        <a href="{{ $backUrl }}" class="el-set-back">@svg('heroicon-o-chevron-left', ['aria-hidden' => 'true']) Plan &amp; usage</a>
        <h1 class="el-set-h1">Upgrade</h1>
        @if ($workspaceLine)<p class="el-prose" style="padding:0;margin:0 0 4px">{{ $workspaceLine }}</p>@endif
        <p class="el-prose is-muted" style="padding:0;margin:0 0 16px">{{ $featureLine }}</p>

        <div class="el-plan-cards" role="radiogroup" aria-label="Plan">
            @foreach ($cards as $card)
                <button type="button" class="el-radio-card" role="radio" wire:click="choose('{{ $card['key'] }}')" data-plan-card="{{ $card['key'] }}"
                    aria-checked="{{ $card['key'] === $this->plan ? 'true' : 'false' }}" aria-label="{{ $card['name'] }}, {{ $card['price'] }}">
                    <span class="el-radio" aria-hidden="true"></span>
                    <span class="el-set-text">
                        <span class="el-plan-card-name">{{ $card['name'] }}</span>
                        <span class="el-plan-card-price" style="margin-top:2px">{{ $card['price'] }}</span>
                        <span class="el-plan-lines" style="margin-top:10px">
                            @if ($card['everything'])<span class="is-muted">Everything in {{ $card['everything'] }}, plus:</span>@endif
                            @foreach ($card['lines'] as $line)<span>{{ $line }}</span>@endforeach
                        </span>
                    </span>
                </button>
            @endforeach
        </div>

        <section class="el-set-section">
            <div class="el-set-box">
                @if (! $this->promoOpen && ! $promoOk)
                    <x-el.row title="Promo code" value="Add" click="$set('promoOpen', true)" />
                @else
                    <form class="el-form-field" wire:submit="applyPromo">
                        <label for="el-promo">Promo code</label>
                        <div class="el-form-inline">
                            <input id="el-promo" type="text" wire:model="promo" maxlength="24" autocomplete="off" spellcheck="false" autocapitalize="characters"
                                style="text-transform:uppercase" x-on:input="$event.target.value = $event.target.value.toUpperCase().replace(/[^A-Z0-9-]/g, '')"
                                @if ($this->promoError) aria-invalid="true" aria-describedby="el-promo-error" @endif>
                            @if ($promoOk)
                                <button type="button" class="el-btn el-btn-text" wire:click="removePromo">Remove</button>
                            @else
                                <button type="submit" class="el-btn el-btn-outline">Apply</button>
                            @endif
                        </div>
                        @if ($this->promoError)
                            <p class="el-error" id="el-promo-error" role="alert">{{ $this->promoError }}</p>
                        @elseif ($promoLine)
                            <p class="el-field-help" style="color:var(--success);font-size:14px;line-height:20px" role="status">{{ $promoLine }}</p>
                        @endif
                    </form>
                @endif
            </div>
        </section>

        <section class="el-set-section">
            <div class="el-set-box">
                <div class="el-form-field">
                    <label for="el-note">Note for our team (optional)</label>
                    <textarea id="el-note" rows="2" maxlength="500" wire:model="note" placeholder="For example, an invoice in my company's name"></textarea>
                </div>
            </div>
        </section>

        <div class="el-flow-bar">
            <span class="el-set-text">
                <span class="el-copy-label">To pay</span>
                <span class="el-to-pay">{{ $toPay ?? '' }}@if ($percent > 0 && $price && ! $free)<s>{{ $price }}</s>@endif</span>
                @if ($percent > 0 && ! $free)<span class="el-copy-label">{{ $firstMonths }}</span>@endif
            </span>
            <button type="button" class="el-btn el-btn-primary" wire:click="submit" wire:loading.attr="disabled" @disabled($selected === null)>
                {{ $free ? 'Switch on '.($selected?->name ?? '') : 'Request '.($selected?->name ?? '') }}
            </button>
            @if (! $free)
                <p class="el-copy-label" style="flex-basis:100%">You'll see our bank details on the next screen.</p>
            @endif
            @if ($this->error)
                <p class="el-error" role="alert" style="flex-basis:100%;margin:0">{{ $this->error }}</p>
            @endif
        </div>
    </div>
</x-filament-panels::page>
