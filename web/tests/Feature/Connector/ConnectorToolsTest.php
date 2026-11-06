<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientContact;
use App\Models\ClientFile;
use App\Models\ClientMemoryItem;
use App\Models\ConnectedApp;
use App\Models\Plan;
use App\Models\SentItem;
use App\Models\Transcript;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sharing\Teammates;
use App\Services\Views\TranscriptViews;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/connector_helpers.php';
require_once dirname(__DIR__).'/Api/Support/screen_helpers.php';
require_once dirname(__DIR__).'/Workspaces/Support/isolation_cases.php';

/*
 * docs/API.md "Claude and ChatGPT connector": the five tools over real HTTP with a real token, what each one answers,
 * and that they see exactly what the person can open in the apps. Nothing of anyone else, in any tool.
 */

beforeEach(function () {
    connectorKeys();
    Mail::fake();
    Fx::fakeRecordingsDisk();
});
afterEach(fn () => Fx::cleanupDisks());

/** A person with a project and subfolder, a filed call with its transcript and screens, and an Inbox call. */
function connectorWorld(): array
{
    $person = Fx::user(['name' => 'Hassam']);
    $device = Fx::device($person);
    $acme = Fx::client($person, 'Acme');
    $site = Client::query()->create(['user_id' => $person->id, 'parent_id' => $acme->id, 'name' => 'Website', 'color' => '#8B5CF6']);
    $call = Fx::session($person, $device, ['title' => 'Tuesday call with Omar', 'client_id' => $site->id, 'started_at' => now()->subDay(), 'duration_ms' => 900_000]);
    $call->forceFill(['caller_name' => 'Omar Khan', 'speaker_names' => ['Client' => 'Omar']])->save();
    $transcript = $call->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed, 'language' => 'en', 'text' => 'Let me share my screen Please send the pelican invoice Click here to pay', 'segments' => [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 5000, 'end_ms' => 9000, 'text' => 'Let me share my screen', 'words' => []],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 60_000, 'end_ms' => 64_000, 'text' => 'Please send the pelican invoice', 'words' => []],
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 900_000, 'end_ms' => 902_000, 'text' => 'Click here to pay', 'words' => []],
    ]]);
    $first = keepScreen($call, screenPicture('png', 1280, 720, seed: 1), ['at_ms' => 30_000]);
    $second = keepScreen($call, screenPicture('webp', 1280, 720, seed: 2), ['at_ms' => 872_000]);
    $second->forceFill(['description' => "The client's invoice page with the Pay button", 'ocr_text' => "Invoice #2041\nTotal walrus 85,000", 'search_text' => 'invoice #2041 total walrus 85,000'])->save();
    $inbox = Fx::session($person, $device, ['title' => 'Voice note', 'started_at' => now()->subHours(2), 'duration_ms' => 65_000]);
    Fx::transcript($inbox, 'Remember the parrot');

    return compact('person', 'device', 'acme', 'site', 'call', 'transcript', 'first', 'second', 'inbox');
}

function connectorPlan(User $person, array $features): void
{
    $plan = Plan::query()->create(['key' => 'test-'.Str::lower(Str::random(6)), 'name' => 'Test', 'features' => $features]);
    Workspace::query()->findOrFail($person->current_workspace_id)->forceFill(['plan_id' => $plan->id])->save();
}

