{{-- Library → Upload (resources/views/filament/library/uploader.blade.php): hashing, chunked upload, resume and cancel. --}}
<script>
    window.elUploader = window.elUploader || (() => {
        const K = new Uint32Array([
            0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
            0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
            0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
            0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
            0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
            0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
            0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
            0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
        ]);

        // SHA-256 fed slice by slice: the browser's own (SubtleCrypto) needs the whole file in memory, and plain-http
        // pages do not have it at all.
        class Sha256 {
            constructor() {
                this.h = new Uint32Array([0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19]);
                this.w = new Uint32Array(64);
                this.buffer = new Uint8Array(64);
                this.buffered = 0;
                this.length = 0;
            }

            update(data) {
                let i = 0;
                this.length += data.length;
                if (this.buffered > 0) {
                    i = Math.min(64 - this.buffered, data.length);
                    this.buffer.set(data.subarray(0, i), this.buffered);
                    this.buffered += i;
                    if (this.buffered < 64) return this;
                    this.block(this.buffer, 0);
                    this.buffered = 0;
                }
                for (; i + 64 <= data.length; i += 64) this.block(data, i);
                if (i < data.length) {
                    this.buffer.set(data.subarray(i), 0);
                    this.buffered = data.length - i;
                }
                return this;
            }

            block(d, o) {
                const w = this.w, h = this.h;
                for (let i = 0; i < 16; i++, o += 4) w[i] = (d[o] << 24) | (d[o + 1] << 16) | (d[o + 2] << 8) | d[o + 3];
                for (let i = 16; i < 64; i++) {
                    const x = w[i - 15], y = w[i - 2];
                    const s0 = ((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3);
                    const s1 = ((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10);
                    w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
                }
                let a = h[0], b = h[1], c = h[2], dd = h[3], e = h[4], f = h[5], g = h[6], hh = h[7];
                for (let i = 0; i < 64; i++) {
                    const s1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
                    const t1 = (hh + s1 + ((e & f) ^ (~e & g)) + K[i] + w[i]) | 0;
                    const s0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
                    const t2 = (s0 + ((a & b) ^ (a & c) ^ (b & c))) | 0;
                    hh = g; g = f; f = e; e = (dd + t1) | 0; dd = c; c = b; b = a; a = (t1 + t2) | 0;
                }
                h[0] += a; h[1] += b; h[2] += c; h[3] += dd; h[4] += e; h[5] += f; h[6] += g; h[7] += hh;
            }

            hex() {
                const bits = this.length * 8;
                const pad = new Uint8Array((this.buffered < 56 ? 56 : 120) - this.buffered + 8);
                const high = Math.floor(bits / 0x100000000), low = bits >>> 0, n = pad.length;
                pad[0] = 0x80;
                for (let i = 0; i < 4; i++) {
                    pad[n - 8 + i] = (high >>> (24 - 8 * i)) & 255;
                    pad[n - 4 + i] = (low >>> (24 - 8 * i)) & 255;
                }
                this.update(pad);
                return Array.from(this.h, (v) => v.toString(16).padStart(8, '0')).join('');
            }
        }

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
