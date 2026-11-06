<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Pages\Plans;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Screen;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\Invitations;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\CredentialVault;
use App\Services\RetentionPolicy;
use App\Services\SettingsRepository;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| Plans and features (since v1.5, docs/API.md "Plans and features"): the super admin's Plans page, each feature and
| limit on its route, storage counting screens, AI credits, and retention per plan.
*/

uses(ApiClient::class);

beforeEach(function () {
    Fx::fakeRecordingsDisk();
    Filament::setCurrentPanel('admin');
    Mail::fake();
});
afterEach(fn () => Fx::cleanupDisks());

/** A plan with these limits for the user's workspace. */
function onPlan(User $user, array $attributes): Plan
{
    $plan = Plan::query()->create(['key' => 'test-'.Str::lower(Str::random(6)), 'name' => 'Test', ...$attributes]);
    Workspace::query()->findOrFail($user->current_workspace_id)->forceFill(['plan_id' => $plan->id])->save();

    return $plan;
}

function pngUpload(): UploadedFile
{
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    $path = tempnam(sys_get_temp_dir(), 'png');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, 'screen.png', 'image/png', null, true);
}

describe('the Plans page', function () {
    it('is only for the super admin', function () {
        $this->actingAs(Fx::user(), 'web');
        $this->get(Plans::getUrl())->assertForbidden();

        $this->actingAs(Fx::superAdmin(), 'web');
        $this->get(Plans::getUrl())->assertOk()->assertSee('Personal')->assertSee('Free');
    });

    it('creates a plan with limits and features, an empty limit being none', function () {
        $this->actingAs(Fx::superAdmin(), 'web');

        Livewire::test(Plans::class)->callTableAction('create', null, [
            'key' => 'team', 'name' => 'Team', 'price_usd' => 29, 'monthly_minutes' => 3000, 'storage_gb' => 50, 'max_devices' => '', 'retention_days' => 90,
            'seats' => 10, 'ai_credits_usd' => 20, 'feature_live_translate' => true, 'feature_screen_moments' => true, 'feature_ai_notes' => true, 'feature_connector' => false,
        ])->assertHasNoTableActionErrors();

        $plan = Plan::query()->where('key', 'team')->firstOrFail();
        expect((float) $plan->price_usd)->toBe(29.0)
            ->and($plan->monthly_minutes)->toBe(3000)
            ->and($plan->storage_bytes)->toBe(50 * 1024 ** 3)
            ->and($plan->max_devices)->toBeNull()
            ->and($plan->retention_days)->toBe(90)
            ->and($plan->seats)->toBe(10)
            ->and($plan->aiCredits())->toBe(20.0)
            ->and($plan->allows('connector'))->toBeFalse()
            ->and($plan->allows('ai_notes'))->toBeTrue();
    });

    it('refuses a plan key already used', function () {
        $this->actingAs(Fx::superAdmin(), 'web');

        Livewire::test(Plans::class)->callTableAction('create', null, ['key' => 'free', 'name' => 'Again', 'price_usd' => 0])->assertHasTableActionErrors(['key']);
    });

    it('edits a plan, and its limits apply at once to the workspaces on it', function () {
        $this->actingAs(Fx::superAdmin(), 'web');
        $free = Plan::free();

        Livewire::test(Plans::class)->callTableAction('edit', $free, ['name' => 'Starter', 'price_usd' => 5, 'monthly_minutes' => 500, 'feature_ai_notes' => true])->assertHasNoTableActionErrors();

        $free->refresh();
        expect($free->name)->toBe('Starter')->and($free->monthly_minutes)->toBe(500)->and($free->allows('ai_notes'))->toBeTrue()->and($free->key)->toBe('free');
    });

    it('assigns a plan to a workspace', function () {
        $admin = Fx::superAdmin();
        $other = Fx::user();
        $this->actingAs($admin, 'web');

        Livewire::test(Plans::class)->callTableAction('assign', Plan::free(), ['workspace_id' => $other->current_workspace_id])->assertHasNoTableActionErrors();

        expect(Workspace::query()->findOrFail($other->current_workspace_id)->plan->key)->toBe('free');
    });

    it('deletes only a plan nobody is on, and never Personal or Free', function () {
        $admin = Fx::superAdmin();
        $this->actingAs($admin, 'web');
        $spare = Plan::query()->create(['key' => 'spare', 'name' => 'Spare']);
        $used = onPlan($admin, []);

        Livewire::test(Plans::class)
            ->assertActionHidden(TestAction::make('delete')->table(Plan::personal()))
            ->assertActionHidden(TestAction::make('delete')->table(Plan::free()))
            ->assertActionHidden(TestAction::make('delete')->table($used))
            ->callTableAction('delete', $spare);

        expect(Plan::query()->find($spare->id))->toBeNull()->and(Plan::query()->find($used->id))->not->toBeNull();
    });
});

