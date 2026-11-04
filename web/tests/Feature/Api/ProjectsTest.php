<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientContact;
use App\Models\ClientFile;
use App\Models\ClientMemoryItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "Projects (since v1.5)": subfolders up to 4 levels, names unique per folder, delete to the Inbox,
 * smart folders, `include_subfolders`, folder counts and `filed_by`.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
    $this->owner = $this->device->user_id;
});

function project(object $test, string $name, ?Client $parent = null, array $state = []): Client
{
    return Client::factory()->create(['user_id' => $test->owner, 'name' => $name, 'parent_id' => $parent?->id, ...$state]);
}

function recordingIn(object $test, ?Client $client, array $state = []): CaptureSession
{
    return CaptureSession::factory()->create(['user_id' => $test->owner, 'device_id' => $test->device->id, 'client_id' => $client?->id, ...$state]);
}

describe('the tree', function () {
    it('creates subfolders and shows their place, subfolders and nested recordings', function () {
        $acme = $this->postJson('/api/clients', ['name' => 'Acme'])->assertCreated()->json('data');
        expect($acme)->toMatchArray(['parent_id' => null, 'path' => [], 'children_count' => 0, 'nested_session_count' => 0]);

        $web = $this->postJson('/api/clients', ['name' => 'Website', 'parent_id' => $acme['id']])->assertCreated()->json('data');
        $pages = $this->postJson('/api/clients', ['name' => 'Pages', 'parent_id' => $web['id']])->assertCreated()->json('data');
        expect($pages['path'])->toBe([['id' => $acme['id'], 'name' => 'Acme'], ['id' => $web['id'], 'name' => 'Website']]);

        recordingIn($this, Client::query()->find($acme['id']));
        recordingIn($this, Client::query()->find($pages['id']));
        recordingIn($this, Client::query()->find($pages['id']));
        $list = collect($this->getJson('/api/clients')->assertOk()->json('data'))->keyBy('name');

        expect($list['Acme'])->toMatchArray(['children_count' => 1, 'session_count' => 1, 'nested_session_count' => 3])
            ->and($list['Website'])->toMatchArray(['parent_id' => $acme['id'], 'children_count' => 1, 'session_count' => 0, 'nested_session_count' => 2])
            ->and($list['Pages'])->toMatchArray(['parent_id' => $web['id'], 'children_count' => 0, 'session_count' => 2, 'nested_session_count' => 2]);
    });

    it('keeps names unique within a folder, capitals ignored, and allows the same name in another folder', function () {
        $acme = project($this, 'Acme');
        $beta = project($this, 'Beta');
        project($this, 'Notes', $acme);

        $this->postJson('/api/clients', ['name' => 'notes', 'parent_id' => $acme->id])->assertStatus(422)->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.name.0', 'A project with this name is already in this folder.');
        $this->postJson('/api/clients', ['name' => 'ACME'])->assertStatus(422);
        $notes = $this->postJson('/api/clients', ['name' => 'Notes', 'parent_id' => $beta->id])->assertCreated()->json('data.id');
        // Moving it next to the other "Notes" or renaming into a taken name is refused as well.
        $this->patchJson("/api/clients/{$notes}", ['parent_id' => $acme->id])->assertStatus(422);
        $this->patchJson("/api/clients/{$beta->id}", ['name' => 'acme'])->assertStatus(422);
        $this->patchJson("/api/clients/{$beta->id}", ['name' => 'Beta'])->assertOk();
    });

    it('refuses a move under itself or its own subfolder with invalid_parent', function () {
        $acme = project($this, 'Acme');
        $web = project($this, 'Website', $acme);
        $pages = project($this, 'Pages', $web);

        $this->patchJson("/api/clients/{$acme->id}", ['parent_id' => $acme->id])->assertStatus(422)->assertJsonPath('code', 'invalid_parent');
        $this->patchJson("/api/clients/{$acme->id}", ['parent_id' => $pages->id])->assertStatus(422)->assertJsonPath('code', 'invalid_parent');
        expect($acme->fresh()->parent_id)->toBeNull();

        // Out to the top and into another branch work.
        $this->patchJson("/api/clients/{$pages->id}", ['parent_id' => null])->assertOk()->assertJsonPath('data.path', []);
        $this->patchJson("/api/clients/{$pages->id}", ['parent_id' => $acme->id])->assertOk()->assertJsonPath('data.parent_id', $acme->id);
    });

    it('nests at most four levels, counting the subfolders of a moved project', function () {
        $one = project($this, 'One');
        $two = project($this, 'Two', $one);
        $three = project($this, 'Three', $two);
        $four = $this->postJson('/api/clients', ['name' => 'Four', 'parent_id' => $three->id])->assertCreated()->json('data.id');

        $this->postJson('/api/clients', ['name' => 'Five', 'parent_id' => $four])->assertStatus(422)->assertJsonPath('code', 'too_deep');
        // A project with one subfolder under level three would make five levels.
        $other = project($this, 'Other');
        project($this, 'Child', $other);
        $this->patchJson("/api/clients/{$other->id}", ['parent_id' => $three->id])->assertStatus(422)->assertJsonPath('code', 'too_deep');
        $this->patchJson("/api/clients/{$other->id}", ['parent_id' => $two->id])->assertOk();
    });

    it('answers client_not_found for a parent of another workspace', function () {
        $foreign = Client::factory()->create(['user_id' => User::factory()]);

        $this->postJson('/api/clients', ['name' => 'Mine', 'parent_id' => $foreign->id])->assertNotFound()->assertJsonPath('code', 'client_not_found');
        $mine = project($this, 'Mine');
        $this->patchJson("/api/clients/{$mine->id}", ['parent_id' => $foreign->id])->assertNotFound()->assertJsonPath('code', 'client_not_found');
    });
});

