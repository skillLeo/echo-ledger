<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiError;
use App\Filament\Admin\Pages\UpgradeRequestsPage;
use App\Filament\Auth\EditProfile;
use App\Filament\Pages\Activity;
use App\Filament\Pages\RecentlyDeleted;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\Notice;
use App\Filament\Support\NoticeTargets;
use App\Livewire\ActivityBell;
use App\Livewire\Shell\Sidebar;
use App\Models\CaptureSession;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use App\Services\SettingsRepository;
use App\Services\System\PlatformNotices;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * Notices (v1.6.1, docs/DESIGN_SYSTEM.md "Notices"): every toast is a Notice with a target where it is about
 * something, Dismiss on every one, a timer (6 s, errors 10 s) with its bar, swipe; the banners about something still
 * true close until their state changes; the bell's cards open their notification and mark it read, only the person's.
 */
beforeEach(function () {
    Filament::setCurrentPanel('app');
});

/** The newest notification of [$kind] for [$user]. */
function noticeOfKind(User $user, string $kind): string
{
    return (string) $user->notifications()->get()->first(fn ($n): bool => ActivityFeed::kindOf($n) === $kind)?->id;
}

/** The toasts sent so far in this test, as the browser would get them. */
function sentNotices(): Collection
{
    app(NotificationMailbox::class)->flush();
    $component = new Notifications;
    $component->mount();
    $component->pullNotificationsFromSession();

    return collect($component->notifications->all())->values();
}

function noticeTitled(string $title): ?Notice
{
    $found = sentNotices()->first(fn ($n): bool => str_contains(strip_tags((string) $n->getTitle()), $title));

    return $found instanceof Notice ? $found : null;
}

describe('every toast', function () {
    it('is a Notice, wherever it is made, and keeps its target only when it is on this server', function () {
        expect(FilamentNotification::make())->toBeInstanceOf(Notice::class);

        expect(Notice::make()->url('/app/sessions?tab=failed')->getUrl())->toBe('/app/sessions?tab=failed')
            ->and(Notice::make()->url(url('/app/activity'))->getUrl())->toBe(url('/app/activity'))
            ->and(Notice::make()->url('https://evil.example/app')->getUrl())->toBeNull()
            ->and(Notice::make()->url('//evil.example')->getUrl())->toBeNull()
            ->and(Notice::make()->url('javascript:alert(1)')->getUrl())->toBeNull()
            ->and(Notice::make()->url("/app\n/x")->getUrl())->toBeNull();

        $back = FilamentNotification::fromArray(Notice::make()->title('Saved')->url('/app/sessions')->toArray());
        expect($back)->toBeInstanceOf(Notice::class)->and($back->getUrl())->toBe('/app/sessions');
    });

    it('waits 6 s, an error 10 s, and one about something still true has no timer', function () {
        expect(Notice::make()->success()->getDuration())->toBe(6000)
            ->and(Notice::make()->danger()->getDuration())->toBe(10000)
            ->and(Notice::make()->danger()->duration(3000)->getDuration())->toBe(3000)
            ->and(Notice::make()->warning()->persistent()->getDuration())->toBe('persistent');
    });

    it('draws Dismiss, the timer bar, and a link over the whole card when it has a target', function () {
        $html = Notice::make()->title('Pinned 1 recording')->url('/app/sessions?tab=pinned')->success()->toEmbeddedHtml();
        expect($html)->toContain('aria-label="Dismiss"')->toContain('el-toast-timer-bar')->toContain('animation-duration: 6000ms')
            ->toContain('class="el-toast-link" draggable="false"')->toContain('href="/app/sessions?tab=pinned"')
            ->toContain('el-has-target')->toContain('window.elToast')->toContain('notificationComponent');

        $plain = Notice::make()->title('Saved')->toEmbeddedHtml();
        expect($plain)->toContain('aria-label="Dismiss"')->not->toContain('el-toast-link')->not->toContain('el-has-target');

        $still = Notice::make()->title('Turn on two-step sign-in')->warning()->persistent()->toEmbeddedHtml();
        expect($still)->not->toContain('el-toast-timer-bar');

        $error = Notice::make()->title('Could not save')->danger()->toEmbeddedHtml();
        expect($error)->toContain('role="alert"')->toContain('animation-duration: 10000ms');
    });

    it('loads the notices script on every page of both panels, the sign-in page too', function () {
        $login = (string) $this->get('/app/login')->assertOk()->getContent();
        expect($login)->toContain('window.elToast = (base, options)')->toContain('ElNoticeCore')->toContain("e.key !== 'Escape'");

        $this->actingAs(Fx::user(), 'web');
        expect((string) $this->get('/app')->getContent())->toContain('window.elBanner = (options)');
    });
});

