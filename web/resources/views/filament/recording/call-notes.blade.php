{{--
    Call notes of a client's recording (v1.4): only the sections with something in them, every line opening the
    recording at its moment, unsure ones marked "verify". "Write notes" (again) in English or Roman Urdu.
--}}
@php
    $note = $this->callNote();
    $client = $getRecord()->client;
    $sections = $note?->status === 'ready' ? $note->toApi()['sections'] : [];
@endphp
<section class="el-card" aria-labelledby="call-notes-heading" x-data="{ lang: @js($note?->language ?? 'en') }">
    <div class="el-card-head">
        <h3 id="call-notes-heading" class="el-card-title">Call notes</h3>
        @if ($note?->status === 'pending')
            <span class="el-status el-status-brand el-status-spin">@svg('heroicon-o-arrow-path', ['aria-hidden' => 'true']) Writing</span>
        @endif
    </div>

    @if ($note === null)
        <p class="el-muted" style="font-size: 14px">Short notes of this call for {{ $client?->name }}: what was asked for, decisions, changes, what to confirm and your action items.</p>
    @elseif ($note->status === 'pending')
        <div style="display: flex; flex-direction: column; gap: var(--space-2)" aria-hidden="true">
            <span class="el-skeleton" style="height: 12px; width: 40%"></span>
            <span class="el-skeleton" style="height: 14px; width: 92%"></span>
            <span class="el-skeleton" style="height: 14px; width: 76%"></span>
        </div>
        <p class="el-sr" role="status">Writing the notes. This updates by itself.</p>
    @elseif (in_array($note->status, ['failed', 'skipped'], true))
        <p class="el-muted" style="font-size: 14px">{{ $note->error ?? 'The notes could not be written.' }}</p>
    @elseif ($sections === [])
        <p class="el-muted" style="font-size: 14px">Nothing to note in this call.</p>
    @else
        <div class="el-notes">
            @foreach ($sections as $section)
                <div>
                    <h4>{{ $section['heading'] }}</h4>
                    <ul>
                        @foreach ($section['items'] as $item)
                            <li class="el-note-item">
                                <span>
                                    @if (isset($item['kind']))<span class="el-faint">{{ ucfirst((string) $item['kind']) }}:</span> @endif{{ $item['text'] }}
                                    @if ($item['verify'] ?? false) <span class="el-verify" title="Check this against the recording">verify</span>@endif
                                    <a class="el-note-at" href="?t={{ (int) ($item['at_ms'] ?? 0) }}"
                                        x-on:click.prevent="$dispatch('el-seek', { ms: {{ (int) ($item['at_ms'] ?? 0) }} })"
                                        aria-label="Play from {{ \App\Services\ClientAi\ClientWorkspace::clock((int) ($item['at_ms'] ?? 0)) }}">{{ \App\Services\ClientAi\ClientWorkspace::clock((int) ($item['at_ms'] ?? 0)) }}</a>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif

    @if ($note?->status !== 'pending')
        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin-top: var(--space-4)">
            <span class="el-seg" role="group" aria-label="Language of the notes">
                <button type="button" x-on:click="lang = 'en'" :aria-pressed="lang === 'en'">English</button>
                <button type="button" x-on:click="lang = 'roman_urdu'" :aria-pressed="lang === 'roman_urdu'">Roman Urdu</button>
            </span>
            <button type="button" class="el-pill el-pill-sm" x-on:click="$wire.writeNotes(lang)" wire:loading.attr="disabled" wire:target="writeNotes">
                @svg('heroicon-o-sparkles', ['aria-hidden' => 'true']) {{ $note === null ? 'Write notes' : 'Write again' }}
            </button>
        </div>
    @endif
</section>
