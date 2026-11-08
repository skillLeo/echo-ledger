@php $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);'; @endphp
{{-- Pending invitations (since v1.5, Phase 1C: Resend and Cancel). --}}
<x-filament::section heading="Pending invitations" :description="$plan ?? 'Each link works for 7 days, once, for its address only. Resend makes a new link; the old one stops working.'">
    @if ($invitations === [])
        <p style="font-size:.85rem;opacity:.7;margin:0">No invitation is waiting.</p>
    @else
        <div style="overflow-x:auto">
            <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                <thead>
                    <tr style="text-align:left;opacity:.7">
                        <th style="padding:.5rem .75rem">Email</th><th style="padding:.5rem .75rem">Role</th>
                        <th style="padding:.5rem .75rem">Sent</th><th style="padding:.5rem .75rem">Ends</th><th style="padding:.5rem .75rem"><span class="el-sr">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invitations as $invitation)
                        <tr>
                            <td style="{{ $cell }}">{{ $invitation['email'] }}</td><td style="{{ $cell }}">{{ $invitation['role'] }}</td>
                            <td style="{{ $cell }}">{{ $invitation['sent'] }}</td><td style="{{ $cell }}">{{ $invitation['expires'] }}</td>
                            <td style="{{ $cell }}text-align:right;white-space:nowrap">
                                <x-filament::link tag="button" size="sm" wire:click="resendInvitation({{ $invitation['id'] }})" aria-label="Resend the invitation to {{ $invitation['email'] }}">Resend</x-filament::link>
                                <x-filament::link tag="button" color="danger" size="sm" style="margin-left:.75rem" wire:click="revokeInvitation({{ $invitation['id'] }})" aria-label="Cancel the invitation to {{ $invitation['email'] }}">Cancel</x-filament::link>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
