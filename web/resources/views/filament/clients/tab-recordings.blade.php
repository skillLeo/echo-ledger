{{-- The project's subfolders, then its recordings (search and sort since v1.5), as Library rows. --}}
<div style="display: flex; flex-direction: column; gap: var(--space-4); max-width: 880px">
    @if ($subfolders->isNotEmpty())
        <section aria-label="Subfolders">
            <h3 class="el-card-title" style="margin-bottom: var(--space-2)">Subfolders</h3>
            <div class="el-chips" role="list">
                @foreach ($subfolders as $folder)
                    <a role="listitem" class="el-chip" href="{{ \App\Filament\Resources\Clients\ClientResource::getUrl('view', ['record' => $folder]) }}" wire:navigate wire:key="subfolder-{{ $folder->id }}">
                        @svg('heroicon-o-folder', ['aria-hidden' => 'true'])
                        <span>{{ $folder->name }}</span>
                        <span class="el-chip-count">{{ $folder->capture_sessions_count }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div style="display: flex; flex-wrap: wrap; gap: var(--space-2); align-items: center">
        <label class="el-sr" for="project-search">Search in this project</label>
        <input id="project-search" type="search" class="el-field-input" style="flex: 1 1 14rem" placeholder="Search in {{ $client->name }}"
            wire:model.live.debounce.400ms="search">
        <label class="el-sr" for="project-sort">Sort</label>
        <select id="project-sort" class="el-field-input" style="flex: 0 0 auto" wire:model.live="sort">
            @foreach ($sorts as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    @if ($recordings === [])
        <div class="el-empty">
            <span class="el-empty-icon">@svg('heroicon-o-play-circle', ['aria-hidden' => 'true'])</span>
            @if (trim($this->search) !== '')
                <p class="el-empty-title">No recordings match “{{ $this->search }}”</p>
                <p class="el-empty-text">Try other words: titles, callers and transcripts are searched.</p>
            @else
                <p class="el-empty-title">No recordings yet</p>
                <p class="el-empty-text">Recordings filed under {{ $client->name }} show here, from the phone, the computer or Upload.</p>
            @endif
        </div>
    @else
        <ul class="el-rows" role="list">
            @foreach ($recordings as $row)
                <li class="el-row-item" wire:key="client-recording-{{ $row['id'] }}">
                    <a href="{{ $row['url'] }}" class="el-row el-row-btn" wire:navigate>
                        @include('filament.library.tile', ['row' => $row])
                        <span class="el-row-main">
                            <span class="el-row-top">
                                <span class="el-row-title">{{ $row['title'] }}</span>
                                <time class="el-row-time" datetime="{{ $row['iso'] }}" title="{{ $row['full_time'] }}">{{ $row['time'] }}</time>
                            </span>
                            <span class="el-row-sub">
                                <span class="el-sr">{{ $row['app'] }} · {{ $row['device'] }} · </span>
                                <span class="el-row-meta">{{ $row['meta'] }}</span>
                                @if ($row['status'] !== null)
                                    @include('filament.library.status', ['status' => $row['status']])
                                @endif
                            </span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        @if (count($recordings) >= 100)
            <a class="el-text-btn" href="{{ $libraryUrl }}" style="margin-top: var(--space-3)">See them all in the Library</a>
        @endif
    @endif
</div>
