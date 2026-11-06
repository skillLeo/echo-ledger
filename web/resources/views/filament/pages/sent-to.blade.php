@php($zone = app(\App\Services\SettingsRepository::class)->displayTimezone())
<div class="space-y-3">
    @forelse ($items as $item)
        <div class="flex items-start justify-between gap-3" wire:key="sent-{{ $item->id }}">
            <div class="min-w-0 text-sm">
                <div class="font-medium">{{ $item->recipient->name }} <span class="text-gray-500 dark:text-gray-400">{{ $item->recipient->email }}</span></div>
                <div class="text-gray-500 dark:text-gray-400">
                    Sent {{ $item->sent_at->copy()->setTimezone($zone)->format('M j, Y · g:i A') }}@if ($item->saved_at) · They saved a copy, which stays theirs @endif
                </div>
                @if ($item->note)
                    <div class="break-words">{{ $item->note }}</div>
                @endif
            </div>
            <x-filament::button color="danger" size="sm" outlined
                wire:click="stopSharing('{{ $item->id }}')"
                wire:confirm="They will no longer be able to open it.">Stop sharing</x-filament::button>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">Not sent to anyone. Use Send to a teammate.</p>
    @endforelse
</div>
