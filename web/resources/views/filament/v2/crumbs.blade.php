{{--
    The section above a page's title in the new frame (classic's breadcrumbs, Library › Search): each part in the
    console's language, the last one the page you are on. The separator turns over right to left.
--}}
<nav class="v2-crumbs" aria-label="{{ __('Breadcrumbs') }}">
    @foreach ($crumbs as $url => $label)
        @if (! $loop->first)
            <x-el.v2.icon name="chevron-end" class="v2-crumbs-sep" />
        @endif
        @if (is_string($url))
            <a href="{{ $url }}" class="v2-crumbs-link">{{ __($label) }}</a>
        @else
            <span class="v2-crumbs-here" aria-current="page">{{ __($label) }}</span>
        @endif
    @endforeach
</nav>
