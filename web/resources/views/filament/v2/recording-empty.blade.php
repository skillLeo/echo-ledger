{{-- The recording page's designed empty states (v2.0): no audio, and a summary that needs a project first. --}}
@php($record = $getRecord())
<div class="v2-empty">
    @if ($kind === 'no_audio')
        <x-el.v2.icon name="mic" class="v2-empty-icon" />
        <p class="v2-empty-title">{{ __('No audio in this recording') }}</p>
        <p class="v2-empty-text">{{ __('The app saved the call, but no sound reached it. Nothing can be transcribed.') }}</p>
    @else
        <x-el.v2.icon name="sparkle" class="v2-empty-icon" />
        <p class="v2-empty-title">{{ __('No summary yet') }}</p>
        <p class="v2-empty-text">{{ __('Summaries are written for recordings in a project. File this recording under a project to get one.') }}</p>
        @unless (\App\Services\Workspaces\ConsoleAccess::readOnly())
            <button type="button" class="v2-btn v2-btn-secondary" wire:click="mountAction('fileUnder')">{{ __('Move to a project') }}</button>
        @endunless
    @endif
</div>