describe('deleting a project', function () {
    it('sends its recordings to the Inbox, moves its subfolders up and removes everything else of it', function () {
        $acme = project($this, 'Acme');
        $site = project($this, 'Site', $acme);
        $docs = project($this, 'Docs', $site);
        project($this, 'Docs', $acme); // the moved "Docs" must not clash with this one
        $call = recordingIn($this, $site, ['title' => 'Kick-off']);
        $other = recordingIn($this, $acme);
        $binned = recordingIn($this, $site);
        $binned->delete();
        $before = $call->updated_at;
        $this->travel(5)->seconds();

        $path = 'client-files/'.$site->id.'/scope.elr';
        Storage::disk('recordings')->put($path, 'encrypted bytes');
        ClientFile::query()->create(['workspace_id' => $site->workspace_id, 'user_id' => $this->owner, 'client_id' => $site->id, 'name' => 'scope.pdf', 'mime' => 'application/pdf', 'size_bytes' => 15, 'path' => $path, 'status' => 'ready']);
        ClientBrief::query()->create(['workspace_id' => $site->workspace_id, 'client_id' => $site->id, 'sections' => ClientBrief::emptySections()]);
        CallNote::query()->create(['workspace_id' => $site->workspace_id, 'client_id' => $site->id, 'capture_session_id' => $call->id, 'status' => 'ready']);
        ClientMemoryItem::query()->create(['workspace_id' => $site->workspace_id, 'client_id' => $site->id, 'kind' => 'decision', 'text' => 'Launch', 'source_type' => 'user']);
        ClientContact::query()->create(['client_id' => $site->id, 'name' => 'Omar', 'phone' => '+92 300 1234567']);

        $this->deleteJson("/api/clients/{$site->id}")->assertOk()->assertExactJson(['moved_sessions' => 1, 'moved_projects' => 1]);

        expect(Client::query()->find($site->id))->toBeNull()
            ->and($call->fresh())->client_id->toBeNull()->filed_by->toBeNull()
            ->and($call->fresh()->updated_at->gt($before))->toBeTrue()
            ->and(CaptureSession::withTrashed()->find($binned->id)->client_id)->toBeNull()
            ->and($other->fresh()->client_id)->toBe($acme->id)
            ->and($docs->fresh())->parent_id->toBe($acme->id)->name->toBe('Docs (2)')
            ->and(ClientFile::query()->where('client_id', $site->id)->exists())->toBeFalse()
            ->and(Storage::disk('recordings')->exists($path))->toBeFalse()
            ->and(ClientBrief::query()->where('client_id', $site->id)->exists())->toBeFalse()
            ->and(CallNote::query()->where('client_id', $site->id)->exists())->toBeFalse()
            ->and(ClientMemoryItem::query()->where('client_id', $site->id)->exists())->toBeFalse()
            ->and(ClientContact::query()->where('client_id', $site->id)->exists())->toBeFalse();
        // The recording itself is untouched and now in the Inbox.
        $inbox = $this->getJson('/api/library?folder=inbox')->assertOk()->json('data');
        expect(array_column($inbox, 'id'))->toContain($call->id);
    });

    it('lets only owners delete, and answers 404 for another workspace or a second delete', function () {
        $owner = User::query()->findOrFail($this->owner);
        $workspace = Workspace::query()->findOrFail($owner->current_workspace_id);
        $member = withoutWorkspace(User::factory()->create());
        app(Workspaces::class)->addMember($workspace, $member);
        [, $memberToken] = $this->enroll($member->refresh());
        $acme = project($this, 'Acme');

        $this->asToken($memberToken)->deleteJson("/api/clients/{$acme->id}")->assertForbidden()->assertJsonPath('code', 'not_allowed');
        expect($acme->fresh())->not->toBeNull();

        $foreign = Client::factory()->create(['user_id' => User::factory()]);
        $this->asToken($this->token)->deleteJson("/api/clients/{$foreign->id}")->assertNotFound()->assertJsonPath('code', 'client_not_found');
        expect($foreign->fresh())->not->toBeNull();

        $this->deleteJson("/api/clients/{$acme->id}")->assertOk();
        $this->deleteJson("/api/clients/{$acme->id}")->assertNotFound()->assertJsonPath('code', 'client_not_found');
    });
});

