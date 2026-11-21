{{-- The app's bell (since v1.6, P6): the number counts only what needs you, "9+" above nine (App\Services\ActionBadge); its name in the console's language (W-213). --}}
@php
    use Filament\Support\Icons\Heroicon;
    use Filament\View\PanelsIconAlias;
@endphp
<x-filament::icon-button
    :badge="$badge"
    color="gray"
    :icon="Heroicon::OutlinedBell"
    :icon-alias="PanelsIconAlias::TOPBAR_OPEN_DATABASE_NOTIFICATIONS_BUTTON"
    icon-size="lg"
    :label="$count > 0 ? ($count === 1 ? __('Notifications, :count needs you', ['count' => 1]) : __('Notifications, :count need you', ['count' => $count])) : __('Notifications')"
    class="fi-topbar-database-notifications-btn"
    data-action-badge="{{ $badge ?? '' }}"
/>