describe('features on their routes', function () {
    it('answers 403 feature_not_in_plan with the feature for a screen', function () {
        $owner = Fx::user();
        onPlan($owner, ['features' => ['screen_moments' => false]]);
        $session = Fx::session($owner, $device = Fx::device($owner));

        $this->api(Fx::token($device))->postJson("/api/sessions/{$session->id}/screens", ['at_ms' => 0])
            ->assertForbidden()->assertJson(['code' => 'feature_not_in_plan', 'feature' => 'screen_moments', 'message' => 'Screen moments are not in your plan']);
    });

    it('still reads the screens kept before the plan changed', function () {
        $owner = Fx::user();
        $session = Fx::session($owner, $device = Fx::device($owner));
        Screen::query()->create(['workspace_id' => $session->workspace_id, 'capture_session_id' => $session->id, 'at_ms' => 1, 'width' => 1, 'height' => 1, 'trigger' => 'manual', 'sha256' => str_repeat('a', 64), 'mime' => 'image/png', 'bytes' => 1]);
        onPlan($owner, ['features' => ['screen_moments' => false]]);

        expect($this->api(Fx::token($device))->getJson("/api/sessions/{$session->id}/screens")->assertOk()->json('data'))->toHaveCount(1);
    });

    it('answers 403 feature_not_in_plan for a Live Translate token', function () {
        $owner = Fx::user();
        onPlan($owner, ['features' => ['live_translate' => false]]);

        $this->api(Fx::token(Fx::device($owner)))->postJson('/api/live-translate/token', ['mode' => 'game', 'target_language' => 'en'])
            ->assertForbidden()->assertJson(['code' => 'feature_not_in_plan', 'feature' => 'live_translate']);
    });

    it('answers 403 feature_not_in_plan for client notes, the brief, memory and Ask, and keeps what is there readable', function () {
        app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
        $owner = Fx::user();
        onPlan($owner, ['features' => ['ai_notes' => false]]);
        $token = Fx::token($device = Fx::device($owner));
        $client = Fx::client($owner, 'Acme');
        $session = Fx::session($owner, $device, ['client_id' => $client->id]);
        Fx::transcript($session, 'Hello there');

        foreach ([
            ['POST', "/api/clients/{$client->id}/ask", ['question' => 'When is it?']],
            ['POST', "/api/clients/{$client->id}/brief/refresh", []],
            ['POST', "/api/clients/{$client->id}/memory", ['kind' => 'decision', 'text' => 'Ship it']],
            ['POST', "/api/sessions/{$session->id}/notes", []],
        ] as [$method, $uri, $body]) {
            $response = $this->api($token)->json($method, $uri, $body);
            expect($response->status())->toBe(403, $uri)->and($response->json('code'))->toBe('feature_not_in_plan', $uri)->and($response->json('feature'))->toBe('ai_notes', $uri);
        }
        $this->api($token)->getJson("/api/clients/{$client->id}/workspace")->assertOk();
    });

    it('does not start call notes on its own when the plan has none', function () {
        app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
        $owner = Fx::user();
        onPlan($owner, ['features' => ['ai_notes' => false]]);
        $client = Fx::client($owner, 'Acme');
        $client->forceFill(['ai_notes' => true])->save();
        $session = Fx::session($owner, Fx::device($owner), ['client_id' => $client->id]);
        $transcript = Fx::transcript($session, 'Hello there');

        app(ClientWorkspace::class)->afterTranscript($transcript->fresh());

        expect(unscoped(fn () => $session->fresh()->callNote))->toBeNull();
    });

    it('never switches off transcript views or suggested fixes', function () {
        $plan = Plan::free();

        expect($plan->allows('views'))->toBeTrue()->and($plan->allows('suggestions'))->toBeTrue();
        // Not features at all: a plan cannot name them on the Plans page.
        expect(Plan::FEATURES)->toBe(['live_translate', 'screen_moments', 'ai_notes', 'connector']);
    });
});

