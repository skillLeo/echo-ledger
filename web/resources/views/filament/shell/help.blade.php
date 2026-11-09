{{--
    Help & shortcuts (since v1.6, P4): the sidebar's button, the top bar's ? and the ? key open it; Esc closes it. The
    keys work on every page except while you type.
--}}
@php
    $mac = str_contains((string) request()->userAgent(), 'Mac OS X');
    $inApp = \App\Filament\Support\Shell::inApp();
    $keys = [
        'Everywhere' => [
            [$mac ? '⌘ K' : 'Ctrl K', 'Search recordings, transcripts and pages, or type a command'],
            ['/', 'Search the list on this page'],
            ['[', 'Fold the sidebar to icons, and back'],
            ['?', 'This list'],
            ['Esc', 'Close a panel, a menu or this list'],
        ],
    ];
    if ($inApp) {
        $keys['In the Library'] = [
            ['J  K', 'Next and previous recording'],
            ['Enter', 'Open it'],
            ['X', 'Select it'],
            ['Esc', 'Close the recording on the right, or clear the selection'],
        ];
        $keys['On a recording'] = [
            ['Space', 'Play or pause'],
            ['←  →', 'Back or forward 5 seconds (Shift: 10)'],
        ];
    }
@endphp
<div x-data="{ open: false }" x-on:el-help.window="open = true" x-on:keydown.escape.window="if (open) { open = false; $event.stopPropagation() }">
    <template x-if="open">
        <div class="el-help-backdrop" x-on:click.self="open = false">
            <div class="el-help" role="dialog" aria-modal="true" aria-labelledby="el-help-title" x-init="$nextTick(() => $el.querySelector('button')?.focus())">
                <header class="el-help-head">
                    <h2 id="el-help-title">Help &amp; shortcuts</h2>
                    <button type="button" class="el-icon-btn" x-on:click="open = false" aria-label="Close">@svg('heroicon-o-x-mark', ['aria-hidden' => 'true'])</button>
                </header>
                @foreach ($keys as $section => $rows)
                    <section class="el-help-section">
                        <h3>{{ $section }}</h3>
                        <dl>
                            @foreach ($rows as [$key, $what])
                                <div class="el-help-row"><dt><kbd class="el-kbd">{{ $key }}</kbd></dt><dd>{{ $what }}</dd></div>
                            @endforeach
                        </dl>
                    </section>
                @endforeach
                @if ($inApp)
                    <footer class="el-help-foot">
                        <a href="{{ \App\Filament\Pages\GetTheApps::getUrl() }}" class="el-text-btn">Get the apps</a>
                        <span aria-hidden="true">·</span>
                        <a href="{{ (\Filament\Facades\Filament::getPanel('app')->getProfileUrl() ?? '/app/profile').'?tab=preferences' }}" class="el-text-btn">Preferences</a>
                    </footer>
                @endif
            </div>
        </div>
    </template>
</div>
