{{-- Settings › Keyboard shortcuts: the keys of the console, by where they work. --}}
@foreach (\App\Filament\Auth\EditProfile::shortcuts() as $section => $keys)
    <x-el.section :title="$section">
        @foreach ($keys as [$key, $what])
            <div class="el-set-row">
                <span class="el-set-text"><span class="el-set-title" style="font-weight:400">{{ $what }}</span></span>
                <kbd class="el-kbd">{{ $key }}</kbd>
            </div>
        @endforeach
    </x-el.section>
@endforeach