describe('storage', function () {
    it('counts recordings and screen pictures of every person, the bin included', function () {
        $owner = Fx::user();
        $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
        $member = withoutWorkspace(Fx::user());
        app(Workspaces::class)->addMember($ws, $member);
        app(Workspaces::class)->switchTo($member, $ws);
        $mine = Fx::session($owner, Fx::device($owner));
        $theirs = Fx::session($member->fresh(), Fx::device($member->fresh()));
        $recording = Fx::readyRecording($mine, str_repeat('a', 100));
        foreach ([[$mine, 'c', 1000, 1500], [$theirs, 'd', 2000, null]] as [$session, $sha, $bytes, $stored]) {
            Screen::query()->create(['workspace_id' => $ws->id, 'capture_session_id' => $session->id, 'at_ms' => 1, 'width' => 1, 'height' => 1, 'trigger' => 'manual',
                'sha256' => str_repeat($sha, 64), 'mime' => 'image/png', 'bytes' => $bytes, 'stored_bytes' => $stored]);
        }

        // The encrypted picture and thumbnail when they are known; a screen stored before: its picture.
        expect(app(PlanLimits::class)->storageUsed($ws->id))->toBe((int) $recording->stored_size_bytes + 1500 + 2000);

        $theirs->delete();
        expect(app(PlanLimits::class)->storageUsed($ws->id))->toBe((int) $recording->stored_size_bytes + 1500 + 2000);
    });

    it('stops a new upload at the plan\'s storage when screens fill it', function () {
        $owner = Fx::user();
        $session = Fx::session($owner, $device = Fx::device($owner));
        Screen::query()->create(['workspace_id' => $session->workspace_id, 'capture_session_id' => $session->id, 'at_ms' => 1, 'width' => 1, 'height' => 1, 'trigger' => 'manual',
            'sha256' => str_repeat('e', 64), 'mime' => 'image/png', 'bytes' => 900, 'stored_bytes' => 900]);
        onPlan($owner, ['storage_bytes' => 1000]);

        $this->api(Fx::token($device))->postJson('/api/sessions', Fx::sessionPayload(['artifacts' => [Fx::artifact(['size_bytes' => 200])]]))
            ->assertForbidden()->assertJsonPath('code', 'storage_limit_reached');
    });

    it('refuses a screen past the plan\'s storage, and records what a kept screen takes', function () {
        $owner = Fx::user();
        $session = Fx::session($owner, $device = Fx::device($owner));
        $file = pngUpload();
        $sha = hash_file('sha256', $file->getPathname());

        $response = $this->api(Fx::token($device))->post("/api/sessions/{$session->id}/screens", ['image' => $file, 'at_ms' => 0, 'width' => 8, 'height' => 8, 'trigger' => 'manual', 'sha256' => $sha]);
        $response->assertCreated();
        $screen = unscoped(fn () => Screen::query()->where('capture_session_id', $session->id)->firstOrFail());
        expect($screen->stored_bytes)->toBeGreaterThan($screen->bytes)
            ->and(app(PlanLimits::class)->storageUsed($session->workspace_id))->toBe($screen->stored_bytes);

        onPlan($owner, ['storage_bytes' => $screen->stored_bytes]);
        $another = imagecreatetruecolor(9, 9);
        imagefill($another, 0, 0, 255);
        ob_start();
        imagepng($another);
        $bytes = (string) ob_get_clean();
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $bytes);
        $this->app['auth']->forgetGuards();
        $this->api(Fx::token($device))->post("/api/sessions/{$session->id}/screens", [
            'image' => new UploadedFile($path, 'two.png', 'image/png', null, true), 'at_ms' => 1, 'width' => 9, 'height' => 9, 'trigger' => 'manual', 'sha256' => hash('sha256', $bytes),
        ])->assertForbidden()->assertJsonPath('code', 'storage_limit_reached');
    });

    it('checks a screen-heavy copy against the plan in Save to my library', function () {
        $sender = Fx::user();
        $receiver = Fx::user();
        $session = Fx::session($sender, $device = Fx::device($sender), ['title' => 'Screens']);
        Screen::query()->create(['workspace_id' => $session->workspace_id, 'capture_session_id' => $session->id, 'at_ms' => 1, 'width' => 1, 'height' => 1, 'trigger' => 'manual',
            'sha256' => str_repeat('f', 64), 'mime' => 'image/png', 'bytes' => 5000, 'stored_bytes' => 5000]);
        $this->api(Fx::token($device))->postJson("/api/sessions/{$session->id}/send", ['to' => [$receiver->email]])->assertCreated();
        $this->app['auth']->forgetGuards();
        onPlan($receiver, ['storage_bytes' => 4000]);

        $token = Fx::token(Fx::device($receiver));
        $item = $this->api($token)->getJson('/api/shared-with-me')->assertOk()->json('data.0.id');
        $this->api($token)->postJson("/api/shared-with-me/{$item}/save")->assertForbidden()->assertJsonPath('code', 'storage_limit_reached');
    });

    it('reports screens in GET /api/storage, the person\'s own', function () {
        $owner = Fx::user();
        $session = Fx::session($owner, $device = Fx::device($owner));
        $recording = Fx::readyRecording($session, str_repeat('a', 100));
        Screen::query()->create(['workspace_id' => $session->workspace_id, 'capture_session_id' => $session->id, 'at_ms' => 1, 'width' => 1, 'height' => 1, 'trigger' => 'manual',
            'sha256' => str_repeat('a', 64), 'mime' => 'image/png', 'bytes' => 700, 'stored_bytes' => 900]);

        $this->api(Fx::token($device))->getJson('/api/storage')->assertOk()
            ->assertJsonPath('recordings_bytes', (int) $recording->stored_size_bytes + 900)
            ->assertJsonPath('recordings_count', 1);
    });
});

