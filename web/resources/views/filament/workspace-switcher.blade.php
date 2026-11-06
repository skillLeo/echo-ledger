@php
    /** @var \App\Models\User|null $user */
    $user = auth()->user();
    $workspaces = $user instanceof \App\Models\User ? $user->workspaces()->orderBy('workspaces.name')->limit(50)->get(['workspaces.id', 'workspaces.name']) : collect();
    $currentId = $user?->current_workspace_id;
    $current = $workspaces->firstWhere('id', $currentId);
@endphp
{{-- Since v1.5: the workspaces the person belongs to, one click away (the Workspace page lists them too). --}}
@if ($workspaces->count() > 1)
    <div class="el-workspace-switcher" style="padding: 0 0.75rem 0.5rem;">
        <x-filament::dropdown placement="bottom-start" width="xs">
            <x-slot name="trigger">
                <button type="button" class="fi-btn" style="width: 100%; justify-content: space-between; display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgb(var(--gray-200)); background: transparent; font-size: 0.875rem;" aria-label="Switch workspace">
                    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $current?->name ?? 'Workspace' }}</span>
                    <x-filament::icon icon="heroicon-m-chevron-up-down" class="h-4 w-4" />
                </button>
            </x-slot>
            <x-filament::dropdown.list>
                @foreach ($workspaces as $workspace)
                    <form method="post" action="{{ route('admin.workspaces.switch', $workspace->id) }}">
                        @csrf
                        <x-filament::dropdown.list.item tag="button" type="submit" :icon="$workspace->id === $currentId ? 'heroicon-m-check' : null">
                            {{ $workspace->name }}
                        </x-filament::dropdown.list.item>
                    </form>
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    </div>
@endif