describe('smart folders', function () {
    it('lists each smart folder and counts them like the library lists them', function () {
        $acme = project($this, 'Acme');
        $inWhatsApp = recordingIn($this, null, ['source_app' => SourceApp::WhatsApp]);
        $whatsAppWeb = recordingIn($this, $acme, ['source_app' => SourceApp::WhatsAppWeb]);
        $business = recordingIn($this, null, ['source_app' => SourceApp::WhatsAppBusiness]);
        $meet = recordingIn($this, $acme, ['source_app' => SourceApp::GoogleMeet]);
        $zoom = recordingIn($this, null, ['source_app' => SourceApp::Zoom]);
        $teams = recordingIn($this, null, ['source_app' => SourceApp::MicrosoftTeams]);
        $sim = recordingIn($this, null, ['source_app' => SourceApp::PhoneCall]);
        $voice = recordingIn($this, null, ['source_app' => SourceApp::VoiceNote]);
        $imported = recordingIn($this, null, ['source_app' => SourceApp::VoiceNote, 'origin' => CaptureSession::ORIGIN_IMPORTED]);
        $requested = recordingIn($this, $acme, ['source_app' => SourceApp::VoiceNote, 'origin' => CaptureSession::ORIGIN_REQUESTED]);
        recordingIn($this, null, ['source_app' => SourceApp::Zoom, 'status' => SessionStatus::Recording]); // still recording: nowhere yet

        $expected = [
            'inbox' => [$inWhatsApp, $business, $zoom, $teams, $sim, $voice, $imported],
            'whatsapp' => [$inWhatsApp, $whatsAppWeb],
            'whatsapp_business' => [$business],
            'google_meet' => [$meet],
            'zoom' => [$zoom],
            'teams' => [$teams],
            'phone' => [$sim],
            'voice_notes' => [$voice],
            'imported' => [$imported, $requested],
            'shared' => [],
        ];
        $counts = $this->getJson('/api/library/views')->assertOk()->json('folders');
        expect(array_keys($counts))->toBe(array_keys($expected));
        foreach ($expected as $folder => $sessions) {
            $listed = array_column($this->getJson("/api/library?folder={$folder}")->assertOk()->json('data'), 'id');
            expect($listed)->toEqualCanonicalizing(array_map(fn (CaptureSession $s): string => $s->id, $sessions), $folder)
                ->and($counts[$folder])->toBe(count($sessions), $folder);
        }
        $this->getJson('/api/library?folder=elsewhere')->assertStatus(422);
    });

    it('lists a project with its subfolders only when asked', function () {
        $acme = project($this, 'Acme');
        $site = project($this, 'Site', $acme);
        $top = recordingIn($this, $acme);
        $nested = recordingIn($this, $site);
        recordingIn($this, null);

        expect(array_column($this->getJson("/api/library?client_id={$acme->id}")->json('data'), 'id'))->toBe([$top->id])
            ->and(array_column($this->getJson("/api/library?client_id={$acme->id}&include_subfolders=1")->json('data'), 'id'))
            ->toEqualCanonicalizing([$top->id, $nested->id]);
        $foreign = Client::factory()->create(['user_id' => User::factory()]);
        expect($this->getJson("/api/library?client_id={$foreign->id}&include_subfolders=1")->json('data'))->toBe([]);
    });

    it('puts imports from a device and console uploads in Imported, voice notes stay in Voice notes', function () {
        $import = $this->createSession(Fixtures::audio(2000), ['source_app' => 'voice_note', 'skip_duplicate' => true])->assertCreated()->json('session.id');
        $note = $this->createSession(Fixtures::audio(2100), ['source_app' => 'voice_note'])->assertCreated()->json('session.id');

        expect(CaptureSession::query()->find($import)->origin)->toBe(CaptureSession::ORIGIN_IMPORTED)
            ->and(CaptureSession::query()->find($note)->origin)->toBeNull();
        $views = $this->getJson('/api/library/views')->json('folders');
        expect($views['imported'])->toBe(1)->and($views['voice_notes'])->toBe(1);
    });
});

