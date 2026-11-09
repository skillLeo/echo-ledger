{{--
    Arriving from search (?highlight=id): the row is scrolled to the middle, focused, and drawn with a 16 % brand fill
    and a 2 px ring until the next click or key, or 8 seconds; with reduced motion, no scrolling animation and no fade.
    ↑ ↓ Enter in the search box move through the results.
--}}
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
