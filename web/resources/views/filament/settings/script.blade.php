{{--
    Arriving from search (?highlight=id): the row is scrolled to the middle, focused, and drawn with a 16 % brand fill
    and a 2 px ring until the next click or key, or 8 seconds; with reduced motion, no scrolling animation and no fade.
    ↑ ↓ Enter in the search box move through the results. Since BE-06, under the new design, every radio group marked data-radio-keys (the
    profile's colours and language, Appearance's rows) answers the arrows, Home and End (← and → swapped right to left)
    and the focus follows the choice (resources/js/radio-keys-core.cjs decides).
--}}
{{-- Under the new design only: the classic console's pages stay exactly as they were (ClassicGoldenTest; plain PHP, so
     Livewire adds no block markers around it). --}}
<?php if (\App\Filament\Support\ConsoleV2::any()): ?>
<script>
    {!! file_get_contents(resource_path('js/radio-keys-core.cjs')) !!}

    (() => {
        if (window.elRadioKeys) return;
        window.elRadioKeys = true;
        document.addEventListener('keydown', (e) => {
            const radio = e.target instanceof Element ? e.target.closest('[role="radio"]') : null;
            const group = radio ? radio.closest('[role="radiogroup"][data-radio-keys]') : null;
            if (! group || e.altKey || e.ctrlKey || e.metaKey || e.defaultPrevented) return;
            const radios = Array.from(group.querySelectorAll('[role="radio"]'));
            const to = window.ElRadioKeysCore.next(radios.length, radios.indexOf(radio), e.key, getComputedStyle(group).direction === 'rtl');
            if (to === null) return;
            e.preventDefault();
            radios[to].click();
            radios[to].focus();
            // The page is drawn again once the choice is saved (a redraw or two): the focus stays on it for a moment.
            const row = group.closest('[id]');
            refocus = row ? { selector: '#' + CSS.escape(row.id) + ' [role="radiogroup"][data-radio-keys] [role="radio"][aria-checked="true"]', until: Date.now() + 3000 } : null;
        });
        let refocus = null;
        // After each redraw and Alpine's bindings on it (two frames): the focus back on the choice if a redraw took it.
        const watch = () => window.Livewire.hook('commit', ({ succeed }) => succeed(() => requestAnimationFrame(() => requestAnimationFrame(() => {
            if (! refocus || Date.now() > refocus.until) {
                refocus = null;
                return;
            }
            if (! document.activeElement || document.activeElement === document.body) document.querySelector(refocus.selector)?.focus({ preventScroll: true });
        }))));
        if (window.Livewire) watch();
        else document.addEventListener('livewire:init', watch, { once: true });
    })();
</script>
<?php endif; ?>
<script>
    (() => {
        if (window.elSettings) {
            window.elSettings.arrive();
            return;
        }
        const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let chosen = -1;
        const results = () => [...document.querySelectorAll('[data-settings-results] [data-result]')];

        function highlight(id) {
            const el = document.getElementById('setting-' + id);
            if (! el) return false;
            const details = el.closest('details');
            if (details) details.open = true;
            if (el.tagName === 'DETAILS') el.open = true;
            el.scrollIntoView({ block: 'center', behavior: reduced() ? 'auto' : 'smooth' });
            if (! el.hasAttribute('tabindex')) el.setAttribute('tabindex', '-1');
            el.focus({ preventScroll: true });
            el.classList.remove('is-fading');
            el.classList.add('is-highlighted');
            let done = false;
            const clear = () => {
                if (done) return;
                done = true;
                window.removeEventListener('pointerdown', clear, true);
                window.removeEventListener('keydown', clear, true);
                if (! reduced()) el.classList.add('is-fading');
                el.classList.remove('is-highlighted');
                setTimeout(() => el.classList.remove('is-fading'), 600);
            };
            setTimeout(() => {
                window.addEventListener('pointerdown', clear, true);
                window.addEventListener('keydown', clear, true);
            }, 100);
            setTimeout(clear, 8000);
            return true;
        }

        window.elSettings = {
            highlight,
            arrive() {
                const id = new URLSearchParams(window.location.search).get('highlight');
                if (id) setTimeout(() => highlight(id), 60);
            },
            move(step) {
                const list = results();
                if (list.length === 0) return;
                chosen = Math.max(0, Math.min(list.length - 1, chosen + step));
                list.forEach((el, i) => el.setAttribute('aria-selected', i === chosen ? 'true' : 'false'));
                list[chosen].scrollIntoView({ block: 'nearest' });
            },
            open() {
                const list = results();
                const target = list[chosen] ?? list[0];
                if (target) window.location.href = target.getAttribute('href');
            },
        };
        document.addEventListener('livewire:initialized', () => {
            window.Livewire.hook('morphed', () => { chosen = -1; });
        });
        window.elSettings.arrive();
    })();
</script>
