<?php

declare(strict_types=1);

use App\Filament\Infolists\TranscriptPlayerEntry;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Jobs\MakeCallNotesJob;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Services\CredentialVault;
use App\Services\TextReplacements;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/*
 * The recording page of a client's call (P4, P5): call notes written on request, the "Always write X as Y" offer after
 * an edit, suggested fixes accepted or dismissed, and the Client and Labels chips.
 */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = $this->signInAsAdmin();
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    $this->client = Client::factory()->create(['user_id' => $this->admin->id, 'name' => 'Acme']);
});

function recordingCall(object $test, ?Client $client, array $meta = []): CaptureSession
{
    return $test->sessionWithTranscript($test->admin, [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'Send it to acme limited today'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 1500, 'end_ms' => 3000, 'text' => 'We need the login page by Friday'],
    ], ['client_id' => $client?->id, 'title' => 'Kick-off'], transcript: ['meta' => $meta]);
}

function recordingPage(object $test, CaptureSession $session): Testable
{
    return $test->panelComponent(ViewCaptureSession::class, ['record' => $session->id]);
}

describe('call notes', function () {
    it('writes the notes on the queue in the chosen language, like POST /api/sessions/{id}/notes', function () {
        Queue::fake();
        $session = recordingCall($this, $this->client);

        expect(recordingPage($this, $session)->html())->toContain('Call notes')->toContain('Write notes')->toContain('Roman Urdu');

        recordingPage($this, $session)->call('writeNotes', 'roman_urdu');

        $note = CallNote::query()->where('capture_session_id', $session->id)->sole();
        expect($note->status)->toBe('pending')->and($note->language)->toBe('roman_urdu')->and($note->client_id)->toBe($this->client->id);
        Queue::assertPushed(MakeCallNotesJob::class, fn (MakeCallNotesJob $job): bool => $job->sessionId === $session->id);
    });

    it('polls while the notes are written and shows them with their moments when ready', function () {
        $session = recordingCall($this, $this->client);
        $note = CallNote::query()->create(['workspace_id' => $session->workspace_id, 'client_id' => $this->client->id, 'capture_session_id' => $session->id, 'status' => 'pending', 'language' => 'en']);

        expect(recordingPage($this, $session)->html())->toContain('wire:poll.5s="refreshWork"')->toContain('Writing');

        $note->update(['status' => 'ready', 'sections' => ['my_actions' => [['text' => 'Send the login page', 'verify' => true, 'lines' => [1], 'at_ms' => 1500]]]]);
        $html = recordingPage($this, $session)->html();
        expect($html)->not->toContain('wire:poll')->toContain('My action items')->toContain('Send the login page')->toContain('verify')
            ->toContain('href="?t=1500"')->toContain('el-seek');
    });

    it('has no call notes for a recording without a client, and refuses to write them', function () {
        Queue::fake();
        $session = recordingCall($this, null);

        recordingPage($this, $session)->assertDontSee('Call notes')->call('writeNotes', 'en')->assertNotified('File this recording under a client first.');
        Queue::assertNothingPushed();
        expect(CallNote::query()->count())->toBe(0);
    });

    it('does not queue notes without an Anthropic key', function () {
        Queue::fake();
        app(CredentialVault::class)->forget('anthropic', 'api_key');

        recordingPage($this, recordingCall($this, $this->client))->call('writeNotes', 'en')->assertNotified('Add your Anthropic key in API Credentials to use client notes and Ask.');
        Queue::assertNothingPushed();
    });
});

describe('learning from edits', function () {
    it('offers "always write X as Y" for the client after an edit, and Yes adds the rule first', function () {
        $this->client->update(['replacements' => [['from' => 'acme ltd', 'to' => 'Acme Holdings']]]);
        $session = recordingCall($this, $this->client);

        $state = recordingPage($this, $session)->instance()->saveSegment(0, 'Send it to Acme Holdings today');

        expect($state['segments'][0]['text'])->toBe('Send it to Acme Holdings today')
            ->and($state['learn'])->toBe([['from' => 'acme limited', 'to' => 'Acme Holdings', 'client_name' => 'Acme']]);

        $result = recordingPage($this, $session)->instance()->learnRule('acme limited', 'Acme Holdings');
        expect($result)->toBe(['ok' => true]);
        expect(TextReplacements::rules($this->client->fresh()->replacements))->toBe([
            ['from' => 'acme limited', 'to' => 'Acme Holdings', 'match_case' => false, 'whole_word' => true],
            ['from' => 'acme ltd', 'to' => 'Acme Holdings', 'match_case' => false, 'whole_word' => true],
        ]);
    });

    it('offers nothing for a recording without a client, nor for a rule the client already has', function () {
        $loose = recordingCall($this, null);
        expect(recordingPage($this, $loose)->instance()->saveSegment(0, 'Send it to Acme Holdings today')['learn'])->toBe([])
            ->and(recordingPage($this, $loose)->instance()->learnRule('a', 'b'))->toBe(['error' => 'File this recording under a client first.']);

        $this->client->update(['replacements' => [['from' => 'acme limited', 'to' => 'Acme Holdings']]]);
        $known = recordingCall($this, $this->client);
        expect(recordingPage($this, $known)->instance()->saveSegment(0, 'Send it to Acme Holdings today')['learn'])->toBe([]);
    });

    it('asks in the player with Yes and No, and never as HTML', function () {
        $html = $this->get('/admin/sessions/'.recordingCall($this, $this->client)->id)->assertOk()->getContent();

        expect($html)->toContain('Always write “<strong x-text="r.from"></strong>” as “<strong x-text="r.to"></strong>” for <span x-text="r.client_name"></span>?')
            ->toContain('teach(ri, true)')->toContain('teach(ri, false)');
    });
});

