<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\FilingRule;
use App\Models\User;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "Filing rules (since v1.5)": title, app and contact rules, applied in that order after the contact match
 * when a recording ends without a project, never moving one that is in a project.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
    $this->owner = $this->device->user_id;
    $this->acme = Client::factory()->create(['user_id' => $this->owner, 'name' => 'Acme']);
    $this->beta = Client::factory()->create(['user_id' => $this->owner, 'name' => 'Beta']);
});

function addRule(object $test, Client $project, string $kind, string $value): array
{
    return $test->postJson("/api/clients/{$project->id}/rules", ['kind' => $kind, 'value' => $value])->assertCreated()->json('data');
}

/** A recording kept on this device (one-shot create): what the library item says about its project. */
function keep(object $test, array $body = []): array
{
    $response = $test->createSession(Fixtures::audio(1000 + random_int(1, 5000)), $body)->assertCreated();

    return ['client' => $response->json('session.client.id'), 'filed_by' => $response->json('session.filed_by'), 'id' => $response->json('session.id')];
}

describe('the rules of a project', function () {
    it('adds, lists and deletes rules', function () {
        $title = addRule($this, $this->acme, 'title', ' Acme  weekly ');
        expect($title)->toHaveKeys(['id', 'kind', 'value', 'created_at'])->and($title['value'])->toBe('Acme weekly')->and($title['id'])->toBeInt();
        $app = addRule($this, $this->acme, 'app', 'zoom');

        $list = $this->getJson("/api/clients/{$this->acme->id}/rules")->assertOk()->json('data');
        expect(array_column($list, 'id'))->toBe([$title['id'], $app['id']]);

        $this->deleteJson("/api/clients/{$this->acme->id}/rules/{$title['id']}")->assertNoContent();
        $this->deleteJson("/api/clients/{$this->acme->id}/rules/{$title['id']}")->assertNotFound()->assertJsonPath('code', 'rule_not_found');
        // Another project's rule through this project is unknown too.
        $this->deleteJson("/api/clients/{$this->beta->id}/rules/{$app['id']}")->assertNotFound()->assertJsonPath('code', 'rule_not_found');
        $this->deleteJson("/api/clients/{$this->acme->id}/rules/abc")->assertNotFound()->assertJsonPath('code', 'rule_not_found');
        expect(FilingRule::query()->pluck('id')->all())->toBe([$app['id']]);
    });

    it('keeps one project per kind and value, and says which project has it', function () {
        $first = addRule($this, $this->acme, 'title', 'Weekly');

        $again = $this->postJson("/api/clients/{$this->acme->id}/rules", ['kind' => 'title', 'value' => 'WEEKLY'])->assertOk();
        expect($again->json('data.id'))->toBe($first['id']);
        $this->postJson("/api/clients/{$this->beta->id}/rules", ['kind' => 'title', 'value' => 'weekly'])->assertStatus(409)
            ->assertJsonPath('code', 'rule_exists')->assertJsonPath('client_id', $this->acme->id)->assertJsonPath('client_name', 'Acme');
        // The same text as an app rule is another rule.
        $this->postJson("/api/clients/{$this->beta->id}/rules", ['kind' => 'app', 'value' => 'zoom'])->assertCreated();
    });

    it('validates kinds and values', function () {
        $this->postJson("/api/clients/{$this->acme->id}/rules", ['kind' => 'colour', 'value' => 'red'])->assertStatus(422);
        $this->postJson("/api/clients/{$this->acme->id}/rules", ['kind' => 'app', 'value' => 'myspace'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->postJson("/api/clients/{$this->acme->id}/rules", ['kind' => 'contact', 'value' => 'Omar'])->assertStatus(422);
        $this->postJson("/api/clients/{$this->acme->id}/rules", ['kind' => 'title', 'value' => str_repeat('x', 121)])->assertStatus(422);
        $this->postJson("/api/clients/{$this->acme->id}/rules", ['kind' => 'title', 'value' => "\u{200B} "])->assertStatus(422);
        expect(FilingRule::query()->count())->toBe(0);
    });

    it('adds the contact a contact rule names, and removes it with the rule', function () {
        $phone = addRule($this, $this->acme, 'contact', '+92 300 1234567');
        $email = addRule($this, $this->acme, 'contact', 'Omar@Example.com');
        expect($email['value'])->toBe('omar@example.com')
            ->and(ClientContact::query()->where('client_id', $this->acme->id)->pluck('phone_digits')->filter()->values()->all())->toBe(['3001234567'])
            ->and(ClientContact::query()->where('client_id', $this->acme->id)->pluck('email')->filter()->values()->all())->toBe(['omar@example.com']);

        // The same number written another way is the same contact, also on another project.
        $this->postJson("/api/clients/{$this->beta->id}/rules", ['kind' => 'contact', 'value' => '0300-1234567'])->assertStatus(409)->assertJsonPath('code', 'rule_exists');
        $this->deleteJson("/api/clients/{$this->acme->id}/rules/{$phone['id']}")->assertNoContent();
        expect(ClientContact::query()->where('client_id', $this->acme->id)->whereNotNull('phone_digits')->exists())->toBeFalse();
    });

    it('refuses a contact rule for a number another project already has as a contact', function () {
        ClientContact::query()->create(['client_id' => $this->beta->id, 'name' => 'Sara', 'phone' => '+44 7700 900123']);

        $this->postJson("/api/clients/{$this->acme->id}/rules", ['kind' => 'contact', 'value' => '07700900123'])->assertStatus(409)
            ->assertJsonPath('client_name', 'Beta');
        // Its own contact is used as it is.
        ClientContact::query()->create(['client_id' => $this->acme->id, 'name' => 'Ali', 'phone' => '+92 321 7654321']);
        addRule($this, $this->acme, 'contact', '03217654321');
        expect(ClientContact::query()->where('client_id', $this->acme->id)->count())->toBe(1);
    });

    it('answers 404 for another workspace\'s project', function () {
        $foreign = Client::factory()->create(['user_id' => User::factory()]);
        $rule = FilingRule::query()->create(['workspace_id' => $foreign->workspace_id, 'client_id' => $foreign->id, 'kind' => 'app', 'value' => 'zoom', 'match_value' => 'zoom']);

        $this->getJson("/api/clients/{$foreign->id}/rules")->assertNotFound()->assertJsonPath('code', 'client_not_found');
        $this->postJson("/api/clients/{$foreign->id}/rules", ['kind' => 'app', 'value' => 'zoom'])->assertNotFound();
        $this->deleteJson("/api/clients/{$foreign->id}/rules/{$rule->id}")->assertNotFound();
        // A rule of another workspace does not block the same rule here.
        addRule($this, $this->acme, 'app', 'zoom');
        expect(unscoped(fn () => FilingRule::query()->count()))->toBe(2);
    });
});

describe('filing', function () {
    it('files by a title rule, also by a meeting window title', function () {
        addRule($this, $this->acme, 'title', 'acme');

        expect(keep($this, ['title' => 'Weekly ACME sync']))->toMatchArray(['client' => $this->acme->id, 'filed_by' => 'rule'])
            ->and(keep($this, ['source_app' => 'microsoft_teams', 'caller' => ['name' => 'Acme | Planning', 'source' => 'window_title']]))
            ->toMatchArray(['client' => $this->acme->id, 'filed_by' => 'rule'])
            ->and(keep($this, ['title' => 'Something else']))->toMatchArray(['client' => null, 'filed_by' => null]);
    });

    it('files by an app rule', function () {
        addRule($this, $this->beta, 'app', 'zoom');

        expect(keep($this, ['source_app' => 'zoom']))->toMatchArray(['client' => $this->beta->id, 'filed_by' => 'rule'])
            ->and(keep($this, ['source_app' => 'google_meet']))->toMatchArray(['client' => null]);
    });

    it('files by a contact rule, a number or an email', function () {
        addRule($this, $this->acme, 'contact', '+92 300 1234567');
        addRule($this, $this->beta, 'contact', 'guest@example.com');

        expect(keep($this, ['source_app' => 'phone_call', 'caller' => ['number' => '03001234567', 'source' => 'phone']]))
            ->toMatchArray(['client' => $this->acme->id, 'filed_by' => 'contact'])
            ->and(keep($this, ['source_app' => 'google_meet', 'caller' => ['name' => 'Guest@example.com', 'source' => 'calendar']]))
            ->toMatchArray(['client' => $this->beta->id, 'filed_by' => 'contact']);
    });

    it('takes the contact first, then the title, then the app', function () {
        $gamma = Client::factory()->create(['user_id' => $this->owner, 'name' => 'Gamma']);
        addRule($this, $this->acme, 'contact', '+92 300 1234567');
        addRule($this, $this->beta, 'title', 'weekly');
        addRule($this, $gamma, 'app', 'whatsapp');

        expect(keep($this, ['title' => 'Weekly call', 'caller' => ['number' => '03001234567', 'source' => 'whatsapp_notification']]))
            ->toMatchArray(['client' => $this->acme->id, 'filed_by' => 'contact'])
            ->and(keep($this, ['title' => 'Weekly call']))->toMatchArray(['client' => $this->beta->id, 'filed_by' => 'rule'])
            ->and(keep($this, ['title' => 'Daily call']))->toMatchArray(['client' => $gamma->id, 'filed_by' => 'rule']);
    });

    it('uses the oldest matching title rule first', function () {
        addRule($this, $this->beta, 'title', 'weekly');
        addRule($this, $this->acme, 'title', 'acme');

        expect(keep($this, ['title' => 'Acme weekly']))->toMatchArray(['client' => $this->beta->id]);
    });

    it('never moves a recording already in a project', function () {
        addRule($this, $this->beta, 'title', 'weekly');
        addRule($this, $this->beta, 'app', 'whatsapp');

        expect(keep($this, ['title' => 'Weekly', 'client_id' => $this->acme->id]))->toMatchArray(['client' => $this->acme->id, 'filed_by' => 'user']);
        // A recording moved later keeps its place too: rules only run when a recording ends.
        $kept = keep($this, ['title' => 'Other']);
        $this->patchJson("/api/sessions/{$kept['id']}", ['client_id' => $this->acme->id, 'title' => 'Weekly'])->assertOk()->assertJsonPath('data.client.id', $this->acme->id);
    });

    it('files an open session when it is finished', function () {
        addRule($this, $this->acme, 'app', 'google_meet');
        $id = $this->openSession(['source_app' => 'google_meet']);
        expect(CaptureSession::query()->find($id)->client_id)->toBeNull();

        $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody(Fixtures::audio(1500)))->assertOk()
            ->assertJsonPath('session.client.id', $this->acme->id)->assertJsonPath('session.filed_by', 'rule');
    });
});
