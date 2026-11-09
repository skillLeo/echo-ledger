{{--
    Notices (since v1.6.1, docs/DESIGN_SYSTEM.md "Notices"): one behaviour for every toast and inline banner of the
    console and the admin panel, on every page (the sign-in pages too). The decisions are plain functions in
    resources/js/notice-core.cjs (tested under Node); this part follows the pointer.

    - elToast(base, options): over Filament's notificationComponent (its timer, its pause on hover and focus, its
      close and its animations): the whole card opens its target, a finger on it pauses the timer too, a swipe left or
      right closes it (touch, a mouse drag or a trackpad's two-finger swipe; past a third of its width or a flick, else
      it springs back; a drag is never a click).
    - elBanner(options): a banner about something still true: no timer, × and swipe close it until its state changes
      or the next visit (sessionStorage), the announcement for good per text (localStorage).
    - Esc closes the newest toast (not while typing, not under a dialog).
--}}
<script>
    {!! file_get_contents(resource_path('js/notice-core.cjs')) !!}

    (() => {
        if (window.elToast) return;
        const core = window.ElNoticeCore;
        const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const typing = (target) => target instanceof Element && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

        /**
         * A swipe on [el]: follows the pointer (or a trackpad's wheel) sideways, then leaves or springs back. [hooks]:
         * start() and end() around a drag, leave(direction) once it has gone. Returns a function that unhooks it.
         */
        const swipe = (el, hooks) => {
            let start = null;
            let dx = 0;
            let dragging = false;
            let samples = [];
            let wheelTimer = null;
            const width = () => el.getBoundingClientRect().width || 1;
            const paint = () => {
                el.style.transform = dx === 0 ? '' : 'translateX(' + dx + 'px)';
                el.style.opacity = dx === 0 ? '' : String(core.opacityFor(dx, width()));
            };
            const velocity = () => {
                const now = performance.now();
                const recent = samples.filter((s) => now - s.t < 120);
                if (recent.length < 2) return 0;
                const first = recent[0];
                const last = recent[recent.length - 1];
                return last.t > first.t ? (last.x - first.x) / (last.t - first.t) : 0;
            };
            const finish = () => {
                const result = core.outcome(dx, width(), velocity());
                samples = [];
                if (result === 'back') {
                    if (!reduced()) {
                        el.style.transition = 'transform 200ms cubic-bezier(0.2, 0, 0, 1), opacity 200ms';
                        setTimeout(() => (el.style.transition = ''), 220);
                    }
                    dx = 0;
                    paint();
                    hooks.end(false);
                    return;
                }
                const direction = result === 'left' ? -1 : 1;
                if (reduced()) {
                    el.style.opacity = '0';
                    hooks.end(true);
                    hooks.leave(direction);
                    return;
                }
                el.style.transition = 'transform 160ms cubic-bezier(0, 0, 0, 1), opacity 160ms';
                el.style.transform = 'translateX(' + direction * width() * 1.1 + 'px)';
                el.style.opacity = '0';
                hooks.end(true);
                setTimeout(() => hooks.leave(direction), 160);
            };
            const down = (e) => {
                if (e.button !== 0 && e.pointerType === 'mouse') return;
                if (e.target instanceof Element && e.target.closest('button, input, textarea, select, [data-no-swipe]')) return;
                start = { x: e.clientX, y: e.clientY, id: e.pointerId };
                dx = 0;
                dragging = false;
                samples = [{ x: 0, t: performance.now() }];
                hooks.press?.(e);
            };
            const move = (e) => {
                if (start === null || e.pointerId !== start.id) return;
                const mx = e.clientX - start.x;
                const my = e.clientY - start.y;
                if (!dragging) {
                    if (!core.isDrag(mx, my)) return;
                    dragging = true;
                    el.setPointerCapture?.(e.pointerId);
                    el.classList.add('el-dragging');
                    hooks.start();
                }
                e.preventDefault();
                dx = mx;
                samples.push({ x: dx, t: performance.now() });
                paint();
            };
            const up = (e) => {
                if (start === null || e.pointerId !== start.id) return;
                start = null;
                hooks.release?.(e);
                if (!dragging) return;
                dragging = false;
                el.classList.remove('el-dragging');
                finish();
            };
            const wheel = (e) => {
                const step = core.wheelStep(e.deltaX, e.deltaY);
                if (step === 0) return;
                e.preventDefault();
                if (wheelTimer === null) {
                    hooks.start();
                    el.classList.add('el-dragging');
                    samples = [{ x: 0, t: performance.now() }];
                }
                dx += step;
                samples.push({ x: dx, t: performance.now() });
                paint();
                clearTimeout(wheelTimer);
                wheelTimer = setTimeout(() => {
                    wheelTimer = null;
                    el.classList.remove('el-dragging');
                    finish();
                }, 140);
            };
            el.addEventListener('pointerdown', down);
            el.addEventListener('pointermove', move);
            el.addEventListener('pointerup', up);
            el.addEventListener('pointercancel', up);
            el.addEventListener('wheel', wheel, { passive: false });
            return () => {
                el.removeEventListener('pointerdown', down);
                el.removeEventListener('pointermove', move);
                el.removeEventListener('pointerup', up);
                el.removeEventListener('pointercancel', up);
                el.removeEventListener('wheel', wheel);
            };
        };
        window.elSwipe = swipe;

        /** A toast (or a card in the bell's list): Filament's notificationComponent with the notice rule over it. */
        window.elToast = (base, options) => {
            const baseInit = base.init;
            const baseDestroy = base.destroy;
            return Object.assign(base, {
                dragging: false,
                touching: false,
                dragged: false,
                unhook: null,
                init() {
                    baseInit.call(this);
                    const el = this.$el;
                    el.dataset.elAt = String(performance.now());
                    // A drag is never a click: the click that ends a drag goes nowhere.
                    el.addEventListener('click', (e) => {
                        if (this.dragged) {
                            e.preventDefault();
                            e.stopPropagation();
                            this.dragged = false;
                        }
                    }, true);
                    this.unhook = swipe(el, {
                        press: (e) => {
                            if (e.pointerType !== 'mouse' && !options.inline) {
                                this.touching = true;
                                this.pauseDuration('hover');
                            }
                        },
                        release: (e) => {
                            if (e.pointerType !== 'mouse' && !options.inline) {
                                this.touching = false;
                                this.resumeDuration('hover');
                            }
                        },
                        start: () => {
                            this.dragging = true;
                            this.dragged = true;
                            if (!options.inline) this.pauseDuration('hover');
                        },
                        end: (gone) => {
                            this.dragging = false;
                            setTimeout(() => (this.dragged = false), 0);
                            if (!gone && !options.inline && !el.matches(':hover')) this.resumeDuration('hover');
                        },
                        leave: () => this.close(true),
                    });
                },
                /** The card's link was followed: the notice closes as it goes. */
                opened(event) {
                    if (this.dragged) return;
                    if (options.inline) return;
                    if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return;
                    this.close(true);
                },
                destroy() {
                    this.unhook?.();
                    baseDestroy?.call(this);
                },
            });
        };

        /**
         * An inline banner about something still true. [options]: id, state (what it is about now), remember ('visit'
         * or 'text'), text (the announcement's).
         */
        window.elBanner = (options) => {
            const store = () => {
                try {
                    return options.remember === 'text' ? window.localStorage : window.sessionStorage;
                } catch (e) {
                    return null;
                }
            };
            const key = core.storageKey(options.id, options.remember === 'text' ? core.hash(options.text) : options.state);
            return {
                open: true,
                dragged: false,
                unhook: null,
                init() {
                    try {
                        this.open = store()?.getItem(key) !== '1';
                    } catch (e) {
                        this.open = true;
                    }
                    const el = this.$el;
                    el.addEventListener('click', (e) => {
                        if (this.dragged) {
                            e.preventDefault();
                            e.stopPropagation();
                            this.dragged = false;
                        }
                    }, true);
                    this.unhook = swipe(el, {
                        start: () => (this.dragged = true),
                        end: () => setTimeout(() => (this.dragged = false), 0),
                        leave: () => this.dismiss(),
                    });
                },
                dismiss() {
                    this.open = false;
                    try {
                        store()?.setItem(key, '1');
                    } catch (e) {
                        // Storage off: closed for this page only.
                    }
                },
                destroy() {
                    this.unhook?.();
                },
            };
        };

        // Esc closes the newest toast.
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape' || e.defaultPrevented || typing(e.target)) return;
            if (document.querySelector('.fi-modal-open[aria-modal="true"], .el-help')) return;
            const shown = Array.from(document.querySelectorAll('[data-el-toast="toast"]'))
                .filter((el) => el.offsetParent !== null && el.style.visibility !== 'hidden')
                .map((el) => ({ el, at: Number(el.dataset.elAt || 0) }));
            const newest = core.newest(shown);
            if (newest === null || !window.Alpine) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            window.Alpine.$data(newest.el).dismiss();
        }, true);
    })();
</script>