describe('suggested fixes', function () {
    it('hands the player the open suggestions to mark on their words', function () {
        $session = recordingCall($this, $this->client, ['suggestions' => [
            ['line' => 1, 'from' => 'login page', 'to' => 'Login page', 'reason' => 'product name', 'status' => 'open'],
            ['line' => 0, 'from' => 'today', 'to' => 'Today', 'reason' => '', 'status' => 'dismissed'],
        ]]);

        expect(TranscriptPlayerEntry::payload($session->fresh())['suggestions'])->toBe([['id' => 0, 'line' => 1, 'from' => 'login page', 'to' => 'Login page', 'reason' => 'product name']]);
        expect($this->get('/admin/sessions/'.$session->id)->getContent())->toContain('decide(s.id, true)')->toContain('decide(s.id, false)')->toContain('data-sugg');
    });

    it('accepts a fix (the words change, as an edit) and dismisses another (nothing changes)', function () {
        $session = recordingCall($this, $this->client, ['suggestions' => [
            ['line' => 1, 'from' => 'login page', 'to' => 'Login page', 'reason' => 'product name', 'status' => 'open'],
            ['line' => 0, 'from' => 'acme limited', 'to' => 'Acme Ltd', 'reason' => '', 'status' => 'open'],
        ]]);

        $accepted = recordingPage($this, $session)->instance()->decideFix(0, true);
        expect($accepted['segments'][1]['text'])->toBe('We need the Login page by Friday')
            ->and(array_column($accepted['suggestions'], 'id'))->toBe([1])
            ->and($session->finalTranscript()->first()->edited_at)->not->toBeNull();

        $dismissed = recordingPage($this, $session)->instance()->decideFix(1, false);
        expect($dismissed['segments'][0]['text'])->toBe('Send it to acme limited today')->and($dismissed['suggestions'])->toBe([])
            ->and(array_column($session->finalTranscript()->first()->meta['suggestions'], 'status'))->toBe(['accepted', 'dismissed']);

        expect(recordingPage($this, $session)->instance()->decideFix(1, true))->toBe(['error' => 'That suggestion is no longer open.']);
    });
});

describe('organise chips', function () {
    it('files the recording under a client, sets its labels, pins it and keeps it', function () {
        $session = recordingCall($this, null);

        $html = recordingPage($this, $session)->html();
        expect($html)->toContain("mountAction('fileUnder')")->toContain("mountAction('labels')")->toContain('No client');

        recordingPage($this, $session)->callAction('fileUnder', data: ['client_id' => $this->client->id])->assertHasNoActionErrors();
        recordingPage($this, $session)->callAction('labels', data: ['labels' => ['Follow up', 'Invoice']])->assertHasNoActionErrors();
        recordingPage($this, $session)->call('togglePin')->call('toggleKeep');

        $session->refresh();
        expect($session->client_id)->toBe($this->client->id)->and($session->labelList())->toBe(['Follow up', 'Invoice'])
            ->and($session->pinned)->toBeTrue()->and($session->keep_forever)->toBeTrue();
        expect(recordingPage($this, $session)->html())->toContain('Acme')->toContain('Follow up');
    });

    it('offers only the workspace\'s own clients', function () {
        Client::factory()->create(['user_id' => $this->makeAdmin()->id, 'name' => 'Not mine']);

        $modal = $this->modalHtml(recordingPage($this, recordingCall($this, null))->mountAction('fileUnder'));

        expect($modal)->toContain('Acme')->not->toContain('Not mine');
    });
});

it('shows the Show as switch in the phone\'s order, a find box and the docked player', function () {
    $session = recordingCall($this, $this->client);
    $this->readyRecording($session);

    $html = $this->get('/admin/sessions/'.$session->id)->assertOk()->getContent();

    expect($html)->toContain('aria-label="Show as"')
        ->and(strpos($html, '>Roman Urdu</button>'))->toBeLessThan(strpos($html, '>اردو</button>'))
        ->and(strpos($html, '>اردو</button>'))->toBeLessThan(strpos($html, '>English</button>'))
        ->and(strpos($html, '>English</button>'))->toBeLessThan(strpos($html, '>Original</button>'));
    expect($html)->toContain('aria-label="Find in the transcript"')->toContain('class="el-dock"')->toContain('role="slider"')
        ->toContain('Copy for AI')->toContain('Copy text');
});
