{{-- Live status: shown only while the recording is uploading or its transcript is being made, so polling ends with it. --}}
<div class="el-live" wire:poll.{{ $interval }}="refreshLiveStatus" role="status">
    @if (! empty($progress))
        {{-- Since v1.6 (P5.4): Upload · Transcribe · Improve, one line that says what the running step does, a bar. --}}
        <div class="el-live-steps">
            @include('filament.library.progress', ['progress' => $progress])
            <span class="el-live-foot">
                <span class="el-live-note">{{ $message }}</span>
                @isset($notify)
                    @if ($notify['asked'])
                        <span class="el-live-asked" data-notify-asked>@svg('heroicon-m-check', ['aria-hidden' => 'true']) You will get an email when it is ready.</span>
                    @else
                        <x-filament::button size="sm" color="gray" icon="heroicon-o-bell" wire:click="notifyWhenReady" data-notify-ready>
                            Notify me when ready
                        </x-filament::button>
                    @endif
                @endisset
            </span>
        </div>
    @else
        <x-filament::loading-indicator class="el-live-spinner" />
        <span>{{ $message }}</span>
    @endif
</div>
