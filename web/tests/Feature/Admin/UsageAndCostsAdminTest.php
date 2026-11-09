<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\ServerUsage;
use App\Mail\CostAlertMail;
use App\Models\StaffRole;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Services\SettingsRepository;
use App\Services\Usage\CostAlerts;
use App\Services\Usage\UsageBreakdown;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.6 P2.6: usage and costs on the admin panel: KPIs compared with the period before, presets, a day-by-day chart,
| per plan, top customers, CSV and the monthly budget alert.
*/

function costRow(User $user, int $minutes, float $cost, ?Carbon $at = null, string $provider = 'assemblyai'): void
{
    UsageLedgerEntry::query()->withoutGlobalScopes()->create([
        'workspace_id' => $user->current_workspace_id, 'user_id' => $user->id, 'source' => UsageLedgerEntry::SOURCE_SERVER,
        'report_id' => (string) Str::ulid(), 'use_case' => 'final_transcript', 'provider' => $provider, 'duration_ms' => $minutes * 60000,
        'channels' => 1, 'billed_ms' => $minutes * 60000, 'unit_price_usd' => 0, 'cost_usd' => $cost, 'billable' => true, 'occurred_at' => $at ?? now(),
    ]);
}

it('is closed to customers', function () {
    $this->actingAs(Fx::user(), 'web');
    $this->get('/admin/server-usage')->assertForbidden();
    Filament::setCurrentPanel('admin');
    Livewire::test(ServerUsage::class)->assertForbidden();
});

it('shows the KPIs against the period before, per plan, the top customers and a bar per day', function () {
    Carbon::setTestNow('2026-11-20 12:00:00');
    $ana = Fx::user(['name' => 'Ana Big']);
    $bob = Fx::user(['name' => 'Bob Small']);
    costRow($ana, 120, 0);
    costRow($bob, 10, 0);
    costRow($ana, 65, 0, now()->subDays(26)); // Oct 25: in the 20 days before Nov 1
    $this->actingAs(Fx::superAdmin(), 'web');

    $html = (string) $this->get('/admin/server-usage')->assertOk()->getContent();
    expect($html)->toContain('data-usage-kpis')->toContain('130.0')->toContain('+100.0% on the period before')
        ->toContain('By plan')->toContain('Top customers')->toContain(url('/admin/customers/'.$ana->id))
        ->toContain('Minutes per day')->toContain('Nov 20: 130.0 minutes');
    expect(strpos($html, 'Ana Big'))->toBeLessThan(strpos($html, 'Bob Small'));

    Filament::setCurrentPanel('admin');
    $page = Livewire::test(ServerUsage::class)->set('filters.preset', 'last_month');
    expect($page->get('filters.from'))->toBe('2026-10-01')->and($page->get('filters.to'))->toBe('2026-10-31');
    $page->set('filters.preset', 'last_7');
    expect($page->get('filters.from'))->toBe('2026-11-14');
    Carbon::setTestNow();
});

it('counts the days in the console\'s time zone', function () {
    app(SettingsRepository::class)->set('display.timezone', 'Asia/Karachi');
    $ana = Fx::user();
    costRow($ana, 30, 0, Carbon::parse('2026-11-05 20:00:00', 'UTC')); // Nov 6 in Karachi
    $days = app(UsageBreakdown::class)->daily(Carbon::parse('2026-11-01', 'Asia/Karachi'), Carbon::parse('2026-11-08', 'Asia/Karachi'));

    expect(collect($days)->firstWhere('date', '2026-11-06')['minutes'])->toBe(30.0)
        ->and(collect($days)->firstWhere('date', '2026-11-05')['minutes'])->toBe(0.0)->and($days)->toHaveCount(7);
});

it('downloads the period as CSV', function () {
    costRow(Fx::user(), 5, 0.2);
    $this->actingAs(Fx::superAdmin(), 'web');
    Filament::setCurrentPanel('admin');
    Livewire::test(ServerUsage::class)->callAction('exportCsv')->assertFileDownloaded();
});

it('alerts staff who see usage at 80% and 100% of the monthly budget, once each, and nobody else', function () {
    Mail::fake();
    $staff = Fx::superAdmin(['email' => 'boss@example.test']);
    $billing = Fx::user(['email' => 'billing@example.test']);
    $billing->forceFill(['staff_role_id' => StaffRole::query()->where('key', 'billing')->value('id')])->save();
    $custom = StaffRole::query()->create(['key' => 'notes', 'name' => 'Notes', 'permissions' => ['customers.view']]);
    $blind = Fx::user(['email' => 'blind@example.test']);
    $blind->forceFill(['staff_role_id' => $custom->id])->save();
    $customer = Fx::user(['email' => 'customer@example.test']);
    $this->actingAs($staff, 'web');
    Filament::setCurrentPanel('admin');
    Livewire::test(ServerUsage::class)->callAction('budgetAlert', ['usd' => 10])->assertNotified();
    expect(app(CostAlerts::class)->budget())->toBe(10.0);
    auth()->logout();

    costRow($customer, 10, 5.0);
    Mail::assertNothingQueued();
    costRow($customer, 10, 3.5);
    Mail::assertQueued(CostAlertMail::class, 2);
    Mail::assertQueued(CostAlertMail::class, fn (CostAlertMail $m): bool => $m->hasTo('boss@example.test') && $m->level === 80);
    Mail::assertQueued(CostAlertMail::class, fn (CostAlertMail $m): bool => $m->hasTo('billing@example.test'));
    costRow($customer, 1, 0.5);
    Mail::assertQueued(CostAlertMail::class, 2);
    costRow($customer, 5, 2.0);
    Mail::assertQueued(CostAlertMail::class, fn (CostAlertMail $m): bool => $m->level === 100);
    Mail::assertQueued(CostAlertMail::class, 4);
    Mail::assertNotQueued(CostAlertMail::class, fn (CostAlertMail $m): bool => $m->hasTo('blind@example.test') || $m->hasTo('customer@example.test'));
    expect($staff->notifications()->get()->filter(fn ($n): bool => ($n->data['viewData']['kind'] ?? null) === 'cost_alert')->count())->toBe(2)
        ->and($customer->notifications()->count())->toBe(0);
});