describe('the server', function () {
    it('offers the five tools of the contract, each read-only, with the contract\'s inputs', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];

        $tools = collect(connectorMessage($this, $token, 'tools/list')->assertOk()->json('result.tools'))->keyBy('name');

        expect($tools->keys()->all())->toBe(['list_projects', 'search', 'get_recording', 'get_project_brief', 'list_recent']);
        foreach ($tools as $tool) {
            expect($tool['annotations']['readOnlyHint'])->toBeTrue()->and($tool['annotations']['openWorldHint'])->toBeFalse()->and($tool['description'])->not->toBe('');
        }
        expect(array_keys($tools['list_projects']['inputSchema']['properties']))->toBe(['parent_id'])
            ->and(array_keys($tools['search']['inputSchema']['properties']))->toBe(['query', 'project_id', 'limit'])
            ->and($tools['search']['inputSchema']['required'])->toBe(['query'])
            ->and($tools['search']['inputSchema']['properties']['limit']['maximum'])->toBe(25)
            ->and(array_keys($tools['get_recording']['inputSchema']['properties']))->toBe(['id', 'view', 'include_screens'])
            ->and($tools['get_recording']['inputSchema']['properties']['view']['enum'])->toBe(['original', 'roman', 'english', 'urdu'])
            ->and($tools['get_project_brief']['inputSchema']['required'])->toBe(['project_id'])
            ->and(array_keys($tools['list_recent']['inputSchema']['properties']))->toBe(['n', 'project_id'])
            ->and($tools['list_recent']['inputSchema']['properties']['n']['maximum'])->toBe(50);
    });

    it('answers an unknown tool, and the first handshake of an MCP client', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];

        $hello = connectorMessage($this, $token, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'Claude', 'version' => '1']])->assertOk();
        expect($hello->json('result.serverInfo.name'))->toBe('EchoLedger')->and($hello->json('result.capabilities'))->toHaveKey('tools');

        connectorMessage($this, $token, 'tools/call', ['name' => 'delete_recording', 'arguments' => (object) []])->assertStatus(400);
    });

    it('takes what an MCP client sends after the handshake: the notification and the version header', function () {
        $token = connectorConnect($this, Fx::user())['access'];
        app('auth')->forgetGuards();

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['Accept' => 'application/json, text/event-stream', 'Authorization' => "Bearer {$token}", 'MCP-Protocol-Version' => '2025-06-18'])->assertStatus(202);
        app('auth')->forgetGuards();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream', 'Authorization' => "Bearer {$token}", 'MCP-Protocol-Version' => '2025-06-18'])
            ->assertOk()->assertJsonCount(5, 'result.tools');
    });

    it('turns away other HTTP methods, as Streamable HTTP without a server stream does', function () {
        $this->get('/mcp')->assertStatus(405);
        $this->delete('/mcp')->assertStatus(405);
    });
});

describe('list_projects', function () {
    it('lists every project with its path, recordings and last recording', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];

        $text = connectorTool($this, $token, 'list_projects')['text'];

        expect($text)->toContain("Acme (id {$world['acme']->id}): 0 recordings, 1 with its subfolders")
            ->toContain("Acme / Website (id {$world['site']->id}): 1 recording, last recording ".$world['call']->started_at->format('j M Y'));
    });

    it('lists only the subfolders directly in a project with parent_id', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];

        expect(connectorTool($this, $token, 'list_projects', ['parent_id' => $world['acme']->id])['text'])->toContain('Website')->not->toContain('(id '.$world['acme']->id.')');
        expect(connectorTool($this, $token, 'list_projects', ['parent_id' => $world['site']->id]))->toBe(['text' => 'This project has no subfolders.', 'error' => false]);
    });

    it('says when there are none, and when the parent is unknown', function () {
        $token = connectorConnect($this, Fx::user())['access'];

        expect(connectorTool($this, $token, 'list_projects')['text'])->toBe('You have no projects yet.');
        expect(connectorTool($this, $token, 'list_projects', ['parent_id' => Fx::MISSING_ID]))->toBe(['text' => 'No project with that id.', 'error' => true]);
    });
});

describe('search', function () {
    it('finds transcript lines with the recording, date, project and time', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];

        $answer = connectorTool($this, $token, 'search', ['query' => 'pelican']);

        expect($answer['error'])->toBeFalse()->and($answer['text'])
            ->toContain("Tuesday call with Omar (id {$world['call']->id})")
            ->toContain($world['call']->started_at->format('j M Y'))
            ->toContain('Acme / Website')
            ->toContain('[1:00] Omar: Please send the pelican invoice');
    });

    it('finds screen text and titles', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];

        expect(connectorTool($this, $token, 'search', ['query' => 'walrus'])['text'])->toContain('[Screen 2 at 14:32]')->toContain('walrus');
        expect(connectorTool($this, $token, 'search', ['query' => 'Tuesday'])['text'])->toContain($world['call']->id)->toContain('(the title matches)');
    });

    it('looks in a project and its subfolders, and only there', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];

        expect(connectorTool($this, $token, 'search', ['query' => 'pelican', 'project_id' => $world['acme']->id])['text'])->toContain($world['call']->id);
        expect(connectorTool($this, $token, 'search', ['query' => 'parrot', 'project_id' => $world['acme']->id])['text'])->toBe('No recording matches that.');
        expect(connectorTool($this, $token, 'search', ['query' => 'pelican', 'project_id' => Fx::MISSING_ID]))->toBe(['text' => 'No project with that id.', 'error' => true]);
    });

    it('checks its inputs and answers in plain words', function () {
        $token = connectorConnect($this, Fx::user())['access'];

        foreach ([['query' => 'x'], [], ['query' => str_repeat('x', 201)], ['query' => 'hello', 'limit' => 26], ['query' => 'hello', 'limit' => 0]] as $arguments) {
            $answer = connectorTool($this, $token, 'search', $arguments);
            expect($answer['error'])->toBeTrue()->and($answer['text'])->toStartWith('The ')->not->toContain('Exception');
        }
        expect(connectorTool($this, $token, 'search', ['query' => '""'])['text'])->toBe('Nothing to look for in that query.');
    });

    it('keeps to the limit and says when more match', function () {
        $person = Fx::user();
        $device = Fx::device($person);
        foreach (range(1, 3) as $i) {
            Fx::transcript(Fx::session($person, $device, ['title' => "Call {$i}"]), 'the mongoose again');
        }
        $token = connectorConnect($this, $person)['access'];

        $text = connectorTool($this, $token, 'search', ['query' => 'mongoose', 'limit' => 2])['text'];

        expect(substr_count($text, '(id '))->toBe(2)->and($text)->toContain('More recordings match');
    });
});

