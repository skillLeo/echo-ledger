<?php

declare(strict_types=1);

use App\Enums\SourceApp;
use App\Filament\Pages\Activity;
use App\Filament\Pages\RequestLinksPage;
use App\Filament\Pages\SharedWithMe;
use App\Filament\Pages\Shares;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\FilingRule;
use App\Models\RequestLink;
use App\Models\SentItem;
use App\Models\ShareLink;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use App\Services\ShareLinks;
use App\Services\Sharing\Teammates;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\Testing\TestAction;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/*
 * The console's v1.5 Projects and sharing (plan item G): the Projects tree, a project's page (breadcrumbs, subfolders,
 * search and sort, rename, move, delete to the Inbox, filing rules), the Library's smart folders, the share dialog
 * with audiences, the Shares page, Request links and Shared with me.
 */

beforeEach(function () {
    $this->isolatedStorage();
    $this->admin = $this->signInAsAdmin(['name' => 'Sam Admin']);
});

function adminProject(User $owner, string $name, ?Client $parent = null): Client
{
    return Client::factory()->create(['user_id' => $owner->id, 'name' => $name, 'parent_id' => $parent?->id]);
}

describe('the Projects tree', function () {
    it('lists the top level, and every level when searching, with each subfolder\'s folder', function () {
        $acme = adminProject($this->admin, 'Acme');
        adminProject($this->admin, 'Website', $acme);

        $this->panelComponent(ListClients::class)->assertCanSeeTableRecords([$acme])->assertCanNotSeeTableRecords([Client::query()->where('name', 'Website')->sole()])
            ->assertSee('1 subfolder')
            ->searchTable('web')->assertCanSeeTableRecords([Client::query()->where('name', 'Website')->sole()])->assertSee('In Acme');
        $this->get('/admin/clients')->assertOk()->assertSee('Projects');
    });

    it('makes a subfolder, keeps names unique per folder and refuses a fifth level', function () {
        $one = adminProject($this->admin, 'One');
        $two = adminProject($this->admin, 'Two', $one);
        $three = adminProject($this->admin, 'Three', $two);
        $four = adminProject($this->admin, 'Four', $three);

        $this->panelComponent(CreateClient::class)->fillForm(['name' => 'Notes', 'parent_id' => $one->id])->call('create')->assertHasNoFormErrors();
        expect(Client::query()->where('name', 'Notes')->sole()->parent_id)->toBe($one->id);
        $this->panelComponent(CreateClient::class)->fillForm(['name' => 'notes', 'parent_id' => $one->id])->call('create')->assertHasFormErrors(['name']);
        $this->panelComponent(CreateClient::class)->fillForm(['name' => 'Five', 'parent_id' => $four->id])->call('create')->assertHasFormErrors(['parent_id']);
        // A subfolder link from the project page fills the folder in.
        $this->get('/admin/clients/create?parent='.$one->id)->assertOk();
    });
});

