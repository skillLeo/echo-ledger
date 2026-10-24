@php
    $data = $entry->getPlayerData();
    $segments = $data['segments'];
    $starts = array_map(fn (array $s): float => ((int) ($s['start_ms'] ?? 0)) / 1000, $segments);
@endphp
<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <div class="el-player" wire:ignore x-data="{
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
                if (this.active >= 0 && rows[this.active]) rows[this.active].removeAttribute('data-active');
                if (i >= 0 && rows[i]) { rows[i].setAttribute('data-active', ''); rows[i].scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
                this.active = i;
            },
            seek(i) { const a = this.$refs.audio; if (!a) return; a.currentTime = this.starts[i]; a.play(); },
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
            .el-player { border: 1px solid rgba(255,255,255,.08); border-radius: 1rem; padding: 1rem;
                background: linear-gradient(135deg, rgba(139,92,246,.10), rgba(34,211,238,.06)); }
            .el-player audio { width: 100%; margin-bottom: .75rem; border-radius: .75rem; }
            .el-player .el-meta { display: flex; gap: .5rem; align-items: center; font-size: .75rem; opacity: .7; margin-bottom: .5rem; }
            .el-player ol { max-height: 30rem; overflow-y: auto; list-style: none; padding: 0; margin: 0; }
            .el-player li { display: grid; grid-template-columns: 4.5rem 5.5rem 1fr; gap: .5rem; cursor: pointer;
                padding: .45rem .6rem; border-radius: .6rem; line-height: 1.45; transition: background .15s; }
            .el-player li:hover { background: rgba(255,255,255,.04); }
            .el-player li[data-active] { background: rgba(139,92,246,.22); box-shadow: inset 3px 0 0 #8B5CF6; }
            .el-player .el-time { font-variant-numeric: tabular-nums; opacity: .55; font-size: .8rem; padding-top: .1rem; }
            .el-player .el-spk { font-weight: 600; font-size: .8rem; padding-top: .1rem; }
            .el-player .el-spk-1 { color: #A78BFA; } .el-player .el-spk-2 { color: #22D3EE; }
            .el-player .el-empty { opacity: .6; font-size: .9rem; padding: .75rem 0; }
            .el-player .el-actions { margin-left: auto; display: flex; gap: .4rem; }
            .el-player .el-btn { font-size: .75rem; padding: .3rem .7rem; border-radius: .5rem; border: 1px solid rgba(255,255,255,.14);
                background: rgba(255,255,255,.04); color: inherit; cursor: pointer; transition: background .15s; }
            .el-player .el-btn:hover { background: rgba(139,92,246,.22); }
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
            @endif
        </div>

        @if (count($segments) === 0)
            <div class="el-empty">No transcript yet.</div>
            <ol x-ref="list"></ol>
        @else
            <ol x-ref="list">
                @foreach ($segments as $i => $s)
                    @php $sec = intdiv((int) ($s['start_ms'] ?? 0), 1000); @endphp
                    <li x-on:click="seek({{ $i }})">
                        <span class="el-time">{{ $sec >= 3600 ? gmdate('G:i:s', $sec) : gmdate('i:s', $sec) }}</span>
                        <span class="el-spk el-spk-{{ (int) ($s['channel'] ?? 1) === 2 ? 2 : 1 }}">{{ $s['speaker'] ?? 'Speaker' }}</span>
                        <span>{{ $s['text'] ?? '' }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</x-dynamic-component>
