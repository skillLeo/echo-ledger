<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Pages\Search;
use App\Filament\Pages\Settings;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Resources\CaptureSessions\RelationManagers\ShareLinksRelationManager;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Support\CommandPalette;
use App\Jobs\TranscribeFinalJob;
use App\Models\Client;
use App\Models\ShareLink;
use App\Models\Transcript;
use App\Models\TranscriptRevision;
use App\Services\SettingsRepository;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/*
 * Console transcript tools (v1.1): inline editing, speaker names, find and replace, history and restore, exports,
 * share links, a second engine with comparison, the Search page, the command palette, and vocabulary settings.
 */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = $this->signInAsAdmin(['name' => 'Sam Admin']);
    $this->client = Client::factory()->create(['user_id' => $this->admin->id, 'name' => 'Acme']);
    $this->session = $this->sessionWithTranscript($this->admin, [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 2_000, 'text' => 'Hello acme limited.'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 3_000, 'end_ms' => 6_000, 'text' => 'The invoice is due Friday.'],
    ], ['client_id' => $this->client->id, 'title' => 'Acme kickoff', 'status' => SessionStatus::Completed]);
});

function toolsPage(object $test)
{
    return $test->panelComponent(ViewCaptureSession::class, ['record' => $test->session->id]);
}

describe('editing', function () {
    it('saves an edited line from the player and names the console user as the author', function () {
        $state = toolsPage($this)->instance()->saveSegment(1, 'The invoice is due on Friday.');

        expect($state['segments'][1]['text'])->toBe('The invoice is due on Friday.')
            ->and($state['edited'])->toBeTrue()
            ->and($this->session->finalTranscript->fresh()->segments[1]['text'])->toBe('The invoice is due on Friday.')
            ->and(TranscriptRevision::query()->where('source', 'edit')->sole()->author)->toBe('Sam Admin');
    });

    it('refuses an empty line and an unfinished transcript', function () {
        expect(toolsPage($this)->instance()->saveSegment(0, '  ')['error'])->toContain('cannot be empty');
        $this->session->finalTranscript->update(['status' => TranscriptStatus::Processing]);
        expect(toolsPage($this)->instance()->saveSegment(0, 'x')['error'])->toBe('Only a finished transcript can be edited.');
    });

    it('renames speakers, remembers them for the client and refreshes the player', function () {
        toolsPage($this)->callAction('speakers', data: ['names' => [0 => 'Sam', 1 => 'Jane'], 'remember' => true])
            ->assertHasNoActionErrors()
            ->assertDispatched('el-transcript-updated');

        expect($this->session->fresh()->speaker_names)->toBe(['Me' => 'Sam', 'Client' => 'Jane'])
            ->and($this->client->fresh()->speaker_names)->toBe(['Me' => 'Sam', 'Client' => 'Jane']);
    });

    it('finds and replaces, then restores the original from the history', function () {
        toolsPage($this)->callAction('replace', data: ['find' => 'acme limited', 'replace' => 'Acme Ltd', 'whole_word' => true])
            ->assertNotified('Replaced 1 time');
        expect($this->session->finalTranscript->fresh()->segments[0]['text'])->toBe('Hello Acme Ltd.');

        $history = $this->modalHtml(toolsPage($this)->mountAction('history'));
        expect($history)->toContain('Find and replace or vocabulary')->toContain('Sam Admin')->toContain('Original from the engine');
        toolsPage($this)->callAction('history')->assertNotified('The original transcript is back');
        expect($this->session->finalTranscript->fresh()->segments[0]['text'])->toBe('Hello acme limited.');
    });

    it('says when nothing matched', function () {
        toolsPage($this)->callAction('replace', data: ['find' => 'zebra', 'replace' => 'x'])->assertNotified('Nothing matched');
    });
});