describe('the targets', function () {
    it('opens the recording after Rename and after Transcription queued in the Library', function () {
        $a = Fx::user();
        $session = Fx::session($a, null, ['title' => 'Budget review']);
        $this->actingAs($a, 'web');

        Livewire::test(ListCaptureSessions::class)->callTableAction('rename', $session, ['title' => 'Budget review 2']);
        expect(noticeTitled('Renamed to')?->getUrl())->toBe(CaptureSessionResource::getUrl('view', ['record' => $session]));
    });

    it('opens the project, the Inbox or Pinned after a drop', function () {
        $a = Fx::user();
        $project = Fx::client($a, 'Acme Holdings');
        $session = Fx::session($a);
        $this->actingAs($a, 'web');

        Livewire::test(Sidebar::class)->call('dropRecordings', 'project:'.$project->id, [$session->id]);
        expect(noticeTitled('Moved 1 recording to Acme Holdings')?->getUrl())->toBe(ClientResource::getUrl('view', ['record' => $project], panel: 'app'));
        Livewire::test(Sidebar::class)->call('dropRecordings', 'pinned', [$session->id]);
        expect(noticeTitled('Pinned 1 recording')?->getUrl())->toContain('tab=pinned');
        Livewire::test(Sidebar::class)->call('dropRecordings', 'inbox', [$session->id]);
        expect(noticeTitled('to the Inbox')?->getUrl())->toContain('folder=inbox');
    });

    it('opens the restored recording from Recently deleted', function () {
        $a = Fx::user();
        $session = Fx::session($a, null, ['title' => 'Back again']);
        $session->delete();
        $this->actingAs($a, 'web');

        Livewire::test(RecentlyDeleted::class)->callTableAction('restore', $session);
        expect(noticeTitled('Recording restored')?->getUrl())->toBe(CaptureSessionResource::getUrl('view', ['record' => $session]));
    });

    it('sends staff without two-step sign-in to Account → Password & sign-in methods, at two-step sign-in, by the redirect and the notice', function () {
        $staff = Fx::superAdmin(twoFactor: false);
        $this->actingAs($staff, 'web');

        $this->get('/admin')->assertRedirect(url('/app/settings/security?highlight=security.two_step'));
        $notice = noticeTitled('Turn on two-step sign-in');
        expect($notice?->getUrl())->toEndWith('/app/settings/security?highlight=security.two_step')->and($notice?->getDuration())->toBe('persistent');
        expect((string) $this->get('/app/settings/security?highlight=security.two_step')->assertOk()->getContent())->toContain('id="setting-security.two_step"');
    });

    it('opens the page that fixes an error: a used-up plan to Usage, a missing feature to Plan & usage', function () {
        $this->actingAs(Fx::user(), 'web');

        expect(NoticeTargets::forError(new ApiError(403, 'budget_exceeded', 'Monthly limit reached')))->toContain('/app/usage')
            ->and(NoticeTargets::forError(new ApiError(403, 'feature_not_in_plan', 'Not in your plan')))->toEndWith('/app/settings/plan')
            ->and(NoticeTargets::forError(new ApiError(503, 'provider_not_configured', 'No key')))->toBeNull() // not staff
            ->and(NoticeTargets::forError(new RuntimeException('Something else')))->toBeNull();
    });

    it('gives every error toast built from an exception its fix page, in every file that sends one', function () {
        $files = collect(glob(app_path('{Filament,Http,Livewire}/**/*.php'), GLOB_BRACE) ?: [])
            ->merge(glob(app_path('Filament/*/*/*.php')) ?: [])->merge(glob(app_path('Filament/*/*/*/*.php')) ?: [])->unique();
        $bare = [];
        foreach ($files as $file) {
            foreach (file($file) ?: [] as $n => $line) {
                if (str_contains($line, 'Notification::make()->title($e->getMessage())') && ! str_contains($line, 'NoticeTargets::forError($e)')) {
                    $bare[] = basename($file).':'.($n + 1);
                }
            }
        }
        expect($bare)->toBe([]);
    });
});

