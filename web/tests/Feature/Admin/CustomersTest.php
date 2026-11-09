<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Customers\CustomerResource;
use App\Filament\Admin\Resources\Customers\Pages\ListCustomers;
use App\Filament\Admin\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Auth\EditProfile;
use App\Mail\VerifyEmailMail;
use App\Models\AdminNote;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\Plan;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLog;
use App\Services\Entitlements\Entitlements;
use App\Services\Staff\Customers;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.6 P2.2: Admin panel → Customers. Metadata and usage only, recordings only while the person lets support look.
*/

function staffOnAdmin(): User
{
    $staff = Fx::superAdmin();
    test()->actingAs($staff, 'web');
    Filament::setCurrentPanel('admin');

    return $staff;
}

function ledgerFor(User $user, int $minutes, string $provider = 'assemblyai', float $cost = 0.5): void
{
    UsageLedgerEntry::query()->withoutGlobalScopes()->create([
        'workspace_id' => $user->current_workspace_id, 'user_id' => $user->id, 'source' => UsageLedgerEntry::SOURCE_SERVER ?? 'server',
        'report_id' => (string) Str::ulid(), 'use_case' => 'final_transcript', 'provider' => $provider, 'duration_ms' => $minutes * 60000,
        'channels' => 1, 'billed_ms' => $minutes * 60000, 'unit_price_usd' => 0, 'cost_usd' => $cost, 'billable' => true, 'occurred_at' => now(),
    ]);
}

it('is closed to anyone who is not staff: the list, a customer\'s page and every action', function () {
    $a = Fx::user();
    $b = Fx::user();
    $this->actingAs($b, 'web');

    $this->get('/admin/customers')->assertForbidden();
    $this->get('/admin/customers/'.$a->id)->assertForbidden();
    $this->get('/admin/customers/'.$b->id)->assertForbidden();
    Filament::setCurrentPanel('admin');
    Livewire::test(ListCustomers::class)->assertForbidden();
    Livewire::test(ViewCustomer::class, ['record' => $a->id])->assertForbidden();
    expect(CustomerResource::canAccess())->toBeFalse();
});

it('lists every account with plan, status, devices, minutes, cost, plan use, storage and dates', function () {
    $staff = staffOnAdmin();
    $ana = Fx::user(['name' => 'Ana Customer', 'email' => 'ana@example.test']);
    $bob = Fx::user(['name' => 'Bob Customer', 'email' => 'bob@example.test']);
    Fx::device($ana);
    ledgerFor($ana, 30, 'assemblyai', 1.25);
    $bob->forceFill(['suspended_at' => now()])->save();

    $html = (string) $this->get('/admin/customers')->assertOk()->getContent();
    expect($html)->toContain('Ana Customer')->toContain('ana@example.test')->toContain('Suspended')->toContain('30.0')
        ->toContain('Minutes this month')->toContain('Cost this month')->toContain('Of plan')->toContain('Storage')->toContain('Last active')->toContain('Signed up');

    Livewire::test(ListCustomers::class)
        ->assertCanSeeTableRecords([$ana, $bob, $staff])
        ->searchTable('ana@')->assertCanSeeTableRecords([$ana])->assertCanNotSeeTableRecords([$bob])
        ->searchTable(null)->filterTable('status', 'suspended')->assertCanSeeTableRecords([$bob])->assertCanNotSeeTableRecords([$ana]);

    $pro = Plan::query()->where('key', 'pro')->firstOrFail();
    Workspace::query()->whereKey($ana->current_workspace_id)->update(['plan_id' => $pro->id]);
    Livewire::test(ListCustomers::class)->filterTable('plan', $pro->id)->assertCanSeeTableRecords([$ana])->assertCanNotSeeTableRecords([$bob]);
});

it('changes the plan of several customers at once, until a date, and suspends them but never staff', function () {
    $staff = staffOnAdmin();
    $ana = Fx::user();
    $bob = Fx::user();
    $other = Fx::superAdmin();
    $pro = Plan::query()->where('key', 'pro')->firstOrFail();

    Livewire::test(ListCustomers::class)->callTableBulkAction('changePlan', [$ana, $bob], ['plan_id' => $pro->id, 'until' => now()->addMonth()->toDateTimeString()])->assertNotified();
    expect(app(Entitlements::class)->plan((int) $ana->current_workspace_id)->key)->toBe('pro')
        ->and(Workspace::query()->find($bob->current_workspace_id)->plan_expires_at)->not->toBeNull();

    Livewire::test(ListCustomers::class)->callTableBulkAction('suspend', [$ana, $other], ['reason' => 'abuse']);
    expect($ana->fresh()->isSuspended())->toBeTrue()->and($other->fresh()->isSuspended())->toBeFalse();
    Livewire::test(ListCustomers::class)->callTableBulkAction('restore', [$ana]);
    expect($ana->fresh()->isSuspended())->toBeFalse()
        ->and(AuditEvent::query()->where('event', 'account_suspended')->where('subject_id', (string) $ana->id)->exists())->toBeTrue();
    expect($staff->fresh()->isSuspended())->toBeFalse();
});