describe('exports', function () {
    it('downloads every format and opens the print page inline', function (string $format, string $type) {
        $id = $this->session->finalTranscript->id;
        $response = $this->get("/admin-export/transcripts/{$id}/{$format}")->assertOk();

        expect($response->headers->get('Content-Type'))->toContain($type)
            ->and($response->headers->get('Content-Disposition'))->toContain($format === 'html' ? 'inline' : 'attachment');
    })->with([
        'word' => ['docx', 'wordprocessingml'], 'pdf page' => ['html', 'text/html'], 'text' => ['txt', 'text/plain'],
        'markdown' => ['md', 'text/markdown'], 'srt' => ['srt', 'subrip'], 'vtt' => ['vtt', 'text/vtt'], 'json' => ['json', 'application/json'],
    ]);

    it('hides other owners\' transcripts and unknown formats', function () {
        $other = $this->sessionWithTranscript($this->makeAdmin(), [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1, 'text' => 'x']]);
        $this->get('/admin-export/transcripts/'.$other->finalTranscript->id.'/txt')->assertNotFound();
        $this->get('/admin-export/transcripts/'.$this->session->finalTranscript->id.'/pdf')->assertNotFound();
    });
});

describe('share links', function () {
    it('makes a link and shows it once in a lasting notification', function () {
        toolsPage($this)->callAction('share', data: ['expires_in' => 86_400, 'password' => 'secret12'])->assertHasNoActionErrors();

        $link = ShareLink::query()->sole();
        expect($link->password_hash)->not->toBeNull()->and($link->created_by)->toBe('Sam Admin')
            ->and($link->expires_at->diffInHours(now(), true))->toBeGreaterThan(23);
    });

    it('lists the links with their views and revokes one', function () {
        toolsPage($this)->callAction('share', data: ['expires_in' => 3_600]);
        $link = ShareLink::query()->sole();

        $this->panelComponent(ShareLinksRelationManager::class, ['ownerRecord' => $this->session, 'pageClass' => ViewCaptureSession::class])
            ->assertCanSeeTableRecords([$link])
            ->assertTableColumnStateSet('state', 'Active', $link)
            ->callTableAction('revoke', $link);

        expect($link->fresh()->revoked_at)->not->toBeNull();
    });

    it('shows each opening of a link in its access log', function () {
        toolsPage($this)->callAction('share', data: ['expires_in' => 3_600]);
        $link = ShareLink::query()->sole();
        $link->views()->create(['outcome' => 'wrong_password', 'ip_hash' => str_repeat('ab', 32), 'user_agent' => 'Mozilla/5.0 Test', 'viewed_at' => now()->subMinute()]);
        $link->views()->create(['outcome' => 'viewed', 'ip_hash' => str_repeat('ab', 32), 'user_agent' => 'Mozilla/5.0 Test', 'viewed_at' => now()]);

        $relations = $this->panelComponent(ShareLinksRelationManager::class, ['ownerRecord' => $this->session, 'pageClass' => ViewCaptureSession::class]);
        $log = $this->modalHtml($relations->mountTableAction('log', $link));

        expect($log)->toContain('Opened')->toContain('Wrong password')->toContain('Mozilla/5.0 Test')
            ->toContain('visitor abababab')
            ->not->toContain(str_repeat('ab', 32));
    });

    it('says so when nobody has opened a link yet', function () {
        toolsPage($this)->callAction('share', data: ['expires_in' => 3_600]);
        $link = ShareLink::query()->sole();

        $relations = $this->panelComponent(ShareLinksRelationManager::class, ['ownerRecord' => $this->session, 'pageClass' => ViewCaptureSession::class]);

        expect($this->modalHtml($relations->mountTableAction('log', $link)))->toContain('Nobody has opened this link yet.');
    });
});

describe('second engine and comparison', function () {
    it('queues a second version with the chosen engine and language', function () {
        Queue::fake();
        $this->readyRecording($this->session);

        toolsPage($this)->callAction('second_engine', data: ['provider' => 'elevenlabs', 'language' => 'ur'])->assertHasNoActionErrors();

        $alternate = $this->session->transcripts()->where('kind', 'alternate')->sole();
        expect($alternate->provider)->toBe('elevenlabs')->and($alternate->language)->toBe('ur');
        Queue::assertPushed(TranscribeFinalJob::class);
    });

    it('compares the versions word by word and makes the second one the transcript', function () {
        $alternate = Transcript::query()->create([
            'capture_session_id' => $this->session->id, 'kind' => TranscriptKind::Alternate, 'provider' => 'elevenlabs',
            'status' => TranscriptStatus::Completed,
            'segments' => [
                ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 2_000, 'text' => 'Hello Acme Limited.'],
                ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 3_000, 'end_ms' => 6_000, 'text' => 'The invoice is due Thursday.'],
            ],
        ]);

        $compare = $this->modalHtml(toolsPage($this)->mountAction('compare'));
        expect($compare)->toContain('Words that differ are highlighted')->toContain('<mark>Thursday.</mark>')->not->toContain('<mark>Hello');
        toolsPage($this)->callAction('compare')->assertNotified('The second version is now the transcript');

        expect($alternate->fresh()->kind)->toBe(TranscriptKind::Final)
            ->and($this->session->fresh()->final_provider)->toBe('elevenlabs');
    });
});

