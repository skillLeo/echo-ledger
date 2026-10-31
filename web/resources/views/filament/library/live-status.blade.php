{{-- Live status: shown only while the recording is uploading or its transcript is being made, so polling ends with it. --}}
<div class="el-live" wire:poll.{{ $interval }}="refreshLiveStatus" role="status">
    <x-filament::loading-indicator class="el-live-spinner" />
    <span>{{ $message }}</span>
</div>
