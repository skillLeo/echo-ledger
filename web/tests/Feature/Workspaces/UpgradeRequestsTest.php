<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\UpgradeRequestsPage;
use App\Filament\Auth\EditProfile;
use App\Filament\Pages\WorkspaceSettings;
use App\Mail\UpgradeDecidedMail;
use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Models\Workspace;
use App\Services\SettingsRepository;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| Upgrade requests (since v1.6, P1.4, docs/API.md "Upgrade requests"): ask for a plan on offer, read the payment
| instructions, one waiting at a time; the super admin approves (the plan, until a date) or declines with a reply, and
| the person gets an email and an activity item. The console shows locked features with one badge and the sheet.
*/

uses(ApiClient::class);

beforeEach(function () {
    Mail::fake();
    app(SettingsRepository::class)->set('upgrade.payment_instructions', "JazzCash 0300 0000000\nReference: your email");
    $this->person = Fx::user(['name' => 'Nida', 'email' => 'nida@example.test']);
    Workspace::query()->whereKey($this->person->current_workspace_id)->update(['plan_id' => Plan::free()->id]);
    $this->token = Fx::token(Fx::device($this->person));
});

describe('the API', function () {
    it('takes a request with the payment instructions, one waiting at a time, and shows the newest', function () {
        $this->api($this->token)->getJson('/api/upgrade-requests/latest')->assertNotFound()->assertJsonPath('code', 'not_found');

        $created = $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'pro', 'message' => 'For my clinic'])->assertCreated()
            ->assertJsonPath('plan', 'pro')->assertJsonPath('status', 'pending')->assertJsonPath('message', 'For my clinic')
            ->assertJsonPath('payment_instructions', "JazzCash 0300 0000000\nReference: your email");
        expect(array_keys($created->json()))->toBe(['id', 'plan', 'status', 'message', 'promo_code', 'percent', 'amount_due', 'payment_instructions', 'created_at',
            // Since v1.7 (Payment receipts): additive.
            'reference_code', 'amount_due_pkr', 'currency', 'payment', 'timeline']);

        $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'business'])->assertStatus(409)
            ->assertJsonPath('code', 'request_pending')->assertJsonPath('request.id', $created->json('id'));
        $this->api($this->token)->getJson('/api/upgrade-requests/latest')->assertOk()->assertJsonPath('id', $created->json('id'))
            ->assertJsonPath('decided_at', null)->assertJsonPath('reply', null);
        $this->api($this->token)->getJson('/api/me/entitlements')->assertJsonPath('upgrade_request.id', $created->json('id'))
            ->assertJsonPath('upgrade_request.status', 'pending');
    });

    it('asks only for a plan on offer, other than the one in use', function () {
        $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'personal'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'free'])->assertStatus(422)->assertJsonValidationErrors('plan');
        expect(UpgradeRequest::query()->count())->toBe(0);
    });

    it('never shows one person\'s request to another, even in the same workspace', function () {
        $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'pro'])->assertCreated();
        $other = Fx::user();
        $team = Workspace::query()->findOrFail($this->person->current_workspace_id);
        app(Workspaces::class)->addMember($team, $other);
        app(Workspaces::class)->switchTo($other, $team);
        $otherToken = Fx::token(Fx::device($other->refresh()));

        $this->api($otherToken)->getJson('/api/upgrade-requests/latest')->assertNotFound();
        $this->api($otherToken)->getJson('/api/me/entitlements')->assertJsonPath('upgrade_request', null);
        // Their own request is theirs alone.
        $this->api($otherToken)->postJson('/api/upgrade-requests', ['plan' => 'business'])->assertCreated();
        $this->api($this->token)->getJson('/api/upgrade-requests/latest')->assertJsonPath('plan', 'pro');
    });

    it('takes 5 requests a day per person', function () {
        foreach (range(1, 5) as $i) {
            $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'nope'])->assertStatus(422);
        }
        $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'pro'])->assertStatus(429)->assertHeader('Retry-After');
    });
});