describe('removing the second version', function () {
    it('deletes the second version from the comparison and keeps the final transcript', function () {
        Queue::fake();
        $alternate = Transcript::query()->create([
            'capture_session_id' => $this->session->id, 'kind' => TranscriptKind::Alternate, 'provider' => 'elevenlabs',
            'status' => TranscriptStatus::Completed,
            'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 2_000, 'text' => 'Hello Acme Limited.']],
        ]);
        $final = $this->session->finalTranscript;

        toolsPage($this)->mountAction('compare')->callAction(TestAction::make('discard_second')->arguments([]))->assertNotified('Second version removed');

        expect(Transcript::query()->whereKey($alternate->id)->exists())->toBeFalse()
            ->and($final->fresh()->kind)->toBe(TranscriptKind::Final)
            ->and($this->session->fresh()->final_provider)->toBe($final->provider);
    });
});

describe('search and the command palette', function () {
    it('finds the moment in a transcript and links to it', function () {
        $this->panelComponent(Search::class)
            ->fillForm(['q' => 'invoice'])
            ->assertSee('Acme kickoff')
            ->assertSee('<mark>invoice</mark>', false)
            ->assertSee('?t=3000', false);
    });

    it('applies the filters and explains an empty result', function () {
        $this->panelComponent(Search::class)
            ->fillForm(['q' => 'invoice', 'bookmarked' => true])
            ->assertSee('Nothing found');
    });

    it('offers pages, recordings and clients in the palette', function () {
        $palette = new CommandPalette;

        $settings = $palette->getResults('settings')->getCategories();
        expect($settings->keys()->all())->toContain('Go to')
            ->and($settings['Go to'][0]->title)->toBe('Settings');

        $found = $palette->getResults('invoice')->getCategories();
        expect($found['Recordings'][0]->title)->toBe('Acme kickoff')
            ->and($found['Recordings'][0]->url)->toEndWith('?t=3000');

        expect($palette->getResults('acme')->getCategories()['Clients'][0]->title)->toBe('Acme');
    });

    it('opens the palette with Ctrl or Cmd + K', function () {
        expect(Filament::getCurrentPanel()->getGlobalSearchKeyBindings())->toBe(['mod+k']);
    });
});

describe('vocabulary and languages', function () {
    it('saves the global keyterms and corrections from Settings', function () {
        $this->panelComponent(Settings::class)
            ->fillForm([
                'vocabulary_keyterms' => ['EchoLedger', 'echoledger', 'Farah Khan'],
                'vocabulary_replacements' => [['from' => 'eco ledger', 'to' => 'EchoLedger', 'whole_word' => true, 'match_case' => false]],
                'default_language' => 'ur',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(SettingsRepository::class);
        expect($settings->globalKeyterms())->toBe(['EchoLedger', 'Farah Khan'])
            ->and($settings->globalReplacements()[0]['to'])->toBe('EchoLedger')
            ->and($settings->get('default_language'))->toBe('ur');
    });

    it('refuses a keyterm that is too long', function () {
        $this->panelComponent(Settings::class)
            ->fillForm(['vocabulary_keyterms' => [str_repeat('word ', 6)]])
            ->call('save')
            ->assertHasFormErrors();
    });

    it('saves a client\'s language and corrections', function () {
        $this->panelComponent(EditClient::class, ['record' => $this->client->id])
            ->fillForm(['language' => 'ur', 'replacements' => [['from' => 'acme limited', 'to' => 'Acme Ltd', 'whole_word' => true, 'match_case' => false]]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->client->fresh()->language)->toBe('ur')
            ->and($this->client->fresh()->replacements[0]['to'])->toBe('Acme Ltd');
    });
});
