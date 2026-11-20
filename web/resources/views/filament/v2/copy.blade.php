{{--
    The v2.0 recording page's Copy menu (parity plan 4), only under ui_v2.library: it asks the player on the page (the
    same player as classic, unchanged) for its text, exactly as classic's own buttons do. text: Copy text, without
    timestamps (who said what, unsure words marked, the note line when there are marks); times: with timestamps; ai:
    Copy for AI after a short header (title, date, people, language). tests/js/v2-copy.test.mjs holds it to classic.
--}}
<script>
    window.elV2Copy = window.elV2Copy || (async (mode, header) => {
        const root = document.querySelector('.el-player');
        const p = root && window.Alpine ? window.Alpine.$data(root) : null;
        if (! p || ! p.d || ! p.d.segments) return;
        const text = mode === 'ai' ? header + '\n\n' + p.transcriptText(false, true) : p.transcriptText(mode === 'times', false);
        try {
            await navigator.clipboard.writeText(text);
            new FilamentNotification().title(@js(__('Copied'))).success().send();
        } catch (e) {
            new FilamentNotification().title(@js(__('Copy failed'))).danger().send();
        }
    });
</script>
