@php
    $data = $entry->getPlayerData();
@endphp
<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <div class="el-player" wire:ignore tabindex="0" role="group"
        aria-label="Transcript player. Space or K plays and pauses, J and L jump 10 seconds, the arrow keys 5 seconds."
        x-on:keydown="onKey($event)"
        x-on:el-transcript-updated.window="load($event.detail)"
        x-data="{
            d: @js($data), active: -1, word: -1, raf: null, playing: false, now: 0, rate: 1, skipSilence: false, follow: true,
            editing: false, saving: null, error: null, copied: null,
            init() {
                const a = this.$refs.audio;
                if (!a) return;
                a.addEventListener('play', () => { this.playing = true; this.tick(); });
                ['pause', 'ended'].forEach(e => a.addEventListener(e, () => { this.playing = false; cancelAnimationFrame(this.raf); this.sync(); }));
                a.addEventListener('seeked', () => this.sync());
                a.addEventListener('loadedmetadata', () => {
                    const t = Number(new URLSearchParams(window.location.search).get('t'));
                    if (Number.isFinite(t) && t > 0) { a.currentTime = t / 1000; this.sync(); }
                });
            },
            load(next) { this.d = next; this.active = -1; this.word = -1; this.$nextTick(() => this.sync()); },
            name(s) { return this.d.speaker_names[s.speaker] || s.speaker; },
            clock(ms) { const t = Math.max(0, Math.floor(ms / 1000)), h = Math.floor(t / 3600), m = Math.floor((t % 3600) / 60), s = t % 60;
                return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0'); },
            pct(ms) { return Math.min(100, Math.max(0, ms / this.d.duration_ms * 100)) + '%'; },
            // Consecutive lines of the same speaker form one block.
            blocks() {
                const out = [];
                this.d.segments.forEach((s, i) => {
                    const last = out[out.length - 1];
                    if (last && last.speaker === s.speaker) { last.items.push(i); } else { out.push({ speaker: s.speaker, channel: s.channel, start: s.start_ms, items: [i] }); }
                });
                return out;
            },
            tick() {
                this.sync();
                const a = this.$refs.audio;
                if (this.skipSilence && a && !a.paused) {
                    const t = a.currentTime * 1000, next = this.d.segments.find(s => s.start_ms > t);
                    const inSpeech = this.d.segments.some(s => s.start_ms <= t && s.end_ms >= t);
                    if (!inSpeech && next && next.start_ms - t > 1500) a.currentTime = (next.start_ms - 200) / 1000;
                }
                this.raf = requestAnimationFrame(() => this.tick());
            },
            sync() {
                const a = this.$refs.audio; if (!a) return;
                const t = a.currentTime * 1000; this.now = t;
                let lo = 0, hi = this.d.segments.length - 1, i = -1;
                while (lo <= hi) { const m = (lo + hi) >> 1; if (this.d.segments[m].start_ms <= t) { i = m; lo = m + 1; } else { hi = m - 1; } }
                let w = -1;
                if (i >= 0) { const words = this.d.segments[i].words; for (let k = 0; k < words.length; k++) { if (words[k].s <= t) w = k; else break; } }
                this.word = w;
                if (i === this.active) return;
                this.active = i;
                if (i >= 0 && this.follow && !this.editing) {
                    const row = this.$refs.list && this.$refs.list.querySelector('[data-seg=\'' + i + '\']');
                    if (row) row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            },
            seekTo(ms) { const a = this.$refs.audio; if (!a) return; a.currentTime = ms / 1000; a.play(); },
            jump(seconds) { const a = this.$refs.audio; if (!a) return; a.currentTime = Math.max(0, a.currentTime + seconds); this.sync(); },
            toggle() { const a = this.$refs.audio; if (!a) return; if (a.paused) { a.play(); } else { a.pause(); } },
            setRate(r) { this.rate = Number(r); if (this.$refs.audio) this.$refs.audio.playbackRate = this.rate; },
            onTimeline(e) {
                const box = e.currentTarget.getBoundingClientRect();
                this.seekTo(Math.max(0, (e.clientX - box.left) / box.width) * this.d.duration_ms);
            },
            onKey(e) {
                const tag = (e.target && e.target.tagName) || '';
                if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || (e.target && e.target.isContentEditable)) return;
                const onControl = ['BUTTON', 'AUDIO', 'A'].includes(tag);
                const key = e.key.toLowerCase();
                if ((key === ' ' && !onControl) || key === 'k') { e.preventDefault(); this.toggle(); }
                else if (key === 'j') { e.preventDefault(); this.jump(-10); }
                else if (key === 'l') { e.preventDefault(); this.jump(10); }
                else if (e.key === 'ArrowLeft' && !onControl) { e.preventDefault(); this.jump(-5); }
                else if (e.key === 'ArrowRight' && !onControl) { e.preventDefault(); this.jump(5); }
            },
            async save(i, text) {
                if (text.trim() === this.d.segments[i].text) return;
                this.saving = i; this.error = null;
                try {
                    const next = await $wire.saveSegment(i, text);
                    if (next && next.error) { this.error = next.error; } else if (next) { this.load(next); }
                } catch (e) { this.error = 'The change could not be saved. Try again.'; }
                this.saving = null;
            },
            // Same text format as the desktop and Android apps: '[m:ss] Speaker: text', speaker omitted when there is only one.
            transcriptText(withTimes) {
                const single = new Set(this.d.segments.map(s => s.speaker)).size <= 1;
                return this.d.segments.map(s => (withTimes ? '[' + this.clock(s.start_ms) + '] ' : '') + (single ? '' : this.name(s) + ': ') + s.text).join('\n');
            },
            async copy(withTimes) {
                try { await navigator.clipboard.writeText(this.transcriptText(withTimes)); this.copied = withTimes ? 'times' : 'text'; }
                catch (e) { this.copied = 'failed'; }
                setTimeout(() => this.copied = null, 2000);
            },
        }">
        <style>
            /* Light theme first; .dark (set by Filament on <html>) swaps the palette. The values are the shared design tokens
               (resources/css/filament/admin/theme.css, C1); text colours meet WCAG AA (4.5:1). */
            .el-player { --el-border: rgba(15,23,42,.10); --el-bg: #FFFFFF;
                --el-text: #15171B; --el-muted: #565B69; --el-me: #6D28D9; --el-client: #0B7187; --el-hover: rgba(15,23,42,.05);
                --el-active: rgba(124,58,237,.10); --el-bar: #7C3AED; --el-focus: #7C3AED; --el-btn-border: rgba(15,23,42,.18);
                --el-btn-bg: #FFFFFF; --el-btn-hover: rgba(139,92,246,.12); --el-word: rgba(250,204,21,.45); --el-unsure: #D97706; --el-lane-me: rgba(124,58,237,.55);
                --el-lane-client: rgba(14,116,144,.55);
                color: var(--el-text); border: 1px solid var(--el-border); border-radius: 12px; padding: 1rem; background: var(--el-bg); }
            .dark .el-player { --el-border: rgba(255,255,255,.08); --el-bg: #1A1B1F;
                --el-text: #F2F3F5; --el-muted: #A8ACB7; --el-me: #B4A0FB; --el-client: #4FD1E8; --el-hover: rgba(255,255,255,.06);
                --el-active: rgba(139,92,246,.22); --el-bar: #A78BFA; --el-focus: #B4A0FB; --el-btn-border: rgba(255,255,255,.15);
                --el-btn-bg: rgba(255,255,255,.04); --el-btn-hover: rgba(139,92,246,.28); --el-word: rgba(250,204,21,.35); --el-unsure: #FBBF24;
                --el-lane-me: rgba(167,139,250,.7); --el-lane-client: rgba(34,211,238,.6); }
            .el-player :focus-visible, .el-player:focus-visible { outline: 2px solid var(--el-focus); outline-offset: 2px; }
            .el-player audio { width: 100%; height: 40px; margin-bottom: .6rem; border-radius: 8px; }
            .el-player .el-tools { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; font-size: .78rem; color: var(--el-muted); margin-bottom: .6rem; }
            .el-player .el-tools label { display: inline-flex; gap: .35rem; align-items: center; cursor: pointer; }
            .el-player .el-tools select { font: inherit; border: 1px solid var(--el-btn-border); background: var(--el-btn-bg); color: var(--el-text); border-radius: .45rem; padding: .15rem .4rem; }
            .el-player .el-spacer { flex: 1; }
            .el-player .el-btn { font-size: .75rem; padding: .3rem .7rem; border-radius: .5rem; border: 1px solid var(--el-btn-border);
                background: var(--el-btn-bg); color: var(--el-text); cursor: pointer; transition: background .15s; }
            .el-player .el-btn:hover, .el-player .el-btn[aria-pressed=true] { background: var(--el-btn-hover); }
            .el-player .el-time { font-variant-numeric: tabular-nums; color: var(--el-muted); font-size: .8rem; }
            .el-player .el-timeline { position: relative; height: 34px; border-radius: .5rem; background: var(--el-hover); margin-bottom: .75rem; cursor: pointer; overflow: hidden; }
            .el-player .el-lane { position: absolute; left: 0; right: 0; height: 11px; }
            .el-player .el-lane-1 { top: 4px; } .el-player .el-lane-2 { top: 19px; }
            .el-player .el-talk { position: absolute; top: 0; height: 100%; border-radius: 3px; min-width: 2px; }
            .el-player .el-lane-1 .el-talk { background: var(--el-lane-me); } .el-player .el-lane-2 .el-talk { background: var(--el-lane-client); }
            .el-player .el-tick { position: absolute; top: 0; width: 3px; height: 100%; background: var(--el-bar); transform: translateX(-50%); }
            .el-player .el-tick-pause { background: var(--el-muted); opacity: .7; }
            .el-player .el-head { position: absolute; top: 0; width: 2px; height: 100%; background: var(--el-text); transform: translateX(-50%); pointer-events: none; }
            .el-player .el-chips { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: .75rem; }
            .el-player .el-chip { display: inline-flex; gap: .4rem; align-items: baseline; font-size: .75rem; padding: .25rem .6rem;
                border-radius: 999px; border: 1px solid var(--el-btn-border); background: var(--el-btn-bg); color: var(--el-text); cursor: pointer; }
            .el-player .el-chip:hover { background: var(--el-btn-hover); }
            .el-player .el-chip-pause { cursor: default; color: var(--el-muted); }
            .el-player .el-note { color: var(--el-muted); }
            .el-player .el-list { max-height: 34rem; overflow-y: auto; padding: 2px; margin: 0; }
            .el-player .el-block { padding: .45rem .5rem .55rem; border-radius: .6rem; }
            .el-player .el-block + .el-block { margin-top: .2rem; }
            .el-player .el-who { display: flex; gap: .5rem; align-items: baseline; margin-bottom: .15rem; }
            .el-player .el-spk { font-weight: 600; font-size: .82rem; }
            .el-player .el-spk-1 { color: var(--el-me); } .el-player .el-spk-2 { color: var(--el-client); }
            .el-player .el-line { display: block; width: 100%; text-align: start; border: 0; background: transparent; color: inherit; font: inherit;
                line-height: 1.55; padding: .1rem .35rem; border-radius: .4rem; cursor: pointer; overflow-wrap: anywhere; unicode-bidi: plaintext; }
            .el-player .el-line:hover { background: var(--el-hover); }
            .el-player .el-line[data-active] { background: var(--el-active); box-shadow: inset 3px 0 0 var(--el-bar); }
            .el-player .el-w[data-on] { background: var(--el-word); border-radius: 3px; }
            /* Words and lines the engine was not sure of: kept as heard, underlined, a click plays them. */
            .el-player .el-w[data-u], .el-player .el-line[data-u] > span { text-decoration: underline dotted var(--el-unsure); text-decoration-thickness: 2px; text-underline-offset: 4px; }
            .el-player .el-w[data-u] { cursor: help; }
            .el-player textarea.el-edit { width: 100%; font: inherit; line-height: 1.5; color: var(--el-text); background: var(--el-btn-bg);
                border: 1px solid var(--el-btn-border); border-radius: .45rem; padding: .3rem .45rem; resize: vertical; unicode-bidi: plaintext; }
            .el-player .el-empty { color: var(--el-muted); font-size: .9rem; padding: .75rem 0; }
            .el-player .el-error { color: #E11D48; font-size: .8rem; margin: 0 0 .5rem; }
            .el-player .el-sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
            @media (max-width: 640px) { .el-player { padding: .75rem; } .el-player .el-spacer { flex-basis: 100%; } }
            @media (prefers-reduced-motion: reduce) { .el-player .el-btn { transition: none; } }
        </style>

        @if ($data['audio_url'])
            <audio x-ref="audio" controls preload="metadata" src="{{ $data['audio_url'] }}"></audio>
        @else
            <div class="el-empty">Audio is not available (still uploading, deleted, or retention expired).</div>
        @endif

        <div class="el-tools">
            <span x-text="d.kind === 'live' ? 'Live captions (final transcript pending)' : 'Final transcript'"></span>
            <span x-show="d.status && d.status !== 'completed'" x-text="'· ' + (d.status || '').replace('_', ' ')"></span>
            <span x-show="d.model" x-text="'· ' + d.model"></span>
            <span x-text="'· ' + d.language"></span>
            <span x-show="d.edited">· edited</span>
            <span class="el-spacer"></span>
            @if ($data['audio_url'])
                <label>Speed
                    <select x-on:change="setRate($event.target.value)" aria-label="Playback speed">
                        <template x-for="r in [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2]" :key="r"><option :value="r" :selected="r === rate" x-text="r + '×'"></option></template>
                    </select>
                </label>
                <label><input type="checkbox" x-model="skipSilence"> Skip silence</label>
                <label><input type="checkbox" x-model="follow"> Follow</label>
            @endif
            <button type="button" class="el-btn" x-show="d.editable" x-on:click="editing = !editing" :aria-pressed="editing" x-text="editing ? 'Done editing' : 'Edit text'"></button>
            <button type="button" class="el-btn" x-show="d.segments.length" x-on:click="copy(false)" x-text="copied === 'text' ? 'Copied' : 'Copy text'"></button>
            <button type="button" class="el-btn" x-show="d.segments.length" x-on:click="copy(true)" x-text="copied === 'times' ? 'Copied' : (copied === 'failed' ? 'Copy failed' : 'Copy with timestamps')"></button>
            <span class="el-sr" role="status" aria-live="polite" x-text="copied === 'failed' ? 'Copy failed' : (copied ? 'Copied to the clipboard' : (saving !== null ? 'Saving' : ''))"></span>
        </div>

        <div class="el-timeline" x-show="d.segments.length || d.markers.length" x-on:click="onTimeline($event)" role="presentation" aria-hidden="true">
            <div class="el-lane el-lane-1"><template x-for="(s, i) in d.segments" :key="'l' + i"><span x-show="s.channel === 1" class="el-talk" :style="'left:' + pct(s.start_ms) + ';width:' + pct(s.end_ms - s.start_ms)"></span></template></div>
            <div class="el-lane el-lane-2"><template x-for="(s, i) in d.segments" :key="'r' + i"><span x-show="s.channel === 2" class="el-talk" :style="'left:' + pct(s.start_ms) + ';width:' + pct(s.end_ms - s.start_ms)"></span></template></div>
            <template x-for="(p, i) in d.pauses" :key="'p' + i"><span class="el-tick el-tick-pause" :style="'left:' + pct(p.at_ms)"></span></template>
            <template x-for="(m, i) in d.markers" :key="'m' + i"><span class="el-tick" :style="'left:' + pct(m.at_ms)"></span></template>
            <span class="el-head" :style="'left:' + pct(now)"></span>
        </div>

        <div class="el-chips" x-show="d.markers.length || d.pauses.length" aria-label="Bookmarks and pauses">
            <template x-for="(m, i) in d.markers" :key="'mc' + i">
                <button type="button" class="el-chip" x-on:click="seekTo(m.at_ms)" :aria-label="'Play from the bookmark at ' + clock(m.at_ms)">
                    <span class="el-time" x-text="clock(m.at_ms)"></span><span x-text="m.tag || 'Bookmark'"></span><span class="el-note" x-show="m.note" x-text="m.note"></span>
                </button>
            </template>
            <template x-for="(p, i) in d.pauses" :key="'pc' + i">
                <span class="el-chip el-chip-pause" x-text="'Paused at ' + clock(p.at_ms) + ' for ' + clock(p.paused_ms)"></span>
            </template>
        </div>

        <p class="el-error" role="alert" x-show="error" x-text="error"></p>
        <div class="el-empty" x-show="!d.segments.length">No transcript yet.</div>
        <div class="el-list" x-ref="list" x-show="d.segments.length" aria-label="Transcript. Choose a line to play from there.">
            <template x-for="(b, bi) in blocks()" :key="'b' + bi + '-' + b.start">
                <div class="el-block">
                    <div class="el-who"><span class="el-spk" :class="'el-spk-' + b.channel" x-text="name(d.segments[b.items[0]])"></span><span class="el-time" x-text="clock(b.start)"></span></div>
                    <template x-for="i in b.items" :key="'s' + i">
                        <div :data-seg="i">
                            <template x-if="!editing">
                                <button type="button" class="el-line" :data-active="active === i ? '' : null" :aria-current="active === i ? 'true' : null" x-on:click="seekTo(d.segments[i].start_ms)"
                                    :data-u="d.segments[i].uncertain ? '' : null" :title="d.segments[i].uncertain ? 'The engine was not sure of this line. Click to hear it.' : null">
                                    <template x-if="d.segments[i].words.length">
                                        <span><template x-for="(w, wi) in d.segments[i].words" :key="wi"><span><span class="el-w" :data-on="active === i && word === wi ? '' : null"
                                            :data-u="w.u ? '' : null" :title="w.u ? 'Not sure of this word. Click to hear it.' : null"
                                            x-on:click="if (w.u) { $event.stopPropagation(); seekTo(w.s); }" x-text="w.t"></span> </span></template></span>
                                    </template>
                                    <template x-if="!d.segments[i].words.length"><span x-text="d.segments[i].text"></span></template>
                                </button>
                            </template>
                            <template x-if="editing">
                                <textarea class="el-edit" rows="2" :aria-label="'Line at ' + clock(d.segments[i].start_ms)" :disabled="saving === i"
                                    x-init="$el.value = d.segments[i].text" x-on:blur="save(i, $el.value)"
                                    x-on:keydown.ctrl.enter.prevent="$el.blur()" x-on:keydown.meta.enter.prevent="$el.blur()"></textarea>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</x-dynamic-component>
