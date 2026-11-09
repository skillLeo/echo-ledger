<?php

declare(strict_types=1);

use App\Exceptions\ApiError;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Support\CommandPalette;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientMemoryItem;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\Entitlements\Entitlements;
use App\Services\Views\TranscriptViews;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.6.2: a paid feature the plan leaves out stays where it is, with the badge of the lowest plan that has it; a click
| opens the upgrade sheet, never the feature, and the server still refuses the feature itself (feature_not_in_plan).
| A plan that has it shows the real control.
*/

beforeEach(function () {
    Plan::query()->where('key', 'business')->update(['name' => 'Ultra', 'badge' => 'ULTRA']);
    $this->person = Fx::user();
    $this->actingAs($this->person, 'web');
    Filament::setCurrentPanel('app');
});

function lockedPlan(User $person, string $key): void
{
    Workspace::query()->whereKey($person->current_workspace_id)->update(['plan_id' => Plan::query()->where('key', $key)->value('id')]);
}

function lockedRecording(User $person, ?Client $project = null): CaptureSession
{
    $session = Fx::session($person, null, ['client_id' => $project?->id, 'title' => 'Kick-off']);
    Fx::transcript($session, 'We need the login page by Friday');

    return $session->fresh();
}

describe('a project', function () {
    it('shows Ask and Screens with their badges on Free, and every AI control opens the sheet instead', function () {
        lockedPlan($this->person, 'free');
        $project = Fx::client($this->person, 'Acme');
        $project->update(['ai_notes' => true]);

        $html = (string) $this->get('/app/clients/'.$project->id)->assertOk()->getContent();
        expect($html)->toMatch('/client-tab-ask">Ask(<!--.*?-->)?\s*<span class="el-tag el-plan-badge"/')
            ->toMatch('/client-tab-screens">Screens(<!--.*?-->)?\s*<span class="el-tag el-plan-badge"/')
            ->toContain('el-plan-badge">Ultra</span>')->toContain('el-plan-badge">Pro</span>')->not->toContain('✦')
            // The switch reads off while the plan leaves AI notes out.
            ->toContain('aria-checked="false" aria-labelledby="ai-heading"');

        $page = Livewire::test(ViewClient::class, ['record' => $project->id]);
        $page->call('toggleAiNotes')->assertRedirectContains('/app/settings/plan/upgrade');
        expect($project->fresh()->ai_notes)->toBeTrue();

        Livewire::test(ViewClient::class, ['record' => $project->id])->call('remakeBrief')->assertRedirectContains('/app/settings/plan/upgrade');
        Livewire::test(ViewClient::class, ['record' => $project->id])->set('memoryText', 'Ship on Friday')->call('addMemory')->assertRedirectContains('/app/settings/plan/upgrade');
        expect(ClientMemoryItem::query()->where('client_id', $project->id)->exists())->toBeFalse();

        Livewire::test(ViewClient::class, ['record' => $project->id])->call('setTab', 'ask')->set('question', 'What is the deadline?')->call('ask')
            ->assertRedirectContains('/app/settings/plan/upgrade')->assertSet('answer', null);

        Livewire::test(ViewClient::class, ['record' => $project->id])->call('setTab', 'screens')
            ->assertSee('data-screens-locked', false)->assertSee('Screen moments are not in your plan')
            ->mountAction('upgradeScreens')->assertRedirectContains('/app/settings/plan/upgrade?feature=screen_moments&plan=pro');
    });

    it('shows the real controls on Ultra', function () {
        lockedPlan($this->person, 'business');
        $project = Fx::client($this->person, 'Acme');
        $project->update(['ai_notes' => false]);

        $html = (string) $this->get('/app/clients/'.$project->id)->assertOk()->getContent();
        expect($html)->not->toMatch('/client-tab-ask">Ask(<!--.*?-->)?\s*<span class="el-tag el-plan-badge"/')->not->toContain('data-ai-locked');

        Livewire::test(ViewClient::class, ['record' => $project->id])->call('toggleAiNotes')->assertActionNotMounted('upgradeAiNotes');
        expect($project->fresh()->ai_notes)->toBeTrue();
        Livewire::test(ViewClient::class, ['record' => $project->id])->call('setTab', 'screens')->assertDontSee('data-screens-locked', false);
    });

    it('keeps the screen moments switch in its settings with the badge and See plans on Free, and the switch itself on Pro', function () {
        lockedPlan($this->person, 'free');
        $project = Fx::client($this->person, 'Acme');

        Livewire::test(EditClient::class, ['record' => $project->id])
            ->assertSee('Keep screen moments of its meetings')->assertSee('Pro')->assertDontSee('✦')
            ->assertFormFieldDisabled('screens')
            ->mountAction(TestAction::make('upgradeScreens')->schemaComponent('screens'))
            ->assertRedirectContains('/app/settings/plan/upgrade');

        lockedPlan($this->person, 'pro');
        Livewire::test(EditClient::class, ['record' => $project->id])->assertFormFieldEnabled('screens')->assertDontSee('el-plan-badge');
    });

    it('still refuses AI notes on the server for a plan without them', function () {
        lockedPlan($this->person, 'free');
        expect(fn () => app(ClientWorkspace::class)->assertCanWriteNotes((int) $this->person->current_workspace_id))
            ->toThrow(fn (ApiError $e) => expect($e->apiCode())->toBe('feature_not_in_plan'));
    });
});

