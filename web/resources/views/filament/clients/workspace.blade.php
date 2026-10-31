{{--
    A client's workspace: Overview · Recordings · Files · Ask. The page refreshes by itself only while something is
    being made (the brief, a file being read, call notes being written).
--}}
<div class="el-workspace" @if ($pending) wire:poll.{{ $poll }}="refreshWorkspace" @endif>
    <nav class="el-tabbar" role="tablist" aria-label="{{ $client->name }}">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                wire:click="setTab('{{ $key }}')" wire:key="client-tab-{{ $key }}">{{ $label }}</button>
        @endforeach
    </nav>

    <div role="tabpanel" id="panel-{{ $tab }}" aria-labelledby="tab-{{ $tab }}" wire:key="client-panel-{{ $tab }}">
        @include('filament.clients.tab-'.$tab)
    </div>
</div>
