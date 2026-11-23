{{--
    Projects' panel (docs/PLAN_NAV_3_LEVELS.md section 3): All projects, Pinned, Recent and + New project, then the
    projects (each folder before its subfolders) with how many recordings each holds; a search over them past ten.
    The web keeps no archive of projects, so there is no Archived.
--}}
@php $panel = \App\Filament\Support\NavFrame::projectsPanel(); @endphp
<ul class="v2-sb-sub" aria-label="{{ __('Views') }}">
    @foreach ($panel['views'] as $view)
        <li>
            <a href="{{ $view['url'] }}" @class(['v2-sb-subitem', 'is-on' => $view['active']]) @if ($view['active']) aria-current="page" @endif data-projects-view="{{ $view['key'] }}">
                <x-el.v2.icon :name="['all' => 'folder', 'pinned' => 'pin', 'recent' => 'clock'][$view['key']]" />
                <span class="v2-sb-label">{{ $view['label'] }}</span>
            </a>
        </li>
    @endforeach
    @if ($panel['create'] !== null)
        <li>
            <a href="{{ $panel['create'] }}" class="v2-sb-subitem" data-new-project>
                <x-el.v2.icon name="plus" />
                <span class="v2-sb-label">{{ __('New project') }}</span>
            </a>
        </li>
    @endif
</ul>
@if ($panel['projects'] !== [])
    <div class="v2-sb-group" x-data="{ find: '' }">
        <p class="v2-panel-group-title">{{ __('Projects') }}</p>
        @if (count($panel['projects']) > 10)
            <input type="search" class="v2-panel-find" x-model="find" placeholder="{{ __('Find a project') }}" aria-label="{{ __('Find a project') }}" data-panel-find>
        @endif
        <ul class="v2-sb-sub" aria-label="{{ __('Projects') }}">
            @foreach ($panel['projects'] as $project)
                <li x-bind:hidden="find !== '' && ! @js(mb_strtolower($project['name'])).includes(find.toLowerCase())">
                    <a href="{{ $project['url'] }}" @class(['v2-sb-subitem', 'is-child' => $project['depth'] > 0, 'is-on' => $project['active']])
                        @if ($project['active']) aria-current="page" @endif data-project="{{ $project['id'] }}">
                        <span class="v2-sb-label" dir="auto"><bdi>{{ $project['name'] }}</bdi></span>
                        @if ($project['count'] > 0)
                            <span class="v2-sb-subcount">{{ \Illuminate\Support\Number::format($project['count']) }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