describe('get_recording', function () {
    it('gives the facts, the transcript with the screens at their moments, and each screen with its link', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];

        $answer = connectorTool($this, $token, 'get_recording', ['id' => $world['call']->id]);

        expect($answer['error'])->toBeFalse();
        expect($answer['text'])
            ->toContain('Title: Tuesday call with Omar')
            ->toContain("Recording id: {$world['call']->id}")
            ->toContain('Date: '.$world['call']->started_at->format('j M Y'))
            ->toContain('Length: 15:00')
            ->toContain('People: Me, Omar, Omar Khan')
            ->toContain('Project: Acme / Website')
            ->toContain('App: WhatsApp')
            ->toContain('View: Original')
            ->toContain("[0:05] Me: Let me share my screen\n[Screen 1 at 0:30]\n[1:00] Omar: Please send the pelican invoice\n[Screen 2 at 14:32: The client's invoice page with the Pay button]\n[15:00] Me: Click here to pay")
            ->toContain('each picture link works for 30 minutes')
            ->toContain("Text: Invoice #2041\nTotal walrus 85,000")
            ->toContain('/screen-media/'.$world['first']->id.'/image?')
            ->toContain('/screen-media/'.$world['second']->id.'/image?');
    });

    it('leaves the screens out when asked', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];

        $text = connectorTool($this, $token, 'get_recording', ['id' => $world['call']->id, 'include_screens' => false])['text'];

        expect($text)->toContain('Please send the pelican invoice')->not->toContain('[Screen')->not->toContain('screen-media')->not->toContain('walrus');
    });

    it('gives links that work without a token for 30 minutes, then stop', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];
        $text = connectorTool($this, $token, 'get_recording', ['id' => $world['call']->id])['text'];
        preg_match('~https://\S+/screen-media/'.$world['first']->id.'/image\?\S+~', $text, $link);
        expect($link)->not->toBe([]);
        app('auth')->forgetGuards();

        $this->flushHeaders()->get($link[0])->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->travel(29)->minutes();
        $this->get($link[0])->assertOk();
        $this->travel(2)->minutes();
        $this->get($link[0])->assertForbidden();
        // A link for another screen cannot be made from it.
        $this->travelBack();
        $this->get(str_replace($world['first']->id, $world['second']->id, $link[0]))->assertForbidden();
    });

    it('opens in the view the console opens it in, when it is made, and takes a view asked for', function () {
        $world = connectorWorld();
        $transcript = $world['transcript'];
        $transcript->forceFill(['language' => 'ur', 'text' => 'yeh', 'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 1000, 'end_ms' => 2000, 'text' => 'یہ دیکھیں', 'words' => []]]])->save();
        $token = connectorConnect($this, $world['person'])['access'];

        // Not made yet: the original words, with a word about the view only when it was asked for.
        $plain = connectorTool($this, $token, 'get_recording', ['id' => $world['call']->id, 'include_screens' => false])['text'];
        expect($plain)->toContain('یہ دیکھیں')->toContain('View: Original')->not->toContain('not made yet');
        expect(connectorTool($this, $token, 'get_recording', ['id' => $world['call']->id, 'view' => 'roman'])['text'])->toContain('The Roman Urdu view of this recording is not made yet')->toContain('یہ دیکھیں');

        $transcript->refresh()->forceFill(['meta' => ['views' => ['status' => 'ready', 'fingerprint' => TranscriptViews::fingerprint($transcript), 'roman' => ['yeh dekhein'], 'urdu' => ['یہ دیکھیں'], 'english' => ['Look at this']]]])->save();

        expect(connectorTool($this, $token, 'get_recording', ['id' => $world['call']->id, 'include_screens' => false])['text'])->toContain('[0:01] Me: yeh dekhein')->toContain('View: Roman Urdu');
        expect(connectorTool($this, $token, 'get_recording', ['id' => $world['call']->id, 'view' => 'english'])['text'])->toContain('[0:01] Me: Look at this')->toContain('View: English');
        expect(connectorTool($this, $token, 'get_recording', ['id' => $world['call']->id, 'view' => 'original'])['text'])->toContain('[0:01] Me: یہ دیکھیں')->toContain('View: Original');
        expect(connectorTool($this, $token, 'get_recording', ['id' => $world['call']->id, 'view' => 'klingon'])['error'])->toBeTrue();
    });

    it('says when there is no transcript yet, and never makes one', function () {
        $person = Fx::user();
        $waiting = Fx::session($person, Fx::device($person), ['title' => 'Still being typed']);
        $token = connectorConnect($this, $person)['access'];

        expect(connectorTool($this, $token, 'get_recording', ['id' => $waiting->id])['text'])->toContain('Title: Still being typed')->toContain('The transcript of this recording is not ready yet.');
        expect(unscoped(fn (): int => Transcript::query()->where('capture_session_id', $waiting->id)->count()))->toBe(0);
    });

    it('answers a recording in the undo bin or an unknown id as "No recording with that id"', function () {
        $world = connectorWorld();
        $world['inbox']->delete();
        $token = connectorConnect($this, $world['person'])['access'];

        foreach ([$world['inbox']->id, Fx::MISSING_ID, 'nonsense'] as $id) {
            expect(connectorTool($this, $token, 'get_recording', ['id' => $id]))->toBe(['text' => 'No recording with that id.', 'error' => true]);
        }
    });
});

