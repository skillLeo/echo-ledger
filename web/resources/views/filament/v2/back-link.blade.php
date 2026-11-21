{{--
    Back to where the page belongs (v2.0), above its title: the chevron turns over right to left. A recording's page also
    has Previous / Next recording at the end of the line (WEB-REC-007): two 44 px icon buttons, ‹ ›, mirrored right to
    left, turned off at the ends of the list.
--}}
@if (isset($neighbours))
    @php($rtl = \App\Filament\Support\ConsoleV2::rtl())
    <div class="v2-viewer-head" data-recording-nav>
        <a href="{{ $url }}" class="v2-back"><x-el.v2.icon name="chevron-start" />{{ $label }}</a>
        <span class="v2-tb-spacer"></span>
        @foreach (['previous' => ['Previous recording', $rtl ? 'heroicon-o-chevron-right' : 'heroicon-o-chevron-left'], 'next' => ['Next recording', $rtl ? 'heroicon-o-chevron-left' : 'heroicon-o-chevron-right']] as $key => [$words, $icon])
            {{-- An empty step between the two: the 44 px buttons draw past their place, so they keep clear of each other. --}}
            @if ($key === 'next')<span aria-hidden="true"></span>@endif
            <x-filament::icon-button :tag="$neighbours[$key] !== null ? 'a' : 'button'" :href="$neighbours[$key]" :disabled="$neighbours[$key] === null"
                :icon="$icon" size="xl" color="gray" :label="__($words)" :tooltip="__($words)" :data-recording-nav-link="$key" />
        @endforeach
    </div>
@else
    <a href="{{ $url }}" class="v2-back"><x-el.v2.icon name="chevron-start" />{{ $label }}</a>
@endif
