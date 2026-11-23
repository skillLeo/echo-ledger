{{--
    The breadcrumb that leads the Library's one toolbar row (docs/PLAN_NAV_3_LEVELS.md section 0): "Library / Unread".
    "Library" goes back to All recordings, with no filter, search or saved view; the view's name is the page's heading.
--}}
<div class="v2-lib-crumbs" data-library-crumbs>
    <nav aria-label="{{ __('Breadcrumbs') }}">
        <a href="{{ $this->libraryHomeUrl() }}" class="v2-lib-crumb" data-crumb-library>{{ __('Library') }}</a><span class="v2-lib-crumb-sep" aria-hidden="true">/</span>
    </nav>
    <h1 class="v2-lib-title" data-library-title><bdi>{{ $this->libraryHeading() }}</bdi></h1>
</div>