describe('get_project_brief', function () {
    function connectorBriefWorld(array $world): void
    {
        $site = $world['site'];
        $site->forceFill(['notes' => 'Always start with the invoice.'])->save();
        ClientBrief::query()->create(['workspace_id' => $site->workspace_id, 'client_id' => $site->id, 'sections' => ['goal' => 'Launch the site', 'scope' => ['Five pages']], 'status' => 'ready']);
        ClientContact::query()->create(['client_id' => $site->id, 'name' => 'Omar Khan', 'phone' => '+92 300 1234567', 'email' => 'omar@acme.example']);
        ClientFile::query()->create(['workspace_id' => $site->workspace_id, 'user_id' => $site->user_id, 'client_id' => $site->id, 'name' => 'scope.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10, 'path' => 'files/x.elr', 'status' => 'ready', 'text' => 'SECRET-PDF-WORDS']);
        ClientFile::query()->create(['workspace_id' => $site->workspace_id, 'user_id' => $site->user_id, 'client_id' => $site->id, 'name' => 'House rules', 'mime' => 'text/plain', 'size_bytes' => 10, 'status' => 'ready', 'text' => 'Use the blue logo.']);
        ClientMemoryItem::query()->create(['workspace_id' => $site->workspace_id, 'client_id' => $site->id, 'kind' => 'decision', 'text' => 'They chose the walrus theme', 'happened_on' => '2026-11-03', 'source_type' => 'user']);
        CallNote::query()->create(['workspace_id' => $site->workspace_id, 'client_id' => $site->id, 'capture_session_id' => $world['call']->id, 'status' => 'ready', 'sections' => ['decisions' => [['text' => 'Pay by Friday', 'at_ms' => 60_000]]]]);
    }

    it('gives the brief, notes, instructions, contacts, file names, memory and call notes', function () {
        $world = connectorWorld();
        connectorBriefWorld($world);
        $token = connectorConnect($this, $world['person'])['access'];

        $text = connectorTool($this, $token, 'get_project_brief', ['project_id' => $world['site']->id])['text'];

        expect($text)->toContain("Project: Acme / Website (id {$world['site']->id})")
            ->toContain('Goal: Launch the site')->toContain("Scope:\n- Five pages")
            ->toContain('Always start with the invoice.')->toContain("House rules:\nUse the blue logo.")
            ->toContain('- Omar Khan · +92 300 1234567 · omar@acme.example')
            ->toContain('- scope.pdf')->not->toContain('SECRET-PDF-WORDS')
            ->toContain('- Decision (2026-11-03): They chose the walrus theme')
            ->toContain('Tuesday call with Omar')->toContain('Pay by Friday');
    });

    it('leaves out memory and call notes when AI notes are not in the plan, and says so', function () {
        $world = connectorWorld();
        connectorBriefWorld($world);
        connectorPlan($world['person'], ['ai_notes' => false]);
        $token = connectorConnect($this, $world['person'])['access'];

        $text = connectorTool($this, $token, 'get_project_brief', ['project_id' => $world['site']->id])['text'];

        expect($text)->toContain('Goal: Launch the site')->toContain('House rules')->toContain('Omar Khan')
            ->toContain('Memory and call notes are not in your plan.')->not->toContain('walrus theme')->not->toContain('Pay by Friday');
    });

    it('answers an unknown project in plain words', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];

        expect(connectorTool($this, $token, 'get_project_brief', ['project_id' => Fx::MISSING_ID]))->toBe(['text' => 'No project with that id.', 'error' => true]);
        expect(connectorTool($this, $token, 'get_project_brief')['error'])->toBeTrue();
    });
});