describe('AI credits', function () {
    function aiSpend(Client $client, float $usd): void
    {
        UsageLedgerEntry::query()->create([
            'workspace_id' => $client->workspace_id, 'user_id' => $client->user_id, 'client_id' => $client->id, 'source' => 'server', 'report_id' => 'client-ai/'.Str::ulid(),
            'use_case' => 'client_ask', 'provider' => 'claude', 'duration_ms' => 0, 'channels' => 1, 'billed_ms' => 0, 'unit_price_usd' => 0, 'cost_usd' => $usd, 'billable' => true, 'occurred_at' => now(),
        ]);
    }

    it('answers ai_budget_reached once the plan\'s credits are used, with a message about the credits', function () {
        app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
        $owner = Fx::user();
        onPlan($owner, ['ai_credits_usd' => 1]);
        $client = Fx::client($owner, 'Acme');
        aiSpend($client, 1.0);

        $this->api(Fx::token(Fx::device($owner)))->postJson("/api/clients/{$client->id}/ask", ['question' => 'When is it?'])
            ->assertForbidden()->assertJsonPath('code', 'ai_budget_reached')->assertJsonFragment(['message' => "This month's AI credits (\$1.00) are used up. They come back next month, or with a bigger plan."]);
    });

    it('counts every person in the workspace against one month\'s credits', function () {
        $owner = Fx::user();
        $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
        onPlan($owner, ['ai_credits_usd' => 2]);
        $member = withoutWorkspace(Fx::user());
        app(Workspaces::class)->addMember($ws, $member);
        app(Workspaces::class)->switchTo($member, $ws);
        aiSpend(Fx::client($owner, 'Mine'), 1.0);
        aiSpend(Fx::client($member->fresh(), 'Theirs'), 0.5);

        expect(app(ClientWorkspace::class)->budget($ws->id))->toBe(['spent_usd' => 1.5, 'budget_usd' => 2.0]);
    });

    it('keeps the server\'s budget for a plan without credits', function () {
        $owner = Fx::user();
        app(SettingsRepository::class)->set('client_ai.monthly_usd', 7);

        expect(app(ClientWorkspace::class)->budget((int) $owner->current_workspace_id)['budget_usd'])->toBe(7.0);
    });
});

