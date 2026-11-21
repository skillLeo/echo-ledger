{{-- W-209: a name or value inside a translated sentence is isolated in right to left, so the sentence keeps the page's direction (D4 §4). --}}
@php
    $iso = fn (mixed $value): string => \App\Filament\Support\ConsoleV2::rtl() ? "\u{2068}".$value."\u{2069}" : (string) $value;
@endphp
{{--
    Files and instructions the brief and Ask read: PDF, Word, text or Markdown documents (kept encrypted, their text
    read on the queue) and pasted instructions.
--}}
@php
    $status = fn (\App\Models\ClientFile $f): ?array => match ($f->status) {
        'extracting' => ['label' => __('Reading'), 'tone' => 'brand', 'icon' => 'heroicon-o-arrow-path', 'spin' => true],
        'failed' => ['label' => __('Failed'), 'tone' => 'danger', 'icon' => 'heroicon-o-exclamation-circle', 'spin' => false],
        default => null,
    };
    $type = fn (\App\Models\ClientFile $f): string => $f->path === null ? __('Instructions') : strtoupper(\App\Services\ClientAi\ClientWorkspace::FILE_TYPES[$f->mime] ?? (str_starts_with($f->mime, 'image/') ? 'image' : 'file'));
    $size = fn (int $bytes): string => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
@endphp
<div style="display: flex; flex-direction: column; gap: var(--space-5); max-width: 880px">
    <div class="el-uploader" x-data="{ dragging: false }">
        <label class="el-drop" x-bind:data-dragging="dragging ? '' : null"
            x-on:dragover.prevent="dragging = true" x-on:dragleave="dragging = false" x-on:drop="dragging = false">
            @svg('heroicon-o-document-arrow-up', 'el-drop-icon', ['aria-hidden' => 'true'])
            <span class="el-drop-title">{!! __('Drop a document here, or :choose', ['choose' => '<span class="el-drop-link">'.e(__('choose a file')).'</span>']) !!}</span>
            <span class="el-drop-hint">{{ __('PDF, Word (.docx), text or Markdown, up to :mb MB · kept encrypted', ['mb' => \App\Services\ClientAi\ClientWorkspace::MAX_FILE_BYTES / 1048576]) }}</span>
            <input type="file" wire:model="upload" accept=".pdf,.docx,.txt,.md,.markdown,application/pdf,text/plain,text/markdown,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                class="el-sr" aria-label="{{ __('Add a document') }}">
        </label>
        <p class="el-caption" wire:loading wire:target="upload" role="status">{{ __('Uploading…') }}</p>
        @error('upload')<p class="el-caption" role="alert" style="color: var(--danger)">{{ $message }}</p>@enderror
    </div>

    <form class="el-card" wire:submit="addInstructions" style="display: flex; flex-direction: column; gap: var(--space-3)">
        <h3 class="el-card-title">{{ __('Paste instructions') }}</h3>
        <label class="el-sr" for="instructions-name">{{ __('Name') }}</label>
        <input id="instructions-name" type="text" class="el-field-input" wire:model="instructionsName" maxlength="200" placeholder="{{ __('Name (optional), like “Scope from the email”') }}">
        <label class="el-sr" for="instructions-text">{{ __('Instructions') }}</label>
        <textarea id="instructions-text" class="el-field-input" rows="5" wire:model="instructions" placeholder="{{ __('Paste the brief, an email or your own notes') }}"></textarea>
        @error('instructions')<p class="el-caption" role="alert" style="color: var(--danger)">{{ $message }}</p>@enderror
        <div><button type="submit" class="el-pill el-pill-primary" wire:loading.attr="disabled" wire:target="addInstructions">{{ __('Add instructions') }}</button></div>
    </form>

    <section aria-labelledby="files-heading">
        <h3 id="files-heading" class="el-section-header">{{ __('Files') }}</h3>
        @if ($files->isEmpty())
            <p class="el-muted" style="font-size: 15px">{{ __('Nothing yet. The brief is written from the call notes until there is a document.') }}</p>
        @else
            <ul class="el-rows" role="list">
                @foreach ($files as $file)
                    @php($state = $status($file))
                    <li class="el-row-item" id="file-{{ $file->id }}" wire:key="client-file-{{ $file->id }}">
                        <div class="el-row el-row-btn" style="cursor: default">
                            <span class="el-tile {{ $file->path === null ? 'el-tile-amber' : 'el-tile-indigo' }}" aria-hidden="true">
                                @svg($file->path === null ? 'heroicon-s-pencil-square' : 'heroicon-s-document-text')
                            </span>
                            <span class="el-row-main">
                                <span class="el-row-top">
                                    <span class="el-row-title">{{ $file->name }}</span>
                                    <time class="el-row-time">{{ \App\Filament\Support\ConsoleDates::day($file->created_at->copy()->setTimezone($zone)) }}</time>
                                </span>
                                <span class="el-row-sub">
                                    <span class="el-row-meta">{{ $type($file) }} · {{ $size($file->size_bytes) }}@if ($file->pages) · {{ $file->pages === 1 ? __(':n page', ['n' => 1]) : __(':n pages', ['n' => $file->pages]) }}@endif</span>
                                    @if ($state !== null)
                                        @include('filament.library.status', ['status' => $state])
                                    @endif
                                </span>
                                @if ($file->status === 'failed' && $file->error)
                                    <span class="el-caption" style="color: var(--danger)">{{ $file->errorForPeople() }}</span>
                                @endif
                            </span>
                            <button type="button" class="el-icon-btn" wire:click="mountAction('removeFile', { file: '{{ $file->id }}' })" aria-label="{{ __('Remove :name', ['name' => $iso($file->name)]) }}" title="{{ __('Remove') }}">
                                @svg('heroicon-o-trash', ['aria-hidden' => 'true'])
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