describe('a recording', function () {
    it('keeps the Show as switch with the badge on Free; choosing a view opens the sheet and makes nothing', function () {
        lockedPlan($this->person, 'free');
        $session = lockedRecording($this->person);

        $page = Livewire::test(ViewCaptureSession::class, ['record' => $session->id]);
        expect($page->html())->toContain('data-views-locked')->toContain('el-plan-badge">Pro</span>');
        $page->call('makeViews')->assertRedirectContains('/app/settings/plan/upgrade');
        expect(app(TranscriptViews::class)->state($session->finalTranscript()->firstOrFail())['status'])->not->toBe('making');

        // The server is the authority: the request itself is refused.
        expect(fn () => app(TranscriptViews::class)->request($session->finalTranscript()->firstOrFail()))
            ->toThrow(fn (ApiError $e) => expect($e->apiCode())->toBe('feature_not_in_plan'));
    });

    it('shows no lock on the views for Pro', function () {
        lockedPlan($this->person, 'pro');
        $session = lockedRecording($this->person);

        expect(Livewire::test(ViewCaptureSession::class, ['record' => $session->id])->html())->not->toContain('data-views-locked');
    });

    it('keeps Write notes with the badge on Free, opening the sheet, and writes them on Ultra', function () {
        lockedPlan($this->person, 'free');
        $project = Fx::client($this->person, 'Acme');
        $session = lockedRecording($this->person, $project);

        $page = Livewire::test(ViewCaptureSession::class, ['record' => $session->id]);
        expect($page->html())->toContain('Write notes')->toContain('el-plan-badge">Ultra</span>');
        $page->call('writeNotes', 'en')->assertRedirectContains('/app/settings/plan/upgrade');
        expect(CallNote::query()->where('capture_session_id', $session->id)->exists())->toBeFalse();

        lockedPlan($this->person, 'business');
        expect(Livewire::test(ViewCaptureSession::class, ['record' => $session->id])->html())->toContain('Write notes')->not->toContain('el-plan-badge');
    });

    it('never shows another person\'s recording, locked or not', function () {
        lockedPlan($this->person, 'free');
        $other = Fx::user();
        $theirs = lockedRecording($other);

        $this->get('/app/sessions/'.$theirs->id)->assertNotFound();
    });
});

describe('the palette', function () {
    it('offers Invite on Free, opening the upgrade sheet on the Workspace page, and the real Invite on Ultra', function () {
        lockedPlan($this->person, 'free');
        $invite = collect(CommandPalette::actions())->firstWhere(0, 'Invite');
        expect($invite[1])->toContain('action=upgradeForTeam')->and($invite[2])->toBe('Team workspaces are in Ultra: see plans');

        lockedPlan($this->person, 'business');
        app(Entitlements::class)->forget();
        $invite = collect(CommandPalette::actions())->firstWhere(0, 'Invite');
        expect($invite[1])->toContain('action=addMember');
    });
});