describe('list_recent', function () {
    it('gives the latest recordings, newest first, with their id, date, length, project and app', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];

        $text = connectorTool($this, $token, 'list_recent')['text'];
        $lines = explode("\n", $text);

        expect($lines)->toHaveCount(2)
            ->and($lines[0])->toContain("Voice note (id {$world['inbox']->id})")->toContain('1:05')->toContain('in no project')->toContain('WhatsApp')
            ->and($lines[1])->toContain("Tuesday call with Omar (id {$world['call']->id})")->toContain('15:00')->toContain('Acme / Website');
    });

    it('takes a count and a project with its subfolders, and checks them', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];

        expect(explode("\n", connectorTool($this, $token, 'list_recent', ['n' => 1])['text']))->toHaveCount(1);
        expect(connectorTool($this, $token, 'list_recent', ['project_id' => $world['acme']->id])['text'])->toContain($world['call']->id)->not->toContain($world['inbox']->id);
        foreach ([['n' => 0], ['n' => 51]] as $arguments) {
            expect(connectorTool($this, $token, 'list_recent', $arguments)['error'])->toBeTrue();
        }
        expect(connectorTool($this, $token, 'list_recent', ['project_id' => Fx::MISSING_ID]))->toBe(['text' => 'No project with that id.', 'error' => true]);
    });

    it('leaves out what is still being recorded or in the undo bin', function () {
        $person = Fx::user();
        $device = Fx::device($person);
        Fx::session($person, $device, ['title' => 'Live now', 'status' => 'recording']);
        Fx::session($person, $device, ['title' => 'Binned'])->delete();
        $token = connectorConnect($this, $person)['access'];

        expect(connectorTool($this, $token, 'list_recent')['text'])->toBe('No recordings yet.');
    });
});