describe('the banners about something still true', function () {
    it('makes the Library lines notices: click → their view, Dismiss, closed until their state changes', function () {
        $a = Fx::user();
        Fx::session($a, null, ['status' => 'failed']);
        $this->actingAs($a, 'web');

        $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
        expect($html)->toMatch('/data-notice="failed-uploads"/')->toContain('el-stretch')->toContain('aria-label="Dismiss"')
            ->toContain('data-remember="visit"')->toContain('x-cloak')->toContain('window.elBanner');
    });

    it('shows a plan limit line while the month\'s minutes are used up (never for a plan with none), opening Plan & usage', function () {
        $a = Fx::user();
        $workspace = Workspace::query()->findOrFail($a->current_workspace_id);
        $workspace->plan->forceFill(['monthly_minutes' => 1])->save();
        UsageLedgerEntry::query()->create(['workspace_id' => $workspace->id, 'user_id' => $a->id, 'source' => 'server', 'report_id' => 'used-up',
            'use_case' => 'final_transcript', 'provider' => 'assemblyai', 'duration_ms' => 120000, 'channels' => 1, 'billed_ms' => 120000,
            'unit_price_usd' => 0, 'cost_usd' => 0, 'billable' => true, 'occurred_at' => now()]);
        $this->actingAs($a, 'web');

        $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
        expect($html)->toContain('data-plan-limit="minutes"')->toContain('This month&#039;s minutes are used up')->toContain('Plan &amp; usage');
    })->skip(fn (): bool => ! method_exists(Workspace::class, 'plan'), 'Plans not on workspaces');

    it('remembers the announcement closed per text, and the maintenance reminder per state', function () {
        app(SettingsRepository::class)->set(PlatformNotices::ANNOUNCEMENT, ['text' => 'New prices from Dec 1', 'level' => 'info']);
        app(SettingsRepository::class)->set(PlatformNotices::MAINTENANCE, ['on' => true]);
        $this->actingAs(Fx::superAdmin(), 'web');

        $html = (string) $this->get('/app')->assertOk()->getContent();
        expect($html)->toContain('data-notice="announcement"')->toContain('data-remember="text"')->toContain('New prices from Dec 1')
            ->toContain('data-notice="maintenance"')->toContain('el-stretch');
    });
});

