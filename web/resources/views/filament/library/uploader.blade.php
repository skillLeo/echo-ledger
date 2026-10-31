{{--
    Library → Upload: drop sound or video files anywhere on the page, or tap to choose several (phones too). Each file
    is checked (SHA-256) in the browser, skipped when it is already in the Library, sent in chunks that resume after a
    dropped connection, and becomes its own recording titled from its file name.
--}}
@include('filament.library.uploader-script')
<div wire:ignore class="el-uploader" data-uploader
    x-data="elUploader(@js(\Illuminate\Support\Arr::except($config, ['clients', 'languages'])))"
    x-on:el-upload-pick.window="pick()"
    x-on:dragenter.window="dragEnter($event)"
    x-on:dragover.window="dragOver($event)"
    x-on:dragleave.window="dragLeave($event)"
    x-on:drop.window="drop($event)">
    <input type="file" hidden multiple x-ref="input" x-on:change="add($event.target.files)"
        accept="{{ collect($config['extensions'])->map(fn (string $e): string => '.'.$e)->push('audio/*', 'video/mp4', 'video/webm')->implode(',') }}">

    <button type="button" class="el-drop" x-on:click="pick()" x-bind:data-dragging="dragging ? '' : null">
        <x-filament::icon icon="heroicon-o-arrow-up-tray" class="el-drop-icon" />
        <span class="el-drop-title">Drop sound or video files here, or <span class="el-drop-link">choose files</span></span>
        <span class="el-drop-hint">
            {{ collect($config['extensions'])->map(fn (string $e): string => '.'.$e)->implode(' ') }}
            · each file becomes its own recording, titled from its name
        </span>
    </button>

    <div class="el-batch" x-show="waiting.length" x-cloak>
        <p class="el-batch-title" x-text="'For ' + (waiting.length === 1 ? 'this file' : 'these ' + waiting.length + ' files')"></p>
        <div class="el-batch-fields">
            <label class="el-field">
                <span>Client</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select x-model="clientId">
                        <option value="">No client</option>
                        @foreach ($config['clients'] as $client)
                            <option value="{{ $client['value'] }}">{{ $client['label'] }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label class="el-field">
                <span>Language</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select x-model="language">
                        @foreach ($config['languages'] as $group => $choice)
                            @if (is_array($choice))
                                <optgroup label="{{ $group }}">
                                    @foreach ($choice as $code => $label)
                                        <option value="{{ $code }}">{{ $label }}</option>
                                    @endforeach
                                </optgroup>
                            @else
                                <option value="{{ $group }}">{{ $choice }}</option>
                            @endif
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        </div>
        {{-- wire:target names no Livewire action: the list's refreshes must not grey these buttons out. --}}
        <div class="el-batch-actions">
            <x-filament::button x-on:click="start()" icon="heroicon-o-arrow-up-tray" :loading-indicator="false" wire:target="elUploaderStart">
                <span x-text="'Upload ' + plural(waiting.length)">Upload</span>
            </x-filament::button>
            <x-filament::button color="gray" x-on:click="clearWaiting()" :loading-indicator="false" wire:target="elUploaderClear">Clear</x-filament::button>
        </div>
    </div>

    <ul class="el-files" x-show="files.length" x-cloak aria-label="Files">
        <template x-for="f in files" :key="f.key">
            <li class="el-file" x-bind:data-state="f.state">
                <div class="el-file-head">
                    <span class="el-file-name" x-text="f.name"></span>
                    <span class="el-file-size" x-text="size(f.size)"></span>
                </div>
                <div class="el-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" x-show="hasBar(f)"
                    x-bind:aria-valuenow="percent(f)" x-bind:aria-label="'Progress of ' + f.name">
                    <span x-bind:style="'width: ' + percent(f) + '%'"></span>
                </div>
                <div class="el-file-foot">
                    <span class="el-file-status" role="status" x-bind:class="'el-tone-' + tone(f)" x-text="status(f)"></span>
                    <span class="el-file-actions">
                        <a class="el-link" x-show="f.url && ['done', 'duplicate'].includes(f.state)" x-bind:href="f.url">Open</a>
                        <button type="button" class="el-link" x-show="canRetry(f)" x-on:click="retry(f)">Retry</button>
                        <button type="button" class="el-link" x-show="canCancel(f)" x-on:click="cancel(f)">Cancel</button>
                        <button type="button" class="el-link" x-show="canDismiss(f)" x-on:click="dismiss(f)"
                            x-bind:aria-label="'Remove ' + f.name + ' from this list'">Remove</button>
                    </span>
                </div>
            </li>
        </template>
    </ul>

    <div class="el-summary" x-show="summary" x-cloak>
        <span role="status" x-text="summary"></span>
        <button type="button" class="el-link" x-on:click="clearFinished()">Clear the list</button>
    </div>
</div>