describe('a project\'s page', function () {
    it('shows its folders above it, its subfolders and searches and sorts its recordings', function () {
        $acme = adminProject($this->admin, 'Acme');
        $site = adminProject($this->admin, 'Website', $acme);
        adminProject($this->admin, 'Pages', $site);
        $kickoff = $this->sessionWithTranscript($this->admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'We talk about invoices']],
            ['client_id' => $site->id, 'title' => 'Kick-off', 'started_at' => now()->subDays(2), 'duration_ms' => 60_000]);
        $review = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'client_id' => $site->id, 'title' => 'Design review', 'started_at' => now()->subDay(), 'duration_ms' => 600_000]);

        $page = $this->panelComponent(ViewClient::class, ['record' => $site->id])->call('setTab', 'recordings');
        expect(array_values($page->instance()->getBreadcrumbs()))->toBe(['Projects', 'Acme', 'Website']);
        $page->assertSee('Subfolders')->assertSee('Pages')->assertSeeInOrder(['Design review', 'Kick-off'])
            ->set('sort', 'oldest')->assertSeeInOrder(['Kick-off', 'Design review'])
            ->set('search', 'invoices')->assertSee('Kick-off')->assertDontSee('Design review')
            ->set('search', 'design')->assertSee('Design review')->assertDontSee('Kick-off');
        expect($kickoff->id)->not->toBe($review->id);
    });

    it('renames and moves a project within the rules of the tree', function () {
        $acme = adminProject($this->admin, 'Acme');
        $beta = adminProject($this->admin, 'Beta');
        $site = adminProject($this->admin, 'Website', $acme);

        $this->panelComponent(ViewClient::class, ['record' => $beta->id])->callAction('renameProject', data: ['name' => 'acme']);
        expect($beta->fresh()->name)->toBe('Beta');
        $this->panelComponent(ViewClient::class, ['record' => $beta->id])->callAction('renameProject', data: ['name' => 'Beta Ltd'])->assertHasNoActionErrors();
        expect($beta->fresh()->name)->toBe('Beta Ltd');

        $this->panelComponent(ViewClient::class, ['record' => $acme->id])->callAction('moveProject', data: ['parent_id' => $site->id]);
        expect($acme->fresh()->parent_id)->toBeNull();
        $this->panelComponent(ViewClient::class, ['record' => $site->id])->callAction('moveProject', data: ['parent_id' => $beta->id]);
        expect($site->fresh()->parent_id)->toBe($beta->id);
    });

    it('deletes a project after saying its recordings go to the Inbox, for owners only', function () {
        $acme = adminProject($this->admin, 'Acme');
        $call = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'client_id' => $acme->id]);

        $page = $this->panelComponent(ViewClient::class, ['record' => $acme->id])->mountAction('deleteProject');
        expect($this->modalHtml($page))->toContain('Its recordings are not deleted: they go to the Inbox.');
        $page->callMountedAction()->assertRedirect(ListClients::getUrl());
        expect(Client::query()->find($acme->id))->toBeNull()->and($call->fresh()->client_id)->toBeNull();

        $member = withoutWorkspace(User::factory()->create());
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($this->admin->current_workspace_id), $member);
        $beta = adminProject($this->admin, 'Beta');
        $this->actingAs($member->refresh(), 'web');
        $this->panelComponent(ViewClient::class, ['record' => $beta->id])->assertActionHidden('deleteProject');
        $this->panelComponent(ListClients::class)->assertTableActionHidden('deleteProject', $beta);
    });

    it('adds and removes filing rules, and says when another project has one', function () {
        $acme = adminProject($this->admin, 'Acme');
        $beta = adminProject($this->admin, 'Beta');

        $page = $this->panelComponent(ViewClient::class, ['record' => $acme->id])->call('setTab', 'rules')
            ->set('ruleKind', 'title')->set('ruleValue', 'Weekly sync')->call('addRule')->assertHasNoErrors()
            ->set('ruleKind', 'app')->set('ruleValue', 'zoom')->call('addRule')->assertHasNoErrors()
            ->assertSee('Weekly sync')->assertSee('Zoom');
        $this->panelComponent(ViewClient::class, ['record' => $beta->id])->call('setTab', 'rules')
            ->set('ruleKind', 'title')->set('ruleValue', 'weekly SYNC')->call('addRule')->assertHasErrors(['ruleValue']);
        $page->call('removeRule', FilingRule::query()->where('kind', 'title')->value('id'));
        expect(FilingRule::query()->pluck('kind')->all())->toBe(['app']);
        // Removing a contact rule says that the contact it added goes with it.
        $page->set('ruleKind', 'contact')->set('ruleValue', '+92 300 1234567')->call('addRule')->assertHasNoErrors()
            ->assertSee('Remove this rule and the contact it added?');
    });
});

describe('the Library\'s smart folders', function () {
    it('narrows the list to a folder and counts each one', function () {
        $acme = adminProject($this->admin, 'Acme');
        $inbox = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'source_app' => SourceApp::Zoom, 'title' => 'Zoom with Ana']);
        $filed = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'client_id' => $acme->id, 'source_app' => SourceApp::WhatsApp]);

        $list = $this->panelComponent(ListCaptureSessions::class);
        $folders = $list->instance()->folders();
        expect($folders['inbox']['count'])->toBe(1)->and($folders['zoom']['count'])->toBe(1)->and($folders['whatsapp']['count'])->toBe(1)
            ->and($folders['shared']['url'])->toBe(SharedWithMe::getUrl());
        $list->assertSee('Inbox')->call('setFolder', 'inbox')->assertCanSeeTableRecords([$inbox])->assertCanNotSeeTableRecords([$filed])
            ->call('setFolder', 'whatsapp')->assertCanSeeTableRecords([$filed])->assertCanNotSeeTableRecords([$inbox])
            ->call('setFolder', 'whatsapp')->assertCanSeeTableRecords([$filed, $inbox]);
    });
});

