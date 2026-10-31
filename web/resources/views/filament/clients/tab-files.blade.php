{{--
    Files and instructions the brief and Ask read: PDF, Word, text or Markdown documents (kept encrypted, their text
    read on the queue) and pasted instructions.
--}}
@php
    $status = fn (\App\Models\ClientFile $f): ?array => match ($f->status) {
        'extracting' => ['label' => 'Reading', 'tone' => 'brand', 'icon' => 'heroicon-o-arrow-path', 'spin' => true],
        'failed' => ['label' => 'Failed', 'tone' => 'danger', 'icon' => 'heroicon-o-exclamation-circle', 'spin' => false],
        default => null,
    };
    $type = fn (\App\Models\ClientFile $f): string => $f->path === null ? 'Instructions' : strtoupper(\App\Services\ClientAi\ClientWorkspace::FILE_TYPES[$f->mime] ?? 'file');
    $size = fn (int $bytes): string => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
@endphp
<div style="display: flex; flex-direction: column; gap: var(--space-5); max-width: 880px">
    <div class="el-uploader" x-data="{ dragging: false }">
        <label class="el-drop" x-bind:data-dragging="dragging ? '' : null"
            x-on:dragover.prevent="dragging = true" x-on:dragleave="dragging = false" x-on:drop="dragging = false">
            @svg('heroicon-o-document-arrow-up', 'el-drop-icon', ['aria-hidden' => 'true'])
            <span class="el-drop-title">Drop a document here, or <span class="el-drop-link">choose a file</span></span>
            <span class="el-drop-hint">PDF, Word (.docx), text or Markdown, up to {{ \App\Services\ClientAi\ClientWorkspace::MAX_FILE_BYTES / 1048576 }} MB · kept encrypted</span>
            <input type="file" wire:model="upload" accept=".pdf,.docx,.txt,.md,.markdown,application/pdf,text/plain,text/markdown,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                class="el-sr" aria-label="Add a document">
        </label>
        <p class="el-caption" wire:loading wire:target="upload" role="status">Uploading…</p>
        @error('upload')<p class="el-caption" role="alert" style="color: var(--danger)">{{ $message }}</p>@enderror
    </div>

    <form class="el-card" wire:submit="addInstructions" style="display: flex; flex-direction: column; gap: var(--space-3)">
        <h3 class="el-card-title">Paste instructions</h3>
        <label class="el-sr" for="instructions-name">Name</label>
        <input id="instructions-name" type="text" class="el-field-input" wire:model="instructionsName" maxlength="200" placeholder="Name (optional), like “Scope from the email”">
        <label class="el-sr" for="instructions-text">Instructions</label>
        <textarea id="instructions-text" class="el-field-input" rows="5" wire:model="instructions" placeholder="Paste the client’s brief, an email or your own notes"></textarea>
        @error('instructions')<p class="el-caption" role="alert" style="color: var(--danger)">{{ $message }}</p>@enderror
        <div><button type="submit" class="el-pill el-pill-primary" wire:loading.attr="disabled" wire:target="addInstructions">Add instructions</button></div>
    </form>

    <section aria-labelledby="files-heading">
        <h3 id="files-heading" class="el-section-header">Files</h3>
        @if ($files->isEmpty())
            <p class="el-muted" style="font-size: 15px">Nothing yet. The brief is written from the call notes until there is a document.</p>
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
                                    <time class="el-row-time">{{ $file->created_at->copy()->setTimezone($zone)->format('M j') }}</time>
                                </span>
                                <span class="el-row-sub">
                                    <span class="el-row-meta">{{ $type($file) }} · {{ $size($file->size_bytes) }}@if ($file->pages) · {{ $file->pages }} {{ $file->pages === 1 ? 'page' : 'pages' }}@endif</span>
                                    @if ($state !== null)
                                        @include('filament.library.status', ['status' => $state])
                                    @endif
                                </span>
                                @if ($file->status === 'failed' && $file->error)
                                    <span class="el-caption" style="color: var(--danger)">{{ $file->error }}</span>
                                @endif
                            </span>
                            <button type="button" class="el-icon-btn" wire:click="mountAction('removeFile', { file: '{{ $file->id }}' })" aria-label="Remove {{ $file->name }}" title="Remove">
                                @svg('heroicon-o-trash', ['aria-hidden' => 'true'])
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