describe('System → Upgrade requests', function () {
    beforeEach(fn () => Filament::setCurrentPanel('app'));

    it('approves: the plan until a date, an email and an activity item', function () {
        $id = $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'pro'])->json('id');
        $admin = Fx::superAdmin();
        $this->actingAs($admin, 'web');
        $this->get('/admin/upgrade-requests')->assertOk()->assertSee('nida@example.test');

        Livewire::test(UpgradeRequestsPage::class)
            ->callTableAction('approve', UpgradeRequest::query()->findOrFail($id), data: ['plan' => 'pro', 'until' => now()->addMonth()->toDateTimeString()])
            ->assertHasNoTableActionErrors();

        $workspace = Workspace::query()->findOrFail($this->person->current_workspace_id);
        expect($workspace->plan->key)->toBe('pro')->and($workspace->plan_expires_at?->isFuture())->toBeTrue()
            ->and(UpgradeRequest::query()->findOrFail($id)->status)->toBe('approved');
        Mail::assertQueued(UpgradeDecidedMail::class, fn (UpgradeDecidedMail $m): bool => $m->hasTo('nida@example.test') && $m->approved && $m->plan === 'Pro');
        expect(collect($this->api($this->token)->getJson('/api/activity')->json('data'))->firstWhere('kind', 'upgrade_approved'))->not->toBeNull();
        $this->api($this->token)->getJson('/api/me/entitlements')->assertJsonPath('plan.key', 'pro')->assertJsonPath('features.views.enabled', true);
        $this->api($this->token)->getJson('/api/upgrade-requests/latest')->assertJsonPath('status', 'approved')->assertJsonPath('decided_at', fn ($v): bool => $v !== null);
    });

    it('declines with a reply, by email and in the activity', function () {
        $id = $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'pro'])->json('id');
        $this->actingAs(Fx::superAdmin(), 'web');

        Livewire::test(UpgradeRequestsPage::class)
            ->callTableAction('decline', UpgradeRequest::query()->findOrFail($id), data: ['reply' => 'Payment not received yet.'])
            ->assertHasNoTableActionErrors();

        Mail::assertQueued(UpgradeDecidedMail::class, fn (UpgradeDecidedMail $m): bool => ! $m->approved && $m->reply === 'Payment not received yet.');
        expect(collect($this->api($this->token)->getJson('/api/activity')->json('data'))->firstWhere('kind', 'upgrade_declined')['body'] ?? null)->toBe('Payment not received yet.');
        $this->api($this->token)->getJson('/api/upgrade-requests/latest')->assertJsonPath('status', 'declined')->assertJsonPath('reply', 'Payment not received yet.');
        expect(Workspace::query()->findOrFail($this->person->current_workspace_id)->plan->key)->toBe('free');
        // Declined: another request may be made.
        $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'pro'])->assertCreated();
    });

    it('is the super admin\'s only', function () {
        $this->actingAs($this->person, 'web');
        $this->person->forceFill(['consent_seen_at' => now()])->save();
        $this->get('/admin/upgrade-requests')->assertForbidden();
    });
});

describe('the console\'s badge and sheet', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('app');
        $this->person->forceFill(['consent_seen_at' => now()])->save();
        $this->actingAs($this->person, 'web');
    });

    it('shows Invite with the plan\'s badge on Free, and the sheet asks for the plan', function () {
        $page = Livewire::test(WorkspaceSettings::class)->assertActionVisible('upgradeForTeam')->assertActionHidden('addMember');
        expect($page->html())->toContain('BUSINESS ✦');

        $page->callAction('upgradeForTeam', ['plan' => 'business', 'message' => 'Our office'])
            ->assertNotified('Request sent. We\'ll activate it once confirmed.');
        expect(UpgradeRequest::query()->where('user_id', $this->person->id)->value('plan'))->toBe('business');
    });

    it('shows the plan on the profile, with the plans side by side', function () {
        $this->get('/app/profile')->assertOk()->assertSee('Your plan')->assertSee('FREE ✦', false);
        Livewire::test(EditProfile::class)
            ->callAction(TestAction::make('comparePlans')->schemaComponent('plan-actions', schema: 'content'), ['plan' => 'pro'])
            ->assertNotified('Request sent. We\'ll activate it once confirmed.');
        expect(UpgradeRequest::query()->where('user_id', $this->person->id)->value('plan'))->toBe('pro');
    });
});
