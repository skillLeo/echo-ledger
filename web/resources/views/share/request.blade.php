@extends('share.layout')

@section('title', $link->title)

@section('footer', 'Sent with EchoLedger')

@section('content')
    <div class="card" id="request" data-config='@json($config)'>
        <h1 dir="auto">{{ $link->title }}</h1>
        <p class="facts">
            Up to {{ $link->max_files }} {{ $link->max_files === 1 ? 'file' : 'files' }}, {{ $link->max_mb }} MB each.
            @if ($link->expires_at) Open until {{ $link->expires_at->copy()->setTimezone(app(\App\Services\SettingsRepository::class)->displayTimezone())->format('j M Y, H:i T') }}. @endif
        </p>
        <p class="muted">
            Sound and video files (Opus, Ogg, MP3, M4A, AAC, WAV, MP4, WebM){{ $takesDocuments ? ', PDF, Word, text and images (PNG, JPG, WebP)' : '' }}.
            They go straight to the person who sent you this link.
        </p>
        <label class="drop" id="drop">
            <input type="file" id="pick" multiple hidden>
            <span class="drop-title">Choose files</span>
            <span class="muted">or drop them here</span>
        </label>
        <ul class="list uploads" id="uploads" aria-live="polite"></ul>
        <noscript><p class="error">Sending files needs JavaScript.</p></noscript>
    </div>
    <style>
        .drop { display: grid; gap: 4px; place-items: center; text-align: center; padding: 28px 16px; margin: 4px 0 8px; border: 2px dashed var(--line); border-radius: 14px; cursor: pointer; }
        .drop.over { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 8%, transparent); }
        .drop-title { font-weight: 600; color: var(--accent); }
        .uploads li { grid-template-columns: 1fr auto; align-items: center; }
        .uploads .name { overflow-wrap: anywhere; }
        .uploads .state { font-size: 13px; color: var(--muted); text-align: end; }
        .uploads .state.ok { color: #15803d; }
        .uploads .state.bad { color: #e11d48; }
        .bar { grid-column: 1 / -1; height: 4px; border-radius: 4px; background: var(--line); overflow: hidden; }
        .bar > span { display: block; height: 100%; width: 0; background: var(--accent); transition: width .2s; }
    </style>
    @include('partials.sha256')
    <script>
        (() => {
            const root = document.getElementById('request');
            const cfg = JSON.parse(root.dataset.config);
            const list = document.getElementById('uploads');
            const drop = document.getElementById('drop');
            const pick = document.getElementById('pick');
            const queue = [];
            let running = false;

            const subtle = () => (window.isSecureContext && window.crypto && window.crypto.subtle) || null;
            const hex = (buffer) => Array.from(new Uint8Array(buffer), (b) => b.toString(16).padStart(2, '0')).join('');
            const mb = (bytes) => Math.round(bytes / 1024 / 1024) + ' MB';

            async function hashFile(file) {
                if (subtle() && file.size <= cfg.nativeHashMaxBytes) {
                    return hex(await subtle().digest('SHA-256', await file.arrayBuffer()));
                }
                const hash = new window.ElSha256();
                for (let offset = 0; offset < file.size; offset += 4 * 1024 * 1024) {
                    hash.update(new Uint8Array(await file.slice(offset, offset + 4 * 1024 * 1024).arrayBuffer()));
                }
                return hash.hex();
            }

            async function hashBytes(bytes) {
                return subtle() ? hex(await subtle().digest('SHA-256', bytes)) : new window.ElSha256().update(new Uint8Array(bytes)).hex();
            }

            async function call(url, body, headers) {
                let response;
                try {
                    response = await fetch(url, {
                        method: 'POST', body, credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest', ...headers },
                    });
                } catch (e) {
                    return { status: 0, json: { message: 'The connection was lost. Try again.' } };
                }
                let json = {};
                try { json = await response.json(); } catch (e) { json = {}; }
                return { status: response.status, json };
            }

            function row(file) {
                const li = document.createElement('li');
                const name = document.createElement('span');
                name.className = 'name';
                name.dir = 'auto';
                name.textContent = file.name;
                const state = document.createElement('span');
                state.className = 'state';
                const bar = document.createElement('div');
                bar.className = 'bar';
                bar.appendChild(document.createElement('span'));
                li.append(name, state, bar);
                list.appendChild(li);
                return {
                    say(text, kind) { state.textContent = text; state.className = 'state' + (kind ? ' ' + kind : ''); },
                    progress(part) { bar.firstChild.style.width = Math.round(part * 100) + '%'; },
                    done() { bar.remove(); },
                };
            }

            function problemOf(file) {
                const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';
                const audio = cfg.audio.includes(extension) || /^audio\//.test(file.type) || /^video\/(mp4|webm)$/.test(file.type);
                const takenDocument = cfg.documents.includes(extension);
                if (!audio && !takenDocument) return cfg.documents.length ? 'This type of file is not taken here.' : 'Only sound and video files are taken here.';
                if (file.size === 0) return 'This file is empty.';
                if (file.size > cfg.maxBytes) return 'Larger than ' + mb(cfg.maxBytes) + '.';
                if (!audio && file.size > cfg.maxDocumentBytes) return 'Documents and images can be at most ' + mb(cfg.maxDocumentBytes) + '.';
                return '';
            }

            async function send(item) {
                const { file, ui } = item;
                ui.say('Preparing…');
                const sha = await hashFile(file);
                let res = await call(cfg.store, JSON.stringify({ name: file.name, size: file.size, sha256: sha, type: file.type }), { 'Content-Type': 'application/json' });
                if (res.status !== 200 && res.status !== 201) throw new Error(res.json.message || 'The file was not taken.');
                const upload = res.json;
                const base = cfg.store + '/' + upload.upload_id;
                const missing = upload.missing_chunks;
                let sent = upload.total_chunks - missing.length;
                for (const index of missing) {
                    ui.say('Sending… ' + Math.round(sent / upload.total_chunks * 100) + '%');
                    const bytes = await file.slice(index * upload.chunk_size, (index + 1) * upload.chunk_size).arrayBuffer();
                    res = await call(base + '/chunks/' + index, bytes, { 'Content-Type': 'application/octet-stream', 'Upload-Chunk-Sha256': await hashBytes(bytes) });
                    if (res.status !== 200) throw new Error(res.json.message || 'The file was not taken.');
                    sent++;
                    ui.progress(sent / upload.total_chunks);
                }
                ui.say('Finishing…');
                res = await call(base + '/complete', null, {});
                if (res.status !== 200) throw new Error(res.json.message || 'The file was not taken.');
            }

            async function pump() {
                if (running) return;
                running = true;
                while (queue.length) {
                    const item = queue.shift();
                    try {
                        await send(item);
                        item.ui.done();
                        item.ui.say('Sent', 'ok');
                    } catch (e) {
                        item.ui.done();
                        item.ui.say(e.message, 'bad');
                    }
                }
                running = false;
            }

            function add(files) {
                for (const file of Array.from(files || [])) {
                    const ui = row(file);
                    const problem = problemOf(file);
                    if (problem) {
                        ui.done();
                        ui.say(problem, 'bad');
                        continue;
                    }
                    ui.say('Waiting…');
                    queue.push({ file, ui });
                }
                pick.value = '';
                pump();
            }

            pick.addEventListener('change', () => add(pick.files));
            ['dragenter', 'dragover'].forEach((type) => drop.addEventListener(type, (e) => { e.preventDefault(); drop.classList.add('over'); }));
            ['dragleave', 'drop'].forEach((type) => drop.addEventListener(type, () => drop.classList.remove('over')));
            drop.addEventListener('drop', (e) => { e.preventDefault(); add(e.dataTransfer.files); });
            window.addEventListener('beforeunload', (e) => { if (running) { e.preventDefault(); e.returnValue = ''; } });
        })();
    </script>
@endsection
