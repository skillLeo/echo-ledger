<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\Entitlements\Entitlements;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.6 P1.4: the plan's badge and the upgrade sheet where the plan leaves a feature out: a project's AI notes and Ask,
| and Profile → Connected apps (the Claude and ChatGPT connector).
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
    $badge = app(Entitlements::class)->lowestPlan('ai_notes')->badgeText();

    $html = (string) $this->get('/app/clients/'.$project->id)->assertOk()->getContent();
    expect($html)->toContain('data-ai-locked')->toContain($badge)->toContain('not in your plan')->toContain("mountAction('upgradeAiNotes')");

    $page = Livewire::test(ViewClient::class, ['record' => $project->id])->call('setTab', 'ask')->assertSee('data-ai-locked', false);
    $page->mountAction('upgradeAiNotes')->assertActionMounted('upgradeAiNotes');
});

it('shows no lock on a plan with AI notes', function () {
    badgePlanFor((int) $this->person->current_workspace_id, 'business');
    $project = Fx::client($this->person, 'Acme');

    expect((string) $this->get('/app/clients/'.$project->id)->assertOk()->getContent())->not->toContain('data-ai-locked');
});

it('shows the badge and the sheet on Connected apps when the plan leaves the connector out', function () {
    badgePlanFor((int) $this->person->current_workspace_id, 'free');
    $this->get('/app/profile')->assertOk()->assertSee('Asking Claude or ChatGPT about your recordings is not in your plan.');
    Livewire::test(EditProfile::class)->mountAction(TestAction::make('upgradeConnector')->schemaComponent('connector-actions', schema: 'content'))
        ->assertActionMounted(TestAction::make('upgradeConnector')->schemaComponent('connector-actions', schema: 'content'));

    badgePlanFor((int) $this->person->current_workspace_id, 'business');
    $this->get('/app/profile')->assertOk()->assertDontSee('Asking Claude or ChatGPT about your recordings is not in your plan.');
});
