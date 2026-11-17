{{--
    Ask (v2.0): the apps' Ask, one project at a time. The question, the language of the answer, then the answer with
    where it comes from (a recording at its moment, a file at its page). Designed empty, loading and error states.
--}}
<x-filament-panels::page>
    <div class="v2-page v2-stack v2-narrow">
        @if ($projects->isEmpty())
            <div class="v2-empty">
                <x-el.v2.icon name="folder" class="v2-empty-icon" />
                <p class="v2-empty-title">{{ __('No projects yet') }}</p>
                <p class="v2-empty-text">{{ __('Ask works on a project\'s recordings. Put recordings in a project from Library first.') }}</p>
                <a class="v2-btn v2-btn-secondary" href="{{ \App\Filament\Resources\Clients\ClientResource::getUrl('index') }}">{{ __('Projects') }}</a>
            </div>
        @else
            <form class="v2-card v2-stack-s" wire:submit="ask">
                <div class="v2-field">
                    <label class="v2-label" for="ask-project">{{ __('Ask about') }}</label>
                    <select id="ask-project" class="v2-input" wire:model.live="project">
                        @foreach ($projects as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                    @error('project')<p class="v2-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="v2-field">
                    <label class="v2-label" for="ask-question">{{ __('Your question') }} @if ($locked){!! $badge?->toHtml() !!}@endif</label>
                    <textarea id="ask-question" class="v2-input" rows="3" wire:model="question" maxlength="500" dir="auto"
                        placeholder="{{ __('What did Bilal Traders agree on payment?') }}"
                        x-on:keydown.ctrl.enter.prevent="$wire.ask()" x-on:keydown.meta.enter.prevent="$wire.ask()"></textarea>
                    @error('question')<p class="v2-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="v2-form-foot">
                    <span class="v2-segmented" role="radiogroup" aria-label="{{ __('Answer in') }}">
                        @foreach (['en' => __('English'), 'roman_urdu' => __('Roman Urdu')] as $value => $label)
                            <button type="button" role="radio" aria-checked="{{ $askLanguage === $value ? 'true' : 'false' }}" wire:click="$set('askLanguage', '{{ $value }}')">{{ $label }}</button>
                        @endforeach
                    </span>
                    <button type="submit" class="v2-btn v2-btn-primary" wire:loading.attr="disabled" wire:target="ask">
                        <span wire:loading.remove wire:target="ask">{{ __('Ask') }}</span>
                        <span wire:loading wire:target="ask">{{ __('Asking') }}</span>
                    </button>
                </div>
                @unless ($configured)
                    <p class="v2-help">{{ __('Ask is not set up on this server yet.') }}</p>
                @endunless
            </form>

            <div wire:loading.flex wire:target="ask" class="v2-skeleton-stack" aria-hidden="true">
                <span class="v2-skeleton v2-skeleton-long"></span>
                <span class="v2-skeleton v2-skeleton-mid"></span>
                <span class="v2-skeleton v2-skeleton-short"></span>
            </div>

            <div wire:loading.remove wire:target="ask">
                @if ($askError !== null)
                    <div class="v2-banner is-danger" role="alert">
                        <x-el.v2.icon name="alert" />
                        <span>{{ $askError }}</span>
                    </div>
                @elseif ($answer !== null)
                    <section class="v2-card v2-stack-s" aria-labelledby="ask-answer">
                        <h2 id="ask-answer" class="v2-sr">{{ __('Answer') }}</h2>
                        <p class="v2-answer" dir="auto">{{ $answer['answer'] }}</p>
                        @if ($answer['citations'] !== [])
                            <h3 class="v2-group-title">{{ __('From') }}</h3>
                            <ul class="v2-list">
                                @foreach ($answer['citations'] as $citation)
                                    <li>
                                        @if ($citation['type'] === 'call')
                                            <a class="v2-row" href="{{ \App\Filament\Resources\CaptureSessions\CaptureSessionResource::getUrl('view', ['record' => $citation['session_id']]) }}?t={{ (int) $citation['at_ms'] }}">
                                                <span class="v2-row-tile"><x-el.v2.icon name="play" /></span>
                                                <span class="v2-row-main">
                                                    <span class="v2-row-title">{{ $citation['title'] }} <span class="v2-mono" dir="ltr">{{ \App\Services\ClientAi\ClientWorkspace::clock((int) $citation['at_ms']) }}</span></span>
                                                    @if (filled($citation['quote'] ?? null))<span class="v2-row-meta" dir="auto">“{{ $citation['quote'] }}”</span>@endif
                                                </span>
                                            </a>
                                        @else
                                            <a class="v2-row" href="{{ \App\Filament\Resources\Clients\ClientResource::getUrl('view', ['record' => $project]) }}?tab=files#file-{{ $citation['file_id'] }}">
                                                <span class="v2-row-tile"><x-el.v2.icon name="file" /></span>
                                                <span class="v2-row-main">
                                                    <span class="v2-row-title">{{ $citation['title'] }}@if ($citation['page']) <span class="v2-row-meta">{{ __('page :n', ['n' => $citation['page']]) }}</span>@endif</span>
                                                    @if (filled($citation['quote'] ?? null))<span class="v2-row-meta" dir="auto">“{{ $citation['quote'] }}”</span>@endif
                                                </span>
                                            </a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @else
                    <div class="v2-empty">
                        <x-el.v2.icon name="ask" class="v2-empty-icon" />
                        <p class="v2-empty-title">{{ __('Ask your recordings') }}</p>
                        <p class="v2-empty-text">{{ __('Pick a project, then ask in your own words. Answers say which recording they come from.') }}</p>
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