describe('what it may see', function () {
    it('never answers with anything of another person, in another workspace or the same one', function (bool $sameWorkspace) {
        $b = isoWorld($sameWorkspace);
        $alice = $b['alice']->fresh();
        $token = connectorConnect($this, $alice)['access'];
        $leaks = [];
        foreach ([
            ['list_projects', []],
            ['list_recent', ['n' => 50]],
            ['search', ['query' => 'BOB-SECRET-TITLE']],
            ['search', ['query' => 'BOBSECRETTRANSCRIPT']],
            ['search', ['query' => 'BOBSECRETNOTE']],
            ['search', ['query' => 'BOB']],
        ] as [$tool, $arguments]) {
            $answer = connectorTool($this, $token, $tool, $arguments);
            expect($answer['error'])->toBeFalse($tool);
            $leaks = [...$leaks, ...isoLeaks($answer['text'], $b)];
        }
        expect($leaks)->toBe([]);

        foreach ([$b['session']->id, $b['binned']->id, $b['transcript']->id] as $id) {
            expect(connectorTool($this, $token, 'get_recording', ['id' => $id]))->toBe(['text' => 'No recording with that id.', 'error' => true]);
        }
        foreach ([
            ['get_project_brief', ['project_id' => $b['client']->id]],
            ['list_projects', ['parent_id' => $b['client']->id]],
            ['search', ['query' => 'BOB-SECRET-TITLE', 'project_id' => $b['client']->id]],
            ['list_recent', ['project_id' => $b['client']->id]],
        ] as [$tool, $arguments]) {
            expect(connectorTool($this, $token, $tool, $arguments))->toBe(['text' => 'No project with that id.', 'error' => true], $tool);
        }
    })->with(['another workspace' => false, 'the same workspace' => true]);

    it('shows the person\'s recordings of their current workspace only, and follows a switch', function () {
        $world = connectorWorld();
        $person = $world['person'];
        $team = Workspace::query()->findOrFail(Fx::user()->current_workspace_id);
        app(Workspaces::class)->addMember($team, $person);
        $inTeam = unscoped(fn () => Fx::session($person, null, ['title' => 'Team standup', 'workspace_id' => $team->id]));
        $token = connectorConnect($this, $person)['access'];

        $text = connectorTool($this, $token, 'list_recent')['text'];
        expect($text)->toContain('Voice note')->not->toContain('Team standup');
        expect(connectorTool($this, $token, 'get_recording', ['id' => $inTeam->id])['error'])->toBeTrue();

        app(Workspaces::class)->switchTo($person->fresh(), $team);
        app('auth')->forgetGuards();
        $text = connectorTool($this, $token, 'list_recent')['text'];
        expect($text)->toContain('Team standup')->not->toContain('Voice note');
        expect(connectorTool($this, $token, 'get_recording', ['id' => $world['call']->id])['error'])->toBeTrue();
    });

    it('reads what a teammate sent the person, read-only, and nothing around it', function () {
        $sender = Fx::user(['name' => 'Ana']);
        $senderDevice = Fx::device($sender);
        $folder = Fx::client($sender, 'ANA-SECRET-PROJECT');
        $sent = Fx::session($sender, $senderDevice, ['title' => 'Sent to you', 'client_id' => $folder->id, 'duration_ms' => 60_000]);
        Fx::transcript($sent, 'The ostrich budget is fine');
        keepScreen($sent, screenPicture('png', 1280, 720, seed: 3), ['at_ms' => 500]);
        $unsent = Fx::session($sender, $senderDevice, ['title' => 'ANA-SECRET-TITLE']);
        Fx::transcript($unsent, 'The ostrich password is hunter2');
        $person = Fx::user();
        app(Teammates::class)->send($sender, $sent, [$person->email], 'for you');
        $token = connectorConnect($this, $person)['access'];

        $recent = connectorTool($this, $token, 'list_recent')['text'];
        expect($recent)->toContain('Sent to you')->toContain('shared with you')->not->toContain('ANA-SECRET');

        $found = connectorTool($this, $token, 'search', ['query' => 'ostrich'])['text'];
        expect($found)->toContain($sent->id)->toContain('shared with you')->toContain('The ostrich budget is fine')
            ->not->toContain($unsent->id)->not->toContain('hunter2')->not->toContain('ANA-SECRET');

        $recording = connectorTool($this, $token, 'get_recording', ['id' => $sent->id])['text'];
        expect($recording)->toContain('Project: Shared with you (read-only)')->toContain('The ostrich budget is fine')->toContain('[Screen 1 at 0:00]')->toContain('/screen-media/')->not->toContain('ANA-SECRET');

        expect(connectorTool($this, $token, 'get_recording', ['id' => $unsent->id])['error'])->toBeTrue();
        expect(connectorTool($this, $token, 'get_project_brief', ['project_id' => $folder->id]))->toBe(['text' => 'No project with that id.', 'error' => true]);
        expect(connectorTool($this, $token, 'list_projects')['text'])->not->toContain('ANA-SECRET');
    });

    it('stops reading what was sent once it is taken off the list or deleted', function () {
        $sender = Fx::user();
        $sent = Fx::session($sender, Fx::device($sender), ['title' => 'Sent for a while']);
        Fx::transcript($sent, 'Kept by the sender');
        $person = Fx::user();
        app(Teammates::class)->send($sender, $sent, [$person->email], null);
        $token = connectorConnect($this, $person)['access'];
        expect(connectorTool($this, $token, 'get_recording', ['id' => $sent->id])['error'])->toBeFalse();

        unscoped(fn () => $sent->fresh()->delete());

        expect(connectorTool($this, $token, 'get_recording', ['id' => $sent->id]))->toBe(['text' => 'No recording with that id.', 'error' => true]);
        expect(connectorTool($this, $token, 'list_recent')['text'])->toBe('No recordings yet.');

        unscoped(fn () => $sent->restore());
        expect(connectorTool($this, $token, 'get_recording', ['id' => $sent->id])['error'])->toBeFalse();
        unscoped(fn () => SentItem::query()->where('to_user_id', $person->id)->delete());
        expect(connectorTool($this, $token, 'get_recording', ['id' => $sent->id]))->toBe(['text' => 'No recording with that id.', 'error' => true]);
        expect(connectorTool($this, $token, 'search', ['query' => 'Kept'])['text'])->toBe('No recording matches that.');
    });

    it('lets a viewer read what was sent to them', function () {
        $owner = Fx::user();
        $team = Workspace::query()->findOrFail($owner->current_workspace_id);
        $viewer = withoutWorkspace(Fx::user());
        app(Workspaces::class)->addMember($team, $viewer, WorkspaceRole::Viewer);
        app(Workspaces::class)->switchTo($viewer->fresh(), $team);
        $sent = Fx::session($owner, Fx::device($owner), ['title' => 'For the viewer']);
        Fx::transcript($sent, 'Hello viewer');
        app(Teammates::class)->send($owner, $sent, [$viewer->email], null);
        $token = connectorConnect($this, $viewer->fresh())['access'];

        expect(connectorTool($this, $token, 'list_recent')['text'])->toContain('For the viewer');
        expect(connectorTool($this, $token, 'get_recording', ['id' => $sent->id])['text'])->toContain('Hello viewer');
    });

    it('never changes or makes anything', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];
        $before = unscoped(fn (): array => [CaptureSession::query()->count(), Client::query()->count(), Transcript::query()->count(), CaptureSession::query()->max('updated_at')]);

        foreach ([['list_projects', []], ['search', ['query' => 'pelican']], ['get_recording', ['id' => $world['call']->id, 'view' => 'roman']], ['get_project_brief', ['project_id' => $world['site']->id]], ['list_recent', []]] as [$tool, $arguments]) {
            connectorTool($this, $token, $tool, $arguments);
        }

        expect(unscoped(fn (): array => [CaptureSession::query()->count(), Client::query()->count(), Transcript::query()->count(), CaptureSession::query()->max('updated_at')]))->toBe($before);
    });
});

