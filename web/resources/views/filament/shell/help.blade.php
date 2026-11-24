{{--
    Help & shortcuts (since v1.6, P4): the sidebar's button, the top bar's ? and the ? key open it; Esc closes it. The
    keys work on every page except while you type.
--}}
@php
    $mac = str_contains((string) request()->userAgent(), 'Mac OS X');
    $inApp = \App\Filament\Support\Shell::inApp();
    // With the three levels (docs/PLAN_NAV_3_LEVELS.md section 6) the frame's own keys take the sidebar's [ line.
    $framed = $inApp && \App\Filament\Support\ConsoleV2::frame();
    $keys = [
        __('Everywhere') => [
            [$mac ? '⌘ K' : 'Ctrl K', __('Search recordings, transcripts, pages and settings, or type a command')],
            ['/', __('Search the list on this page')],
            ...($framed ? [
                [$mac ? '⌘ 1…6' : 'Ctrl 1…6', __('Library, Projects, Live, Ask, Activity, Settings')],
                [$mac ? '⌥ 1…9' : 'Alt 1…9', __('The panel\'s first nine (the Library\'s views, then My views)')],
                [$mac ? '⌘ \\  [' : 'Ctrl \\  [', __('Hide or show the panel')],
                [$mac ? '⌘ [  ⌘ ]' : 'Ctrl [  Ctrl ]', __('Back and forward')],
            ] : [['[', __('Fold the sidebar to icons, and back')]]),
            ['?', __('This list')],
            ['Esc', __('Close a panel, a menu or this list')],
        ],
    ];
    if ($inApp) {
        $keys[__('In the Library')] = [
            ['J  K', __('Next and previous recording')],
            ['Enter', __('Open it')],
            ['X', __('Select it')],
            ['Esc', __('Close the recording on the right, or clear the selection')],
        ];
        $keys[__('On a recording')] = [
            ['Space', __('Play or pause')],
            ['←  →', __('Back or forward 5 seconds (Shift: 10)')],
        ];
    }
@endphp
<div x-data="{ open: false }" x-on:el-help.window="open = true" x-on:keydown.escape.window="if (open) { open = false; $event.stopPropagation() }">
    <template x-if="open">
        <div class="el-help-backdrop" x-on:click.self="open = false">
            <div class="el-help" role="dialog" aria-modal="true" aria-labelledby="el-help-title" x-init="$nextTick(() => $el.querySelector('button')?.focus())">
                <header class="el-help-head">
                    <h2 id="el-help-title">{{ __('Help & shortcuts') }}</h2>
                    <button type="button" class="el-icon-btn" x-on:click="open = false" aria-label="{{ __('Close') }}">@svg('heroicon-o-x-mark', ['aria-hidden' => 'true'])</button>
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
                        {{-- Since v1.8 P6.4: the guide, What's new and Contact support. --}}
                        <a href="{{ \App\Filament\Pages\Help::getUrl() }}" class="el-text-btn">{{ __('Search the guide') }}</a>
                        <span aria-hidden="true">·</span>
                        <a href="{{ \App\Filament\Pages\Help::getUrl() }}#whats-new" class="el-text-btn">@lang("What's new")</a>
                        <span aria-hidden="true">·</span>
                        <a href="{{ \App\Filament\Pages\Help::getUrl() }}?contact=1" class="el-text-btn">{{ __('Contact support') }}</a>
                        <span aria-hidden="true">·</span>
                        <a href="{{ \App\Filament\Pages\GetTheApps::getUrl() }}" class="el-text-btn">{{ __('Get the apps') }}</a>
                        <span aria-hidden="true">·</span>
                        <a href="{{ \App\Filament\Auth\EditProfile::settingsUrl() }}" class="el-text-btn">{{ __('Settings') }}</a>
                    </footer>
                @endif
            </div>
        </div>
    </template>
</div>
