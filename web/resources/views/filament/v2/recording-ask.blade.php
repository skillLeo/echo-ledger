{{-- Ask inside a recording (v2.0, IA 4.2): the same Ask, with its scope set to the recording's project. --}}
@php($record = $getRecord())
<div class="v2-empty">
    <x-el.v2.icon name="ask" class="v2-empty-icon" />
    @if ($record->client_id !== null && \App\Filament\Pages\Ask::canAccess())
        <p class="v2-empty-title">{{ __('Ask about :project', ['project' => $record->client?->name]) }}</p>
        <p class="v2-empty-text">{{ __('Answers come from this project\'s recordings, files and notes, with the moment each one is from.') }}</p>
        <a class="v2-btn v2-btn-primary" href="{{ \App\Filament\Pages\Ask::getUrl(['project' => $record->client_id]) }}">{{ __('Ask') }}</a>
    @else
        <p class="v2-empty-title">{{ __('Ask works on a project') }}</p>
        <p class="v2-empty-text">{{ __('File this recording under a project, then ask about it in your own words.') }}</p>
        @unless (\App\Services\Workspaces\ConsoleAccess::readOnly())
            <button type="button" class="v2-btn v2-btn-secondary" wire:click="mountAction('fileUnder')">{{ __('Move to a project') }}</button>
        @endunless
    @endif
</div>
