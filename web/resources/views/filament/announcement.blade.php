{{-- Since v1.6 (P2.7): the platform's banner (Admin panel → Announcement), and for staff a reminder while maintenance is on. --}}
@php
    $notices = app(\App\Services\System\PlatformNotices::class);
    $announcement = $notices->announcement();
    $maintenance = $notices->maintenance();
    $isStaff = \App\Services\Staff\StaffAccess::isStaff();
@endphp
@if ($announcement)
    <div role="status" data-announcement="{{ $announcement['level'] }}"
         style="margin:0 0 1rem;padding:.6rem .9rem;border-radius:.6rem;font-size:.9rem;{{ $announcement['level'] === 'warning' ? 'background:var(--warning-50);color:var(--warning-800);border:1px solid var(--warning-300)' : 'background:var(--info-50);color:var(--info-800);border:1px solid var(--info-300)' }}"
         dir="auto">{{ $announcement['text'] }}</div>
@endif
@if ($maintenance && $isStaff)
    <div role="status" data-maintenance-on style="margin:0 0 1rem;padding:.6rem .9rem;border-radius:.6rem;font-size:.9rem;background:var(--danger-50);color:var(--danger-800);border:1px solid var(--danger-300)">
        Maintenance is on: customers see a pause page and their apps wait. Turn it off under Admin panel → Announcement.
    </div>
@endif
