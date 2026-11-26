{{--
    The v2.0 recording page's moment markers on the player bar (parity plan 4, WEB-REC-010), only under ui_v2.library:
    the bar is the player's own (the same as classic, unchanged), and its ticks (the bookmarks, and the pauses) become
    a moment each: a click plays from it exactly, and hovering says which it is. The list under the tools, "Bookmarks
    and pauses", stays as it is (and is the keyboard's way to the same moments). resources/js/recording-markers-core.cjs
    decides.
--}}
<script>
    {!! file_get_contents(resource_path('js/recording-markers-core.cjs')) !!}

    (() => {
        if (window.elV2RecordingMarkers) return;
        window.elV2RecordingMarkers = true;
        const core = window.ElRecordingMarkersCore;
        const words = @js(['bookmark' => __('Bookmark'), 'paused' => trim(__('Paused at '))]);
        const read = (tick) => {
            const root = tick.closest('.el-player');
            const player = root && window.Alpine ? window.Alpine.$data(root) : null;
            if (! player || ! player.d) return null;
            const pause = tick.classList.contains('el-tick-pause');
            const same = [...tick.parentElement.querySelectorAll(pause ? '.el-tick.el-tick-pause' : '.el-tick:not(.el-tick-pause)')];
            return { player, item: core.moment(player.d, pause, same.indexOf(tick)) };
        };
        // A click on a tick plays from its moment, not from wherever the pointer was on the bar.
        document.addEventListener('click', (e) => {
            const tick = e.target instanceof Element ? e.target.closest('.el-player .el-wave .el-tick') : null;
            const found = tick && read(tick);
            if (! found || ! found.item) return;
            e.stopPropagation();
            found.player.seekTo(found.item.ms);
        }, true);
        // Hovering says what it is.
        document.addEventListener('pointerover', (e) => {
            const tick = e.target instanceof Element ? e.target.closest('.el-player .el-wave .el-tick') : null;
            const found = tick && read(tick);
            if (found && found.item) tick.title = core.label(found.item, words);
        });
    })();
</script>
