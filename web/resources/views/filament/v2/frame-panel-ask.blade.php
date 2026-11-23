{{--
    Ask's panel (docs/PLAN_NAV_3_LEVELS.md section 3): + New question, then the projects to ask about, with a search
    over them past ten. The web keeps no list of earlier questions (each answer is on the page that asked it).
--}}
@php $panel = \App\Filament\Support\NavFrame::askPanel(); @endphp
@if ($panel['new'] !== null)
    <ul class="v2-sb-sub" aria-label="{{ __('Ask') }}">
        <li>
            <a href="{{ $panel['new'] }}" class="v2-sb-subitem" data-new-question>
                <x-el.v2.icon name="plus" />
                <span class="v2-sb-label">{{ __('New question') }}</span>
            </a>
        </li>
    </ul>
    @if ($panel['projects'] !== [])
        <div class="v2-sb-group" x-data="{ find: '' }">
            <p class="v2-panel-group-title">{{ __('Ask about') }}</p>
            @if (count($panel['projects']) > 10)
                <input type="search" class="v2-panel-find" x-model="find" placeholder="{{ __('Find a project') }}" aria-label="{{ __('Find a project') }}" data-panel-find>
            @endif
            <ul class="v2-sb-sub" aria-label="{{ __('Ask about') }}">
                @foreach ($panel['projects'] as $project)
                    <li x-bind:hidden="find !== '' && ! @js(mb_strtolower($project['name'])).includes(find.toLowerCase())">
                        <a href="{{ $project['url'] }}" @class(['v2-sb-subitem', 'is-child' => $project['depth'] > 0, 'is-on' => $project['active']])
                            @if ($project['active']) aria-current="page" @endif data-ask-project="{{ $project['id'] }}">
                            <span class="v2-sb-label" dir="auto"><bdi>{{ $project['name'] }}</bdi></span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endif