describe('sharing in the console', function () {
    it('makes a people-only link from the recording page and a project link from the project page', function () {
        $session = $this->sessionWithTranscript($this->admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello']]);
        $this->readyRecording($session);

        $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
            ->callAction('share', data: ['audience' => 'people', 'people' => ['ana@example.com'], 'expires' => 'never', 'allow_download' => true])
            ->assertHasNoActionErrors();
        $link = ShareLink::query()->sole();
        expect($link)->audience->toBe('people')->people->toBe(['ana@example.com'])->expires_at->toBeNull()->allow_download->toBeTrue()
            ->created_by->toBe('Sam Admin');

        $acme = adminProject($this->admin, 'Acme');
        $this->panelComponent(ViewClient::class, ['record' => $acme->id])->callAction('share', data: ['audience' => 'public', 'expires' => '86400'])->assertHasNoActionErrors();
        expect(ShareLink::query()->where('client_id', $acme->id)->sole()->kind())->toBe('project');
    });

    it('lists the workspace\'s links on Shares, revokes one, and shows no other workspace\'s', function () {
        $session = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'title' => 'Mine to share']);
        ['link' => $mine] = app(ShareLinks::class)->create($session, 3600, null, false, 'Sam');
        $other = User::factory()->create();
        $theirs = CaptureSession::factory()->create(['user_id' => $other->id, 'title' => 'NOT-MINE-TITLE']);
        ['link' => $foreign] = app(ShareLinks::class)->create($theirs, 3600, null, false, 'Other');

        $this->panelComponent(Shares::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$foreign])
            ->assertSee('Mine to share')->assertDontSee('NOT-MINE-TITLE')
            ->callTableAction('revoke', $mine);
        expect($mine->fresh()->revoked_at)->not->toBeNull()->and($foreign->fresh()->revoked_at)->toBeNull();
        $this->get(Shares::getUrl())->assertOk();
    });

    it('makes a request link shown once, lists it and revokes it', function () {
        $acme = adminProject($this->admin, 'Acme');

        $this->panelComponent(RequestLinksPage::class)
            ->callAction('create', data: ['title' => 'Send me the drafts', 'client_id' => $acme->id, 'expires' => '604800', 'max_files' => 5, 'max_mb' => 50])
            ->assertHasNoActionErrors()->assertNotified('Request link ready. Copy it now: it is shown only once.');
        $link = RequestLink::query()->sole();
        expect($link)->title->toBe('Send me the drafts')->client_id->toBe($acme->id)->max_files->toBe(5)->max_mb->toBe(50)->user_id->toBe($this->admin->id);

        $this->panelComponent(RequestLinksPage::class)->assertCanSeeTableRecords([$link])->assertSee('0 of 5')->callTableAction('revoke', $link);
        expect($link->fresh()->revoked_at)->not->toBeNull();
        $this->get(RequestLinksPage::getUrl())->assertOk();
    });

    it('saves what a teammate sent into the library, and removes it from the list', function () {
        $ana = User::factory()->create(['name' => 'Ana']);
        $call = $this->sessionWithTranscript($ana, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Plans for May']], ['title' => 'Call with Omar']);
        $this->readyRecording($call);
        $item = SentItem::query()->create(['workspace_id' => $call->workspace_id, 'from_user_id' => $ana->id, 'to_user_id' => $this->admin->id,
            'kind' => 'session', 'capture_session_id' => $call->id, 'title' => 'Call with Omar', 'note' => 'for tomorrow']);

        $page = $this->panelComponent(SharedWithMe::class)->assertCanSeeTableRecords([$item])->assertSee('for tomorrow');
        $page->mountTableAction('open', $item);
        expect($this->modalHtml($page))->toContain('Plans for May');
        $this->panelComponent(SharedWithMe::class)->callTableAction('save', $item)->assertNotified('Saved to your library');
        $copy = CaptureSession::query()->where('workspace_id', $this->admin->current_workspace_id)->sole();
        expect($copy->title)->toBe('Call with Omar')->and($copy->origin)->toBe('saved');

        $this->panelComponent(SharedWithMe::class)->callTableAction('remove', $item);
        expect(SentItem::query()->count())->toBe(0);
    });

    it('opens what a teammate sent from Activity in Shared with me, and no recording page of another workspace', function () {
        $ana = User::factory()->create(['name' => 'Ana']);
        $call = $this->sessionWithTranscript($ana, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Plans for May']], ['title' => 'Call with Omar']);
        $folder = adminProject($ana, 'Acme');
        app(Teammates::class)->send($ana, $call, [$this->admin->email], null);
        app(Teammates::class)->send($ana, $folder, [$this->admin->email], null);
        $notice = fn (string $title): string => (string) $this->admin->notifications()->where('data->title', $title)->value('id');

        $this->panelComponent(Activity::class)->call('open', $notice('Ana sent you Call with Omar'))->assertRedirect(SharedWithMe::getUrl());
        $this->panelComponent(Activity::class)->call('open', $notice('Ana sent you Acme'))->assertRedirect(SharedWithMe::getUrl());
        expect($this->admin->unreadNotifications()->where('data->title', 'like', 'Ana sent you%')->count())->toBe(0);

        // A recording that is no longer in this workspace's library says so instead of opening a missing page.
        $gone = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'title' => 'Old call']);
        app(ActivityFeed::class)->post($this->admin, 'transcript_ready', 'Transcript ready', 'Old call', $gone, 'heroicon-o-document-text');
        $gone->delete();
        $this->panelComponent(Activity::class)->call('open', $notice('Transcript ready'))->assertNoRedirect()
            ->assertNotified('This recording is no longer in this workspace\'s library.');
    });
});

it('keeps the Projects resource free of the old Delete action', function () {
    $acme = adminProject($this->admin, 'Acme');

    expect(fn () => $this->panelComponent(ListClients::class)->callAction(TestAction::make('delete')->table($acme)))->toThrow(Exception::class);
    expect($acme->fresh())->not->toBeNull();
});
