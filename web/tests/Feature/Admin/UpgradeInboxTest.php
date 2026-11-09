<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\UpgradeRequestsPage;
use App\Mail\UpgradeDecidedMail;
use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Models\Workspace;
use App\Services\Entitlements\UpgradeRequests;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.6 P2.4: the upgrade requests inbox on the admin panel: what each person is on now, a link to the customer, approve
| with a plan, an end date and an optional word, or decline with a reply; email and an activity item either way.
*/

uses(ApiClient::class);

beforeEach(function () {
    Mail::fake();
    $this->ana = Fx::user(['name' => 'Ana', 'email' => 'ana@example.test']);
    Workspace::query()->whereKey($this->ana->current_workspace_id)->update(['plan_id' => Plan::free()->id]);
    $this->request = app(UpgradeRequests::class)->create($this->ana, (int) $this->ana->current_workspace_id, 'pro', 'Paid by JazzCash, ref 7781');
});

it('is closed to customers: the page and its actions', function () {
    $this->actingAs(Fx::user(), 'web');
    $this->get('/admin/upgrade-requests')->assertForbidden();
    Filament::setCurrentPanel('admin');
    Livewire::test(UpgradeRequestsPage::class)->assertForbidden();
    expect($this->request->fresh()->status)->toBe(UpgradeRequest::PENDING);
});

it('lists the waiting requests first with what each person is on now and their message', function () {
    $this->actingAs(Fx::superAdmin(), 'web');
    $declined = app(UpgradeRequests::class)->create($bob = Fx::user(['email' => 'bob@example.test']), (int) $bob->current_workspace_id, 'pro', null);
    $declined->forceFill(['status' => UpgradeRequest::DECLINED])->save();

    $this->get('/admin/upgrade-requests')->assertOk()->assertSee('ana@example.test')->assertSee('Paid by JazzCash, ref 7781')->assertSee('On now')->assertSee('Free');
    Filament::setCurrentPanel('admin');
    Livewire::test(UpgradeRequestsPage::class)->assertCanSeeTableRecords([$this->request])->assertCanNotSeeTableRecords([$declined])
        ->filterTable('status', UpgradeRequest::DECLINED)->assertCanSeeTableRecords([$declined])
        ->assertTableActionHidden('approve', $declined);
    expect(UpgradeRequestsPage::getNavigationBadge())->toBe('1');
});

it('approves with a plan, an end date and a word, which the person reads by email, in the apps and the API', function () {
    $this->actingAs(Fx::superAdmin(), 'web');
    Filament::setCurrentPanel('admin');

    Livewire::test(UpgradeRequestsPage::class)
        ->callTableAction('approve', $this->request, data: ['plan' => 'business', 'until' => now()->addMonths(3)->toDateTimeString(), 'message' => 'Welcome aboard!'])
        ->assertHasNoTableActionErrors();

    $workspace = Workspace::query()->findOrFail($this->ana->current_workspace_id);
    expect($workspace->plan->key)->toBe('business')->and($workspace->plan_expires_at?->isFuture())->toBeTrue();
    Mail::assertQueued(UpgradeDecidedMail::class, fn (UpgradeDecidedMail $m): bool => $m->hasTo('ana@example.test') && $m->approved && $m->reply === 'Welcome aboard!');
    $token = Fx::token(Fx::device($this->ana));
    expect(collect($this->api($token)->getJson('/api/activity')->json('data'))->firstWhere('kind', 'upgrade_approved')['body'] ?? null)->toBe('Welcome aboard!');
    $this->api($token)->getJson('/api/upgrade-requests/latest')->assertJsonPath('approved_plan', 'business')->assertJsonPath('reply', 'Welcome aboard!');
    expect((new UpgradeDecidedMail(true, 'Business', null, 'Welcome aboard!', url('/app')))->render())->toContain('Welcome aboard!');
});

it('never offers an archived plan when approving', function () {
    $this->actingAs(Fx::superAdmin(), 'web');
    Plan::query()->where('key', 'business')->update(['archived_at' => now()]);
    Filament::setCurrentPanel('admin');

    Livewire::test(UpgradeRequestsPage::class)
        ->callTableAction('approve', $this->request, data: ['plan' => 'business'])
        ->assertHasTableActionErrors(['plan']);
    expect($this->request->fresh()->status)->toBe(UpgradeRequest::PENDING);
});

it('links each request to the customer\'s page, and another person\'s request is never theirs to read', function () {
    $staff = Fx::superAdmin();
    $this->actingAs($staff, 'web');
    $this->get('/admin/upgrade-requests')->assertOk()->assertSee(url('/admin/customers/'.$this->ana->id), false);

    $bob = Fx::user();
    $this->api(Fx::token(Fx::device($bob)))->getJson('/api/upgrade-requests/latest')->assertNotFound();
});
