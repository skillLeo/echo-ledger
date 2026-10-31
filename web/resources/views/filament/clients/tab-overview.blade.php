@php
    $sections = \App\Models\ClientBrief::clean($brief->sections);
    $briefEmpty = $sections['goal'] === '' && collect(array_slice(\App\Models\ClientBrief::SECTIONS, 1))->every(fn ($k) => $sections[$k] === []);
    $recordingUrl = fn (string $session, ?int $ms = null): string => \App\Filament\Resources\CaptureSessions\CaptureSessionResource::getUrl('view', ['record' => $session]).($ms !== null ? '?t='.$ms : '');
    $percent = $budget['budget_usd'] > 0 ? min(100, round($budget['spent_usd'] / $budget['budget_usd'] * 100)) : 100;
@endphp
<div class="el-workspace-grid">
    <div style="display: flex; flex-direction: column; gap: var(--space-5); min-width: 0">
        {{-- AI notes, the month's budget and Copy for AI --}}
        <section class="el-card" aria-labelledby="ai-heading">
            <div class="el-setting-row">
                <div>
                    <h3 id="ai-heading" class="el-setting-title">AI notes</h3>
                    <p class="el-muted" style="font-size: 14px">Notes after every call, the memory and suggested fixes. Off: no Claude request for this client.</p>
                </div>
                <button type="button" class="el-switch" role="switch" aria-checked="{{ $client->ai_notes ? 'true' : 'false' }}" aria-labelledby="ai-heading" wire:click="toggleAiNotes"></button>
            </div>
            <div class="el-budget" style="margin-top: var(--space-4)">
                <p class="el-meta" data-ai-budget>AI this month: ${{ number_format($budget['spent_usd'], 2) }} of ${{ number_format($budget['budget_usd'], 2) }} · this client ${{ number_format($clientSpend, 2) }}</p>
                <div class="el-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="AI budget used this month"><span style="width: {{ $percent }}%"></span></div>
                @unless ($configured)
                    <p class="el-caption">Add your Anthropic key in <a class="el-link" href="{{ $credentialsUrl }}">API Credentials</a> to use client notes and Ask.</p>
                @endunless
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: var(--space-2); margin-top: var(--space-4)" x-data="{ copied: null }">
                <button type="button" class="el-pill" x-on:click="try { await navigator.clipboard.writeText(await $wire.bundle()); copied = 'ok' } catch (e) { copied = 'failed' } setTimeout(() => copied = null, 2000)">
                    @svg('heroicon-o-clipboard-document', ['aria-hidden' => 'true'])
                    <span x-text="copied === 'ok' ? 'Copied' : (copied === 'failed' ? 'Copy failed' : 'Copy for AI')">Copy for AI</span>
                </button>
                <span class="el-caption" style="align-self: center">The brief, the memory and the latest call notes, to paste into a chat.</span>
                <span class="el-sr" role="status" aria-live="polite" x-text="copied === 'ok' ? 'Copied to the clipboard' : (copied === 'failed' ? 'Copy failed' : '')"></span>
            </div>
        </section>

        {{-- The brief --}}
        <section class="el-card" aria-labelledby="brief-heading">
            <div class="el-card-head">
                <h3 id="brief-heading" class="el-card-title">Brief
                    @if ($brief->edited)<span class="el-status" style="margin-inline-start: var(--space-2)">Edited</span>@endif
                </h3>
                <div style="display: flex; gap: var(--space-1)">
                    <button type="button" class="el-text-btn" wire:click="mountAction('editBrief')">@svg('heroicon-o-pencil-square', ['aria-hidden' => 'true']) Edit</button>
                    <button type="button" class="el-text-btn" wire:click="remakeBrief" @disabled($brief->status === 'working')>@svg('heroicon-o-arrow-path', ['aria-hidden' => 'true']) Make again</button>
                </div>
            </div>
            @if ($brief->status === 'working')
                <div style="display: flex; flex-direction: column; gap: var(--space-2)" aria-hidden="true">
                    <span class="el-skeleton" style="height: 14px; width: 70%"></span>
                    <span class="el-skeleton" style="height: 14px; width: 90%"></span>
                    <span class="el-skeleton" style="height: 14px; width: 55%"></span>
                </div>
                <p class="el-caption" role="status" style="margin-top: var(--space-2)">Making the brief. This updates by itself.</p>
            @else
                @if ($brief->status === 'failed')
                    <div class="el-banner el-banner-danger" style="margin-bottom: var(--space-3)">@svg('heroicon-o-exclamation-circle', ['aria-hidden' => 'true'])<span class="el-banner-body">{{ $brief->error ?? 'The brief could not be made.' }}</span></div>
                @endif
                @if ($briefEmpty)
                    <p class="el-muted" style="font-size: 15px">No brief yet. Add documents or instructions on Files, or it is written from the call notes.</p>
                @else
                    <dl class="el-brief">
                        @if ($sections['goal'] !== '')
                            <dt>Goal</dt><dd>{{ $sections['goal'] }}</dd>
                        @endif
                        @foreach (array_slice(\App\Models\ClientBrief::SECTIONS, 1) as $key)
                            @if ($sections[$key] !== [])
                                <dt>{{ ucfirst($key) }}</dt>
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
            <div class="el-card-head"><h3 id="memory-heading" class="el-card-title">Memory</h3></div>
            @php($any = $memory->isNotEmpty())
            @foreach (\App\Filament\Resources\Clients\Pages\ViewClient::MEMORY_GROUPS as $kind => $heading)
                @if ($memory->has($kind))
                    <h4 class="el-section-header">{{ $heading }}</h4>
                    <ul>
                        @foreach ($memory[$kind] as $item)
                            <li class="el-memory-item" wire:key="memory-{{ $item->id }}">
                                <div class="el-memory-text">
                                    @if ($item->pinned)@svg('heroicon-s-star', ['aria-hidden' => 'true', 'style' => 'display: inline; width: 14px; height: 14px; color: var(--warning-fill); vertical-align: -1px'])<span class="el-sr">Pinned: </span>@endif
                                    {{ $item->text }}
                                    <span class="el-caption" style="display: block">
                                        {{ $item->happened_on?->format('M j, Y') }}
                                        @if ($item->source_type === 'call' && $item->source_id)
                                            · <a class="el-note-at" href="{{ $recordingUrl($item->source_id, $item->at_ms) }}">From the call{{ $item->at_ms !== null ? ' at '.\App\Services\ClientAi\ClientWorkspace::clock($item->at_ms) : '' }}</a>
                                        @elseif ($item->source_type === 'user')
                                            · Added by you
                                        @endif
                                    </span>
                                </div>
                                <span class="el-memory-tools">
                                    <button type="button" class="el-icon-btn" wire:click="togglePinMemory('{{ $item->id }}')" aria-pressed="{{ $item->pinned ? 'true' : 'false' }}"
                                        aria-label="{{ $item->pinned ? 'Unpin' : 'Pin' }}" title="{{ $item->pinned ? 'Unpin' : 'Pin' }}">@svg($item->pinned ? 'heroicon-s-star' : 'heroicon-o-star', ['aria-hidden' => 'true'])</button>
                                    <button type="button" class="el-icon-btn" wire:click="removeMemory('{{ $item->id }}')" wire:confirm="Remove this line from the memory?"
                                        aria-label="Remove" title="Remove">@svg('heroicon-o-trash', ['aria-hidden' => 'true'])</button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
            @unless ($any)
                <p class="el-muted" style="font-size: 15px">Decisions, open questions, changes and scope from the calls collect here.</p>
            @endunless
            <form wire:submit="addMemory" style="display: flex; flex-wrap: wrap; gap: var(--space-2); margin-top: var(--space-4)">
                <label class="el-sr" for="memory-kind">Kind</label>
                <select id="memory-kind" class="el-field-input" style="width: auto" wire:model="memoryKind">
                    @foreach (['decision' => 'Decision', 'question' => 'Open question', 'change' => 'Change', 'scope' => 'Scope'] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <label class="el-sr" for="memory-text">Line to remember</label>
                <input id="memory-text" type="text" class="el-field-input" style="flex: 1; min-width: 12rem" wire:model="memoryText" maxlength="500" placeholder="Add a line to remember">
                <button type="submit" class="el-pill">Add</button>
                @error('memoryText')<p class="el-caption" style="width: 100%; color: var(--danger)">{{ $message }}</p>@enderror
            </form>
        </section>

        {{-- The latest call notes --}}
        <section class="el-card" aria-labelledby="notes-heading">
            <div class="el-card-head"><h3 id="notes-heading" class="el-card-title">Latest call notes</h3></div>
            @forelse ($notes as $note)
                @php($session = $note->captureSession)
                <article class="el-note-block" wire:key="note-{{ $note->id }}">
                    <p style="display: flex; justify-content: space-between; gap: var(--space-3); margin-bottom: var(--space-2)">
                        <a class="el-row-title" style="font-size: 15px" href="{{ $recordingUrl($note->capture_session_id) }}">{{ $session?->displayTitle() ?? 'Recording' }}</a>
                        <time class="el-row-time">{{ $session?->started_at->copy()->setTimezone($zone)->format('M j') }}</time>
                    </p>
                    @if ($note->status === 'pending')
                        <span class="el-status el-status-brand el-status-spin">@svg('heroicon-o-arrow-path', ['aria-hidden' => 'true']) Writing the notes</span>
                    @elseif ($note->status !== 'ready')
                        <p class="el-caption">{{ $note->error ?? 'No notes for this call.' }}</p>
                    @else
                        <div class="el-notes">
                            @foreach ($note->toApi()['sections'] as $section)
                                <div>
                                    <h4>{{ $section['heading'] }}</h4>
                                    <ul>
                                        @foreach ($section['items'] as $item)
                                            <li class="el-note-item"><span>
                                                @if (isset($item['kind']))<span class="el-faint">{{ ucfirst((string) $item['kind']) }}:</span> @endif{{ $item['text'] }}
                                                @if ($item['verify'] ?? false) <span class="el-verify" title="Check this against the recording">verify</span>@endif
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
                <p class="el-muted" style="font-size: 15px">Notes are written after each call filed under {{ $client->name }}{{ $client->ai_notes ? '' : ' once AI notes are on' }}.</p>
            @endforelse
        </section>
    </div>
</div>
