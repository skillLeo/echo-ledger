{{--
    The smart views as the phone's filter chips, under the search pill (the list page's tabs, drawn as chips). The
    active one is filled; a count shows where there is something to see. Since v1.5 a second, quieter row holds the
    smart folders (Inbox, the apps, Phone, Voice notes, Imported, Shared with me); a chosen folder narrows any view.
--}}
<div class="el-chips el-view-chips" role="group" aria-label="Show">
    @foreach ($this->getTabs() as $key => $tab)
        @php($active = (string) $this->activeTab === (string) $key)
        <button type="button" @class(['el-chip', 'el-on' => $active]) aria-pressed="{{ $active ? 'true' : 'false' }}"
            wire:click="$set('activeTab', @js((string) $key))" wire:key="view-chip-{{ $key }}">
            <span>{{ $tab->getLabel() }}</span>
            @if (filled($tab->getBadge()))
                <span class="el-chip-count">{{ $tab->getBadge() }}</span>
            @endif
        </button>
    @endforeach
</div>
@if (method_exists($this, 'folders'))
    <div class="el-chips el-folder-chips" role="group" aria-label="Folders" style="margin-top: var(--space-2)">
        @foreach ($this->folders() as $key => $folder)
            @continue($folder['count'] === 0 && $this->folder !== $key && $key !== 'inbox')
            @php($active = $this->folder === $key)
            @if ($folder['url'] !== null)
                <a href="{{ $folder['url'] }}" class="el-chip" wire:navigate wire:key="folder-chip-{{ $key }}">
                    @svg('heroicon-o-'.$folder['icon'], ['aria-hidden' => 'true'])
                    <span>{{ $folder['label'] }}</span>
                    <span class="el-chip-count">{{ $folder['count'] }}</span>
                </a>
            @else
                <button type="button" @class(['el-chip', 'el-on' => $active]) aria-pressed="{{ $active ? 'true' : 'false' }}"
                    wire:click="setFolder(@js($key))" wire:key="folder-chip-{{ $key }}">
                    @svg(($active ? 'heroicon-s-' : 'heroicon-o-').$folder['icon'], ['aria-hidden' => 'true'])
                    <span>{{ $folder['label'] }}</span>
                    <span class="el-chip-count">{{ $folder['count'] }}</span>
                </button>
            @endif
        @endforeach
    </div>
@endif
