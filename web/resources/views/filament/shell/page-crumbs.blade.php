{{-- The page pattern (since v1.6, P4.4): the section above the title, for pages that draw no trail of their own. --}}
@if ($crumbs !== [])
    <nav class="el-crumbs" aria-label="Breadcrumbs">
        @foreach ($crumbs as $url => $label)
            @if (! $loop->first)
                @svg('heroicon-m-chevron-right', ['class' => 'el-crumbs-sep', 'aria-hidden' => 'true'])
            @endif
            @if (is_string($url))
                <a href="{{ $url }}" class="el-crumbs-link">{{ $label }}</a>
            @else
                <span class="el-crumbs-here" aria-current="page">{{ $label }}</span>
            @endif
        @endforeach
    </nav>
@endif
