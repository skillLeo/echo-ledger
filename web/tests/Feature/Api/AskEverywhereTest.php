<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\WorkspaceRole;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ClientAi\ClientAi;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Str;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

require_once __DIR__.'/Support/client_workspace_helpers.php';

/*
 * Ask beyond one project (2.0.0-o, docs/API.md "Ask about a recording and across recordings"): POST /api/sessions/{id}/ask
 * about one recording of the person's, POST /api/ask across all of their own recordings in the workspace, with the
 * project Ask's request, answer, citations, limiter, plan feature and AI budget. Claude is a fake that says what it was
 * given, so these tests see exactly which words would have left the server.
 */

beforeEach(function () {
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    $this->ai = fakeClientAi();
    $this->me = Fx::user();
    $this->device = Fx::device($this->me);
    $this->token = Fx::token($this->device);
});

/**
 * A finished recording of [$owner] with these lines (four lines: the fake cites the fourth).
 *
 * @param  list<string>  $lines
 * @param  array<string, mixed>  $attributes
 */
function askRecording(User $owner, ?Device $device, string $title, array $lines, array $attributes = [], TranscriptStatus $status = TranscriptStatus::Completed): CaptureSession
{
    $session = Fx::session($owner, $device, ['title' => $title, 'status' => 'completed'] + $attributes);
    Transcript::query()->create([
        'capture_session_id' => $session->id, 'kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => $status, 'language' => 'en',
        'text' => implode(' ', $lines),
        'segments' => array_map(fn (string $text, int $i): array => ['channel' => 1 + $i % 2, 'speaker' => $i % 2 === 0 ? 'Me' : 'Client',
            'start_ms' => [0, 1500, 61_000, 125_000][$i] ?? $i * 60_000, 'end_ms' => ([0, 1500, 61_000, 125_000][$i] ?? $i * 60_000) + 1000, 'text' => $text], $lines, array_keys($lines)),
        'completed_at' => $status === TranscriptStatus::Completed ? now() : null,
    ]);

    return $session;
}

/** @return list<string> every passage line Claude was given in its last call */
function askedLines(object $ai): array
{
    $asked = end($ai->calls)[1] ?? ['passages' => []];

    return array_merge([], ...array_map(fn (array $p): array => $p['blocks'], $asked['passages']));
}

function askPlan(User $user, array $attributes): void
{
    $plan = Plan::query()->create(['key' => 'test-'.Str::lower(Str::random(6)), 'name' => 'Test', ...$attributes]);
    Workspace::query()->findOrFail($user->current_workspace_id)->forceFill(['plan_id' => $plan->id])->save();
}

function askSpent(User $user, float $usd): void
{
    UsageLedgerEntry::query()->withoutGlobalScopes()->create(['user_id' => $user->id, 'workspace_id' => $user->current_workspace_id, 'report_id' => 'spent-'.Str::random(6),
        'source' => 'server', 'use_case' => 'client_ask', 'provider' => 'claude', 'duration_ms' => 0, 'channels' => 1, 'billed_ms' => 0,
        'unit_price_usd' => 0, 'cost_usd' => $usd, 'billable' => true, 'occurred_at' => now()]);
}

/** A Claude that is down. */
function failingClientAi(): void
{
    $fake = fakeClientAi();
    app()->instance(ClientAi::class, new class($fake) implements ClientAi
    {
        public function __construct(private readonly object $inner) {}

        public function callNotes(string $clientName, array $brief, array $lines, string $language): array
        {
            return $this->inner->callNotes($clientName, $brief, $lines, $language);
        }

        public function brief(string $clientName, array $documents, array $callNotes): array
        {
            return $this->inner->brief($clientName, $documents, $callNotes);
        }

        public function pdfText(string $pdf, string $name): array
        {
            return $this->inner->pdfText($pdf, $name);
        }

        public function ask(string $clientName, array $brief, array $memory, array $passages, string $question, string $language): array
        {
            throw new RuntimeException('upstream 529 with sk-ant-secret-in-the-message');
        }

        public function suggestFixes(string $clientName, array $terms, array $lines): array
        {
            return $this->inner->suggestFixes($clientName, $terms, $lines);
        }
    });
}

