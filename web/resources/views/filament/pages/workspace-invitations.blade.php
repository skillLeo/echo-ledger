@php $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);'; @endphp
<x-filament::section heading="Open invitations" :description="$plan ?? 'Each link works for 7 days, once, for its address only.'">
    @if ($invitations === [])
        <p style="font-size:.85rem;opacity:.7;margin:0">No invitation is waiting.</p>
    @else
        <div style="overflow-x:auto">
            <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                <thead>
                    <tr style="text-align:left;opacity:.7">
                        <th style="padding:.5rem .75rem">Email</th><th style="padding:.5rem .75rem">Role</th>
                        <th style="padding:.5rem .75rem">Ends</th><th style="padding:.5rem .75rem"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invitations as $invitation)
                        <tr>
                            <td style="{{ $cell }}">{{ $invitation['email'] }}</td><td style="{{ $cell }}">{{ $invitation['role'] }}</td>
                            <td style="{{ $cell }}">{{ $invitation['expires'] }}</td>
                            <td style="{{ $cell }}text-align:right">
                                <x-filament::link tag="button" color="danger" size="sm" wire:click="revokeInvitation({{ $invitation['id'] }})">Take back</x-filament::link>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
