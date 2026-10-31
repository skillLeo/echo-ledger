<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceDevice;
use App\Filament\Widgets\SessionsChart;
use App\Filament\Widgets\SourceStatsOverview;
use App\Models\CaptureSession;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Calls a protected widget method so the computed data can be asserted directly. */
function adminWidgetData(object $widget, string $method): mixed
{
    return (new ReflectionMethod($widget, $method))->invoke($widget);
}

/** @return array<string, Stat> */
function adminStatsByLabel(): array
{
    $widget = new SourceStatsOverview;
    $stats = [];
    foreach (adminWidgetData($widget, 'getStats') as $stat) {
        $stats[(string) $stat->getLabel()] = $stat;
    }

    return $stats;
}

it('renders the dashboard for a super admin with every widget placed', function () {
    $admin = $this->signInAsAdmin(['name' => 'Farah Khan']);

    $html = $this->get('/admin')->assertOk()->getContent();

    // Widgets load lazily, so the page carries their component names; their content is asserted below.
    expect($html)->toContain('Dashboard')
        ->toContain('SourceStatsOverview')
        ->toContain('SessionsChart')
        ->toContain('Farah Khan');
    expect($admin->is_super_admin)->toBeTrue();
});

it('registers the stats, chart and account widgets on the dashboard', function () {
    $this->signInAsAdmin();

    $widgets = collect(Filament::getWidgets())->all();

    expect($widgets)->toContain(SourceStatsOverview::class)
        ->toContain(SessionsChart::class)
        ->toContain(AccountWidget::class);
});

it('does not poll the server from the dashboard', function () {
    $this->signInAsAdmin();

    $this->get('/admin')->assertOk()->assertDontSee('wire:poll', false);
    expect((new ReflectionProperty(SourceStatsOverview::class, 'pollingInterval'))->getDefaultValue())->toBeNull()
        ->and((new ReflectionProperty(SessionsChart::class, 'pollingInterval'))->getDefaultValue())->toBeNull();
});

it('lists the navigation groups and pages in the sidebar', function () {
    $this->signInAsAdmin();

    $html = $this->get('/admin')->assertOk()->getContent();

    foreach (['Library', 'More', 'System', 'Clients', 'Activity', 'Upload', 'Devices', 'API Credentials', 'Settings'] as $label) {
        expect($html)->toContain($label);
    }
    expect($html)->toContain('fi-global-search'); // the command palette (Ctrl / Cmd + K)
});

it('counts sessions per source device with an icon and a text label', function () {
    $admin = $this->signInAsAdmin();
    CaptureSession::factory()->count(3)->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Phone]);
    CaptureSession::factory()->count(2)->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Desktop]);
    CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Web]);

    $stats = adminStatsByLabel();

    expect($stats['Phone']->getValue())->toBe(3)
        ->and($stats['Desktop']->getValue())->toBe(2)
        ->and($stats['Web']->getValue())->toBe(1)
        ->and($stats['Phone']->getDescription())->toBe('sessions')
        ->and($stats['Phone']->getIcon())->toBe(SourceDevice::Phone->getIcon())
        ->and($stats['Desktop']->getIcon())->toBe(SourceDevice::Desktop->getIcon())
        ->and($stats['Web']->getIcon())->toBe(SourceDevice::Web->getIcon());
});

it('only counts the signed-in admin\'s own sessions', function () {
    $admin = $this->signInAsAdmin();
    $other = $this->makeAdmin();
    CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Phone]);
    CaptureSession::factory()->count(4)->create(['user_id' => $other->id, 'source_device' => SourceDevice::Phone]);

    expect(adminStatsByLabel()['Phone']->getValue())->toBe(1);
});

it('does not count deleted sessions', function () {
    $admin = $this->signInAsAdmin();
    CaptureSession::factory()->count(2)->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Phone]);
    CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Phone])->delete();

    expect(adminStatsByLabel()['Phone']->getValue())->toBe(2);
});

it('shows zero for every source when the library is empty', function () {
    $this->signInAsAdmin();

    $stats = adminStatsByLabel();

    expect($stats['Phone']->getValue())->toBe(0)
        ->and($stats['Desktop']->getValue())->toBe(0)
        ->and($stats['Web']->getValue())->toBe(0)
        ->and($stats['Transcribed (30 days)']->getValue())->toBe('0 min');
});

it('adds up transcribed minutes for completed sessions in the last 30 days only', function () {
    $admin = $this->signInAsAdmin();
    $recent = now()->subDays(2);
    CaptureSession::factory()->create(['user_id' => $admin->id, 'status' => SessionStatus::Completed, 'started_at' => $recent, 'duration_ms' => 10 * 60000]);
    CaptureSession::factory()->create(['user_id' => $admin->id, 'status' => SessionStatus::Completed, 'started_at' => $recent, 'duration_ms' => 5 * 60000]);
    // Excluded: still processing, failed, too old, someone else's.
    CaptureSession::factory()->create(['user_id' => $admin->id, 'status' => SessionStatus::Processing, 'started_at' => $recent, 'duration_ms' => 99 * 60000]);
    CaptureSession::factory()->create(['user_id' => $admin->id, 'status' => SessionStatus::Failed, 'started_at' => $recent, 'duration_ms' => 99 * 60000]);
    CaptureSession::factory()->create(['user_id' => $admin->id, 'status' => SessionStatus::Completed, 'started_at' => now()->subDays(45), 'duration_ms' => 99 * 60000]);
    CaptureSession::factory()->create(['status' => SessionStatus::Completed, 'started_at' => $recent, 'duration_ms' => 99 * 60000]);

    expect(adminStatsByLabel()['Transcribed (30 days)']->getValue())->toBe('15 min');
});

