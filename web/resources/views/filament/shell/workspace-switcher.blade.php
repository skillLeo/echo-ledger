{{--
    The workspace in the top bar (since v1.6, P4.3; the sidebar's switcher since v1.5): its initials, name and your
    role there, and the other workspaces you belong to one click away. "Manage workspace" for owners and admins.
--}}
@php
    /** @var \App\Models\User|null $user */
    $user = auth()->user();
    $workspaces = $user instanceof \App\Models\User ? $user->workspaces()->orderBy('workspaces.name')->limit(50)->get(['workspaces.id', 'workspaces.name']) : collect();
    $currentId = $user?->current_workspace_id;
    $current = $workspaces->firstWhere('id', $currentId);
    $role = fn ($w): ?string => \App\Enums\WorkspaceRole::tryFrom((string) ($w?->pivot?->role ?? ''))?->label();
    $name = $current?->name ?? 'Workspace';
    $initials = mb_strtoupper(collect(preg_split('/\s+/u', trim($name)) ?: [])->filter()->take(2)->map(fn (string $w): string => mb_substr($w, 0, 1))->implode('')) ?: 'W';
    $manage = \App\Filament\Pages\WorkspaceSettings::canAccess() ? \App\Filament\Pages\WorkspaceSettings::getUrl() : null;
@endphp
@if ($current !== null)
    <div class="el-workspace-switcher el-tb-workspace">
        <x-filament::dropdown placement="bottom-start" width="xs" teleport>
            <x-slot name="trigger">
                <button type="button" class="el-tb-workspace-btn" aria-label="Workspace: {{ $name }}">
                    <span class="el-tb-workspace-mark" aria-hidden="true">{{ $initials }}</span>
                    <span class="el-tb-workspace-text">
                        <span class="el-tb-workspace-name">{{ $name }}</span>
                        @if ($role($current))
                            <span class="el-tb-workspace-role">{{ $role($current) }}</span>
                        @endif
                    </span>
                    @svg('heroicon-m-chevron-up-down', ['class' => 'el-tb-chevron', 'aria-hidden' => 'true'])
                </button>
            </x-slot>
            <x-filament::dropdown.header>You work in {{ $name }}</x-filament::dropdown.header>
            <x-filament::dropdown.list>
                @foreach ($workspaces as $workspace)
                    <form method="post" action="{{ route('admin.workspaces.switch', $workspace->id) }}">
                        @csrf
                        <x-filament::dropdown.list.item tag="button" type="submit" :icon="$workspace->id === $currentId ? 'heroicon-m-check' : 'heroicon-o-building-office'">
                            {{ $workspace->name }}@if ($role($workspace)) <span class="el-muted"> · {{ $role($workspace) }}</span>@endif
                        </x-filament::dropdown.list.item>
                    </form>
                @endforeach
            </x-filament::dropdown.list>
            @if ($manage !== null)
                <x-filament::dropdown.list>
                    <x-filament::dropdown.list.item tag="a" :href="$manage" icon="heroicon-o-cog-6-tooth">Manage workspace</x-filament::dropdown.list.item>
                </x-filament::dropdown.list>
            @endif
        </x-filament::dropdown>
    </div>
@endif
