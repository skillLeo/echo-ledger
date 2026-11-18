{{--
    The v2.0 recording page's Copy menu (parity plan 4), only under ui_v2.library: it asks the player on the page (the
    same player as classic, unchanged) for its text. text: the words only; plain: who said what; times: with
    timestamps; ai: Copy for AI (the unsure-word note and screens) after a short header (title, date, people, language).
--}}
<script>
    window.elV2Copy = window.elV2Copy || (async (mode, header) => {
        const root = document.querySelector('.el-player');
        const p = root && window.Alpine ? window.Alpine.$data(root) : null;
        if (! p || ! p.d || ! p.d.segments) return;
        const text = mode === 'text' ? p.d.segments.map((s, i) => p.markedLine(i, 'off')).join(' ')
            : (mode === 'ai' ? header + '\n\n' + p.transcriptText(false, true) : p.transcriptText(mode === 'times', false));
        try {
            await navigator.clipboard.writeText(text);
            new FilamentNotification().title(@js(__('Copied'))).success().send();
        } catch (e) {
            new FilamentNotification().title(@js(__('Copy failed'))).danger().send();
        }
    });
</script>
