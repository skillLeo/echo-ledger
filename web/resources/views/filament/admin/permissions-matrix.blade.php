{{-- Admin panel → Staff → Permissions (since v1.6, P2.5): a box per permission and role; the super admin has them all. --}}
@php
    $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);';
    $head = 'padding:.5rem .75rem;text-align:center;font-weight:600;white-space:nowrap';
@endphp
<x-filament::section heading="Permissions" description="Tick what each role may do. It applies at once to everyone with the role." data-permissions-matrix>
    <div style="overflow-x:auto">
        <table style="width:100%;font-size:.85rem;border-collapse:collapse">
            <thead>
                <tr>
                    <th style="padding:.5rem .75rem;text-align:left;opacity:.7;font-weight:500">May</th>
                    <th style="{{ $head }}">Super admin</th>
                    @foreach ($roles as $role)
                        <th style="{{ $head }}">
                            {{ $role->name }}
                            <div style="font-weight:400;opacity:.7;font-size:.75rem">{{ $role->users_count === 1 ? '1 person' : $role->users_count.' people' }}</div>
                            @unless ($role->is_system)
                                <button type="button" style="font-size:.75rem;text-decoration:underline;opacity:.8" wire:click="deleteRole({{ $role->id }})" wire:confirm="Delete the role {{ $role->name }}?">Delete</button>
                            @endunless
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($permissions as $permission => $label)
                    <tr>
                        <td style="{{ $cell }}">{{ $label }}</td>
                        <td style="{{ $cell }}text-align:center"><input type="checkbox" checked disabled aria-label="{{ $label }}: super admin" style="width:1.1rem;height:1.1rem"></td>
                        @foreach ($roles as $role)
                            <td style="{{ $cell }}text-align:center">
                                <input type="checkbox" @checked($role->grants($permission))
                                       aria-label="{{ $label }}: {{ $role->name }}" data-role="{{ $role->key }}" data-permission="{{ $permission }}"
                                       wire:click="togglePermission({{ $role->id }}, '{{ $permission }}')" wire:loading.attr="disabled"
                                       style="width:1.1rem;height:1.1rem;accent-color:var(--primary-600)">
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-filament::section>