describe('retention per plan', function () {
    it('moves recordings past the plan\'s days to the bin, per workspace, leaving kept ones', function () {
        app(SettingsRepository::class)->set('retention_days', 365);
        $short = Fx::user();
        onPlan($short, ['retention_days' => 30]);
        $long = Fx::user();
        $old = Fx::session($short, $device = Fx::device($short), ['title' => 'Old']);
        $kept = Fx::session($short, $device, ['title' => 'Kept', 'keep_forever' => true]);
        $recent = Fx::session($short, $device, ['title' => 'Recent']);
        $otherOld = Fx::session($long, Fx::device($long), ['title' => 'Other old']);
        CaptureSession::query()->withoutGlobalScopes()->whereIn('id', [$old->id, $kept->id, $otherOld->id])->update(['created_at' => now()->subDays(31)]);
        $this->app['auth']->forgetGuards();

        expect(app(RetentionPolicy::class)->applyRetention())->toBe(1);

        unscoped(function () use ($old, $kept, $recent, $otherOld): void {
            expect(CaptureSession::query()->find($old->id))->toBeNull()
                ->and(CaptureSession::onlyTrashed()->find($old->id))->not->toBeNull()
                ->and(CaptureSession::query()->find($kept->id))->not->toBeNull()
                ->and(CaptureSession::query()->find($recent->id))->not->toBeNull()
                ->and(CaptureSession::query()->find($otherOld->id))->not->toBeNull();
        });
    });

    it('goes by a shorter general setting too, and runs through the existing command', function () {
        app(SettingsRepository::class)->set('retention_days', 7);
        $owner = Fx::user();
        onPlan($owner, ['retention_days' => 30]);
        $old = Fx::session($owner, Fx::device($owner));
        CaptureSession::query()->withoutGlobalScopes()->whereKey($old->id)->update(['created_at' => now()->subDays(8)]);
        $this->app['auth']->forgetGuards();

        expect(app(RetentionPolicy::class)->daysForWorkspace((int) $owner->current_workspace_id))->toBe(7);
        $this->artisan('recordings:retention')->assertSuccessful();

        expect(unscoped(fn () => CaptureSession::onlyTrashed()->find($old->id)))->not->toBeNull();
    });

    it('takes a client\'s longer days down to the plan\'s', function () {
        $owner = Fx::user();
        onPlan($owner, ['retention_days' => 30]);
        $client = Fx::client($owner, 'Acme');
        $client->forceFill(['retention_days' => 200])->save();
        $old = Fx::session($owner, Fx::device($owner), ['client_id' => $client->id]);
        CaptureSession::query()->withoutGlobalScopes()->whereKey($old->id)->update(['created_at' => now()->subDays(31)]);
        $this->app['auth']->forgetGuards();

        app(RetentionPolicy::class)->applyRetention();

        expect(unscoped(fn () => CaptureSession::onlyTrashed()->find($old->id)))->not->toBeNull();
    });
});

describe('seats', function () {
    it('counts people and open invitations, and a plan without seats never blocks', function () {
        $owner = Fx::user();
        $ws = Workspace::query()->findOrFail($owner->current_workspace_id);

        foreach (range(1, 3) as $i) {
            app(Invitations::class)->invite($ws, $owner, "p{$i}@example.test", WorkspaceRole::Member);
        }

        expect(app(PlanLimits::class)->seatsUsed($ws->id))->toBe(4)
            ->and(app(PlanLimits::class)->summary($ws->id)['seats'])->toBe(['used' => 4, 'limit' => null]);
    });
});
