{{--
    The breadcrumb that leads the Library's one toolbar row (docs/PLAN_NAV_3_LEVELS.md section 0): "Library / Unread".
    "Library" goes back to All recordings, with no filter, search or saved view; the view's name is the page's heading.
--}}
<div class="v2-lib-crumbs" data-library-crumbs>
    <nav aria-label="{{ __('Breadcrumbs') }}">
        <a href="{{ $this->libraryHomeUrl() }}" class="v2-lib-crumb" data-crumb-library>{{ __('Library') }}</a><span class="v2-lib-crumb-sep" aria-hidden="true">/</span>
    </nav>
    <h1 class="v2-lib-title" data-library-title><bdi>{{ $this->libraryHeading() }}</bdi></h1>
    @if (\App\Filament\Support\ConsoleV2::frame())
        @php
            // How many the list holds, said once beside the title too (under it on a narrow row, decision 18): under the
            // wide width (or while the filters push the list) the table's own "Showing 1 to 7 of 7" folds away with its
            // narrower box, and the count must never depend on it.
            $total = (int) (collect($this->libraryTabs())->firstWhere('active', true)['count'] ?? 0);
        @endphp
        <p class="v2-lib-total" data-library-total>{{ $total === 1 ? __(':n recording', ['n' => 1]) : __(':n recordings', ['n' => \Illuminate\Support\Number::format($total)]) }}</p>
    @endif
</div>
{{-- The top tabs (docs/PLAN_NAV_3_LEVELS.md section 4) come after the search and the toolbar in the markup
     (library-toolbar): on two lines they have a row of their own under them, and the keys go as the rows read. --}}
