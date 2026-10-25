@php
    $data = $entry->getPlayerData();
    $segments = $data['segments'];
    $markers = $data['markers'];
    $pauses = $data['pauses'];
    $durationMs = max(1, (int) ($data['duration_ms'] ?? 0));
    $clock = function (int $ms): string {
        $sec = intdiv(max(0, $ms), 1000);

        return $sec >= 3600 ? sprintf('%d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60) : sprintf('%d:%02d', intdiv($sec, 60), $sec % 60);
    };
    $starts = array_map(fn (array $s): float => ((int) ($s['start_ms'] ?? 0)) / 1000, $segments);
    $stamp = function (array $s): string {
        $sec = intdiv(max(0, (int) ($s['start_ms'] ?? 0)), 1000);

        return $sec >= 3600 ? sprintf('%d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60) : sprintf('%02d:%02d', intdiv($sec, 60), $sec % 60);
    };
@endphp
<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <div class="el-player" wire:ignore tabindex="0" role="group" aria-label="Transcript player. Press Space to play or pause."
        x-on:keydown="onKey($event)" x-data="{
            starts: @js($starts), active: -1, raf: null,
            init() {
                const a = this.$refs.audio;
                if (!a) return;
                a.addEventListener('play', () => this.tick());
                ['pause', 'ended'].forEach(e => a.addEventListener(e, () => cancelAnimationFrame(this.raf)));
                a.addEventListener('seeked', () => this.sync());
            },
            tick() { this.sync(); this.raf = requestAnimationFrame(() => this.tick()); },
            sync() {
                const t = this.$refs.audio.currentTime; let lo = 0, hi = this.starts.length - 1, i = -1;
                while (lo <= hi) { const m = (lo + hi) >> 1; if (this.starts[m] <= t) { i = m; lo = m + 1; } else { hi = m - 1; } }
                if (i === this.active) return;
                const rows = this.$refs.list.children;
                if (this.active >= 0 && rows[this.active]) { rows[this.active].removeAttribute('data-active'); rows[this.active].removeAttribute('aria-current'); }
                if (i >= 0 && rows[i]) { rows[i].setAttribute('data-active', ''); rows[i].setAttribute('aria-current', 'true'); rows[i].scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
                this.active = i;
            },
            seek(i) { const a = this.$refs.audio; if (!a) return; a.currentTime = this.starts[i]; a.play(); },
            seekTo(ms) { const a = this.$refs.audio; if (!a) return; a.currentTime = ms / 1000; a.play(); },
            toggle() { const a = this.$refs.audio; if (!a) return; if (a.paused) { a.play(); } else { a.pause(); } },
            // Space toggles playback while the player itself has focus; on a line or a button Space keeps its native
            // meaning (a line seeks, a button presses), and the audio element handles its own keys.
            onKey(e) {
                if (e.key !== ' ' && e.code !== 'Space') return;
                const tag = (e.target && e.target.tagName) || '';
                if (['BUTTON', 'AUDIO', 'INPUT', 'TEXTAREA', 'SELECT', 'A'].includes(tag)) return;
                e.preventDefault();
                this.toggle();
            },
            segments: @js(array_map(fn (array $s): array => ['speaker' => $s['speaker'] ?? 'Speaker', 'start_ms' => (int) ($s['start_ms'] ?? 0), 'text' => $s['text'] ?? ''], $segments)),
            copied: null,
            // Same text format as the desktop and Android apps: '[m:ss] Speaker: text', speaker omitted when there is only one.
            transcriptText(withTimes) {
                const single = new Set(this.segments.map(s => s.speaker)).size <= 1;
                const stamp = ms => { const t = Math.floor(ms / 1000), h = Math.floor(t / 3600), m = Math.floor((t % 3600) / 60), s = t % 60;
                    return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0'); };
                return this.segments.map(s => (withTimes ? '[' + stamp(s.start_ms) + '] ' : '') + (single ? '' : s.speaker + ': ') + s.text).join('\n');
            },
            async copy(withTimes) {
                try { await navigator.clipboard.writeText(this.transcriptText(withTimes)); this.copied = withTimes ? 'times' : 'text'; }
                catch (e) { this.copied = 'failed'; }
                setTimeout(() => this.copied = null, 2000);
            },
        }">
        <style>
            /* Light theme first; .dark (set by Filament on <html>) swaps the palette. Text colours meet WCAG AA (4.5:1). */
            .el-player { --el-border: #E2E8F0; --el-bg: linear-gradient(135deg, rgba(139,92,246,.06), rgba(34,211,238,.04)), #FFFFFF;
                --el-text: #0F172A; --el-muted: #475569; --el-me: #6D28D9; --el-client: #155E75; --el-hover: rgba(15,23,42,.05);
                --el-active: rgba(139,92,246,.14); --el-bar: #7C3AED; --el-focus: #7C3AED; --el-btn-border: #CBD5E1;
                --el-btn-bg: #FFFFFF; --el-btn-hover: rgba(139,92,246,.12);
                color: var(--el-text); border: 1px solid var(--el-border); border-radius: 1rem; padding: 1rem; background: var(--el-bg); }
            .dark .el-player { --el-border: rgba(255,255,255,.10); --el-bg: linear-gradient(135deg, rgba(139,92,246,.12), rgba(34,211,238,.06));
                --el-text: #F1F5F9; --el-muted: #CBD5E1; --el-me: #C4B5FD; --el-client: #67E8F9; --el-hover: rgba(255,255,255,.06);
                --el-active: rgba(139,92,246,.30); --el-bar: #A78BFA; --el-focus: #C4B5FD; --el-btn-border: rgba(255,255,255,.22);
                --el-btn-bg: rgba(255,255,255,.04); --el-btn-hover: rgba(139,92,246,.28); }
            .el-player:focus-visible, .el-player .el-seg:focus-visible, .el-player .el-btn:focus-visible, .el-player audio:focus-visible {
                outline: 2px solid var(--el-focus); outline-offset: 2px; }
            .el-player audio { width: 100%; margin-bottom: .75rem; border-radius: .75rem; }
            .el-player .el-meta { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; font-size: .75rem; color: var(--el-muted); margin-bottom: .5rem; }
            .el-player ol { max-height: 30rem; overflow-y: auto; list-style: none; padding: 2px; margin: 0; }
            .el-player li { border-radius: .6rem; }
            .el-player li[data-active] { background: var(--el-active); box-shadow: inset 3px 0 0 var(--el-bar); }
            .el-player .el-seg { display: grid; grid-template-columns: 4.5rem 5.5rem minmax(0, 1fr); gap: .5rem; width: 100%;
                padding: .45rem .6rem; border: 0; border-radius: .6rem; background: transparent; color: inherit; font: inherit;
                text-align: left; line-height: 1.45; cursor: pointer; transition: background .15s; }
            .el-player .el-seg:hover { background: var(--el-hover); }
            .el-player .el-time { font-variant-numeric: tabular-nums; color: var(--el-muted); font-size: .8rem; padding-top: .1rem; }
            .el-player .el-spk { font-weight: 600; font-size: .8rem; padding-top: .1rem; }
            .el-player .el-spk-1 { color: var(--el-me); } .el-player .el-spk-2 { color: var(--el-client); }
            .el-player .el-text { overflow-wrap: anywhere; }
            .el-player .el-empty { color: var(--el-muted); font-size: .9rem; padding: .75rem 0; }
            .el-player .el-actions { margin-left: auto; display: flex; flex-wrap: wrap; gap: .4rem; }
            .el-player .el-btn { font-size: .75rem; padding: .3rem .7rem; border-radius: .5rem; border: 1px solid var(--el-btn-border);
                background: var(--el-btn-bg); color: var(--el-text); cursor: pointer; transition: background .15s; }
            .el-player .el-btn:hover { background: var(--el-btn-hover); }
            .el-player .el-sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden;
                clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
            .el-player .el-marks { margin: 0 0 .75rem; }
            .el-player .el-bar { position: relative; height: 6px; border-radius: 3px; background: var(--el-hover); margin-bottom: .5rem; }
            .el-player .el-tick { position: absolute; top: -3px; width: 3px; height: 12px; border-radius: 2px; background: var(--el-bar); transform: translateX(-50%); }
            .el-player .el-tick-pause { background: var(--el-muted); opacity: .6; }
            .el-player .el-chips { display: flex; flex-wrap: wrap; gap: .4rem; }
            .el-player .el-chip { display: inline-flex; gap: .4rem; align-items: baseline; font-size: .75rem; padding: .25rem .6rem;
                border-radius: 999px; border: 1px solid var(--el-btn-border); background: var(--el-btn-bg); color: var(--el-text); cursor: pointer; }
            .el-player .el-chip:hover { background: var(--el-btn-hover); }
            .el-player .el-chip:focus-visible { outline: 2px solid var(--el-focus); outline-offset: 2px; }
            .el-player .el-chip-pause { cursor: default; color: var(--el-muted); }
            .el-player .el-note { color: var(--el-muted); }
            /* Phones: time and speaker on one line, the text below at full width. */
            @media (max-width: 640px) {
                .el-player { padding: .75rem; }
                .el-player .el-seg { grid-template-columns: auto minmax(0, 1fr); row-gap: .15rem; }
                .el-player .el-seg .el-text { grid-column: 1 / -1; }
                .el-player .el-actions { margin-left: 0; }
            }
        </style>

        @if ($data['audio_url'])
            <audio x-ref="audio" controls preload="metadata" src="{{ $data['audio_url'] }}"></audio>
        @else
            <div class="el-empty">Audio is not available (still uploading, deleted, or retention expired).</div>
        @endif

        <div class="el-meta">
            <span>{{ $data['kind'] === 'live' ? 'Live captions (final transcript pending)' : 'Final transcript' }}</span>
            @if ($data['status'] && $data['status'] !== 'completed') <span>· {{ str_replace('_', ' ', $data['status']) }}</span> @endif
            @if (count($segments) > 0)
                <span class="el-actions">
                    <button type="button" class="el-btn" x-on:click="copy(false)" x-text="copied === 'text' ? 'Copied' : 'Copy text'"></button>
                    <button type="button" class="el-btn" x-on:click="copy(true)" x-text="copied === 'times' ? 'Copied' : (copied === 'failed' ? 'Copy failed' : 'Copy with timestamps')"></button>
                </span>
                <span class="el-sr" role="status" aria-live="polite" x-text="copied === 'failed' ? 'Copy failed' : (copied ? 'Copied to the clipboard' : '')"></span>
            @endif
        </div>

        @if (count($markers) > 0 || count($pauses) > 0)
            <div class="el-marks" aria-label="Bookmarks and pauses">
                <div class="el-bar" aria-hidden="true">
                    @foreach ($pauses as $p)
                        <span class="el-tick el-tick-pause" style="left: {{ min(100, round($p['at_ms'] / $durationMs * 100, 2)) }}%"></span>
                    @endforeach
                    @foreach ($markers as $m)
                        <span class="el-tick" style="left: {{ min(100, round($m['at_ms'] / $durationMs * 100, 2)) }}%"></span>
                    @endforeach
                </div>
                <div class="el-chips">
                    @foreach ($markers as $m)
                        <button type="button" class="el-chip" x-on:click="seekTo({{ $m['at_ms'] }})" aria-label="Play from the bookmark at {{ $clock($m['at_ms']) }}">
                            <span class="el-time">{{ $clock($m['at_ms']) }}</span>
                            <span>{{ $m['tag'] ?? 'Bookmark' }}</span>
                            @if ($m['note']) <span class="el-note">{{ $m['note'] }}</span> @endif
                        </button>
                    @endforeach
                    @foreach ($pauses as $p)
                        <span class="el-chip el-chip-pause">Paused at {{ $clock($p['at_ms']) }} for {{ $clock($p['paused_ms']) }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        @if (count($segments) === 0)
            <div class="el-empty">No transcript yet.</div>
            <ol x-ref="list"></ol>
        @else
            <ol x-ref="list" aria-label="Transcript lines. Choose a line to play from there.">
                @foreach ($segments as $i => $s)
                    <li>
                        <button type="button" class="el-seg" x-on:click="seek({{ $i }})">
                            <span class="el-time">{{ $stamp($s) }}</span>
                            <span class="el-spk el-spk-{{ (int) ($s['channel'] ?? 1) === 2 ? 2 : 1 }}">{{ $s['speaker'] ?? 'Speaker' }}</span>
                            <span class="el-text">{{ $s['text'] ?? '' }}</span>
                        </button>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</x-dynamic-component>
