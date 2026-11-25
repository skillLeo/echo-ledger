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
    // Since v2.0 the role and the default workspace's name in the console's language (English stays as it was).
    $role = fn ($w): ?string => ($label = \App\Enums\WorkspaceRole::tryFrom((string) ($w?->pivot?->role ?? ''))?->label()) !== null ? __($label) : null;
    $shown = fn (?string $n): string => $n === 'Personal' ? __('Personal') : (string) $n;
    $name = $current !== null ? $shown($current->name) : __('Workspace');
    $initials = mb_strtoupper(collect(preg_split('/\s+/u', trim($name)) ?: [])->filter()->take(2)->map(fn (string $w): string => mb_substr($w, 0, 1))->implode('')) ?: 'W';
    $manage = \App\Filament\Pages\WorkspaceSettings::canAccess() ? \App\Filament\Pages\WorkspaceSettings::getUrl() : null;
    // FIX-06: in the new console the button's name holds the words it shows, the name and the role (voice control users
    // say what they see), and the mark's letters are drawn (data-initials) so they are not among them. Plain PHP picks
    // the mark, so Livewire adds no block markers and classic keeps its markup byte for byte.
    $v2 = \App\Filament\Support\ConsoleV2::any();
@endphp
@if ($current !== null)
    <div class="el-workspace-switcher el-tb-workspace">
        <x-filament::dropdown placement="bottom-start" width="xs" teleport>
            <x-slot name="trigger">
                <button type="button" class="el-tb-workspace-btn" aria-label="{{ $v2 && $role($current) ? __('Workspace: :name, :role', ['name' => $name, 'role' => $role($current)]) : __('Workspace: :name', ['name' => $name]) }}">
<?php if ($v2): ?>
                    <span class="el-tb-workspace-mark" aria-hidden="true" data-initials="{{ $initials }}"></span>
<?php else: ?>
                    <span class="el-tb-workspace-mark" aria-hidden="true">{{ $initials }}</span>
<?php endif; ?>
                    <span class="el-tb-workspace-text">
                        <span class="el-tb-workspace-name">{{ $name }}</span>
                        @if ($role($current))
                            <span class="el-tb-workspace-role">{{ $role($current) }}</span>
                        @endif
                    </span>
                    @svg('heroicon-m-chevron-up-down', ['class' => 'el-tb-chevron', 'aria-hidden' => 'true'])
                </button>
            </x-slot>
            <x-filament::dropdown.header>{{ __('You work in :name', ['name' => $name]) }}</x-filament::dropdown.header>
            <x-filament::dropdown.list>
                @foreach ($workspaces as $workspace)
                    <form method="post" action="{{ route('admin.workspaces.switch', $workspace->id) }}">
                        @csrf
                        <x-filament::dropdown.list.item tag="button" type="submit" :icon="$workspace->id === $currentId ? 'heroicon-m-check' : 'heroicon-o-building-office'">
                            {{ $shown($workspace->name) }}@if ($role($workspace)) <span class="el-muted"> · {{ $role($workspace) }}</span>@endif
                        </x-filament::dropdown.list.item>
                    </form>
                @endforeach
            </x-filament::dropdown.list>
            @if ($manage !== null)
                <x-filament::dropdown.list>
                    <x-filament::dropdown.list.item tag="a" :href="$manage" icon="heroicon-o-cog-6-tooth">{{ __('Manage workspace') }}</x-filament::dropdown.list.item>
                </x-filament::dropdown.list>
            @endif
        </x-filament::dropdown>
    </div>
@endif
