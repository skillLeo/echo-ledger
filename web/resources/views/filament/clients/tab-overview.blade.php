{{-- W-209: a name or value inside a translated sentence is isolated in right to left, so the sentence keeps the page's direction (D4 §4). --}}
@php
    $iso = fn (mixed $value): string => \App\Filament\Support\ConsoleV2::rtl() ? "\u{2068}".$value."\u{2069}" : (string) $value;
@endphp
@php
    $sections = \App\Models\ClientBrief::clean($brief->sections);
    $briefEmpty = $sections['goal'] === '' && collect(array_slice(\App\Models\ClientBrief::SECTIONS, 1))->every(fn ($k) => $sections[$k] === []);
    $recordingUrl = fn (string $session, ?int $ms = null): string => \App\Filament\Resources\CaptureSessions\CaptureSessionResource::getUrl('view', ['record' => $session]).($ms !== null ? '?t='.$ms : '');
    $percent = $budget['budget_usd'] > 0 ? min(100, round($budget['spent_usd'] / $budget['budget_usd'] * 100)) : 100;
@endphp
<div class="el-workspace-grid">
    <div style="display: flex; flex-direction: column; gap: var(--space-5); min-width: 0">
        @include('filament.clients.ai-locked')
        {{-- AI notes, the month's budget and Copy for AI --}}
        <section class="el-card" aria-labelledby="ai-heading">
            <div class="el-setting-row">
                <div>
                    <h3 id="ai-heading" class="el-setting-title">{{ __('AI notes') }} @if ($aiLocked){!! $aiBadge?->toHtml() !!}@endif</h3>
                    <p class="el-muted" style="font-size: 14px">{{ __('Notes after every call, the memory and suggested fixes. Off: nothing from this project is sent to the AI.') }}</p>
                </div>
                <button type="button" class="el-switch" role="switch" aria-checked="{{ $client->ai_notes && ! $aiLocked ? 'true' : 'false' }}" aria-labelledby="ai-heading" wire:click="toggleAiNotes" @if ($aiLocked) data-locked @endif></button>
            </div>
            <div class="el-budget" style="margin-top: var(--space-4)">
                <p class="el-meta" data-ai-budget>{{ __('AI this month: :spent of :budget · this project :project', ['spent' => '$'.number_format($budget['spent_usd'], 2), 'budget' => '$'.number_format($budget['budget_usd'], 2), 'project' => '$'.number_format($clientSpend, 2)]) }}</p>
                <div class="el-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="{{ __('AI budget used this month') }}"><span style="width: {{ $percent }}%"></span></div>
                @unless ($configured)
                    @if ($credentialsUrl)
                        <p class="el-caption">{!! __('Add your Anthropic key in :link to use project notes and Ask.', ['link' => '<a class="el-link" href="'.e($credentialsUrl).'">'.e(__('API Credentials')).'</a>']) !!}</p>
                    @else
                        <p class="el-caption">{{ __('Project notes and Ask are not set up on this server yet.') }}</p>
                    @endif
                @endunless
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: var(--space-2); margin-top: var(--space-4)" x-data="{ copied: null }">
                <button type="button" class="el-pill" x-on:click="try { await navigator.clipboard.writeText(await $wire.bundle()); copied = 'ok' } catch (e) { copied = 'failed' } setTimeout(() => copied = null, 2000)">
                    @svg('heroicon-o-clipboard-document', ['aria-hidden' => 'true'])
                    <span x-text="copied === 'ok' ? {{ \Illuminate\Support\Js::from(__('Copied')) }} : (copied === 'failed' ? {{ \Illuminate\Support\Js::from(__('Copy failed')) }} : {{ \Illuminate\Support\Js::from(__('Copy for AI')) }})">{{ __('Copy for AI') }}</span>
                </button>
                <span class="el-caption" style="align-self: center">{{ __('The brief, the memory and the latest call notes, to paste into a chat.') }}</span>
                <span class="el-sr" role="status" aria-live="polite" x-text="copied === 'ok' ? {{ \Illuminate\Support\Js::from(__('Copied to the clipboard')) }} : (copied === 'failed' ? {{ \Illuminate\Support\Js::from(__('Copy failed')) }} : '')"></span>
            </div>
        </section>

        {{-- The brief --}}
        <section class="el-card" aria-labelledby="brief-heading">
            <div class="el-card-head">
                <h3 id="brief-heading" class="el-card-title">{{ __('Brief') }}
                    @if ($brief->edited)<span class="el-status" style="margin-inline-start: var(--space-2)">{{ __('Edited') }}</span>@endif
                </h3>
                <div style="display: flex; gap: var(--space-1)">
                    <button type="button" class="el-text-btn" wire:click="mountAction('editBrief')">@svg('heroicon-o-pencil-square', ['aria-hidden' => 'true']) {{ __('Edit') }}</button>
                    <button type="button" class="el-text-btn" wire:click="remakeBrief" @disabled($brief->status === 'working')>@svg('heroicon-o-arrow-path', ['aria-hidden' => 'true']) {{ __('Make again') }}</button>
                </div>
            </div>
            @if ($brief->status === 'working')
                <div style="display: flex; flex-direction: column; gap: var(--space-2)" aria-hidden="true">
                    <span class="el-skeleton" style="height: 14px; width: 70%"></span>
                    <span class="el-skeleton" style="height: 14px; width: 90%"></span>
                    <span class="el-skeleton" style="height: 14px; width: 55%"></span>
                </div>
                <p class="el-caption" role="status" style="margin-top: var(--space-2)">{{ __('Making the brief. This updates by itself.') }}</p>
            @else
                @if ($brief->status === 'failed')
                    <div class="el-banner el-banner-danger" style="margin-bottom: var(--space-3)">@svg('heroicon-o-exclamation-circle', ['aria-hidden' => 'true'])<span class="el-banner-body">{{ $brief->errorForPeople() ?? __('The brief could not be made.') }}</span></div>
                @endif
                @if ($briefEmpty)
                    <p class="el-muted" style="font-size: 15px">{{ __('No brief yet. Add documents or instructions on Files, or it is written from the call notes.') }}</p>
                @else
                    <dl class="el-brief">
                        @if ($sections['goal'] !== '')
                            <dt>{{ __('Goal') }}</dt><dd>{{ $sections['goal'] }}</dd>
                        @endif
                        @foreach (array_slice(\App\Models\ClientBrief::SECTIONS, 1) as $key)
                            @if ($sections[$key] !== [])
                                <dt>{{ __(ucfirst($key)) }}</dt>
                                <dd><ul>@foreach ($sections[$key] as $line)<li>{{ $line }}</li>@endforeach</ul></dd>
                            @endif
                        @endforeach
                    </dl>
                @endif
            @endif
        </section>
    </div>

    <div style="display: flex; flex-direction: column; gap: var(--space-5); min-width: 0">
        {{-- The memory --}}
        <section class="el-card" aria-labelledby="memory-heading">
            <div class="el-card-head"><h3 id="memory-heading" class="el-card-title">{{ __('Memory') }}</h3></div>
            @php($any = $memory->isNotEmpty())
            @foreach (\App\Filament\Resources\Clients\Pages\ViewClient::MEMORY_GROUPS as $kind => $heading)
                @if ($memory->has($kind))
                    <h4 class="el-section-header">{{ __($heading) }}</h4>
                    <ul>
                        @foreach ($memory[$kind] as $item)
                            <li class="el-memory-item" wire:key="memory-{{ $item->id }}">
                                <div class="el-memory-text">
                                    @if ($item->pinned)@svg('heroicon-s-star', ['aria-hidden' => 'true', 'style' => 'display: inline; width: 14px; height: 14px; color: var(--warning-fill); vertical-align: -1px'])<span class="el-sr">{{ __('Pinned:') }} </span>@endif
                                    {{ $item->text }}
                                    <span class="el-caption" style="display: block">
                                        {{ $item->happened_on !== null ? \App\Filament\Support\ConsoleDates::date($item->happened_on) : '' }}
                                        @if ($item->source_type === 'call' && $item->source_id)
                                            · <a class="el-note-at" href="{{ $recordingUrl($item->source_id, $item->at_ms) }}">{{ $item->at_ms !== null ? __('From the call at :time', ['time' => \App\Services\ClientAi\ClientWorkspace::clock($item->at_ms)]) : __('From the call') }}</a>
                                        @elseif ($item->source_type === 'user')
                                            · {{ __('Added by you') }}
                                        @endif
                                    </span>
                                </div>
                                <span class="el-memory-tools">
                                    <button type="button" class="el-icon-btn" wire:click="togglePinMemory('{{ $item->id }}')" aria-pressed="{{ $item->pinned ? 'true' : 'false' }}"
                                        aria-label="{{ $item->pinned ? __('Unpin') : __('Pin') }}" title="{{ $item->pinned ? __('Unpin') : __('Pin') }}">@svg($item->pinned ? 'heroicon-s-star' : 'heroicon-o-star', ['aria-hidden' => 'true'])</button>
                                    <button type="button" class="el-icon-btn" wire:click="removeMemory('{{ $item->id }}')" wire:confirm="{{ __('Remove this line from the memory?') }}"
                                        aria-label="{{ __('Remove') }}" title="{{ __('Remove') }}">@svg('heroicon-o-trash', ['aria-hidden' => 'true'])</button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
            @unless ($any)
                <p class="el-muted" style="font-size: 15px">{{ __('Decisions, open questions, changes and scope from the calls collect here.') }}</p>
            @endunless
            <form wire:submit="addMemory" style="display: flex; flex-wrap: wrap; gap: var(--space-2); margin-top: var(--space-4)">
                <label class="el-sr" for="memory-kind">{{ __('Kind') }}</label>
                <select id="memory-kind" class="el-field-input" style="width: auto" wire:model="memoryKind">
                    @foreach (['decision' => 'Decision', 'question' => 'Open question', 'change' => 'Change', 'scope' => 'Scope'] as $value => $label)
                        <option value="{{ $value }}">{{ __($label) }}</option>
                    @endforeach
                </select>
                <label class="el-sr" for="memory-text">{{ __('Line to remember') }}</label>
                <input id="memory-text" type="text" class="el-field-input" style="flex: 1; min-width: 12rem" wire:model="memoryText" maxlength="500" placeholder="{{ __('Add a line to remember') }}">
                <button type="submit" class="el-pill">{{ __('Add') }}</button>
                @error('memoryText')<p class="el-caption" style="width: 100%; color: var(--danger)">{{ $message }}</p>@enderror
            </form>
        </section>

        {{-- The latest call notes --}}
        <section class="el-card" aria-labelledby="notes-heading">
            <div class="el-card-head"><h3 id="notes-heading" class="el-card-title">{{ __('Latest call notes') }}</h3></div>
            @forelse ($notes as $note)
                @php($session = $note->captureSession)
                <article class="el-note-block" wire:key="note-{{ $note->id }}">
                    <p style="display: flex; justify-content: space-between; gap: var(--space-3); margin-bottom: var(--space-2)">
                        <a class="el-row-title" style="font-size: 15px" href="{{ $recordingUrl($note->capture_session_id) }}">{{ $session?->displayTitle() ?? __('Recording') }}</a>
                        <time class="el-row-time">{{ $session !== null ? \App\Filament\Support\ConsoleDates::day($session->started_at->copy()->setTimezone($zone)) : '' }}</time>
                    </p>
                    @if ($note->status === 'pending')
                        <span class="el-status el-status-brand el-status-spin">@svg('heroicon-o-arrow-path', ['aria-hidden' => 'true']) {{ __('Writing the notes') }}</span>
                    @elseif ($note->status !== 'ready')
                        <p class="el-caption">{{ $note->errorForPeople() ?? __('No notes for this call.') }}</p>
                    @else
                        <div class="el-notes">
                            @foreach ($note->toApi()['sections'] as $section)
                                <div>
                                    <h4>{{ __($section['heading']) }}</h4>
                                    <ul>
                                        @foreach ($section['items'] as $item)
                                            <li class="el-note-item"><span>
                                                @if (isset($item['kind']))<span class="el-faint">{{ __(ucfirst((string) $item['kind']).':') }}</span> @endif{{ $item['text'] }}
                                                @if ($item['verify'] ?? false) <span class="el-verify" title="{{ __('Check this against the recording') }}">{{ __('verify') }}</span>@endif
                                                <a class="el-note-at" href="{{ $recordingUrl($note->capture_session_id, (int) ($item['at_ms'] ?? 0)) }}">{{ \App\Services\ClientAi\ClientWorkspace::clock((int) ($item['at_ms'] ?? 0)) }}</a>
                                            </span></li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </article>
            @empty
                <p class="el-muted" style="font-size: 15px">{{ $client->ai_notes ? __('Notes are written after each call filed under :name.', ['name' => $iso($client->name)]) : __('Notes are written after each call filed under :name once AI notes are on.', ['name' => $iso($client->name)]) }}</p>
            @endforelse
        </section>
    </div>
</div>