describe('the plan', function () {
    it('answers every tool with the contract\'s words when the connector is not in the plan', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];
        connectorPlan($world['person'], ['connector' => false]);

        foreach ([['list_projects', []], ['search', ['query' => 'pelican']], ['get_recording', ['id' => $world['call']->id]], ['get_project_brief', ['project_id' => $world['site']->id]], ['list_recent', []]] as [$tool, $arguments]) {
            expect(connectorTool($this, $token, $tool, $arguments))->toBe(['text' => 'The connector is not in your plan.', 'error' => true], $tool);
        }
    });

    it('asks the plan of the current workspace', function () {
        $world = connectorWorld();
        $person = $world['person'];
        $team = Workspace::query()->findOrFail(Fx::user()->current_workspace_id);
        app(Workspaces::class)->addMember($team, $person);
        $plan = Plan::query()->create(['key' => 'no-connector', 'name' => 'No connector', 'features' => ['connector' => false]]);
        $team->forceFill(['plan_id' => $plan->id])->save();
        $token = connectorConnect($this, $person)['access'];
        expect(connectorTool($this, $token, 'list_recent')['error'])->toBeFalse();

        app(Workspaces::class)->switchTo($person->fresh(), $team);
        app('auth')->forgetGuards();

        expect(connectorTool($this, $token, 'list_recent')['text'])->toBe('The connector is not in your plan.');
    });

    it('stops reading for an account being deleted, in plain words', function () {
        $person = Fx::user();
        $token = connectorConnect($this, $person)['access'];
        $person->fresh()->forceFill(['deletes_at' => now()->addDays(7)])->save();

        $answer = connectorTool($this, $token, 'list_recent');

        expect($answer['error'])->toBeTrue()->and($answer['text'])->toContain('being deleted');
    });
});

