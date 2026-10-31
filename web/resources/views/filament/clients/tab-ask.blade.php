{{-- Ask: a short answer from the brief, memory, files and transcripts, with the moments and pages it comes from. --}}
<div style="display: flex; flex-direction: column; gap: var(--space-5); max-width: 880px">
    <form class="el-card" wire:submit="ask" style="display: flex; flex-direction: column; gap: var(--space-3)">
        <label for="ask-question" class="el-card-title">Ask about {{ $client->name }}</label>
        <textarea id="ask-question" class="el-field-input" rows="3" wire:model="question" maxlength="500"
            placeholder="What did we agree about the deadline?" x-on:keydown.ctrl.enter.prevent="$wire.ask()" x-on:keydown.meta.enter.prevent="$wire.ask()"></textarea>
        @error('question')<p class="el-caption" role="alert" style="color: var(--danger)">{{ $message }}</p>@enderror
        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3)">
            <span class="el-seg" role="group" aria-label="Answer in">
                <button type="button" wire:click="$set('askLanguage', 'en')" aria-pressed="{{ $this->askLanguage === 'en' ? 'true' : 'false' }}">English</button>
                <button type="button" wire:click="$set('askLanguage', 'roman_urdu')" aria-pressed="{{ $this->askLanguage === 'roman_urdu' ? 'true' : 'false' }}">Roman Urdu</button>
            </span>
            <button type="submit" class="el-pill el-pill-primary" wire:loading.attr="disabled" wire:target="ask">
                <span wire:loading.remove wire:target="ask">Ask</span>
                <span wire:loading wire:target="ask">Asking…</span>
            </button>
        </div>
        @unless ($configured)
            <p class="el-caption">Add your Anthropic key in <a class="el-link" href="{{ $credentialsUrl }}">API Credentials</a> to ask.</p>
        @endunless
    </form>

    <div wire:loading.flex wire:target="ask" style="flex-direction: column; gap: var(--space-2)" aria-hidden="true">
        <span class="el-skeleton" style="height: 16px; width: 85%"></span>
        <span class="el-skeleton" style="height: 16px; width: 70%"></span>
        <span class="el-skeleton" style="height: 16px; width: 40%"></span>
    </div>

    <div wire:loading.remove wire:target="ask">
        @if ($this->askError !== null)
            <div class="el-banner el-banner-danger" role="alert">@svg('heroicon-o-exclamation-circle', ['aria-hidden' => 'true'])<span class="el-banner-body">{{ $this->askError }}</span></div>
        @elseif ($this->answer !== null)
            <section class="el-card" aria-labelledby="answer-heading" style="display: flex; flex-direction: column; gap: var(--space-4)">
                <h3 id="answer-heading" class="el-sr">Answer</h3>
                <p class="el-answer" dir="auto">{{ $this->answer['answer'] }}</p>
                @if ($this->answer['citations'] !== [])
                    <div>
                        <h4 class="el-section-header">Sources</h4>
                        <div class="el-sources">
                            @foreach ($this->answer['citations'] as $citation)
                                @if ($citation['type'] === 'call')
                                    <a class="el-source" href="{{ \App\Filament\Resources\CaptureSessions\CaptureSessionResource::getUrl('view', ['record' => $citation['session_id']]) }}?t={{ (int) $citation['at_ms'] }}">
                                        @svg('heroicon-o-play-circle', ['aria-hidden' => 'true'])
                                        <span>
                                            <span style="font-weight: 600">{{ $citation['title'] }}</span> <span class="el-note-at">{{ \App\Services\ClientAi\ClientWorkspace::clock((int) $citation['at_ms']) }}</span>
                                            @if (filled($citation['quote'] ?? null))<span class="el-source-quote" style="display: block" dir="auto">“{{ $citation['quote'] }}”</span>@endif
                                        </span>
                                    </a>
                                @else
                                    <a class="el-source" href="?tab=files#file-{{ $citation['file_id'] }}" wire:click.prevent="setTab('files')">
                                        @svg('heroicon-o-document-text', ['aria-hidden' => 'true'])
                                        <span>
                                            <span style="font-weight: 600">{{ $citation['title'] }}</span>@if ($citation['page']) <span class="el-note-at">page {{ $citation['page'] }}</span>@endif
                                            @if (filled($citation['quote'] ?? null))<span class="el-source-quote" style="display: block" dir="auto">“{{ $citation['quote'] }}”</span>@endif
                                        </span>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>
        @else
            <div class="el-empty" style="padding-block: var(--space-6)">
                <span class="el-empty-icon">@svg('heroicon-o-chat-bubble-left-ellipsis', ['aria-hidden' => 'true'])</span>
                <p class="el-empty-title">Ask anything about {{ $client->name }}</p>
                <p class="el-empty-text">Answers come from the brief, the memory, the files and the transcripts, with the moment or page they are from.</p>
            </div>
        @endif
    </div>
</div>
