{{-- Live status: shown only while the recording is uploading or its transcript is being made, so polling ends with it. --}}
<div class="el-live" wire:poll.{{ $interval }}="refreshLiveStatus" role="status">
    @if (! empty($progress))
        {{-- Since v1.5 (Phase 1C): the three steps with their numbers. --}}
        <div class="el-live-steps">
            @include('filament.library.progress', ['progress' => $progress])
            <span class="el-live-note">{{ $message }}</span>
        </div>
    @else
        <x-filament::loading-indicator class="el-live-spinner" />
        <span>{{ $message }}</span>
    @endif
</div>