describe('POST /api/sessions/{id}/ask', function () {
    it('answers from that recording\'s transcript and notes only, cites it, and bills the recording', function () {
        $project = Fx::client($this->me, 'Acme');
        $call = askRecording($this->me, $this->device, 'Kick-off', ['Hi Ali', 'I need a login page', 'Also add dark mode', 'We launch on November 15'], ['client_id' => $project->id]);
        askRecording($this->me, $this->device, 'Other call', ['We launch on December 1', 'Launch is late']);
        CallNote::query()->withoutGlobalScopes()->create(['workspace_id' => $call->workspace_id, 'client_id' => $project->id, 'capture_session_id' => $call->id, 'status' => 'ready',
            'sections' => ['decisions' => [['text' => 'Launch on November 15', 'verify' => false, 'lines' => [3], 'at_ms' => 125_000]]]]);

        $this->api($this->token)->postJson("/api/sessions/{$call->id}/ask", ['question' => 'When do we launch?', 'language' => 'roman_urdu'])->assertOk()
            ->assertExactJson(['data' => ['answer' => 'Launch is on November 15.', 'citations' => [
                ['type' => 'call', 'session_id' => $call->id, 'title' => 'Kick-off · '.$call->started_at->copy()->setTimezone(app(SettingsRepository::class)->displayTimezone())->format('M j'),
                    'at_ms' => 125_000, 'quote' => 'We launch on November 15'],
            ]]]);

        $asked = $this->ai->calls[0][1];
        expect($asked['language'])->toBe('roman_urdu')
            ->and(askedLines($this->ai))->toBe(['[0:00] You: Hi Ali', '[0:01] Client: I need a login page', '[1:01] You: Also add dark mode', '[2:05] Client: We launch on November 15'])
            ->and($asked['memory'])->toHaveCount(1)
            ->and($asked['memory'][0])->toContain('Launch on November 15');
        $entry = UsageLedgerEntry::query()->withoutGlobalScopes()->sole();
        expect($entry->use_case)->toBe('client_ask')
            ->and($entry->provider)->toBe('claude')
            ->and($entry->capture_session_id)->toBe($call->id)
            ->and($entry->client_id)->toBe($project->id)
            ->and($entry->user_id)->toBe($this->me->id)
            ->and($entry->workspace_id)->toBe($call->workspace_id)
            ->and((float) $entry->cost_usd)->toBeGreaterThan(0.0);
    });

    it('answers 404 session_not_found for another workspace\'s, a teammate\'s, a binned or an unknown recording, and asks nothing', function () {
        $stranger = Fx::user();
        $theirs = askRecording($stranger, Fx::device($stranger), 'STRANGER-SECRET', ['STRANGERSECRET launch date', 'b', 'c', 'd']);
        $teammate = Fx::user();
        $team = Workspace::query()->findOrFail($this->me->current_workspace_id);
        app(Workspaces::class)->addMember($team, $teammate);
        app(Workspaces::class)->switchTo($teammate, $team);
        $teammates = askRecording($teammate->fresh(), null, 'TEAMMATE-SECRET', ['TEAMMATESECRET launch date', 'b', 'c', 'd'], ['workspace_id' => $team->id]);
        $binned = askRecording($this->me, $this->device, 'Binned', ['launch date', 'b', 'c', 'd']);
        $binned->delete();

        foreach ([$theirs->id, $teammates->id, $binned->id, Fx::MISSING_ID] as $id) {
            $response = $this->api($this->token)->postJson("/api/sessions/{$id}/ask", ['question' => 'When is the launch date?']);
            $response->assertNotFound()->assertJsonPath('code', 'session_not_found');
            expect($response->getContent())->not->toContain('SECRET');
        }
        expect($this->ai->calls)->toBe([])
            ->and(UsageLedgerEntry::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('answers 409 no_transcript for a recording without a finished transcript', function () {
        $none = Fx::session($this->me, $this->device, ['title' => 'Not transcribed']);
        $making = askRecording($this->me, $this->device, 'Making', ['a', 'b', 'c', 'd'], [], TranscriptStatus::Processing);

        foreach ([$none, $making] as $session) {
            $this->api($this->token)->postJson("/api/sessions/{$session->id}/ask", ['question' => 'When do we launch?'])
                ->assertStatus(409)->assertJsonPath('code', 'no_transcript');
        }
        expect($this->ai->calls)->toBe([]);
    });

    it('answers 422 for a question that is missing, too short or too long, or an unknown language', function () {
        $call = askRecording($this->me, $this->device, 'Kick-off', ['a', 'b', 'c', 'd']);

        foreach ([[], ['question' => 'ab'], ['question' => str_repeat('a', 501)], ['question' => 'When?', 'language' => 'fr'], ['question' => ['When?']]] as $body) {
            $this->api($this->token)->postJson("/api/sessions/{$call->id}/ask", $body)->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
        }
        expect($this->ai->calls)->toBe([]);
    });

    it('keeps the plan feature, the AI key and the month\'s AI budget', function () {
        $call = askRecording($this->me, $this->device, 'Kick-off', ['a', 'b', 'c', 'We launch on November 15']);
        $ask = fn () => $this->api($this->token)->postJson("/api/sessions/{$call->id}/ask", ['question' => 'When do we launch?']);

        app(CredentialVault::class)->put('anthropic', 'api_key', '');
        $ask()->assertStatus(409)->assertJsonPath('code', 'ai_unavailable');
        app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');

        app(SettingsRepository::class)->set('client_ai.monthly_usd', 0.01);
        askSpent($this->me, 0.02);
        $ask()->assertForbidden()->assertJsonPath('code', 'ai_budget_reached');
        UsageLedgerEntry::query()->withoutGlobalScopes()->delete();
        app(SettingsRepository::class)->set('client_ai.monthly_usd', 5);

        askPlan($this->me, ['features' => ['ai_notes' => false]]);
        $ask()->assertForbidden()->assertJsonPath('code', 'feature_not_in_plan')->assertJsonPath('feature', 'ai_notes');
        expect($this->ai->calls)->toBe([]);
    });

    it('answers 502 provider_error when the AI fails, with none of its words, and bills nothing', function () {
        failingClientAi();
        $call = askRecording($this->me, $this->device, 'Kick-off', ['a', 'b', 'c', 'We launch on November 15']);

        $failed = $this->api($this->token)->postJson("/api/sessions/{$call->id}/ask", ['question' => 'When do we launch?'])->assertStatus(502)->assertJsonPath('code', 'provider_error');
        expect($failed->getContent())->not->toContain('sk-ant')->not->toContain('529')
            ->and(UsageLedgerEntry::query()->withoutGlobalScopes()->count())->toBe(0);
    });
});

describe('POST /api/ask', function () {
    it('answers across the person\'s own recordings in this workspace, projects and Inbox alike, and bills no project', function () {
        $acme = Fx::client($this->me, 'Acme');
        $globex = Fx::client($this->me, 'Globex');
        $one = askRecording($this->me, $this->device, 'Acme call', ['Hello', 'The invoice is due', 'Fine', 'Acme pays the invoice on Friday'], ['client_id' => $acme->id, 'started_at' => now()->subDays(2)]);
        $two = askRecording($this->me, $this->device, 'Globex call', ['Hi', 'Globex invoice next month'], ['client_id' => $globex->id, 'started_at' => now()->subDay()]);
        $three = askRecording($this->me, $this->device, 'Voice note', ['Remember the invoice for the van'], ['started_at' => now()]);

        $this->api($this->token)->postJson('/api/ask', ['question' => 'When is the invoice paid?'])->assertOk()
            ->assertJsonPath('data.answer', 'Launch is on November 15.')
            ->assertJsonPath('data.citations.0.type', 'call')
            ->assertJsonStructure(['data' => ['answer', 'citations' => [['type', 'session_id', 'title', 'at_ms', 'quote']]]]);

        $titles = array_map(fn (array $p): string => $p['title'], $this->ai->calls[0][1]['passages']);
        expect(collect($titles)->map(fn (string $t): string => explode(' · ', $t)[0])->unique()->sort()->values()->all())->toBe(['Acme call', 'Globex call', 'Voice note'])
            ->and($this->ai->calls[0][1]['memory'])->toBe([])
            ->and($this->ai->calls[0][1]['language'])->toBe('en');
        $entry = UsageLedgerEntry::query()->withoutGlobalScopes()->sole();
        expect($entry->use_case)->toBe('client_ask')->and($entry->client_id)->toBeNull()->and($entry->capture_session_id)->toBeNull()
            ->and($entry->user_id)->toBe($this->me->id)->and($entry->workspace_id)->toBe($this->me->current_workspace_id);
        expect([$one->id, $two->id, $three->id])->toContain($this->api($this->token)->postJson('/api/ask', ['question' => 'When is the invoice paid?'])->json('data.citations.0.session_id'));
    });

    it('never reads another workspace\'s, a teammate\'s, the person\'s other workspace\'s or a binned recording', function () {
        askRecording($this->me, $this->device, 'Mine', ['MINE the launch date is Monday', 'b', 'c', 'd']);
        $stranger = Fx::user();
        askRecording($stranger, Fx::device($stranger), 'STRANGER-SECRET', ['STRANGERSECRET the launch date is Tuesday', 'b', 'c', 'd']);
        $teammate = Fx::user();
        $team = Workspace::query()->findOrFail($this->me->current_workspace_id);
        app(Workspaces::class)->addMember($team, $teammate);
        app(Workspaces::class)->switchTo($teammate, $team);
        askRecording($teammate->fresh(), null, 'TEAMMATE-SECRET', ['TEAMMATESECRET the launch date is Wednesday', 'b', 'c', 'd'], ['workspace_id' => $team->id]);
        $other = app(Workspaces::class)->createFor($this->me, 'My other workspace');
        askRecording($this->me, null, 'ELSEWHERE-SECRET', ['ELSEWHERESECRET the launch date is Thursday', 'b', 'c', 'd'], ['workspace_id' => $other->id]);
        askRecording($this->me, $this->device, 'BINNED-SECRET', ['BINNEDSECRET the launch date is Friday', 'b', 'c', 'd'])->delete();

        $response = $this->api($this->token)->postJson('/api/ask', ['question' => 'What is the launch date?'])->assertOk();

        $lines = implode("\n", askedLines($this->ai));
        expect($lines)->toContain('MINE the launch date is Monday')
            ->not->toContain('STRANGERSECRET')->not->toContain('TEAMMATESECRET')->not->toContain('ELSEWHERESECRET')->not->toContain('BINNEDSECRET')
            ->and($response->getContent())->not->toContain('SECRET');
        // The teammate's own Ask reads only theirs, never mine.
        $this->api(Fx::token(Fx::device($teammate->fresh(), ['workspace_id' => $team->id])))->postJson('/api/ask', ['question' => 'What is the launch date?'])->assertOk();
        expect(implode("\n", askedLines($this->ai)))->toContain('TEAMMATESECRET')->not->toContain('MINE');
    });

    it('answers 409 no_transcript when none of the person\'s recordings has a finished transcript', function () {
        Fx::session($this->me, $this->device, ['title' => 'Not transcribed']);
        askRecording($this->me, $this->device, 'Making', ['a', 'b', 'c', 'd'], [], TranscriptStatus::Processing);
        $teammate = Fx::user();
        $team = Workspace::query()->findOrFail($this->me->current_workspace_id);
        app(Workspaces::class)->addMember($team, $teammate);
        askRecording($teammate, null, 'Teammate call', ['a', 'b', 'c', 'd'], ['workspace_id' => $team->id]);

        $this->api($this->token)->postJson('/api/ask', ['question' => 'When do we launch?'])->assertStatus(409)->assertJsonPath('code', 'no_transcript');
        expect($this->ai->calls)->toBe([]);
    });

    it('answers 422 for a question that is missing, too short or too long, or an unknown language', function () {
        askRecording($this->me, $this->device, 'Kick-off', ['a', 'b', 'c', 'd']);

        foreach ([[], ['question' => 'ab'], ['question' => str_repeat('a', 501)], ['question' => 'When?', 'language' => 'ur']] as $body) {
            $this->api($this->token)->postJson('/api/ask', $body)->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
        }
        expect($this->ai->calls)->toBe([]);
    });

    it('keeps the plan feature, the AI key and the month\'s AI budget', function () {
        askRecording($this->me, $this->device, 'Kick-off', ['a', 'b', 'c', 'We launch on November 15']);
        $ask = fn () => $this->api($this->token)->postJson('/api/ask', ['question' => 'When do we launch?']);

        app(CredentialVault::class)->put('anthropic', 'api_key', '');
        $ask()->assertStatus(409)->assertJsonPath('code', 'ai_unavailable');
        app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');

        app(SettingsRepository::class)->set('client_ai.monthly_usd', 0.01);
        askSpent($this->me, 0.02);
        $ask()->assertForbidden()->assertJsonPath('code', 'ai_budget_reached');
        UsageLedgerEntry::query()->withoutGlobalScopes()->delete();
        app(SettingsRepository::class)->set('client_ai.monthly_usd', 5);

        askPlan($this->me, ['features' => ['ai_notes' => false]]);
        $ask()->assertForbidden()->assertJsonPath('code', 'feature_not_in_plan')->assertJsonPath('feature', 'ai_notes');
        expect($this->ai->calls)->toBe([]);
    });

    it('answers 502 provider_error when the AI fails, with none of its words, and bills nothing', function () {
        failingClientAi();
        askRecording($this->me, $this->device, 'Kick-off', ['a', 'b', 'c', 'We launch on November 15']);

        $failed = $this->api($this->token)->postJson('/api/ask', ['question' => 'When do we launch?'])->assertStatus(502)->assertJsonPath('code', 'provider_error');
        expect($failed->getContent())->not->toContain('sk-ant')->not->toContain('529')
            ->and(UsageLedgerEntry::query()->withoutGlobalScopes()->count())->toBe(0);
    });
});

it('shares the project Ask\'s limit: 20 questions a minute per person, then 429 with Retry-After', function () {
    $project = Fx::client($this->me, 'Acme');
    $call = askRecording($this->me, $this->device, 'Kick-off', ['a', 'b', 'c', 'We launch on November 15'], ['client_id' => $project->id]);
    $api = $this->api($this->token);

    foreach (range(1, 20) as $n) {
        $uri = ['/api/ask', "/api/sessions/{$call->id}/ask", "/api/clients/{$project->id}/ask"][$n % 3];
        $api->postJson($uri, ['question' => 'When do we launch?'])->assertOk();
    }
    // Another device of the same person counts too.
    foreach (['/api/ask', "/api/sessions/{$call->id}/ask"] as $uri) {
        $limited = $this->api(Fx::token(Fx::device($this->me)))->postJson($uri, ['question' => 'When do we launch?']);
        $limited->assertStatus(429)->assertHeader('Retry-After');
    }
    expect($this->ai->calls)->toHaveCount(20);
});

it('lets a viewer\'s device ask nothing', function () {
    $owner = Fx::user();
    $team = Workspace::query()->findOrFail($owner->current_workspace_id);
    $viewer = Fx::user();
    app(Workspaces::class)->addMember($team, $viewer, WorkspaceRole::Viewer);
    app(Workspaces::class)->switchTo($viewer, $team);
    $device = Fx::device($viewer->fresh(), ['workspace_id' => $team->id]);
    $call = askRecording($viewer->fresh(), $device, 'Old call', ['a', 'b', 'c', 'd'], ['workspace_id' => $team->id]);

    foreach (['/api/ask', "/api/sessions/{$call->id}/ask"] as $uri) {
        $this->api(Fx::token($device))->postJson($uri, ['question' => 'When do we launch?'])->assertForbidden()->assertJsonPath('code', 'not_allowed');
    }
    expect($this->ai->calls)->toBe([]);
});
