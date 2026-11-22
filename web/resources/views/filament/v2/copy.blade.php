{{--
    The v2.0 recording page's Copy menu (parity plan 4), only under ui_v2.library: it asks the player on the page (the
    same player as classic, unchanged) for its text, exactly as classic's own buttons do. text: Copy text, without
    timestamps (who said what, unsure words marked, the note line when there are marks); times: with timestamps; ai:
    Copy for AI after a short header (title, date, people, language). tests/js/v2-copy.test.mjs holds it to classic.
    Since U19 the text is in the view the player shows (Roman Urdu once it is made), and the header's language line
    then names that view ("Language: Roman Urdu"; the names are V2CopyForAi::VIEW_LANGUAGE).
    Also More › Edit text (WEB-REC-015): the player's own line editing, turned on as its Edit text button does.
--}}
<script>
    window.elV2Copy = window.elV2Copy || (async (mode, header) => {
        const root = document.querySelector('.el-player');
        const p = root && window.Alpine ? window.Alpine.$data(root) : null;
        if (! p || ! p.d || ! p.d.segments) return;
        const shown = p.view !== 'original' && p.viewReady(p.view) ? ({ roman: 'Roman Urdu', urdu: 'Urdu', english: 'English' })[p.view] : null;
        if (shown && header) header = /^Language: .*$/m.test(header) ? header.replace(/^Language: .*$/m, 'Language: ' + shown) : header + '\nLanguage: ' + shown;
        const text = mode === 'ai' ? header + '\n\n' + p.transcriptText(false, true) : p.transcriptText(mode === 'times', false);
        try {
            await navigator.clipboard.writeText(text);
            new FilamentNotification().title(@js(__('Copied'))).success().send();
        } catch (e) {
            new FilamentNotification().title(@js(__('Copy failed'))).danger().send();
        }
    });
    // More › Edit text (WEB-REC-015): the player's own line editing, as its Edit text button; Done editing ends it.
    window.elV2EditText = window.elV2EditText || (() => {
        const root = document.querySelector('.el-player');
        const p = root && window.Alpine ? window.Alpine.$data(root) : null;
        if (! p || ! p.d || ! p.d.editable) return;
        if (p.view !== 'original') p.pickView('original');
        p.panel = 'transcript';
        p.editing = true;
        root.scrollIntoView({ block: 'start', behavior: 'smooth' });
        setTimeout(() => { const first = root.querySelector('textarea.el-edit'); if (first) first.focus({ preventScroll: true }); }, 50);
    });
</script>
