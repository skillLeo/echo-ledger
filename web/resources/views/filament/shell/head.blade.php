{{--
    The app shell's script (since v1.6, P4), in the head so it runs before Alpine: the person's sidebar (folded or not,
    its width) is put where Filament's sidebar store reads it, so every browser they sign in on starts the same; then
    the sidebar's behaviour, the keys that work on every page ("[" folds the sidebar, "?" opens Help & shortcuts, "/"
    searches) and recordings dragged onto a project, the Inbox or Pinned.
--}}
@php($shell = \App\Filament\Support\Shell::preferences())
<script>
    try {
        localStorage.setItem('filament:sidebar:desktop_open', @js($shell['collapsed'] ? 'false' : 'true'));
        if (window.matchMedia('(min-width: 1024px)').matches) {
            localStorage.setItem('filament:sidebar:open', @js($shell['collapsed'] ? 'false' : 'true'));
        }
    } catch (e) {
        // Storage off: Filament's own default (open) then.
    }
    document.documentElement.dataset.density = @js($shell['density']);

    window.elTyping = (target) => target instanceof Element && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || target.closest('[contenteditable=true]') !== null);

    window.elShellSidebar = window.elShellSidebar || ((config) => ({
        width: config.width,
        usual: config.usual,
        open: config.open,
        place: config.place,
        pages: config.pages,
        query: '',
        chosen: 0,

        init() {
            this.$watch('$store.sidebar.isOpenDesktop', (value) => {
                if (! this.$store.sidebar.isMobile) this.save('collapsed', ! value);
            });
        },
        rail() { return ! this.$store.sidebar.isMobile && ! this.$store.sidebar.isOpen; },
        expand(focus) {
            this.$store.sidebar.open();
            if (focus) this.$nextTick(() => this.$refs.find && this.$refs.find.focus());
        },
        toggle() {
            if (this.$store.sidebar.isOpen) this.$store.sidebar.close();
            else this.$store.sidebar.open();
        },
        isOpen(group) { return this.open.includes(group); },
        toggleGroup(group) {
            this.open = this.isOpen(group) ? this.open.filter((g) => g !== group) : [...this.open, group];
            this.save('open', this.open);
        },
        found() {
            const words = this.query.toLowerCase().split(/\s+/).filter(Boolean);
            if (words.length === 0) return [];
            return this.pages.filter((p) => {
                const hay = (p.label + ' ' + p.group + ' ' + p.words).toLowerCase();
                return words.every((w) => hay.includes(w));
            }).sort((a, b) => Number(! a.label.toLowerCase().startsWith(words[0])) - Number(! b.label.toLowerCase().startsWith(words[0]))).slice(0, 8);
        },
        findKey(e) {
            const list = this.found();
            if (e.key === 'Escape') {
                if (this.query) e.stopPropagation();
                this.query = '';
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.chosen = (this.chosen + 1) % Math.max(1, list.length);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.chosen = (this.chosen - 1 + list.length) % Math.max(1, list.length);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (list[this.chosen]) this.go(list[this.chosen]);
            }
        },
        go(page) {
            this.query = '';
            window.location.href = page.url;
        },
        setWidth(value, done) {
            const width = Math.round(Math.max(config.min, Math.min(config.max, value)));
            this.width = width;
            document.documentElement.style.setProperty('--sidebar-width', width + 'px');
            if (done) this.save('width', width);
        },
        startResize(e) {
            if (e.button !== 0) return;
            e.preventDefault();
            const startX = e.clientX;
            const start = this.width;
            const move = (m) => this.setWidth(start + m.clientX - startX, false);
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                document.body.classList.remove('el-resizing');
                this.setWidth(this.width, true);
            };
            document.body.classList.add('el-resizing');
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },
        resizeKey(e) {
            const step = e.shiftKey ? 40 : 16;
            if (e.key === 'ArrowLeft') this.setWidth(this.width - step, true);
            else if (e.key === 'ArrowRight') this.setWidth(this.width + step, true);
            else if (e.key === 'Home') this.setWidth(config.min, true);
            else if (e.key === 'End') this.setWidth(config.max, true);
            else return;
            e.preventDefault();
        },
        save(key, value) {
            if (this.$wire && typeof this.$wire.rememberShell === 'function') this.$wire.rememberShell(key, value);
        },
    }));

    if (! window.elShellKeys) {
        window.elShellKeys = true;
        document.addEventListener('keydown', (e) => {
            if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey || window.elTyping(e.target)) return;
            if (document.querySelector('.fi-modal-open[aria-modal="true"]')) return;
            if (e.key === '[') {
                const store = window.Alpine && window.Alpine.store('sidebar');
                if (store && ! store.isMobile) {
                    e.preventDefault();
                    if (store.isOpen) store.close(); else store.open();
                }
            } else if (e.key === '?') {
                e.preventDefault();
                window.dispatchEvent(new CustomEvent('el-help'));
            } else if (e.key === '/') {
                const field = document.querySelector('[data-el-search] input, .fi-ta-search-field input, .fi-global-search-field input');
                if (field) {
                    e.preventDefault();
                    field.focus();
                    field.select && field.select();
                }
            }
        });

        // Recordings dragged onto a place: the rows chosen with their box, or the one dragged.
        const TYPE = 'application/x-echoledger-recordings';
        document.addEventListener('dragstart', (e) => {
            const row = e.target instanceof Element ? e.target.closest('[data-recording][draggable=true]') : null;
            if (! row || ! e.dataTransfer) return;
            const id = row.dataset.recording;
            const box = row.closest('tr, .fi-ta-record')?.querySelector('input.fi-ta-record-checkbox');
            let ids = [id];
            if (box && box.checked) {
                ids = Array.from(document.querySelectorAll('input.fi-ta-record-checkbox:checked')).map((b) => b.value).filter(Boolean);
                if (! ids.includes(id)) ids.push(id);
            }
            e.dataTransfer.setData(TYPE, JSON.stringify(ids));
            e.dataTransfer.setData('text/plain', ids.length === 1 ? (row.querySelector('.el-row-title')?.textContent ?? '') : ids.length + ' recordings');
            e.dataTransfer.effectAllowed = 'move';
            document.body.classList.add('el-dragging-recordings');
        });
        document.addEventListener('dragend', () => {
            document.body.classList.remove('el-dragging-recordings');
            document.querySelectorAll('[data-el-drop].el-drop-over').forEach((el) => el.classList.remove('el-drop-over'));
        });
        const target = (e) => (e.target instanceof Element ? e.target.closest('[data-el-drop]') : null);
        const carries = (e) => !! (e.dataTransfer && Array.from(e.dataTransfer.types || []).includes(TYPE));
        document.addEventListener('dragover', (e) => {
            const place = target(e);
            if (! place || ! carries(e)) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            place.classList.add('el-drop-over');
        });
        document.addEventListener('dragleave', (e) => {
            const place = target(e);
            if (place && ! place.contains(e.relatedTarget)) place.classList.remove('el-drop-over');
        });
        document.addEventListener('drop', (e) => {
            const place = target(e);
            if (! place || ! carries(e)) return;
            e.preventDefault();
            place.classList.remove('el-drop-over');
            let ids = [];
            try { ids = JSON.parse(e.dataTransfer.getData(TYPE)); } catch (err) { return; }
            const host = place.closest('[wire\\:id]');
            const component = host && window.Livewire ? window.Livewire.find(host.getAttribute('wire:id')) : null;
            if (component && Array.isArray(ids) && ids.length) component.call('dropRecordings', place.dataset.elDrop, ids);
        });
    }
</script>