it('downloads the customers as CSV with no formula a spreadsheet would run', function () {
    staffOnAdmin();
    Fx::user(['name' => '=HYPERLINK("x")', 'email' => 'csv@example.test']);

    $response = Livewire::test(ListCustomers::class)->callTableAction('csv');
    $response->assertFileDownloaded();
    $csv = CustomerResource::csv(User::query()->get());
    ob_start();
    $csv->sendContent();
    $body = (string) ob_get_clean();
    expect($body)->toContain('Name,Email,Plan,Status')->toContain('csv@example.test')->toContain("'=HYPERLINK")->not->toContain(',=HYPERLINK');
});

it('shows a customer\'s usage, devices, workspaces, plan and notes, and nothing of their recordings', function () {
    staffOnAdmin();
    $ana = Fx::user(['name' => 'Ana']);
    $device = Fx::device($ana, ['name' => 'Ana phone']);
    $session = Fx::session($ana, $device, ['title' => 'Secret board meeting']);
    Fx::transcript($session, 'The confidential numbers are 42.');
    ledgerFor($ana, 12, 'groq', 0.3);

    $html = (string) $this->get('/admin/customers/'.$ana->id)->assertOk()->getContent();
    expect($html)->toContain('Minutes this month')->toContain('12.0')->toContain('Groq')->toContain('Ana phone')->toContain('Personal')
        ->toContain('Recordings and transcripts are private')
        ->not->toContain('Secret board meeting')->not->toContain('confidential numbers');
    expect(app(Customers::class)->stats($ana)['recordings'])->toBe(1);
});

it('revokes a customer\'s device, and only theirs: another person\'s device id is 404', function () {
    $staff = staffOnAdmin();
    $ana = Fx::user();
    $bob = Fx::user();
    $anas = Fx::device($ana);
    $bobs = Fx::device($bob);

    Livewire::test(ViewCustomer::class, ['record' => $ana->id])->callAction(TestAction::make('revokeDevice')->arguments(['device' => $anas->id]))->assertNotified();
    expect(Device::query()->withoutGlobalScopes()->find($anas->id)->status())->toBe(Device::REVOKED);

    Livewire::test(ViewCustomer::class, ['record' => $ana->id])->callAction(TestAction::make('revokeDevice')->arguments(['device' => $bobs->id]))->assertNotFound();
    expect(Device::query()->withoutGlobalScopes()->find($bobs->id)->signed_out_at)->toBeNull();
    expect($staff->id)->toBeInt();
});

it('runs the support actions: confirmation email, two-step reset, sign out everywhere, suspend, delete', function () {
    Mail::fake();
    staffOnAdmin();
    $ana = Fx::user(['email_verified_at' => null]);
    $ana->forceFill(['app_authentication_secret' => 'SECRETSECRETSECR'])->save();
    Fx::device($ana);

    Livewire::test(ViewCustomer::class, ['record' => $ana->id])->callAction('resendVerification')->assertNotified();
    Mail::assertQueued(VerifyEmailMail::class);

    Livewire::test(ViewCustomer::class, ['record' => $ana->id])->callAction('resetTwoFactor');
    expect($ana->fresh()->hasTwoFactor())->toBeFalse();

    Livewire::test(ViewCustomer::class, ['record' => $ana->id])->callAction('signOutEverywhere');
    expect(Device::query()->withoutGlobalScopes()->where('user_id', $ana->id)->whereNull('signed_out_at')->count())->toBe(0);

    Livewire::test(ViewCustomer::class, ['record' => $ana->id])->callAction('suspend', ['reason' => 'chargeback']);
    expect($ana->fresh()->isSuspended())->toBeTrue()->and($ana->fresh()->suspended_reason)->toBe('chargeback');
    Livewire::test(ViewCustomer::class, ['record' => $ana->id])->callAction('suspend');
    expect($ana->fresh()->isSuspended())->toBeFalse();

    Livewire::test(ViewCustomer::class, ['record' => $ana->id])->callAction('delete');
    expect($ana->fresh()->isBeingDeleted())->toBeTrue();
    foreach (['verification_resent', 'mfa_reset_by_staff', 'signed_out_by_staff', 'account_deletion_by_staff'] as $event) {
        expect(AuditEvent::query()->where('event', $event)->where('subject_id', (string) $ana->id)->exists())->toBeTrue($event);
    }
});

it('never suspends, resets or deletes staff from a customer page', function () {
    staffOnAdmin();
    $other = Fx::superAdmin();
    $other->forceFill(['app_authentication_secret' => 'SECRETSECRETSECR'])->save();

    Livewire::test(ViewCustomer::class, ['record' => $other->id])
        ->assertActionHidden('suspend')->assertActionHidden('delete')->assertActionHidden('resetTwoFactor');
});

