@php $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);'; @endphp
{{-- Pending invitations (since v1.5, Phase 1C: Resend and Cancel). DES-08: in the new console each address and each
     link's maker have an avatar (an address with no account yet: its first letter on a colour from the address); plain
     PHP picks it, so Livewire adds no block markers and the classic cells stay byte for byte as they were. FIX-06: in
     the new console the heading rows are marked (v2-plain-thead) for their contrast, the same way. --}}
<x-filament::section :heading="__('Pending invitations')" :description="($plan ? $plan.' ' : '').__('Each email link works once, for its address only, until it ends. Resend makes a new link; the old one stops working.')">
    @if ($invitations === [])
        <p style="font-size:.85rem;opacity:.7;margin:0">{{ __('No invitation is waiting.') }}</p>
    @else
        {{-- W-220: the box that scrolls holds the table's hidden "Actions" too (position), so on a phone the page
             itself never scrolls sideways (right to left it shifted the page and showed the hidden panel). --}}
        <div style="overflow-x:auto;position:relative">
            <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                <thead>
                    <tr style="text-align:left;opacity:.7"<?php if ($v2 ?? false): ?> class="v2-plain-thead"<?php endif; ?>>
                        <th style="padding:.5rem .75rem">{{ __('Email') }}</th><th style="padding:.5rem .75rem">{{ __('Role') }}</th>
                        <th style="padding:.5rem .75rem">{{ __('workspace_context.col_sent') }}</th><th style="padding:.5rem .75rem">{{ __('Ends') }}</th><th style="padding:.5rem .75rem"><span class="el-sr">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invitations as $invitation)
                        <tr>
                            <td style="{{ $cell }}"><?php if ($v2 ?? false): ?><x-el.person :email="$invitation['email']" /><?php else: ?>{{ $invitation['email'] }}<?php endif; ?></td><td style="{{ $cell }}">{{ $invitation['role'] }}</td>
                            <td style="{{ $cell }}">{{ $invitation['sent'] }}</td><td style="{{ $cell }}">{{ $invitation['expires'] }}</td>
                            <td style="{{ $cell }}text-align:right;white-space:nowrap">
                                <x-filament::link tag="button" size="sm" wire:click="resendInvitation({{ $invitation['id'] }})" aria-label="{{ __('Resend the invitation to :email', ['email' => $invitation['email']]) }}">{{ __('Resend') }}</x-filament::link>
                                <x-filament::link tag="button" color="danger" size="sm" style="margin-left:.75rem" wire:click="revokeInvitation({{ $invitation['id'] }})" aria-label="{{ __('Cancel the invitation to :email', ['email' => $invitation['email']]) }}">{{ __('Cancel') }}</x-filament::link>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>

{{-- Invite links (since v1.8 P6.3): open ones, with Revoke. --}}
<x-filament::section :heading="__('Invite links')" :description="__('Anyone with one of these links can join until it ends. The address was shown once, when it was made.')">
    @if ($links === [])
        <p style="font-size:.85rem;opacity:.7;margin:0">{{ __('No invite link is open.') }}</p>
    @else
        <div style="overflow-x:auto;position:relative">
            <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                <thead>
                    <tr style="text-align:left;opacity:.7"<?php if ($v2 ?? false): ?> class="v2-plain-thead"<?php endif; ?>>
                        <th style="padding:.5rem .75rem">{{ __('Role') }}</th><th style="padding:.5rem .75rem">{{ __('Made by') }}</th><th style="padding:.5rem .75rem">{{ __('People') }}</th>
                        <th style="padding:.5rem .75rem">{{ __('Ends') }}</th><th style="padding:.5rem .75rem"><span class="el-sr">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($links as $link)
                        <tr>
                            <td style="{{ $cell }}">{{ $link['role'] }}</td><td style="{{ $cell }}"><?php if (($v2 ?? false) && ($link['creator'] ?? null) !== null): ?><x-el.person :person="$link['creator']" /><?php else: ?>{{ $link['by'] }}<?php endif; ?></td>
                            <td style="{{ $cell }}">{{ $link['uses'] }}</td><td style="{{ $cell }}">{{ $link['expires'] }}</td>
                            <td style="{{ $cell }}text-align:right;white-space:nowrap">
                                <x-filament::link tag="button" color="danger" size="sm" wire:click="revokeLink({{ $link['id'] }})" wire:confirm="{{ __('Revoke this invite link? It stops working at once.') }}" aria-label="{{ __('Revoke the :role invite link', ['role' => \Illuminate\Support\Str::lower($link['role'])]) }}">{{ __('Revoke') }}</x-filament::link>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