describe('limits and records', function () {
    it('allows 60 tool calls a minute per person, answers the next in plain words, and comes back', function () {
        $person = Fx::user();
        $token = connectorConnect($this, $person)['access'];
        $other = connectorConnect($this, Fx::user())['access'];

        foreach (range(1, 60) as $i) {
            expect(connectorTool($this, $token, 'list_recent')['error'])->toBeFalse("call {$i}");
        }

        $answer = connectorTool($this, $token, 'list_recent');
        expect($answer['error'])->toBeTrue()->and($answer['text'])->toStartWith('Too many requests: at most 60 a minute.');
        // Listing the tools is no tool call, and another person has their own count.
        connectorMessage($this, $token, 'tools/list')->assertOk();
        expect(connectorTool($this, $other, 'list_recent')['error'])->toBeFalse();

        $this->travel(61)->seconds();
        expect(connectorTool($this, $token, 'list_recent')['error'])->toBeFalse();
    });

    it('counts every message to /mcp with a generous limit of its own', function () {
        $person = Fx::user();
        $token = connectorConnect($this, $person)['access'];

        foreach (range(1, 240) as $ignored) {
            connectorMessage($this, $token, 'ping')->assertOk();
        }

        connectorMessage($this, $token, 'ping')->assertTooManyRequests();
    });

    it('writes each call in the audit log: the tool, the arguments without content, the result size', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];

        $answer = connectorTool($this, $token, 'search', ['query' => 'the secret pelican words', 'limit' => 5, 'project_id' => $world['acme']->id]);
        $recording = connectorTool($this, $token, 'get_recording', ['id' => $world['call']->id, 'view' => 'original']);
        $refused = connectorTool($this, $token, 'get_recording', ['id' => Fx::MISSING_ID]);
        connectorPlan($world['person'], ['connector' => false]);
        connectorTool($this, $token, 'list_recent');

        $rows = unscoped(fn () => AuditEvent::query()->where('event', 'connector_tool_call')->orderBy('id')->get());
        expect($rows)->toHaveCount(4);
        [$search, $get, $missing, $noPlan] = $rows->all();
        expect($search->user_id)->toBe($world['person']->id)
            ->and($search->actor)->toBe('connector')
            ->and($search->summary)->toBe('Claude used search')
            ->and($search->meta['tool'])->toBe('search')
            ->and($search->meta['client'])->toBe('Claude')
            ->and($search->meta['arguments'])->toBe(['project_id' => $world['acme']->id, 'limit' => 5, 'query_length' => 24])
            ->and($search->meta['result_bytes'])->toBe(strlen($answer['text']))
            ->and($search->meta['error'])->toBeFalse()
            ->and($get->meta['arguments'])->toBe(['id' => $world['call']->id, 'view' => 'original'])
            ->and($get->meta['result_bytes'])->toBe(strlen($recording['text']))
            ->and($missing->meta['error'])->toBeTrue()
            ->and($missing->meta['result_bytes'])->toBe(strlen($refused['text']))
            ->and($noPlan->meta['tool'])->toBe('list_recent')
            ->and($noPlan->meta['error'])->toBeTrue();
        // Never the words: not the query, not a line of the answer.
        expect((string) json_encode($rows->map->only(['summary', 'meta'])->all()))->not->toContain('pelican')->not->toContain('Tuesday');
    });

    it('shows the calls on the console\'s security page, by the app', function () {
        $person = Fx::user();
        $token = connectorConnect($this, $person, 'ChatGPT')['access'];
        connectorTool($this, $token, 'list_recent');
        app('auth')->forgetGuards();

        $this->actingAs($person->fresh(), 'web')->get('/admin/security')->assertOk()->assertSee('ChatGPT used list_recent')->assertSee('Read by a connected app');
    });

    it('marks the connection as used', function () {
        $person = Fx::user();
        $token = connectorConnect($this, $person)['access'];
        expect(ConnectedApp::query()->sole()->last_used_at)->toBeNull();

        connectorTool($this, $token, 'list_recent');

        expect(ConnectedApp::query()->sole()->last_used_at)->not->toBeNull();
    });

    it('never shows an internal error to the AI, and records the failure', function () {
        $world = connectorWorld();
        $token = connectorConnect($this, $world['person'])['access'];
        CaptureSession::retrieved(function (): void {
            throw new RuntimeException('SQLSTATE secret internal details');
        });

        $answer = connectorTool($this, $token, 'list_recent');

        expect($answer)->toBe(['text' => 'Something went wrong reading that. Try again in a moment.', 'error' => true]);
        expect(unscoped(fn () => AuditEvent::query()->where('event', 'connector_tool_call')->sole()->meta['error']))->toBeTrue();
    });
});
