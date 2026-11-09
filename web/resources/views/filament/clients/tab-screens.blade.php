{{--
    The project's Screens tab (v1.5): what was on the shared screen in its meetings (and its subfolders'), newest
    recording first, each recording's screens in time order. A screen opens large with the ones before and after it.
--}}
@php
    $shots = [];
    foreach ($groups as $group) {
        foreach ($group['screens'] as $screen) {
            $shots[] = ['label' => 'Screen '.$screen['index'].' · '.$screen['clock'], 'image' => $screen['image'], 'description' => $screen['description'],
                'url' => $screen['url'], 'title' => $group['title'], 'clock' => $screen['clock']];
        }
    }
@endphp
<div class="el-screens-tab" style="display: flex; flex-direction: column; gap: var(--space-5); max-width: 1080px"
    x-data="{ shots: @js($shots), shot: null,
        open(k) { this.shot = k; this.$nextTick(() => this.$refs.close && this.$refs.close.focus()); },
        move(step) { if (this.shot !== null) this.shot = Math.min(this.shots.length - 1, Math.max(0, this.shot + step)); } }"
    x-on:keydown.escape="shot = null" x-on:keydown.left="shot !== null && move(-1)" x-on:keydown.right="shot !== null && move(1)">
    <style>
        .el-screens-tab .el-screen-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: var(--space-3); }
        .el-screens-tab .el-screen { display: flex; flex-direction: column; gap: var(--space-2); padding: 6px; border: 1px solid var(--border); border-radius: var(--radius-md);
            background: var(--surface); color: var(--text); text-align: start; cursor: zoom-in; }
        .el-screens-tab .el-screen:hover { background: var(--surface-2); }
        .el-screens-tab .el-screen img { width: 100%; height: auto; aspect-ratio: 16 / 9; object-fit: cover; border-radius: var(--radius-sm); background: var(--surface-2); }
        .el-screens-tab .el-screen-cap { display: flex; flex-direction: column; gap: 2px; font-size: 13px; line-height: 18px; color: var(--text-muted); unicode-bidi: plaintext; }
        .el-screens-tab .el-screen-cap strong { color: var(--text); font-weight: 600; font-variant-numeric: tabular-nums; }
        .el-screens-tab .el-group-head { display: flex; flex-wrap: wrap; gap: var(--space-1) var(--space-3); align-items: baseline; margin-bottom: var(--space-2); }
        .el-screens-tab .el-group-head a { color: inherit; text-decoration: none; }
        .el-screens-tab .el-group-head a:hover { text-decoration: underline; }
        .el-screens-tab .el-group-when { font-size: 13px; color: var(--text-muted); }
        .el-screens-tab .el-shot { position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; padding: 24px; background: rgba(0,0,0,.72); }
        .el-screens-tab .el-shot-box { display: flex; flex-direction: column; gap: var(--space-3); max-width: min(1200px, 100%); max-height: 100%; margin: 0; padding: var(--space-4);
            border-radius: var(--radius-lg); background: var(--surface); color: var(--text); overflow: auto; }
        .el-screens-tab .el-shot-box img { max-width: 100%; max-height: 70vh; object-fit: contain; border-radius: var(--radius-sm); }
        .el-screens-tab .el-shot-box figcaption { display: flex; flex-direction: column; gap: 4px; font-size: 14px; color: var(--text-muted); unicode-bidi: plaintext; }
        .el-screens-tab .el-shot-tools { display: flex; flex-wrap: wrap; gap: var(--space-2); align-items: center; }
        @media (max-width: 640px) { .el-screens-tab .el-screen-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .el-screens-tab .el-shot { padding: 12px; } }
    </style>

    {{-- Since v1.6.2: without screen moments in the plan, the plan's badge and the upgrade sheet (screens kept before stay). --}}
    @if ($screensLocked ?? false)
        <div class="el-card" role="note" data-screens-locked style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3)">
            <p style="margin: 0; font-size: 14px">{!! $screensBadge?->toHtml() !!} Screen moments are not in your plan: what was on the screen during a meeting, next to the words that were said.</p>
            <button type="button" class="el-text-btn" wire:click="mountAction('upgradeScreens')">See plans</button>
        </div>
    @endif

    @if ($groups === [])
        <div class="el-empty">
            <span class="el-empty-icon">@svg('heroicon-o-computer-desktop', ['aria-hidden' => 'true'])</span>
            <p class="el-empty-title">No screens yet</p>
            <p class="el-empty-text">When a meeting filed under {{ $client->name }} shares a screen, the desktop app keeps what was shown at each moment, and it shows here.</p>
        </div>
    @else
        @php($k = 0)
        @foreach ($groups as $group)
            <section aria-label="{{ $group['title'] }}" wire:key="screens-{{ $loop->index }}">
                <div class="el-group-head">
                    <h3 class="el-card-title"><a href="{{ $group['url'] }}" wire:navigate dir="auto">{{ $group['title'] }}</a></h3>
                    <span class="el-group-when">{{ $group['when'] }} · {{ count($group['screens']) === 1 ? '1 screen' : count($group['screens']).' screens' }}</span>
                </div>
                <div class="el-screen-grid">
                    @foreach ($group['screens'] as $screen)
                        <button type="button" class="el-screen" x-on:click="open({{ $k }})" aria-label="Open Screen {{ $screen['index'] }} at {{ $screen['clock'] }}">
                            <img src="{{ $screen['thumb'] }}" alt="" loading="lazy">
                            <span class="el-screen-cap">
                                <strong>Screen {{ $screen['index'] }} · {{ $screen['clock'] }}</strong>
                                @if ($screen['description'] !== null)<span>{{ $screen['description'] }}</span>@endif
                            </span>
                        </button>
                        @php($k++)
                    @endforeach
                </div>
            </section>
        @endforeach
        @if (count($groups) >= $limit)
            <p class="el-empty-text">The screens of the newest {{ $limit }} recordings. Older ones are on each recording's page.</p>
        @endif

        <div class="el-shot" x-show="shot !== null" x-cloak role="dialog" aria-modal="true" aria-label="Screen" x-on:click.self="shot = null">
            <template x-if="shot !== null && shots[shot]">
                <figure class="el-shot-box">
                    <img :src="shots[shot].image" :alt="shots[shot].label + (shots[shot].description ? ': ' + shots[shot].description : '')">
                    <figcaption>
                        <strong x-text="shots[shot].title + ' · ' + shots[shot].label"></strong>
                        <span x-show="shots[shot].description" x-text="shots[shot].description"></span>
                    </figcaption>
                    <div class="el-shot-tools">
                        <x-filament::button color="gray" size="sm" x-bind:disabled="shot === 0" x-on:click="move(-1)">Previous</x-filament::button>
                        <x-filament::button color="gray" size="sm" x-bind:disabled="shot === shots.length - 1" x-on:click="move(1)">Next</x-filament::button>
                        <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-play" x-bind:href="shots[shot].url">
                            <span x-text="'Play from ' + shots[shot].clock"></span>
                        </x-filament::button>
                        <span style="flex: 1"></span>
                        <x-filament::button color="gray" size="sm" x-ref="close" x-on:click="shot = null">Close</x-filament::button>
                    </div>
                </figure>
            </template>
        </div>
    @endif
</div>
