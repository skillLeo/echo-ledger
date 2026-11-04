{{-- Library → Upload (resources/views/filament/library/uploader.blade.php): hashing, chunked upload, resume and cancel. --}}
@include('partials.sha256')
<script>
    window.elUploader = window.elUploader || (() => {
        // The streaming SHA-256 (partials.sha256) for files the browser cannot hash in one go.
        const Sha256 = window.ElSha256;

        const SLICE = 4 * 1024 * 1024;
        const PARALLEL = 2;
        const BUSY = ['queued', 'hashing', 'starting', 'uploading', 'finishing', 'paused'];
        const FINAL_CODES = ['not_audio', 'unsupported_type'];
        const hex = (buffer) => Array.from(new Uint8Array(buffer), (b) => b.toString(16).padStart(2, '0')).join('');
        const subtle = () => (window.isSecureContext && window.crypto && window.crypto.subtle) || null;
        const cancelled = () => Object.assign(new Error('Cancelled'), { name: 'AbortError' });

        class Offline extends Error {}

        async function hashFile(file, nativeMax, onProgress, isCancelled) {
            const native = subtle();
            if (native && file.size <= nativeMax) {
                return hex(await native.digest('SHA-256', await file.arrayBuffer()));
            }
            const hash = new Sha256();
            for (let offset = 0; offset < file.size; offset += SLICE) {
                if (isCancelled()) throw cancelled();
                hash.update(new Uint8Array(await file.slice(offset, offset + SLICE).arrayBuffer()));
                onProgress(Math.min(1, (offset + SLICE) / file.size));
            }
            return hash.hex();
        }

        async function hashBytes(bytes) {
            const native = subtle();
            return native ? hex(await native.digest('SHA-256', bytes)) : new Sha256().update(bytes).hex();
        }

        // The length the browser can read from the file's header; null when it cannot tell (the server measures it).
        function durationOf(file) {
            return new Promise((resolve) => {
                const video = /^video\//.test(file.type) || /\.(mp4|webm)$/i.test(file.name);
                const media = document.createElement(video ? 'video' : 'audio');
                const url = URL.createObjectURL(file);
                let done = false;
                const finish = (ms) => {
                    if (done) return;
                    done = true;
                    clearTimeout(timer);
                    media.removeAttribute('src');
                    URL.revokeObjectURL(url);
                    resolve(ms);
                };
                const timer = setTimeout(() => finish(null), 5000);
                media.preload = 'metadata';
                media.muted = true;
                media.onloadedmetadata = () => finish(Number.isFinite(media.duration) && media.duration > 0 ? Math.round(media.duration * 1000) : null);
                media.onerror = () => finish(null);
                media.src = url;
            });
        }

        // XMLHttpRequest rather than fetch: it reports upload progress within a chunk. Status 0 is a network failure.
        function request(method, url, { body = null, headers = {}, csrf = '', signal = null, onProgress = null } = {}) {
            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open(method, url);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                Object.entries(headers).forEach(([name, value]) => xhr.setRequestHeader(name, value));
                if (onProgress) xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(e.loaded);
                xhr.onload = () => {
                    let json = {};
                    try { json = JSON.parse(xhr.responseText || '{}') || {}; } catch (e) { json = {}; }
                    resolve({ status: xhr.status, json });
                };
                xhr.onerror = () => resolve({ status: 0, json: {} });
                xhr.onabort = () => reject(cancelled());
                if (signal) {
                    if (signal.aborted) return reject(cancelled());
                    signal.addEventListener('abort', () => xhr.abort(), { once: true });
                }
                xhr.send(body);
            });
        }

        return (config) => ({
            cfg: config,
            files: [],
            clientId: '',
            language: '',
            dragging: false,
            depth: 0,
            active: 0,
            seq: 0,
            refreshTimer: null,

            init() {
                window.addEventListener('online', () => this.files.filter((f) => f.state === 'paused').forEach((f) => this.retry(f)));
                window.addEventListener('beforeunload', (e) => {
                    if (this.busy()) {
                        e.preventDefault();
                        e.returnValue = '';
                    }
                });
            },

            get waiting() { return this.files.filter((f) => f.state === 'waiting'); },
            busy() { return this.files.some((f) => BUSY.includes(f.state)); },
            pick() { this.$refs.input.click(); },

            // The whole page takes dropped files, except a file field of its own (none here today).
            takes(e) {
                const files = !!(e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files'));
                return files && !(e.target && e.target.closest && e.target.closest('input[type=file], .filepond--root'));
            },
            dragEnter(e) { if (this.takes(e)) { this.depth++; this.dragging = true; } },
            dragOver(e) { if (this.takes(e)) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; this.dragging = true; } },
            dragLeave(e) { if (this.takes(e)) { this.depth = Math.max(0, this.depth - 1); this.dragging = this.depth > 0; } },
            drop(e) {
                if (!this.takes(e)) return;
                e.preventDefault();
                this.depth = 0;
                this.dragging = false;
                this.add(e.dataTransfer.files);
            },

            add(list) {
                for (const file of Array.from(list || [])) {
                    const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';
                    const known = this.cfg.extensions.includes(extension) || /^audio\//.test(file.type) || /^video\/(mp4|webm)$/.test(file.type);
                    const problem = !known ? 'Not a sound or video file EchoLedger can read.'
                        : file.size === 0 ? 'This file is empty.'
                        : file.size > this.cfg.maxBytes ? 'This file is larger than the ' + this.size(this.cfg.maxBytes) + ' limit.' : '';
                    this.files.push({
                        key: ++this.seq, file, name: file.name, size: file.size, type: file.type || '', duration: undefined,
                        state: problem ? 'refused' : 'waiting', message: problem, clientId: null, language: null,
                        sha: null, hashed: null, id: null, url: null, chunkSize: 0, missing: [], sent: 0, inflight: 0,
                        abort: null, cancelled: false, running: false,
                    });
                }
                if (this.$refs.input) this.$refs.input.value = '';
            },

            // One client and language for the whole batch; each file becomes its own recording.
            start() {
                for (const f of this.waiting) {
                    Object.assign(f, { clientId: this.clientId || null, language: this.language || null, state: 'queued', message: '' });
                }
                this.pump();
            },
            clearWaiting() { this.files = this.files.filter((f) => f.state !== 'waiting'); },

            pump() {
                while (this.active < PARALLEL) {
                    const next = this.files.find((f) => f.state === 'queued' && !f.running);
                    if (!next) return;
                    this.active++;
                    Object.assign(next, { running: true, state: 'hashing' });
                    this.run(next).finally(() => {
                        next.running = false;
                        this.active--;
                        this.pump();
                    });
                }
            },

            async run(f) {
                f.cancelled = false;
                f.abort = new AbortController();
                try {
                    if (!f.sha) {
                        f.state = 'hashing';
                        f.hashed = null;
                        f.sha = await hashFile(f.file, this.cfg.nativeHashMaxBytes, (part) => { f.hashed = part; }, () => f.cancelled);
                    }
                    if (f.cancelled) throw cancelled();
                    // The same file twice in the list goes up once.
                    if (!f.id && this.files.some((o) => o.key !== f.key && o.sha === f.sha && ['starting', 'uploading', 'finishing', 'paused', 'done'].includes(o.state))) {
                        Object.assign(f, { state: 'duplicate', message: 'Already in this list' });
                        return;
                    }
                    if (f.id) {
                        await this.resume(f);
                    } else {
                        await this.open(f);
                    }
                    if (f.state === 'duplicate') return;
                    await this.send(f);
                    await this.finish(f);
                } catch (e) {
                    if (e && e.name === 'AbortError') {
                        Object.assign(f, { state: 'cancelled', message: 'Cancelled' });
                    } else if (e instanceof Offline) {
                        Object.assign(f, { state: 'paused', message: 'Connection lost. The upload carries on when you are back online.' });
                    } else {
                        Object.assign(f, { state: e && e.final ? 'refused' : 'failed', message: (e && e.message) || 'The upload failed.' });
                    }
                }
            },

            async open(f) {
                f.state = 'starting';
                if (f.duration === undefined) f.duration = await durationOf(f.file);
                const res = await this.call(f, 'POST', this.cfg.store, JSON.stringify({
                    name: f.name, size: f.size, sha256: f.sha, type: f.type, duration_ms: f.duration,
                    client_id: f.clientId, language: f.language,
                }), { 'Content-Type': 'application/json' });
                if (res.status === 409 && res.json.code === 'duplicate_recording') {
                    Object.assign(f, { state: 'duplicate', message: res.json.message || 'Already in your Library', url: res.json.url || null });
                    return;
                }
                if (res.status !== 200 && res.status !== 201) throw this.failure(res);
                this.accept(f, res.json);
                this.refresh();
            },

            // After a dropped connection or a reload: ask the server which chunks it still needs.
            async resume(f) {
                f.state = 'starting';
                const res = await this.call(f, 'GET', this.url(f));
                if (res.status === 404) {
                    f.id = null;
                    return this.open(f);
                }
                if (res.status !== 200) throw this.failure(res);
                this.accept(f, res.json);
            },

            accept(f, state) {
                Object.assign(f, { id: state.recording_id, url: state.url || null, chunkSize: state.chunk_size, missing: state.missing_chunks || [] });
                f.sent = f.size - f.missing.reduce((sum, index) => sum + this.chunkLength(f, index), 0);
            },

            chunkLength(f, index) { return Math.max(0, Math.min(f.chunkSize, f.size - index * f.chunkSize)); },

            async send(f) {
                for (const index of [...f.missing]) {
                    if (f.cancelled) throw cancelled();
                    f.state = 'uploading';
                    const start = index * f.chunkSize;
                    const bytes = new Uint8Array(await f.file.slice(start, start + this.chunkLength(f, index)).arrayBuffer());
                    const res = await this.call(f, 'POST', this.url(f) + '/chunks/' + index, bytes, {
                        'Content-Type': 'application/octet-stream', 'Upload-Chunk-Sha256': await hashBytes(bytes),
                    }, (loaded) => { f.inflight = Math.min(loaded, bytes.length); });
                    f.inflight = 0;
                    if (res.status !== 200) throw this.failure(res);
                    f.sent += bytes.length;
                    f.missing = f.missing.filter((i) => i !== index);
                }
            },

            async finish(f) {
                for (let round = 0; round < 3; round++) {
                    f.state = 'finishing';
                    const res = await this.call(f, 'POST', this.url(f) + '/complete');
                    if (res.status === 202 || res.status === 200) {
                        Object.assign(f, {
                            state: 'done', url: res.json.url || f.url,
                            message: res.json.status === 'ready' ? 'Uploaded. The transcript is on its way.' : 'Uploaded. The server is finishing the file.',
                        });
                        this.refresh();
                        return;
                    }
                    if (res.status === 422 && res.json.code === 'upload_incomplete') {
                        this.accept(f, { recording_id: f.id, url: f.url, chunk_size: f.chunkSize, missing_chunks: res.json.missing_chunks });
                        await this.send(f);
                        continue;
                    }
                    if (res.status === 422 && res.json.code === 'verification_failed' && round === 0) {
                        // The server threw the parts away: send the whole file once more.
                        await this.resume(f);
                        await this.send(f);
                        continue;
                    }
                    throw this.failure(res);
                }
                throw new Error('The upload could not be finished. Try again.');
            },

            // Network failures and busy servers are tried again with growing pauses; offline waits for the connection.
            async call(f, method, url, body = null, headers = {}, onProgress = null) {
                for (let attempt = 0; ; attempt++) {
                    if (f.cancelled) throw cancelled();
                    const res = await request(method, url, { body, headers, csrf: this.cfg.csrf, signal: f.abort.signal, onProgress });
                    if (res.status !== 0 && res.status !== 429 && res.status < 500) return res;
                    if (res.status === 0 && (!navigator.onLine || attempt >= 5)) throw new Offline('offline');
                    if (attempt >= 5) return res;
                    await this.wait(f, Math.min(30000, 1000 * 2 ** attempt));
                }
            },

            wait(f, ms) {
                return new Promise((resolve, reject) => {
                    const timer = setTimeout(resolve, ms);
                    f.abort.signal.addEventListener('abort', () => { clearTimeout(timer); reject(cancelled()); }, { once: true });
                });
            },

            failure(res) {
                const signedOut = res.status === 401 || res.status === 419;
                const message = signedOut ? 'You were signed out. Reload the page, sign in and add the file again.'
                    : (res.json && res.json.message) || 'The upload failed (' + res.status + ').';
                return Object.assign(new Error(message), { final: FINAL_CODES.includes(res.json && res.json.code) });
            },

            async cancel(f) {
                if (f.state === 'waiting' || f.state === 'refused') return this.dismiss(f);
                f.cancelled = true;
                if (f.abort) f.abort.abort();
                Object.assign(f, { state: 'cancelled', message: 'Cancelled' });
                if (!f.id) return;
                const res = await request('DELETE', this.url(f), { csrf: this.cfg.csrf });
                if (res.status === 409) {
                    Object.assign(f, { state: 'done', message: res.json.message || 'Already uploaded.' });
                } else {
                    f.id = null;
                }
                this.refresh();
            },

            retry(f) {
                if (!this.canRetry(f)) return;
                Object.assign(f, { state: 'queued', message: '' });
                this.pump();
            },

            dismiss(f) { this.files = this.files.filter((x) => x.key !== f.key); },
            clearFinished() { this.files = this.files.filter((f) => !['done', 'duplicate', 'cancelled', 'refused'].includes(f.state)); },

            // The list shows the new rows at once; it then refreshes itself while anything is uploading or transcribing.
            refresh() {
                clearTimeout(this.refreshTimer);
                this.refreshTimer = setTimeout(() => this.$wire && this.$wire.$refresh(), 300);
            },

            url(f) { return this.cfg.store + '/' + encodeURIComponent(f.id); },

            percent(f) {
                if (f.state === 'done') return 100;
                if (f.state === 'hashing') return f.hashed === null ? 0 : Math.round(f.hashed * 100);
                return f.size ? Math.min(100, Math.round(((f.sent + f.inflight) / f.size) * 100)) : 0;
            },
            hasBar(f) { return BUSY.includes(f.state) && f.state !== 'queued'; },
            canCancel(f) { return BUSY.includes(f.state); },
            // Not before a cancelled run has really stopped (a file being hashed finishes its slice first).
            canRetry(f) { return ['failed', 'paused', 'cancelled'].includes(f.state) && !f.running; },
            canDismiss(f) { return !BUSY.includes(f.state); },

            status(f) {
                switch (f.state) {
                    case 'waiting': return 'Ready to upload';
                    case 'queued': return 'Waiting for the files before it';
                    case 'hashing': return f.hashed === null ? 'Checking the file…' : 'Checking the file… ' + this.percent(f) + '%';
                    case 'starting': return 'Starting…';
                    case 'uploading': return 'Uploading ' + this.size(f.sent + f.inflight) + ' of ' + this.size(f.size);
                    case 'finishing': return 'Finishing…';
                    default: return f.message;
                }
            },
            tone(f) {
                return { done: 'success', duplicate: 'info', failed: 'danger', refused: 'danger', paused: 'warning' }[f.state] || 'muted';
            },

            get summary() {
                if (this.busy()) return '';
                const count = (states) => this.files.filter((f) => states.includes(f.state)).length;
                return [
                    [count(['done']), 'uploaded'],
                    [count(['duplicate']), 'already in your Library'],
                    [count(['failed', 'refused']), 'not uploaded'],
                ].filter(([n]) => n > 0).map(([n, text]) => this.plural(n) + ' ' + text).join(' · ');
            },

            plural(n) { return n === 1 ? '1 file' : n + ' files'; },
            size(bytes) {
                const units = ['bytes', 'KB', 'MB', 'GB'];
                let value = bytes, unit = 0;
                while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++; }
                return (unit === 0 ? value : value.toFixed(value < 10 ? 1 : 0)) + ' ' + units[unit];
            },
        });
    })();
</script>