it('changes a customer\'s plan with an end date and their own features and limits, and keeps staff notes', function () {
    $staff = staffOnAdmin();
    $ana = Fx::user();
    $business = Plan::query()->where('key', 'business')->firstOrFail();

    Livewire::test(ViewCustomer::class, ['record' => $ana->id])
        ->callAction('changePlan', ['plan_id' => $business->id, 'until' => now()->addWeek()->toDateTimeString()])
        ->callAction('overrides', ['override_feature_live_translate' => 'on', 'override_limit_devices' => '7'])
        ->callAction('addNote', ['body' => 'Paid by bank transfer, ref 991.']);

    expect(app(Entitlements::class)->plan((int) $ana->current_workspace_id)->key)->toBe('business')
        ->and($ana->fresh()->entitlement_overrides)->toBe(['features' => ['live_translate' => true], 'limits' => ['devices' => 7]])
        ->and(AdminNote::query()->where('user_id', $ana->id)->value('body'))->toBe('Paid by bank transfer, ref 991.')
        ->and(AdminNote::query()->where('user_id', $ana->id)->value('author_id'))->toBe($staff->id);

    $this->get('/admin/customers/'.$ana->id)->assertOk()->assertSee('Paid by bank transfer, ref 991.');
    // The person never sees the note.
    $this->actingAs($ana->fresh(), 'web');
    $this->get('/app/profile')->assertOk()->assertDontSee('Paid by bank transfer');
});

it('opens recordings only while the person lets support look, logs every look, and only theirs', function () {
    $staff = staffOnAdmin();
    $ana = Fx::user();
    $bob = Fx::user();
    $session = Fx::session($ana, null, ['title' => 'Call with the bank']);
    Fx::transcript($session, 'Account number is 1234.');
    $bobs = Fx::session($bob, null, ['title' => 'Bob private']);

    Livewire::test(ViewCustomer::class, ['record' => $ana->id])->mountAction(TestAction::make('openRecording')->arguments(['session' => $session->id]))
        ->assertActionNotMounted('openRecording');
    expect(AuditEvent::query()->where('event', 'support_content_viewed')->count())->toBe(0);

    $ana->forceFill(['support_access_until' => now()->addHour()])->save();
    $this->get('/admin/customers/'.$ana->id)->assertOk()->assertSee('Call with the bank')->assertSee('Support access is on until');
    $open = Livewire::test(ViewCustomer::class, ['record' => $ana->id])->mountAction(TestAction::make('openRecording')->arguments(['session' => $session->id]));
    expect(implode('', array_map('strval', (array) ($open->effects['partials'] ?? []))).$open->html())->toContain('Account number is 1234.');
    expect(AuditEvent::query()->where('event', 'support_content_viewed')->where('actor', 'web')->value('user_id'))->toBe($staff->id);

    // Another person's recording is never reached through this page.
    Livewire::test(ViewCustomer::class, ['record' => $ana->id])->mountAction(TestAction::make('openRecording')->arguments(['session' => $bobs->id]))->assertNotFound();
    expect(AuditEvent::query()->where('event', 'support_content_viewed')->count())->toBe(1);

    $ana->forceFill(['support_access_until' => now()->subMinute()])->save();
    $this->get('/admin/customers/'.$ana->id)->assertOk()->assertDontSee('Call with the bank');
});

it('lets a person turn Support access on for a while and off again, for their own account only', function () {
    $ana = Fx::user();
    $bob = Fx::user();
    $this->actingAs($ana, 'web');
    Filament::setCurrentPanel('app');

    Livewire::test(EditProfile::class)->callAction(TestAction::make('grantSupportAccess')->schemaComponent('support-access-actions', schema: 'content'), ['hours' => '24']);
    expect($ana->fresh()->support_access_until?->isFuture())->toBeTrue()
        ->and($ana->fresh()->support_access_until->diffInHours(now(), true))->toBeGreaterThan(23)
        ->and($bob->fresh()->support_access_until)->toBeNull();
    $this->get('/app/profile')->assertOk()->assertSee('On until');

    Livewire::test(EditProfile::class)->callAction(TestAction::make('endSupportAccess')->schemaComponent('support-access-actions', schema: 'content'));
    expect($ana->fresh()->support_access_until)->toBeNull()
        ->and(AuditEvent::query()->where('event', 'support_access_granted')->where('user_id', $ana->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event', 'support_access_ended')->where('user_id', $ana->id)->exists())->toBeTrue();
});

it('shows the customer\'s own activity log on their page', function () {
    staffOnAdmin();
    $ana = Fx::user();
    app(AuditLog::class)->record('password_changed', $ana->id, $ana, 'Password changed by Ana');

    $this->get('/admin/customers/'.$ana->id)->assertOk()->assertSee('Password changed by Ana');
});
