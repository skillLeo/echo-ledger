@php
    $data = $entry->getPlayerData();
@endphp
<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <div class="el-player" wire:ignore tabindex="0" role="group"
        aria-label="Transcript player. Space or K plays and pauses, J and L jump 10 seconds, the arrow keys 5 seconds."
        x-on:keydown="onKey($event)"
        x-on:el-transcript-updated.window="load($event.detail)"
        x-on:el-seek.window="seekTo($event.detail.ms)"
        x-data="{
            d: @js($data), active: -1, word: -1, raf: null, playing: false, now: 0, rate: 1, skipSilence: false, follow: true,
            editing: false, saving: null, error: null, copied: null, view: 'original', find: '', hitAt: 0, peaks: null,
            learn: [], deciding: null,
            init() {
                // Show as: open in the default view (Settings) when it is made for this recording.
                if (this.d.views && this.d.views.status === 'ready' && this.d.views.default && this.d.views[this.d.views.default]) this.view = this.d.views.default;
                const a = this.$refs.audio;
                if (!a) return;
                a.addEventListener('play', () => { this.playing = true; this.tick(); });
                ['pause', 'ended'].forEach(e => a.addEventListener(e, () => { this.playing = false; cancelAnimationFrame(this.raf); this.sync(); }));
                a.addEventListener('seeked', () => this.sync());
                a.addEventListener('loadedmetadata', () => {
                    const t = Number(new URLSearchParams(window.location.search).get('t'));
                    if (Number.isFinite(t) && t > 0) { a.currentTime = t / 1000; this.sync(); }
                });
                this.loadPeaks();
            },
            load(next) { this.d = next; this.active = -1; this.word = -1; this.$nextTick(() => this.sync()); },
            name(s) { return this.d.speaker_names[s.speaker] || s.speaker; },
            initial(s) { return (this.name(s) || '?').trim().charAt(0).toUpperCase(); },
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
            // The waveform: the audio's own peaks when they could be read, otherwise how much is said in each slice.
            bars() {
                const n = 72;
                if (this.peaks && this.peaks.length === n) return this.peaks;
                const out = [], step = this.d.duration_ms / n;
                for (let b = 0; b < n; b++) {
                    const from = b * step, to = from + step;
                    let said = 0;
                    for (const s of this.d.segments) { said += Math.max(0, Math.min(to, s.end_ms) - Math.max(from, s.start_ms)); }
                    const jitter = Math.abs(Math.sin((b + 1) * 12.9898) * 43758.5453) % 1;
                    out.push(said > 0 ? Math.round(28 + 72 * Math.min(1, said / step) * (0.55 + 0.45 * jitter)) : 10);
                }
                return out;
            },
            async loadPeaks() {
                if (!this.d.audio_url || !this.d.audio_bytes || this.d.audio_bytes > 25 * 1024 * 1024 || !window.AudioContext) return;
                try {
                    const buffer = await (await fetch(this.d.audio_url, { credentials: 'same-origin' })).arrayBuffer();
                    const ctx = new AudioContext(), audio = await ctx.decodeAudioData(buffer), n = 72, peaks = [];
                    const channels = [...Array(audio.numberOfChannels).keys()].map(c => audio.getChannelData(c)), size = Math.floor(audio.length / n);
                    for (let b = 0; b < n; b++) {
                        let max = 0;
                        for (const data of channels) { for (let k = b * size; k < (b + 1) * size; k += 64) { const v = Math.abs(data[k] || 0); if (v > max) max = v; } }
                        peaks.push(max);
                    }
                    const top = Math.max(...peaks) || 1;
                    this.peaks = peaks.map(p => Math.max(8, Math.round(p / top * 100)));
                    ctx.close();
                } catch (e) { this.peaks = null; }
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
                    if (next && next.error) { this.error = next.error; } else if (next) { this.load(next); this.learn = next.learn || []; }
                } catch (e) { this.error = 'The change could not be saved. Try again.'; }
                this.saving = null;
            },
            // Always write X as Y for this client? (after an edit of a client's recording)
            async teach(index, yes) {
                const rule = this.learn[index];
                this.learn = this.learn.filter((r, k) => k !== index);
                if (!yes || !rule) return;
                try { const result = await $wire.learnRule(rule.from, rule.to); if (result && result.error) this.error = result.error; }
                catch (e) { this.error = 'The rule could not be saved. Try again.'; }
            },
            // Suggested fixes: Accept changes the words in that line (an edit), Dismiss only hides the mark.
            suggFor(i) { return this.view === 'original' && !this.editing ? (this.d.suggestions || []).filter(s => s.line === i) : []; },
            async decide(id, accept) {
                this.deciding = id; this.error = null;
                try {
                    const next = await $wire.decideFix(id, accept);
                    if (next && next.error) { this.error = next.error; } else if (next) { this.load(next); }
                } catch (e) { this.error = 'That suggestion could not be saved. Try again.'; }
                this.deciding = null;
            },
            // Show as: a view that is not made yet is made on the queue; the page swaps it in when it is ready.
            async pickView(v) {
                if (v === 'original' || this.viewReady(v)) { this.view = v; this.editing = v === 'original' && this.editing; return; }
                if (!this.d.views || this.d.views.status === 'making') return;
                try { const views = await $wire.makeViews(); if (views) { this.d.views = views; if (this.viewReady(v)) this.view = v; } }
                catch (e) { this.error = 'The views could not be started. Try again.'; }
            },
            // Same text format as the desktop and Android apps: '[m:ss] Speaker: text', speaker omitted when there is only one.
            // Copies mark unsure words as [?word] (or the console style); Copy for AI starts with the note line (plan A4).
            markedLine(i, style) {
                const s = this.d.segments[i];
                let raw = s.text;
                if (this.view !== 'original' && this.viewReady(this.view)) {
                    raw = this.lineText(i);
                } else if (s.words.length) {
                    // The line keeps its own text; each unsure word is found in order and wrapped where it stands.
                    let out = '', at = 0;
                    for (const w of s.words) {
                        const pos = w.t ? s.text.indexOf(w.t, at) : -1;
                        if (pos < 0) continue;
                        out += s.text.slice(at, pos) + (w.u && !w.t.startsWith('[') ? '[?' + w.t + ']' : w.t);
                        at = pos + w.t.length;
                    }
                    raw = out + s.text.slice(at);
                }
                return style === 'suffix' ? raw.replace(/\[\?([^\]]+)\]/g, '$1(?)') : (style === 'off' ? raw.replace(/\[\?([^\]]+)\]/g, '$1') : raw);
            },
            transcriptText(withTimes, forAi = false) {
                const u = this.d.unsure || { style: 'brackets', note: true, note_text: '' };
                const style = forAi && u.style === 'off' ? 'brackets' : u.style;
                const single = new Set(this.d.segments.map(s => s.speaker)).size <= 1;
                const body = this.d.segments.map((s, i) => (withTimes ? '[' + this.clock(s.start_ms) + '] ' : '') + (single ? '' : this.name(s) + ': ') + this.markedLine(i, style)).join('\n');
                const note = (u.note_text || 'Note: [?word] = the transcription is unsure about this word; [unclear] = inaudible audio.').replace('[?word]', style === 'suffix' ? 'word(?)' : '[?word]');
                const marked = /\[\?[^\]]+\]|\[unclear|\(\?\)/.test(body);
                return forAi || (u.note && style !== 'off' && marked) ? note + '\n\n' + body : body;
            },
            // A line on screen as text parts (rendered with x-text, never as HTML): its [?word] marks become underlined
            // parts, open suggested fixes marked parts, and words that match Find highlighted parts.
            // No double quotes in here: it sits inside x-data.
            lineParts(i) {
                let parts = this.lineText(i).split(/(\[\?[^\]]+\])/).filter(p => p !== '').map(p => {
                    const m = /^\[\?([^\]]+)\]$/.exec(p);
                    return m ? { t: m[1], u: true } : { t: p, u: false };
                });
                for (const s of this.suggFor(i)) { parts = this.splitParts(parts, s.from, { s: s.id }); }
                const q = this.find.trim();
                if (q.length > 1) parts = this.splitParts(parts, q, { f: true }, true);
                return parts;
            },
            splitParts(parts, needle, mark, anyCase = false) {
                const out = [];
                for (const p of parts) {
                    if (p.s !== undefined) { out.push(p); continue; }
                    const hay = anyCase ? p.t.toLowerCase() : p.t, key = anyCase ? needle.toLowerCase() : needle;
                    let at = 0, k = hay.indexOf(key);
                    while (k >= 0 && key) {
                        if (k > at) out.push({ ...p, t: p.t.slice(at, k) });
                        out.push({ ...p, ...mark, t: p.t.slice(k, k + key.length) });
                        at = k + key.length; k = hay.indexOf(key, at);
                    }
                    if (at < p.t.length) out.push({ ...p, t: p.t.slice(at) });
                }
                return out;
            },
            // Find: the lines that hold the words, the current one, and moving between them.
            hits() { const q = this.find.trim().toLowerCase(); return q.length > 1 ? this.d.segments.map((s, i) => i).filter(i => this.lineText(i).toLowerCase().includes(q)) : []; },
            isHit(i) { return this.find.trim().length > 1 && this.hits().includes(i); },
            findMove(step) {
                const hits = this.hits(); if (!hits.length) return;
                this.hitAt = (this.hitAt + step + hits.length) % hits.length;
                const row = this.$refs.list && this.$refs.list.querySelector('[data-seg=\'' + hits[this.hitAt] + '\']');
                if (row) row.scrollIntoView({ block: 'center', behavior: 'smooth' });
            },
            wordHit(w) { const q = this.find.trim().toLowerCase(); return q.length > 1 && w.t.toLowerCase().includes(q); },
            // Show as: a line in the chosen view (Roman Urdu or English) when it is made, else its own words.
            viewReady(v) { return !!(this.d.views && this.d.views.status === 'ready' && this.d.views[v]); },
            viewOffered(v) { return !!(this.d.views && (this.d.views.available || []).includes(v)); },
            lineText(i) { return this.view !== 'original' && this.viewReady(this.view) ? (this.d.views[this.view][i] ?? this.d.segments[i].text) : this.d.segments[i].text; },
            // A line whose view failed the checks twice shows the original words, with a note (plan A3).
            keptLine(i) { return this.view !== 'original' && this.viewReady(this.view) && ((this.d.views.kept || {})[this.view] || []).includes(i); },
            useWords(i) { return this.d.segments[i].words.length && this.view === 'original' && !this.suggFor(i).length && !this.find.trim(); },
            async copy(withTimes, forAi = false) {
                try { await navigator.clipboard.writeText(this.transcriptText(withTimes, forAi)); this.copied = forAi ? 'ai' : (withTimes ? 'times' : 'text'); }
                catch (e) { this.copied = 'failed'; }
                setTimeout(() => this.copied = null, 2000);
            },
        }">
        <style>
            /* Urdu in Nastaliq, Arabic in Naskh (Noto, OFL: public/fonts); only Arabic-script letters use them. */
            @font-face { font-family: "Noto Nastaliq Urdu"; src: url("{{ asset('fonts/noto-nastaliq-urdu-arabic.woff2') }}") format("woff2"); font-weight: 400 700; font-display: swap; unicode-range: U+0600-06FF, U+0750-077F, U+0870-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC; }
            @font-face { font-family: "Noto Naskh Arabic"; src: url("{{ asset('fonts/noto-naskh-arabic-arabic.woff2') }}") format("woff2"); font-weight: 400 700; font-display: swap; unicode-range: U+0600-06FF, U+0750-077F, U+0870-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC; }
            .el-player .el-list[lang="ur"] .el-line, .el-player .el-list[lang="ur"] .el-edit { font-family: "Noto Nastaliq Urdu", inherit; font-size: 19px; line-height: 38px; }
            .el-player .el-list[lang="ar"] .el-line, .el-player .el-list[lang="ar"] .el-edit { font-family: "Noto Naskh Arabic", inherit; font-size: 18px; line-height: 30px; }
            /* Light theme first; .dark (set by Filament on <html>) swaps the palette. The values are the phone's tokens
               (resources/css/filament/admin/theme.css); text colours meet WCAG AA (4.5:1), also on the active line. */
            .el-player { --el-border: #E8E8EC; --el-bg: #FFFFFF;
                --el-text: #1C2024; --el-muted: #60646C; --el-me: #3A5BC7; --el-client: #007563; --el-hover: #F0F0F3;
                --el-active: #EDF2FE; --el-bar: #3E63DD; --el-focus: #3E63DD; --el-btn-border: #D9D9E0;
                --el-btn-bg: #F0F0F3; --el-btn-hover: #E8E8EC; --el-word: rgba(255,197,61,.45); --el-unsure: #AB6400; --el-wave: #D9D9E0;
                --el-hit: rgba(255,197,61,.55); --el-sugg: #3E63DD; --el-danger: #CE2C31;
                color: var(--el-text); position: relative; display: flex; flex-direction: column; gap: 16px; outline: none; }
            .dark .el-player { --el-border: #272A2D; --el-bg: #18191B;
                --el-text: #EDEEF0; --el-muted: #B0B4BA; --el-me: #9EB1FF; --el-client: #0BD8B6; --el-hover: #212225;
                --el-active: #182449; --el-bar: #9EB1FF; --el-focus: #9EB1FF; --el-btn-border: #363A3F;
                --el-btn-bg: #212225; --el-btn-hover: #272A2D; --el-word: rgba(255,202,22,.3); --el-unsure: #FFCA16; --el-wave: #363A3F;
                --el-hit: rgba(255,202,22,.35); --el-sugg: #9EB1FF; --el-danger: #FF9592; }
            .el-player :focus-visible, .el-player:focus-visible { outline: 2px solid var(--el-focus); outline-offset: 2px; }
            .el-player .el-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; }
            .el-player .el-meta-line { font-size: 13px; line-height: 18px; color: var(--el-muted); }
            .el-player .el-tools { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
            .el-player .el-spacer { flex: 1; }
            .el-player .el-btn { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 0 14px; border-radius: 999px; border: 0;
                background: var(--el-btn-bg); color: var(--el-text); font-size: 14px; font-weight: 600; cursor: pointer; transition: background-color .12s; white-space: nowrap; }
            .el-player .el-btn:hover, .el-player .el-btn[aria-pressed=true] { background: var(--el-btn-hover); }
            .el-player .el-btn-text { background: transparent; color: var(--el-me); padding: 0 8px; }
            .el-player .el-btn-text:hover { background: var(--el-active); }
            .el-player .el-btn .el-i { width: 18px; height: 18px; }
            .el-player .el-find { display: inline-flex; align-items: center; gap: 4px; min-height: 36px; padding: 0 6px 0 12px; border-radius: 999px; background: var(--el-btn-bg); }
            .el-player .el-find input { width: 9rem; border: 0; background: transparent; color: var(--el-text); font-size: 14px; outline: none; box-shadow: none; padding: 0; }
            .el-player .el-find .el-i { width: 16px; height: 16px; color: var(--el-muted); flex: none; }
            .el-player .el-find button { display: inline-flex; padding: 4px; border-radius: 999px; color: var(--el-muted); }
            .el-player .el-find button:hover { background: var(--el-btn-hover); color: var(--el-text); }
            .el-player .el-find-count { font-size: 12px; color: var(--el-muted); font-variant-numeric: tabular-nums; min-width: 3.5rem; text-align: end; }
            .el-player .el-time { font-variant-numeric: tabular-nums; color: var(--el-muted); font-size: 13px; }
            .el-player .el-chips { display: flex; flex-wrap: wrap; gap: 8px; }
            .el-player .el-chip { display: inline-flex; gap: 6px; align-items: baseline; min-height: 32px; padding: 6px 12px; border-radius: 10px;
                border: 1px solid var(--el-btn-border); background: transparent; color: var(--el-text); font-size: 13px; cursor: pointer; }
            .el-player .el-chip:hover { background: var(--el-hover); }
            .el-player .el-chip-pause { cursor: default; color: var(--el-muted); }
            .el-player .el-note { color: var(--el-muted); }
            .el-player .el-list { margin: 0; padding: 2px; display: flex; flex-direction: column; gap: 20px; }
            .el-player .el-block { display: grid; grid-template-columns: 36px minmax(0, 1fr); column-gap: 12px; }
            .el-player .el-avatar { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 999px;
                font-size: 14px; font-weight: 600; background: var(--el-hover); }
            .el-player .el-block-1 .el-avatar { color: var(--el-me); } .el-player .el-block-2 .el-avatar { color: var(--el-client); }
            .el-player .el-who { display: flex; gap: 8px; align-items: baseline; min-height: 36px; align-items: center; }
            .el-player .el-spk { font-weight: 600; font-size: 15px; }
            .el-player .el-spk-1 { color: var(--el-me); } .el-player .el-spk-2 { color: var(--el-client); }
            .el-player .el-lines { grid-column: 2; display: flex; flex-direction: column; gap: 2px; }
            .el-player .el-line { display: block; width: 100%; text-align: start; border: 0; background: transparent; color: inherit; font: inherit;
                font-size: 17px; line-height: 28px; padding: 2px 8px; margin-inline-start: -8px; border-radius: 8px; cursor: pointer; overflow-wrap: anywhere; unicode-bidi: plaintext; }
            .el-player .el-line:hover { background: var(--el-hover); }
            .el-player .el-line[data-active] { background: var(--el-active); }
            .el-player .el-line[data-hit] { box-shadow: inset 3px 0 0 var(--el-unsure); }
            .el-player .el-w[data-on] { background: var(--el-word); border-radius: 3px; }
            .el-player [data-hit] > .el-w[data-hit], .el-player span[data-f] { background: var(--el-hit); border-radius: 3px; }
            /* Words and lines the engine was not sure of: kept as heard, underlined, a click plays them. */
            .el-player .el-w[data-u], .el-player .el-line[data-u] > span { text-decoration: underline dotted var(--el-unsure); text-decoration-thickness: 2px; text-underline-offset: 5px; }
            .el-player .el-w[data-u] { cursor: help; }
            /* A suggested fix: a quiet dashed mark on the words, with Accept and Dismiss under the line. */
            .el-player .el-w[data-sugg] { text-decoration: underline dashed var(--el-sugg); text-decoration-thickness: 2px; text-underline-offset: 5px; }
            .el-player .el-sugg-row { display: flex; flex-wrap: wrap; align-items: center; gap: 2px 8px; margin: 0 0 4px; font-size: 13px; color: var(--el-muted); }
            .el-player .el-sugg-row strong { color: var(--el-text); font-weight: 600; }
            .el-player .el-check { margin: 0 0 4px; font-size: 13px; color: var(--el-muted); unicode-bidi: plaintext; }
            .el-player .el-kept { font-size: 12px; color: var(--el-muted); }
            .el-player .el-check-who { font-weight: 600; }
            .el-player textarea.el-edit { width: 100%; font: inherit; font-size: 16px; line-height: 26px; color: var(--el-text); background: var(--el-bg);
                border: 1px solid var(--el-btn-border); border-radius: 12px; padding: 6px 10px; resize: vertical; unicode-bidi: plaintext; }
            .el-player .el-empty { color: var(--el-muted); font-size: 15px; padding: 12px 0; }
            .el-player .el-error { color: var(--el-danger); font-size: 14px; margin: 0; }
            .el-player .el-learn { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; padding: 12px 16px; border-radius: 14px; background: var(--el-active); font-size: 14px; }
            .el-player .el-learn strong { font-weight: 600; }
            .el-player .el-learn > span { flex: 1; min-width: 12rem; }
            .el-player .el-sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
            /* The segmented Show as switch. */
            .el-player .el-views { display: inline-flex; gap: 2px; padding: 3px; border-radius: 999px; background: var(--el-btn-bg); }
            .el-player .el-views button { min-height: 30px; padding: 0 12px; border-radius: 999px; font-size: 13px; font-weight: 500; color: var(--el-muted); }
            .el-player .el-views button[aria-pressed=true] { background: var(--el-bg); color: var(--el-text); box-shadow: 0 1px 2px rgba(0,0,0,.08); }
            .el-player .el-views button:lang(ur) { font-family: "Noto Nastaliq Urdu", inherit; font-size: 14px; }
            .el-player .el-views button[data-unmade] { font-style: normal; opacity: .75; }
            /* The docked player: it stays at the bottom of the window while the transcript scrolls under it. */
            .el-player .el-dock { position: sticky; bottom: 12px; z-index: 5; display: grid; align-items: center; gap: 6px 12px;
                grid-template-columns: auto auto auto auto minmax(0, 1fr) auto auto auto; grid-template-areas: 'play back fwd now wave total speed more';
                padding: 10px 14px; border-radius: 20px; background: var(--el-bg); border: 1px solid var(--el-border); box-shadow: 0 4px 16px rgba(0,0,0,.10), 0 1px 2px rgba(0,0,0,.06); }
            .dark .el-player .el-dock { box-shadow: none; border-color: #363A3F; background: #212225; }
            .el-player .el-dock audio { display: none; }
            .el-player .el-play { grid-area: play; display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; border-radius: 999px; background: var(--el-bar); color: #fff; cursor: pointer; }
            .dark .el-player .el-play { background: #3E63DD; }
            .el-player .el-play .el-i { width: 22px; height: 22px; }
            .el-player .el-back { grid-area: back; } .el-player .el-fwd { grid-area: fwd; }
            .el-player .el-back, .el-player .el-fwd { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 999px; color: var(--el-muted); font-size: 12px; font-weight: 600; }
            .el-player .el-back:hover, .el-player .el-fwd:hover { background: var(--el-hover); color: var(--el-text); }
            .el-player .el-now { grid-area: now; } .el-player .el-total { grid-area: total; }
            .el-player .el-wave { grid-area: wave; position: relative; height: 40px; cursor: pointer; }
            .el-player .el-wave-bars { position: absolute; inset: 0; display: flex; align-items: center; gap: 2px; }
            .el-player .el-wave-bars > span { flex: 1 1 0; min-width: 0; border-radius: 2px; background: var(--el-wave); }
            .el-player .el-wave-played { position: absolute; inset: 0; pointer-events: none; }
            .el-player .el-wave-played .el-wave-bars > span { background: var(--el-bar); }
            .el-player .el-tick { position: absolute; top: -3px; width: 6px; height: 6px; border-radius: 999px; background: var(--el-unsure); transform: translateX(-50%); }
            .el-player .el-tick-pause { background: var(--el-muted); }
            .el-player .el-speed { grid-area: speed; font: inherit; font-size: 13px; border: 0; border-radius: 999px; background: var(--el-btn-bg); color: var(--el-text); padding: 4px 26px 4px 10px; }
            .el-player .el-more { grid-area: more; position: relative; }
            .el-player .el-more-panel { position: absolute; bottom: calc(100% + 10px); right: 0; display: flex; flex-direction: column; gap: 10px; min-width: 11rem; padding: 12px 14px;
                border-radius: 14px; background: var(--el-bg); border: 1px solid var(--el-border); box-shadow: 0 4px 16px rgba(0,0,0,.10), 0 1px 2px rgba(0,0,0,.06); font-size: 14px; }
            .el-player .el-more-panel label { display: flex; gap: 8px; align-items: center; cursor: pointer; white-space: nowrap; }
            .el-player .el-icon { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 999px; color: var(--el-muted); }
            .el-player .el-icon:hover, .el-player .el-icon[aria-expanded=true] { background: var(--el-hover); color: var(--el-text); }
            .el-player .el-icon .el-i { width: 20px; height: 20px; }
            @media (max-width: 640px) {
                .el-player .el-dock { grid-template-columns: 34px 44px 34px minmax(0, 1fr) auto auto; grid-template-areas: 'now wave wave wave wave total' 'back play fwd . speed more';
                    bottom: calc(76px + env(safe-area-inset-bottom)); padding: 8px 12px; }
                .el-player .el-play { justify-self: center; }
                .el-player .el-find input { width: 6.5rem; }
                .el-player .el-line { font-size: 16px; line-height: 26px; }
                .el-player .el-tools .el-btn { padding: 0 12px; font-size: 13px; }
            }
            @media (min-width: 641px) and (max-width: 1023.98px) { .el-player .el-dock { bottom: calc(76px + env(safe-area-inset-bottom)); } }
            @media (prefers-reduced-motion: reduce) { .el-player .el-btn { transition: none; } }
        </style>

        <div class="el-head">
            <p class="el-meta-line">
                <span x-text="d.kind === 'live' ? 'Live captions (final transcript pending)' : 'Final transcript'"></span>
                <span x-show="d.status && d.status !== 'completed'" x-text="'· ' + (d.status || '').replace('_', ' ')"></span>
                <span x-show="d.model" x-text="'· ' + d.model"></span>
                <span x-text="'· ' + d.language"></span>
                <span x-show="d.edited">· edited</span>
                <span x-show="view !== 'original'" x-text="'· ' + ({roman: 'Roman Urdu', english: 'English', urdu: 'اردو'}[view] || '') + ' view by ' + (d.views ? d.views.model : '')"></span>
                <span x-show="d.views && d.views.status === 'making'">· making the views…</span>
            </p>
            {{-- Show as: Roman Urdu · اردو · English · Original. A view not made yet is made when it is chosen. --}}
            <span class="el-views" role="group" aria-label="Show as" x-show="d.views && (d.views.available || []).length" x-cloak>
                <button type="button" x-show="viewOffered('roman')" x-on:click="pickView('roman')" :aria-pressed="view === 'roman'" :data-unmade="viewReady('roman') ? null : ''">Roman Urdu</button>
                <button type="button" lang="ur" x-show="viewOffered('urdu')" x-on:click="pickView('urdu')" :aria-pressed="view === 'urdu'" :data-unmade="viewReady('urdu') ? null : ''">اردو</button>
                <button type="button" x-show="viewOffered('english')" x-on:click="pickView('english')" :aria-pressed="view === 'english'" :data-unmade="viewReady('english') ? null : ''">English</button>
                <button type="button" x-on:click="pickView('original')" :aria-pressed="view === 'original'">Original</button>
            </span>
        </div>

        <div class="el-tools">
            <label class="el-find" x-show="d.segments.length">
                <span class="el-i el-i-search" aria-hidden="true"></span>
                <input type="search" x-model="find" x-on:input="hitAt = 0" x-on:keydown.enter.prevent="findMove($event.shiftKey ? -1 : 1)" x-on:keydown.escape="find = ''"
                    placeholder="Find" aria-label="Find in the transcript">
                <span class="el-find-count" x-show="find.trim().length > 1" x-text="hits().length ? (hitAt + 1) + ' of ' + hits().length : 'None'"></span>
                <button type="button" x-show="hits().length > 1" x-on:click="findMove(-1)" aria-label="Previous match"><span class="el-i el-i-up" aria-hidden="true"></span></button>
                <button type="button" x-show="hits().length > 1" x-on:click="findMove(1)" aria-label="Next match"><span class="el-i el-i-down" aria-hidden="true"></span></button>
            </label>
            <span class="el-spacer"></span>
            <button type="button" class="el-btn" x-show="d.editable && view === 'original'" x-on:click="editing = !editing" :aria-pressed="editing" x-text="editing ? 'Done editing' : 'Edit text'"></button>
            <button type="button" class="el-btn" x-show="d.segments.length" x-on:click="copy(false)" x-text="copied === 'text' ? 'Copied' : 'Copy text'"></button>
            <button type="button" class="el-btn" x-show="d.segments.length" x-on:click="copy(true)" x-text="copied === 'times' ? 'Copied' : (copied === 'failed' ? 'Copy failed' : 'Copy with timestamps')"></button>
            <button type="button" class="el-btn el-btn-text" x-show="d.segments.length" x-on:click="copy(false, true)" title="Unsure words marked [?word], with a first line that tells an AI what the marks mean" x-text="copied === 'ai' ? 'Copied' : 'Copy for AI'"></button>
            <span class="el-sr" role="status" aria-live="polite" x-text="copied === 'failed' ? 'Copy failed' : (copied ? 'Copied to the clipboard' : (saving !== null ? 'Saving' : ''))"></span>
        </div>

        {{-- After an edit of a client's recording: teach the client the words that were wrong. --}}
        <template x-for="(r, ri) in learn" :key="'learn' + ri">
            <div class="el-learn" role="status">
                <span>Always write “<strong x-text="r.from"></strong>” as “<strong x-text="r.to"></strong>” for <span x-text="r.client_name"></span>?</span>
                <button type="button" class="el-btn" x-on:click="teach(ri, true)">Yes</button>
                <button type="button" class="el-btn el-btn-text" x-on:click="teach(ri, false)">No</button>
            </div>
        </template>

        <p class="el-error" role="alert" x-show="error" x-text="error"></p>

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

        <div class="el-empty" x-show="!d.segments.length">No transcript yet.</div>
        <div class="el-list" x-ref="list" x-show="d.segments.length" :lang="view === 'original' ? d.language_code : (view === 'urdu' ? 'ur' : null)" aria-label="Transcript. Choose a line to play from there.">
            <template x-for="(b, bi) in blocks()" :key="'b' + bi + '-' + b.start">
                <div class="el-block" :class="'el-block-' + b.channel">
                    <span class="el-avatar" aria-hidden="true" x-text="initial(d.segments[b.items[0]])"></span>
                    <div class="el-who"><span class="el-spk" :class="'el-spk-' + b.channel" x-text="name(d.segments[b.items[0]])"></span><span class="el-time" x-text="clock(b.start)"></span></div>
                    <div class="el-lines">
                        <template x-for="i in b.items" :key="'s' + i">
                            <div :data-seg="i">
                                <template x-if="!editing">
                                    <button type="button" class="el-line" :data-active="active === i ? '' : null" :aria-current="active === i ? 'true' : null" x-on:click="seekTo(d.segments[i].start_ms)"
                                        :data-hit="isHit(i) ? '' : null"
                                        :data-u="d.segments[i].uncertain ? '' : null" :title="d.segments[i].uncertain ? 'The engine was not sure of this line. Click to hear it.' : null">
                                        <template x-if="useWords(i)">
                                            <span><template x-for="(w, wi) in d.segments[i].words" :key="wi"><span><span class="el-w" :data-on="active === i && word === wi ? '' : null"
                                                :data-u="w.u ? '' : null" :title="w.u ? 'Not sure of this word. Click to hear it.' : null"
                                                x-on:click="if (w.u) { $event.stopPropagation(); seekTo(w.s); }" x-text="w.t"></span> </span></template></span>
                                        </template>
                                        <template x-if="!useWords(i)"><span><template x-for="(p, pi) in lineParts(i)" :key="pi"><span :class="p.u || p.s !== undefined ? 'el-w' : null" :data-u="p.u ? '' : null"
                                            :data-sugg="p.s !== undefined ? '' : null" :data-f="p.f ? '' : null" :title="p.s !== undefined ? 'A suggested fix: see below the line' : null" x-text="p.t"></span></template><span class="el-kept" x-show="keptLine(i)" title="This line could not be written in the view, so it shows the words as transcribed."> · original words</span></span></template>
                                    </button>
                                </template>
                                <template x-for="s in suggFor(i)" :key="'sg' + s.id">
                                    <p class="el-sugg-row">
                                        <span>Suggested: “<strong x-text="s.from"></strong>” → “<strong x-text="s.to"></strong>”<span x-show="s.reason" x-text="' · ' + s.reason"></span></span>
                                        <button type="button" class="el-btn el-btn-text" :disabled="deciding === s.id" x-on:click="decide(s.id, true)">Accept</button>
                                        <button type="button" class="el-btn el-btn-text" :disabled="deciding === s.id" x-on:click="decide(s.id, false)">Dismiss</button>
                                    </p>
                                </template>
                                <template x-if="d.segments[i].check && !editing">
                                    <p class="el-check" dir="auto"><span class="el-check-who" x-text="d.segments[i].check.label + ' heard:'"></span> <span x-text="d.segments[i].check.text"></span></p>
                                </template>
                                <template x-if="editing">
                                    <textarea class="el-edit" rows="2" :aria-label="'Line at ' + clock(d.segments[i].start_ms)" :disabled="saving === i"
                                        x-init="$el.value = d.segments[i].text" x-on:blur="save(i, $el.value)"
                                        x-on:keydown.ctrl.enter.prevent="$el.blur()" x-on:keydown.meta.enter.prevent="$el.blur()"></textarea>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        @if ($data['audio_url'])
            <div class="el-dock" role="group" aria-label="Player">
                <audio x-ref="audio" preload="metadata" src="{{ $data['audio_url'] }}"></audio>
                <button type="button" class="el-play" x-on:click="toggle()" :aria-label="playing ? 'Pause' : 'Play'">
                    <span class="el-i el-i-play" x-show="!playing" aria-hidden="true"></span>
                    <span class="el-i el-i-pause" x-show="playing" x-cloak aria-hidden="true"></span>
                </button>
                <button type="button" class="el-back" x-on:click="jump(-10)" aria-label="Back 10 seconds">−10</button>
                <button type="button" class="el-fwd" x-on:click="jump(10)" aria-label="Forward 10 seconds">+10</button>
                <span class="el-time el-now" x-text="clock(now)"></span>
                <div class="el-wave" x-on:click="onTimeline($event)" role="slider" aria-label="Position" aria-valuemin="0" :aria-valuemax="Math.round(d.duration_ms / 1000)"
                    :aria-valuenow="Math.round(now / 1000)" :aria-valuetext="clock(now) + ' of ' + clock(d.duration_ms)">
                    <div class="el-wave-bars"><template x-for="(h, bi) in bars()" :key="'w' + bi"><span :style="'height:' + h + '%'"></span></template></div>
                    <div class="el-wave-played" :style="'clip-path: inset(0 ' + (100 - parseFloat(pct(now))) + '% 0 0)'"><div class="el-wave-bars"><template x-for="(h, bi) in bars()" :key="'p' + bi"><span :style="'height:' + h + '%'"></span></template></div></div>
                    <template x-for="(p, i) in d.pauses" :key="'p' + i"><span class="el-tick el-tick-pause" :style="'left:' + pct(p.at_ms)"></span></template>
                    <template x-for="(m, i) in d.markers" :key="'m' + i"><span class="el-tick" :style="'left:' + pct(m.at_ms)"></span></template>
                </div>
                <span class="el-time el-total" x-text="clock(d.duration_ms)"></span>
                <select class="el-speed" x-on:change="setRate($event.target.value)" aria-label="Playback speed">
                    <template x-for="r in [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2]" :key="r"><option :value="r" :selected="r === rate" x-text="r + '×'"></option></template>
                </select>
                <div class="el-more" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.stop="open = false">
                    <button type="button" class="el-icon" x-on:click="open = !open" :aria-expanded="open" aria-label="Player options"><span class="el-i el-i-sliders" aria-hidden="true"></span></button>
                    <div class="el-more-panel" x-show="open" x-cloak x-transition.opacity>
                        <label><input type="checkbox" x-model="skipSilence"> Skip silence</label>
                        <label><input type="checkbox" x-model="follow"> Follow</label>
                    </div>
                </div>
            </div>
        @else
            <div class="el-empty">Audio is not available (still uploading, deleted, or retention expired).</div>
        @endif
    </div>
</x-dynamic-component>