it('renders the stat cards in the dashboard HTML', function () {
    $admin = $this->signInAsAdmin();
    CaptureSession::factory()->count(7)->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Desktop]);

    $this->panelComponent(SourceStatsOverview::class)
        ->assertSee('Desktop')
        ->assertSee('7')
        ->assertSee('Transcribed (30 days)');
});

it('plots sessions per day for the last 14 days with one series per source', function () {
    $admin = $this->signInAsAdmin();
    CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Phone, 'started_at' => Carbon::parse('2026-10-24 09:00', 'UTC')]);
    CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Phone, 'started_at' => Carbon::parse('2026-10-24 10:00', 'UTC')]);
    CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Desktop, 'started_at' => Carbon::parse('2026-10-20 10:00', 'UTC')]);
    CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Web, 'started_at' => Carbon::parse('2026-10-11 10:00', 'UTC')]);

    $data = adminWidgetData(new SessionsChart, 'getData');
    $series = collect($data['datasets'])->keyBy('label');

    expect($data['labels'])->toHaveCount(14)
        ->and($data['labels'][0])->toBe('Oct 11')
        ->and($data['labels'][13])->toBe('Oct 24')
        ->and($series->keys()->all())->toBe(['Phone', 'Desktop', 'Web'])
        ->and($series['Phone']['data'][13])->toBe(2)
        ->and($series['Desktop']['data'][9])->toBe(1)
        ->and($series['Web']['data'][0])->toBe(1)
        ->and(array_sum($series['Phone']['data']))->toBe(2)
        ->and(array_sum($series['Desktop']['data']))->toBe(1)
        ->and(array_sum($series['Web']['data']))->toBe(1);
});

it('leaves out sessions older than 14 days and other admins\' sessions from the chart', function () {
    $admin = $this->signInAsAdmin();
    $other = $this->makeAdmin();
    CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Phone, 'started_at' => Carbon::parse('2026-10-09 10:00', 'UTC')]);
    CaptureSession::factory()->create(['user_id' => $other->id, 'source_device' => SourceDevice::Phone, 'started_at' => Carbon::parse('2026-10-24 10:00', 'UTC')]);

    $data = adminWidgetData(new SessionsChart, 'getData');

    foreach ($data['datasets'] as $dataset) {
        expect(array_sum($dataset['data']))->toBe(0);
    }
});

it('renders a bar chart canvas on the dashboard', function () {
    $this->signInAsAdmin();

    $this->panelComponent(SessionsChart::class)
        ->assertSee('Sessions · last 14 days')
        ->assertSeeHtml('<canvas');
    expect(adminWidgetData(new SessionsChart, 'getType'))->toBe('bar');
});

it('buckets the chart by the console time zone rather than UTC', function () {
    $admin = $this->signInAsAdmin();
    app(SettingsRepository::class)->set('display.timezone', 'Pacific/Kiritimati'); // UTC+14
    // 11:00 UTC on Oct 24 is 01:00 on Oct 25 in the console's zone, which is how the Sessions list shows it.
    CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Phone, 'started_at' => Carbon::parse('2026-10-24 11:00', 'UTC')]);

    $data = adminWidgetData(new SessionsChart, 'getData');
    $phone = collect($data['datasets'])->firstWhere('label', 'Phone');

    expect($data['labels'][13])->toBe('Oct 25')
        ->and($phone['data'][13])->toBe(1);
});

// --- This month's usage (A5.2) ----------------------------------------------------------------------------------

/** @return array<string, mixed> */
function adminUsageBlock(array $overrides = []): array
{
    return array_merge(['month' => '2026-10', 'time_zone' => 'UTC', 'minutes' => 812.5, 'cost_usd' => 6.42, 'limit_minutes' => null,
        'limit_usd' => null, 'warn_at_percent' => 80, 'warn' => false, 'limit_reached' => false], $overrides);
}

it('shows this month\'s billable minutes and cost on the dashboard', function () {
    $this->signInAsAdmin();

    $stat = adminStatsByLabel()['This month'];

    expect($stat->getValue())->toBe('0.0 min · $0.00')
        ->and($stat->getDescription())->toBe('No monthly limit')
        ->and($stat->getColor())->toBe('gray');
    $this->panelComponent(SourceStatsOverview::class)->assertSee('This month');
});

it('describes the limit and colours the month by the warning and limit flags', function (array $usage, string $value, string $description, string $colour) {
    $stat = SourceStatsOverview::monthStat(adminUsageBlock($usage));

    expect($stat->getValue())->toBe($value)
        ->and($stat->getDescription())->toBe($description)
        ->and($stat->getColor())->toBe($colour);
})->with([
    'no limit' => [[], '812.5 min · $6.42', 'No monthly limit', 'gray'],
    'minutes limit' => [['limit_minutes' => 3000], '812.5 min · $6.42', 'of 3,000 min', 'gray'],
    'dollar limit, warning' => [['limit_usd' => 7.5, 'warn' => true], '812.5 min · $6.42', 'of $7.50', 'warning'],
    'both limits, reached' => [['limit_minutes' => 800, 'limit_usd' => 6, 'warn' => true, 'limit_reached' => true], '812.5 min · $6.42', 'of 800 min · $6.00', 'danger'],
]);
