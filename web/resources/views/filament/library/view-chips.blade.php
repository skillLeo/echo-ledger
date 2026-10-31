{{--
    The smart views as the phone's filter chips, under the search pill (the list page's tabs, drawn as chips). The
    active one is filled; a count shows where there is something to see.
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
