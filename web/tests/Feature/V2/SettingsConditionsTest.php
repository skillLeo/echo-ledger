<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Filament\Support\SettingsContext;
use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Entitlements\PaymentProofs;
use App\Services\Entitlements\UpgradeRequests;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * WEB-SET-015 (docs/v2/audit/parity.csv): the settings that show only when they apply, each seeded and found shown on
 * its Settings page in the new design (and, where cheap, not shown without its condition). The settings pages are
 * shared by classic and v2.0; every page read here must be drawn by the v2.0 frame.
 */

require_once __DIR__.'/Support/parity_world.php';
require_once dirname(__DIR__).'/Accounts/Support/account_helpers.php';

beforeEach(function () {
    Mail::fake();
    Fx::fakeRecordingsDisk();
    Filament::setCurrentPanel('app');
    webParityDesign(true);
});

afterEach(fn () => Fx::cleanupDisks());

/** A person on [$plan], alone in their workspace. */
function v2SetPerson(string $plan = 'free', array $attributes = []): User
{
    $user = Fx::user(['name' => 'Ayesha Khan', ...$attributes]);
    Workspace::query()->whereKey($user->current_workspace_id)->update([
        'plan_id' => Plan::query()->where('key', $plan)->value('id'),
        'plan_expires_at' => $plan === 'free' ? null : now()->addDays(30),
    ]);

    return $user->fresh();
}

/** One Settings page as the person sees it in the new design: 200 and drawn by the v2.0 frame. */
function v2SetPage(User $user, string $page): string
{
    Filament::setCurrentPanel('app');
    app('auth')->forgetGuards();
    SettingsContext::forget();
    $response = test()->actingAs($user->fresh(), 'web')->get('/app/settings/'.$page);
    expect($response->getStatusCode())->toBe(200, "{$page} answers");
    $html = (string) $response->getContent();
    expect(webParityIsV2($html))->toBeTrue("{$page} is drawn by the v2.0 frame");

    return $html;
}

/** Whether the page's own content shows a control whose key starts with [$prefix] ("element|normalised text"). */
function v2SetShows(string $html, string $prefix): bool
{
    return collect(array_keys(webParityControls($html, 'main')))->contains(fn (string $key): bool => str_starts_with($key, $prefix));
}

it('WEB-SET-015: the Groq key box shows on Free without a key, the saved key and Remove key once one is saved, and the page is gone on Pro', function () {
    $free = v2SetPerson('free');
    $html = v2SetPage($free, 'transcription-key');
    expect(v2SetShows($html, 'input|groq key'))->toBeTrue('the Groq key box')
        ->and(v2SetShows($html, 'button|save'))->toBeTrue('Save')
        ->and($html)->not->toContain('id="setting-key.saved"');

    $free->forceFill(['transcription_key' => 'gsk_'.str_repeat('a', 40).'WXYZ', 'transcription_key_provider' => 'groq',
        'transcription_key_last4' => 'WXYZ', 'transcription_key_checked_at' => now()])->save();
    $html = v2SetPage($free, 'transcription-key');
    expect($html)->toContain('id="setting-key.saved"')->toContain('Ending in WXYZ')
        ->and(v2SetShows($html, 'button|remove key'))->toBeTrue('Remove key')
        ->and(v2SetShows($html, 'input|groq key'))->toBeFalse();

    $this->actingAs(v2SetPerson('pro'), 'web');
    SettingsContext::forget();
    $this->get('/app/settings/transcription-key')->assertNotFound();
});

it('WEB-SET-015: Recovery codes shows on Sign-in & security once two-step sign-in is on', function () {
    $user = v2SetPerson();
    expect(v2SetShows(v2SetPage($user, 'security'), 'button|recovery codes'))->toBeFalse();

    accountsEnrolTotp($user->fresh());
    $html = v2SetPage($user, 'security');
    expect(v2SetShows($html, 'button|recovery codes'))->toBeTrue('Recovery codes')
        ->and($html)->toContain('id="setting-security.recovery"');
});

