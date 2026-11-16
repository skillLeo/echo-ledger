<?php

declare(strict_types=1);

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Support\SettingsContext;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\Entitlements\Entitlements;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.6 P1.4: the plan's badge and the upgrade sheet where the plan leaves a feature out: a project's AI notes and Ask,
| and Profile → Connected apps (Ask an AI assistant, the connector).
*/

beforeEach(function () {
    $this->person = Fx::user();
    $this->actingAs($this->person, 'web');
    Filament::setCurrentPanel('app');
});

function badgePlanFor(Workspace|int $workspace, string $key): void
{
    Workspace::query()->whereKey($workspace instanceof Workspace ? $workspace->id : $workspace)->update(['plan_id' => Plan::query()->where('key', $key)->value('id')]);
}

it('shows the badge and See plans on a project\'s Overview and Ask when the plan leaves AI notes out', function () {
    badgePlanFor((int) $this->person->current_workspace_id, 'free');
    $project = Fx::client($this->person, 'Acme');
    $badge = 'el-plan-badge">'.app(Entitlements::class)->lowestPlan('ai_notes')->name.'</span>';

    $html = (string) $this->get('/app/clients/'.$project->id)->assertOk()->getContent();
    expect($html)->toContain('data-ai-locked')->toContain($badge)->toContain('not in your plan')->toContain("mountAction('upgradeAiNotes')");

    $page = Livewire::test(ViewClient::class, ['record' => $project->id])->call('setTab', 'ask')->assertSee('data-ai-locked', false);
    $page->mountAction('upgradeAiNotes')->assertRedirectContains('feature=ai_notes');
});

it('shows no lock on a plan with AI notes', function () {
    badgePlanFor((int) $this->person->current_workspace_id, 'business');
    $project = Fx::client($this->person, 'Acme');

    expect((string) $this->get('/app/clients/'.$project->id)->assertOk()->getContent())->not->toContain('data-ai-locked');
});

it('shows the badge and the sheet on Connected apps when the plan leaves the connector out', function () {
    badgePlanFor((int) $this->person->current_workspace_id, 'free');
    $html = (string) $this->get('/app/settings/devices')->assertOk()->getContent();
    expect($html)->toContain('id="setting-devices.connected"')->toContain('<span class="el-tag">Pro</span>')->toContain('/app/settings/plan/upgrade?feature=connector')
        // The feature's own name, never the assistants' makers (D-129).
        ->toContain('Ask an AI assistant')->not->toContain('Claude')->not->toContain('ChatGPT');

    badgePlanFor((int) $this->person->current_workspace_id, 'business');
    SettingsContext::forget();
    expect((string) $this->get('/app/settings/devices')->assertOk()->getContent())->toContain('Not connected')->toContain('Ask an AI assistant')->not->toContain('feature=connector');
});

it('shows the locked Show as… with its badge in the button, never inside an attribute', function () {
    Fx::fakeRecordingsDisk();
    badgePlanFor((int) $this->person->current_workspace_id, 'free');
    $session = Fx::session($this->person, null, ['language' => 'ur', 'status' => 'completed']);
    Fx::readyRecording($session);
    Fx::transcript($session, 'آپ کیسے ہیں')->update(['language' => 'ur']);

    $html = (string) $this->get('/app/sessions/'.$session->id)->assertOk()->getContent();

    // The badge's markup inside an attribute ends it early and spills the rest of the tag onto the page as text.
    expect($html)->toContain('Show as… <span class="el-tag el-plan-badge">')
        ->and(preg_match('/(?:title|aria-label)="[^"]*<span/', $html))->toBe(0);
    Fx::cleanupDisks();
});
