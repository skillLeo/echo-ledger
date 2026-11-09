{{--
    The nested column of the Library and the project pages (since v1.6, P4.2), as in the desktop app: All · Waiting ·
    Pinned · Failed uploads, the smart folders with their counts, then the projects with "+ New project". Recordings
    dragged onto the Inbox or a project move there, onto Pinned they are pinned. Shown from 1200 px; below that the
    Library's Show and Folder menus do the same.
--}}
@php
    use App\Filament\Resources\Clients\ClientResource;
    use App\Services\Workspaces\ConsoleAccess;

    $places ??= [];
    $active ??= null;
    $projects ??= [];
    $currentProject ??= null;
    $placeAction ??= null;
    $canMove = ConsoleAccess::full() && ! ConsoleAccess::readOnly();
    $canCreate = ClientResource::canAccess() && ClientResource::canCreate() && ! ConsoleAccess::readOnly();
@endphp
<aside class="el-col" aria-label="{{ $places !== [] ? 'Library places and projects' : 'Projects' }}">
    @if ($places !== [])
        <ul class="el-col-list" aria-label="Library">
            @foreach ($places as $p)
                @php($on = $active === $p['key'])
                <li @if ($canMove && $p['drop']) data-el-drop="{{ $p['drop'] }}" @endif>
                    @if ($placeAction !== null && $p['key'] !== 'shared')
                        <button type="button" @class(['el-col-item', 'el-on' => $on]) @if ($on) aria-current="true" @endif
                            wire:click="{{ $placeAction }}('{{ $p['key'] }}')" data-place="{{ $p['key'] }}">
                            @svg(($on ? 'heroicon-s-' : 'heroicon-o-').$p['icon'], ['aria-hidden' => 'true'])
                            <span class="el-col-label">{{ $p['label'] }}</span>
                            @if (($p['count'] ?? 0) > 0)
                                <span class="el-col-count">{{ number_format($p['count']) }}</span>
                            @endif
                        </button>
                    @else
                        <a href="{{ $p['url'] }}" @class(['el-col-item', 'el-on' => $on]) @if ($on) aria-current="true" @endif data-place="{{ $p['key'] }}">
                            @svg(($on ? 'heroicon-s-' : 'heroicon-o-').$p['icon'], ['aria-hidden' => 'true'])
                            <span class="el-col-label">{{ $p['label'] }}</span>
                            @if (($p['count'] ?? 0) > 0)
                                <span class="el-col-count">{{ number_format($p['count']) }}</span>
                            @endif
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if (ClientResource::canAccess())
        <div class="el-col-head">
            <p class="el-col-heading">Projects</p>
            @if ($canCreate)
                <a href="{{ ClientResource::getUrl('create') }}" class="el-col-add" title="New project" aria-label="New project">
                    @svg('heroicon-o-plus', ['aria-hidden' => 'true'])
                </a>
            @endif
        </div>
        @if ($projects === [])
            <p class="el-col-empty">No projects yet.</p>
        @else
            <ul class="el-col-list" aria-label="Projects">
                @foreach ($projects as $project)
                    @php($on = $currentProject === $project['id'])
                    <li @if ($canMove) data-el-drop="project:{{ $project['id'] }}" @endif style="--depth: {{ $project['depth'] }}">
                        <a href="{{ $project['url'] }}" @class(['el-col-item', 'el-col-project', 'el-on' => $on]) @if ($on) aria-current="page" @endif title="{{ $project['name'] }}">
                            @svg(($on ? 'heroicon-s-' : 'heroicon-o-').'folder', ['aria-hidden' => 'true'])
                            <span class="el-col-label">{{ $project['name'] }}</span>
                            @if ($project['count'] > 0)
                                <span class="el-col-count">{{ number_format($project['count']) }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($canCreate)
            <a href="{{ ClientResource::getUrl('create') }}" class="el-col-new">@svg('heroicon-o-plus', ['aria-hidden' => 'true']) New project</a>
        @endif
        @if ($canMove && $places !== [])
            <p class="el-col-hint">Drag recordings onto the Inbox or a project to move them.</p>
        @endif
    @endif
</aside>