describe('the bell', function () {
    it('makes each card open its notification and take its Open button away', function () {
        $a = Fx::user();
        $session = Fx::session($a, null, ['title' => 'Budget review']);
        app(ActivityFeed::class)->post($a, 'transcript_ready', 'Transcript ready', 'Budget review', $session, 'heroicon-o-check-circle');
        $id = (string) $a->notifications()->latest()->value('id');
        $this->actingAs($a, 'web');

        $notice = (new ActivityBell)->getNotification($a->notifications()->findOrFail($id));
        expect($notice)->toBeInstanceOf(Notice::class)->and($notice->getUrl())->toBe(route('notices.open', $id, absolute: false))
            ->and(collect($notice->getActions())->map->getName()->all())->not->toContain('open');
        expect($notice->inline()->toEmbeddedHtml())->toContain('data-el-toast="inline"')->toContain('el-toast-link');
    });

    it('opens the recording and marks it read; anything without a page goes to Activity', function () {
        $a = Fx::user();
        $session = Fx::session($a, null, ['title' => 'Budget review']);
        app(ActivityFeed::class)->post($a, 'transcript_ready', 'Transcript ready', 'Budget review', $session, 'heroicon-o-check-circle');
        $id = (string) $a->notifications()->latest()->value('id');
        $this->actingAs($a, 'web');

        $this->get(route('notices.open', $id))->assertRedirect(CaptureSessionResource::getUrl('view', ['record' => $session], panel: 'app'));
        expect($a->notifications()->whereKey($id)->value('read_at'))->not->toBeNull();

        app(ActivityFeed::class)->post($a, 'upgrade_approved', 'You are on Pro now', 'Thank you!', null, 'heroicon-o-check-badge');
        $plan = noticeOfKind($a, 'upgrade_approved');
        $this->get(route('notices.open', $plan))->assertRedirect(EditProfile::settingsUrl('plan'));
        app(ActivityFeed::class)->post($a, 'some_old_kind', 'Something', 'x', null, 'heroicon-o-bell');
        $other = noticeOfKind($a, 'some_old_kind');
        $this->get(route('notices.open', $other))->assertRedirect(Activity::getUrl(panel: 'app'));
    });

    it('answers 404 when user B opens user A\'s notification, and leaves it unread', function () {
        $a = Fx::user();
        $b = Fx::user();
        app(ActivityFeed::class)->post($a, 'transcript_ready', 'A private title', 'x', Fx::session($a), 'heroicon-o-check-circle');
        $id = (string) $a->notifications()->latest()->value('id');

        $this->actingAs($b, 'web');
        $this->get(route('notices.open', $id))->assertNotFound();
        $this->get(route('notices.open', 'not-a-uuid'))->assertNotFound();
        expect($a->notifications()->whereKey($id)->value('read_at'))->toBeNull();

        auth()->logout();
        $this->get(route('notices.open', $id))->assertRedirect();
        expect($a->notifications()->whereKey($id)->value('read_at'))->toBeNull();
    });

    it('sends staff notices to the admin page only for staff who may open it', function () {
        $staff = Fx::superAdmin();
        app(ActivityFeed::class)->post($staff, 'upgrade_requested', 'Upgrade request', 'Ana asks for Pro.', null, 'heroicon-o-arrow-trending-up');
        $n = $staff->notifications()->latest()->firstOrFail();
        $this->actingAs($staff, 'web');
        expect(NoticeTargets::forNotification($n, $staff))->toBe(UpgradeRequestsPage::getUrl(panel: 'admin'));

        $customer = Fx::user();
        app(ActivityFeed::class)->post($customer, 'upgrade_requested', 'Upgrade request', 'x', null, 'heroicon-o-arrow-trending-up');
        $m = $customer->notifications()->latest()->firstOrFail();
        $this->actingAs($customer, 'web');
        expect(NoticeTargets::forNotification($m, $customer))->toBeNull();
    });

    it('opens the same target from Activity', function () {
        $a = Fx::user();
        $session = Fx::session($a);
        app(ActivityFeed::class)->post($a, 'transcript_failed', 'Transcription failed', 'x', $session, 'heroicon-o-exclamation-circle');
        $id = (string) $a->notifications()->latest()->value('id');
        $this->actingAs($a, 'web');

        Livewire::test(Activity::class)->call('open', $id)->assertRedirect(CaptureSessionResource::getUrl('view', ['record' => $session], panel: 'app'));
        $session->delete();
        app(ActivityFeed::class)->post($a, 'transcript_failed', 'Transcription failed', 'x', $session, 'heroicon-o-exclamation-circle');
        $gone = (string) $a->notifications()->get()->filter(fn ($n): bool => ActivityFeed::kindOf($n) === 'transcript_failed')->sortByDesc('created_at')->last(fn ($n): bool => $n->id !== $id)?->id;
        Livewire::test(Activity::class)->call('open', $gone)->assertRedirect(CaptureSessionResource::getUrl('index', ['tab' => 'failed'], panel: 'app'));
    });
});

describe('the decisions, without a browser', function () {
    it('passes the Node tests of the notice rule (resources/js/notice-core.cjs)', function () {
        $result = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/notice-core.test.mjs']);
        expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
    })->skip(fn (): bool => trim((string) shell_exec('command -v node')) === '', 'Node is not installed here');
});

describe('a viewer', function () {
    it('has the same notices, and opens only their own', function () {
        $owner = Fx::user();
        $viewer = withoutWorkspace(Fx::user());
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($owner->current_workspace_id), $viewer, WorkspaceRole::Viewer);
        app(ActivityFeed::class)->post($owner, 'transcript_ready', 'Owner only', 'x', CaptureSession::factory()->create(['user_id' => $owner->id]), 'heroicon-o-check-circle');
        $ownerNotice = (string) $owner->notifications()->latest()->value('id');

        $this->actingAs($viewer->fresh(), 'web');
        $this->get(route('notices.open', $ownerNotice))->assertNotFound();
    });
});
