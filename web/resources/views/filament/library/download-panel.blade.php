{{--
    Download on the recording page: the audio, and the transcript in each format for the chosen Show as view. Plain
    links, so the browser downloads them (and PDF opens the print page in a new tab) without a round trip.
--}}
<div class="el-download" x-data="{ view: @js($default) }">
    @if ($audio !== null)
        <section class="el-download-part">
            <h3 class="el-download-heading">Audio</h3>
            <div class="el-download-formats">
                <x-filament::button tag="a" :href="$audio['url']" color="gray" size="sm" icon="heroicon-o-musical-note">
                    {{ $audio['label'] }}
                </x-filament::button>
            </div>
        </section>
    @endif

    @if ($links !== [])
        <section class="el-download-part">
            <h3 class="el-download-heading">Transcript</h3>
            @if (count($views) > 1)
                <fieldset class="el-download-views">
                    <legend>Show as</legend>
                    @foreach ($views as $key => $label)
                        <label class="el-download-view">
                            <input type="radio" name="el-download-view" value="{{ $key }}" x-model="view">
                            <span dir="auto">{{ $label }}</span>
                        </label>
                    @endforeach
                </fieldset>
            @endif
            @foreach ($links as $view => $formats)
                <div class="el-download-formats" data-view="{{ $view }}" x-show="view === @js($view)" @if ($view !== $default) style="display: none" @endif>
                    @foreach ($formats as $format)
                        <x-filament::button tag="a" :href="$format['url']" :target="$format['print'] ? '_blank' : null" color="gray" size="sm"
                            :rel="$format['print'] ? 'noopener' : null">
                            {{ $format['label'] }}
                        </x-filament::button>
                    @endforeach
                </div>
            @endforeach
            <p class="el-download-hint">
                @if ($unsure)
                    Words the engine was unsure of keep their mark, as in the transcript.
                @endif
                PDF (print) opens a page to save as PDF from the browser's print dialog.
            </p>
        </section>
    @endif
</div>
