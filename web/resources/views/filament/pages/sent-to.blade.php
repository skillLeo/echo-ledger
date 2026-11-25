@php($zone = app(\App\Services\SettingsRepository::class)->displayTimezone())
@php($v2 = \App\Filament\Support\ConsoleV2::any())
<div class="space-y-3">
    @forelse ($items as $item)
        <div class="flex items-start justify-between gap-3" wire:key="sent-{{ $item->id }}">
            <div class="min-w-0 text-sm">
<?php if ($v2): ?>
                {{-- DES-08: the person with their avatar, their email under the name. Plain PHP, so Livewire adds no block
                     markers and the classic line stays byte for byte as it was. --}}
                <x-el.person :person="$item->recipient" :sub="$item->recipient->email" />
<?php else: ?>
                <div class="font-medium">{{ $item->recipient->name }} <span class="text-gray-500 dark:text-gray-400">{{ $item->recipient->email }}</span></div>
<?php endif; ?>
                <div class="text-gray-500 dark:text-gray-400">
                    {{ __('Sent :date', ['date' => \App\Filament\Support\ConsoleDates::dateTime($item->sent_at->copy()->setTimezone($zone), 'g:i A')]) }}@if ($item->saved_at) · {{ __('They saved a copy, which stays theirs') }} @endif
                </div>
                @if ($item->note)
                    <div class="break-words">{{ $item->note }}</div>
                @endif
            </div>
            <x-filament::button color="danger" size="sm" outlined
                wire:click="stopSharing('{{ $item->id }}')"
                wire:confirm="{{ __('They will no longer be able to open it.') }}">{{ __('Stop sharing') }}</x-filament::button>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Not sent to anyone. Use Send to a teammate.') }}</p>
    @endforelse
</div>
