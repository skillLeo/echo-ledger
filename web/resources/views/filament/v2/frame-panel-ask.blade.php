{{--
    Ask's panel (docs/PLAN_NAV_3_LEVELS.md section 3): + New question. The web keeps no list of earlier questions (each
    answer is on the page that asked it), and the project to ask about is the page's own picker: nothing twice.
--}}
@php $panel = \App\Filament\Support\NavFrame::askPanel(); @endphp
@if ($panel['new'] !== null)
    <ul class="v2-sb-sub" aria-label="{{ __('Ask') }}">
        <li>
            <a href="{{ $panel['new'] }}" class="v2-sb-subitem" data-new-question>
                <x-el.v2.icon name="plus" />
                <span class="v2-sb-label">{{ __('New question') }}</span>
            </a>
        </li>
    </ul>
@endif
