{{-- Shared with me → Open: a received recording (player and transcript, read-only) or a received project's recordings. --}}
@php($zone = app(\App\Services\SettingsRepository::class)->displayTimezone())
<div class="el-shared-preview" style="display: grid; gap: 12px;">
<?php if (\App\Filament\Support\ConsoleV2::any()): ?>
    {{-- DES-08: who sent it, with their avatar, then when. Plain PHP, so Livewire adds no block markers and the classic
         line stays byte for byte as it was. --}}
    <div style="display: grid; gap: 4px;">
        <x-el.person :person="$item->sender" :sub="$item->sender->email" />
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $item->sent_at->copy()->setTimezone($zone)->format('M j, Y · g:i A') }}</p>
    </div>
<?php else: ?>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        From {{ $item->sender->name }} ({{ $item->sender->email }}) · {{ $item->sent_at->copy()->setTimezone($zone)->format('M j, Y · g:i A') }}
    </p>
<?php endif; ?>
    @if ($item->note)
        <blockquote class="text-sm" style="margin: 0; padding-inline-start: 12px; border-inline-start: 3px solid rgb(var(--primary-500));" dir="auto">{{ $item->note }}</blockquote>
    @endif
    @if ($sessions !== null)
        @forelse ($sessions as $session)
            <div class="text-sm" style="display: flex; justify-content: space-between; gap: 12px;">
                <span dir="auto">{{ $session->displayTitle() }}</span>
                <span class="text-gray-500 dark:text-gray-400" style="white-space: nowrap;">{{ $session->started_at->copy()->setTimezone($zone)->format('M j') }}</span>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">This project has no recordings yet.</p>
        @endforelse
    @else
        @if ($audio)
            <audio controls preload="metadata" src="{{ $audio }}" style="width: 100%;"></audio>
        @endif
        @forelse ($lines as $line)
            <p class="text-sm" dir="auto" style="margin: 0;">
                <span class="text-gray-500 dark:text-gray-400" style="font-variant-numeric: tabular-nums;">{{ \App\Services\TranscriptExporter::clock($line['start_ms']) }}</span>
                <strong>{{ $line['speaker'] }}</strong>
                {{ $line['text'] }}
            </p>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">The transcript is not ready yet.</p>
        @endforelse
    @endif
</div>
