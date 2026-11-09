{{-- One Settings section (since v1.7): a sentence-case header, one container, footer lines under it. --}}
@props(['title' => null, 'footer' => null, 'setting' => null])
<section {{ $attributes->class(['el-set-section']) }} @if ($setting) id="setting-{{ $setting }}" tabindex="-1" data-setting="{{ $setting }}" @endif>
    @if (filled($title))
        <h2 class="el-set-head">{{ $title }}</h2>
    @endif
    <div class="el-set-box">
        {{ $slot }}
    </div>
    @foreach (array_filter((array) $footer) as $line)
        <p class="el-set-foot">{{ $line }}</p>
    @endforeach
    {{ $after ?? '' }}
</section>