describe('filed_by', function () {
    it('says how a recording got into its project', function () {
        $acme = project($this, 'Acme');
        ClientContact::query()->create(['client_id' => $acme->id, 'name' => 'Omar Khan', 'phone' => '+92 300 1234567']);

        $chosen = $this->createSession(Fixtures::audio(2000), ['client_id' => $acme->id])->assertCreated();
        expect($chosen->json('session.filed_by'))->toBe('user');
        $matched = $this->createSession(Fixtures::audio(2100), ['caller' => ['name' => 'Someone', 'number' => '03001234567', 'source' => 'phone']])->assertCreated();
        expect($matched->json('session.client.id'))->toBe($acme->id)->and($matched->json('session.filed_by'))->toBe('contact');
        $inbox = $this->createSession(Fixtures::audio(2200))->assertCreated();
        expect($inbox->json('session.filed_by'))->toBeNull();

        // Moving by hand is the person's choice; moving out puts it in the Inbox.
        $id = $inbox->json('session.id');
        $this->patchJson("/api/sessions/{$id}", ['client_id' => $acme->id])->assertOk()->assertJsonPath('data.filed_by', 'user');
        $this->postJson('/api/sessions/bulk', ['ids' => [$id], 'action' => 'move', 'client_id' => null])->assertOk();
        expect(CaptureSession::query()->find($id)->filed_by)->toBeNull();
    });
});
