{{--
    Since v1.6 (P2.7): the platform's banner (Admin panel → Announcement), and for staff a reminder while maintenance is
    on. Since v1.6.1 both are notices: × and a swipe close them; the announcement stays closed for that text, the
    reminder until maintenance is turned on again or the next visit, and a click on it opens Announcement.
--}}
@php
    $notices = app(\App\Services\System\PlatformNotices::class);
    $announcement = $notices->announcement();
    $maintenance = $notices->maintenance();
    $isStaff = \App\Services\Staff\StaffAccess::isStaff();
    $noticesUrl = $isStaff && \App\Filament\Admin\Pages\Notices::canAccess() ? \App\Filament\Admin\Pages\Notices::getUrl(panel: 'admin') : null;
@endphp
@if ($announcement)
    <x-notice id="announcement" remember="text" :text="$announcement['text']" class="el-notice-box" data-announcement="{{ $announcement['level'] }}"
        :style="$announcement['level'] === 'warning' ? 'background:var(--warning-50);color:var(--warning-800);border:1px solid var(--warning-300)' : 'background:var(--info-50);color:var(--info-800);border:1px solid var(--info-300)'">
        <span class="el-notice-text" dir="auto">{{ $announcement['text'] }}</span>
    </x-notice>
@endif
@if ($maintenance && $isStaff)
    <x-notice id="maintenance" :state="md5(json_encode($maintenance))" :href="$noticesUrl" class="el-notice-box" data-maintenance-on
        style="background:var(--danger-50);color:var(--danger-800);border:1px solid var(--danger-300)">
        <span class="el-notice-text">
            @if ($noticesUrl)
                <a href="{{ $noticesUrl }}" class="el-stretch">Maintenance is on: customers see a pause page and their apps wait. Turn it off under Admin panel → Announcement.</a>
            @else
                Maintenance is on: customers see a pause page and their apps wait. Turn it off under Admin panel → Announcement.
            @endif
        </span>
    </x-notice>
@endif
