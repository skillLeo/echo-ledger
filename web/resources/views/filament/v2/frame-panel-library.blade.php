{{--
    The Library's panel (docs/PLAN_NAV_3_LEVELS.md section 3): Views (All recordings first and chosen by default, Needs
    you, Unread, Important, Audio only, Shared), My views (+ New view; drag, rename, delete), Labels
    (colour dots, + New label), and Trash at its foot. The web reads the server, so there is no "Synced" line. The
    first nine answer Alt+1…9.
--}}
@include('filament.v2.sidebar-library', ['inPanel' => true])
@php $trash = \App\Filament\Support\ConsoleV2Nav::trash(); @endphp
@if ($trash !== null)
    <div class="v2-panel-foot">
        <a href="{{ $trash['url'] }}" @class(['v2-sb-subitem', 'is-on' => $trash['active']]) @if ($trash['active']) aria-current="page" @endif
            data-v2-object="trash" data-v2-object-id="trash" data-place="trash">
            <x-el.v2.icon name="trash" />
            <span class="v2-sb-label">{{ $trash['label'] }}</span>
        </a>
    </div>
@endif