it('WEB-SET-015: Google sign-in shows on Sign-in & security once Google is set up, Connected once linked', function () {
    $user = v2SetPerson();
    expect(v2SetPage($user, 'security'))->not->toContain('id="setting-security.google"');

    fakeGoogle();
    $html = v2SetPage($user, 'security');
    expect(v2SetShows($html, 'a|google sign-in not connected'))->toBeTrue('Google sign-in, Not connected, a link to Google');

    $user->forceFill(['google_id' => 'google-sub-1'])->save();
    expect(v2SetShows(v2SetPage($user, 'security'), 'button|google sign-in connected'))->toBeTrue('Google sign-in, Connected (Unlink)');
});

it('WEB-SET-015: Signed-in browsers shows on Sign-in & security when sessions are kept in the database, each other browser with Sign out', function () {
    $user = v2SetPerson();
    expect(v2SetPage($user, 'security'))->not->toContain('Signed-in browsers');

    config(['session.driver' => 'database']);
    $row = fn (string $id, string $agent, int $ago): array => ['id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.7', 'user_agent' => $agent,
        'payload' => '', 'last_activity' => now()->subMinutes($ago)->timestamp];
    DB::table('sessions')->insert([
        $row('v2set-phone', 'Mozilla/5.0 (Linux; Android 15) Chrome/130.0 Mobile Safari/537.36', 30),
        $row('v2set-laptop', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Version/18.0 Safari/605.1.15', 90),
    ]);
    $html = v2SetPage($user, 'security');
    expect($html)->toContain('id="setting-security.browsers"')->toContain('Signed-in browsers')
        ->toContain('Chrome on Android')->toContain('Safari on macOS')
        ->and(collect(array_keys(webParityControls($html, 'main')))->filter(fn (string $k): bool => $k === 'button|sign out')->count())->toBe(1, 'Sign out per other browser');
    expect(substr_count($html, "mountAction('signOutBrowser'"))->toBe(2);
});

it('WEB-SET-015: Sign in again and Remove from list show on a signed-out device in Devices, not on an active one', function () {
    $user = v2SetPerson();
    $active = Fx::device($user, ['platform' => 'windows', 'name' => 'Desk PC']);
    $out = Fx::device($user, ['platform' => 'android', 'name' => 'samsung SM-S938B']);
    $out->forceFill(['name_chosen' => true, 'name' => 'Work phone'])->save();
    $out->signOut('self');

    $html = v2SetPage($user, 'devices');
    expect(v2SetShows($html, 'button|work phone signed out by you'))->toBeTrue('the signed-out row')
        ->and(v2SetShows($html, 'button|desk pc'))->toBeTrue('the active row');

    Filament::setCurrentPanel('app');
    $this->actingAs($user->fresh(), 'web');
    // Opening a device's row: its modal's footer has Sign in again and Remove from list once it is signed out.
    $open = fn (int|string $id): TestAction => TestAction::make('device')->arguments(['device' => $id]);
    $page = Livewire::test(EditProfile::class, ['subpage' => 'devices']);
    $page->mountAction($open($out->id))->assertActionMounted('device')
        ->assertActionVisible('reauthDevice')
        ->assertActionVisible('forgetDevice')
        ->assertActionDoesNotExist([$open($out->id), 'signOutDevice']);
    expect($page->instance()->getAction([['name' => 'device', 'arguments' => ['device' => $out->id]], ['name' => 'reauthDevice']])?->getLabel())->toBe('Sign in again')
        ->and($page->instance()->getAction([['name' => 'device', 'arguments' => ['device' => $out->id]], ['name' => 'forgetDevice']])?->getLabel())->toBe('Remove from list');
    Livewire::test(EditProfile::class, ['subpage' => 'devices'])
        ->assertActionVisible([$open($active->id), 'signOutDevice'])
        ->assertActionDoesNotExist([$open($active->id), 'reauthDevice'])
        ->assertActionDoesNotExist([$open($active->id), 'forgetDevice']);
});

it('WEB-SET-015: Cancel deletion shows in Privacy & data while the account is being deleted, and the console leads to Keep my account', function () {
    $user = v2SetPerson();
    $html = v2SetPage($user, 'privacy');
    expect(v2SetShows($html, 'a|delete my account'))->toBeTrue()
        ->and($html)->not->toContain('id="setting-privacy.cancel_deletion"');

    $user->forceFill(['deletion_requested_at' => now(), 'deletes_at' => now()->addDays(7)])->save();
    // Every console page, Settings too, leads to the deletion page meanwhile (AccountGate), in both designs.
    SettingsContext::forget();
    $this->actingAs($user->fresh(), 'web')->get('/app/settings/privacy')->assertRedirect('/app/account-deletion');
    $this->app['auth']->forgetGuards();
    $page = $this->actingAs($user->fresh(), 'web')->get('/app/account-deletion');
    expect($page->getStatusCode())->toBe(200)
        ->and(webParityIsV2((string) $page->getContent()))->toBeTrue('the deletion page is drawn by the v2.0 frame')
        ->and(v2SetShows((string) $page->getContent(), 'button|keep my account'))->toBeTrue('Keep my account');

    // The row itself, as the Privacy & data page draws it.
    Filament::setCurrentPanel('app');
    SettingsContext::forget();
    Livewire::test(EditProfile::class, ['subpage' => 'privacy'])
        ->assertSeeHtml('id="setting-privacy.cancel_deletion"')->assertSee('Cancel deletion')->assertDontSee('Delete my account');
});

it('WEB-SET-015: the upgrade request shows on Plan & usage while one is open, with Payment details and Cancel request, and Upgrade goes', function () {
    $user = v2SetPerson('free');
    $html = v2SetPage($user, 'plan');
    expect($html)->not->toContain('id="setting-plan.request"')->toContain('id="setting-plan.upgrade"');

    app(UpgradeRequests::class)->create($user->fresh(), (int) $user->current_workspace_id, 'pro', null);
    $html = v2SetPage($user, 'plan');
    expect($html)->toContain('id="setting-plan.request"')->toContain('data-stage="awaiting_payment"')->not->toContain('id="setting-plan.upgrade"')
        ->and(v2SetShows($html, 'a|pro requested'))->toBeTrue('the request card')
        ->and(v2SetShows($html, 'button|cancel request'))->toBeTrue('Cancel request');
});

it('WEB-SET-015: Payment details shows on Plan & usage while the request waits for the payment', function () {
    $user = v2SetPerson('free');
    expect(v2SetPage($user, 'plan'))->not->toContain('id="setting-plan.payment_details"');

    app(UpgradeRequests::class)->create($user->fresh(), (int) $user->current_workspace_id, 'pro', null);
    $html = v2SetPage($user, 'plan');
    expect($html)->toContain('id="setting-plan.payment_details"')
        ->and(v2SetShows($html, 'a|payment details'))->toBeTrue('Payment details');
});

it('WEB-SET-015: Add receipt shows on Plan & usage when a new receipt is asked for, with Payment details beside it', function () {
    $user = v2SetPerson('free');
    $request = app(UpgradeRequests::class)->create($user->fresh(), (int) $user->current_workspace_id, 'pro', null);
    expect(v2SetPage($user, 'plan'))->not->toContain('id="setting-plan.receipt"');

    app(PaymentProofs::class)->markPaid($request->fresh(), 'FT26313ABC7781', 'Ayesha Khan');
    app(PaymentProofs::class)->requestNewReceipt($request->fresh(), Fx::superAdmin(), 'Please send the whole receipt.');
    expect(UpgradeRequest::query()->findOrFail($request->id)->stage())->toBe('needs_receipt');
    $html = v2SetPage($user, 'plan');
    expect($html)->toContain('id="setting-plan.receipt"')->toContain('We need another receipt')->toContain('Please send the whole receipt.')
        ->and(v2SetShows($html, 'a|add receipt'))->toBeTrue('Add receipt')
        ->and(v2SetShows($html, 'a|payment details'))->toBeTrue('Payment details');
});
