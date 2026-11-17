<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Pages\LiveAssist\AssistPreps;
use App\Filament\Pages\LiveAssist\AssistReportPage;
use App\Filament\Pages\LiveAssist\AssistSessions;
use App\Http\Controllers\Api\Assist\AssistSessionController;
use App\Jobs\MakeAssistReportJob;
use App\Models\AssistPrep;
use App\Models\AssistSession;
use App\Models\AssistTurn;
use App\Models\Client;
use App\Models\ClientFile;
use App\Models\Device;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Assist\AssistRecords;
use App\Services\InterfaceFlags;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/assist_helpers.php';

uses(ApiClient::class);

/*
 * Live Assist in the console (v1.9 L8): Preps, Sessions and a session's report, only for a person the live_assist
 * flag is on for, each person's own only; the small read-only console reads them and changes nothing.
 */

beforeEach(function () {
    assistRelaySetUp();
    Filament::setCurrentPanel('app');
});

/** Signs in the console as [$device]'s person. */
function consoleAs(Device $device): User
{
    $user = $device->user->fresh();
    test()->actingAs($user, 'web');

    return $user;
}

/** An ended session of [$device]'s person with a ready report (made with the fake model). */
function reportedSession(Device $device, bool $practice = false): AssistSession
{
    fakeAssistReportAi();
    $session = AssistSession::query()->create(['workspace_id' => $device->workspace_id, 'user_id' => $device->user_id, 'device_id' => $device->id,
        'mode' => 'fast', 'practice' => $practice, 'platform' => 'android', 'status' => 'active', 'started_at' => now()->subMinutes(10), 'summary' => 'Running summary.']);
    AssistTurn::query()->create(['assist_session_id' => $session->id, 'client_ref' => 'pq-1', 'channel' => 'other', 'start_ms' => 0, 'end_ms' => 2000, 'text' => 'Why this job?', 'is_question' => true]);
    AssistTurn::query()->create(['assist_session_id' => $session->id, 'client_ref' => 'm-1', 'channel' => 'me', 'start_ms' => 2500, 'end_ms' => 9000, 'text' => 'Um I like Laravel.']);
    AssistSessionController::close($session, now());
    (new MakeAssistReportJob($session->id))->handle();

    return $session->fresh();
}

describe('who sees it', function () {
    it('is not there for a person the flag is off for: no pages, no sidebar item', function () {
        consoleAs(assistPerson('pro', flag: false));

        $this->get(AssistSessions::getUrl())->assertForbidden();
        $this->get(AssistPreps::getUrl())->assertForbidden();
        expect((string) $this->get('/app')->getContent())->not->toContain('Live Assist');
    });

    it('is in the sidebar and opens for a person the flag is on for', function () {
        consoleAs(assistPerson('pro'));

        $html = (string) $this->get(AssistSessions::getUrl())->assertOk()->assertSee('Practice: 5 h 0 min left')->assertSee('Live Assist: 5 h 0 min left')->getContent();
        expect($html)->toContain('Live Assist')->toContain(AssistPreps::getUrl());
        $this->get(AssistPreps::getUrl())->assertOk()->assertSee('No preps yet');
    });

    it('says Free has Practice only', function () {
        consoleAs(assistPerson('free'));

        $this->get(AssistSessions::getUrl())->assertOk()->assertSee('Live Assist in calls is not in your plan')->assertSee('Practice: 30 min left');
    });
});

describe('preps', function () {
    it('makes, schedules, edits, cancels and deletes a prep, with the person\'s own project and its documents only', function () {
        $device = assistPerson('pro');
        $user = consoleAs($device);
        $project = Fx::client($user, 'Acme');
        $jd = ClientFile::query()->create(['workspace_id' => $device->workspace_id, 'user_id' => $user->id, 'client_id' => $project->id,
            'name' => 'JD.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10, 'status' => 'ready', 'text' => str_repeat('a', 400)]);
        $theirs = Fx::client(Fx::user(), 'THEIR-PROJECT');

        Livewire::test(AssistPreps::class)
            ->assertActionVisible('create')
            ->callAction('create', ['meeting_type' => 'interview', 'title' => 'Interview at Acme', 'counterpart' => 'Sara', 'client_id' => $project->id,
                'file_ids' => [$jd->id], 'goals' => 'Get the offer', 'mode' => 'balanced', 'hint_level' => 'outline', 'language_help' => 'roman_urdu',
                'scheduled_at' => '2026-12-01 10:00:00'])
            ->assertHasNoActionErrors()->assertNotified('Prep saved');
        $prep = AssistPrep::query()->withoutGlobalScopes()->sole();
        expect($prep)->toMatchArray(['meeting_type' => 'interview', 'title' => 'Interview at Acme', 'client_id' => $project->id, 'status' => 'scheduled',
            'language_help' => 'roman_urdu', 'user_id' => $user->id, 'workspace_id' => $device->workspace_id])
            ->and($prep->documents->pluck('id')->all())->toBe([$jd->id])
            ->and((int) $prep->documents->first()->pivot->tokens)->toBe(100);
        // The same prep the apps read.
        $this->api(Fx::token($device))->getJson("/api/assist/preps/{$prep->id}")->assertJsonPath('documents.0.file_id', $jd->id)->assertJsonPath('status', 'scheduled');
        consoleAs($device);

        // Someone else's project, sent anyway: refused, nothing changes.
        Livewire::test(AssistPreps::class)->callAction(TestAction::make('edit')->table($prep), ['client_id' => $theirs->id])->assertHasActionErrors(['client_id']);
        expect($prep->fresh()->client_id)->toBe($project->id);
        // Without a time it is a draft again; a field left empty is cleared.
        Livewire::test(AssistPreps::class)->callAction(TestAction::make('edit')->table($prep), ['client_id' => null, 'file_ids' => [], 'title' => '',
            'mode' => 'fast', 'hint_level' => 'keywords', 'scheduled_at' => null])->assertHasNoActionErrors();
        expect($prep->fresh())->toMatchArray(['client_id' => null, 'status' => 'draft', 'mode' => 'fast'])->and($prep->fresh()->title)->toBeNull()
            ->and($prep->fresh()->documents)->toHaveCount(0)->and($prep->fresh()->goals)->toBe('Get the offer');

        $prep->refresh()->forceFill(['status' => 'scheduled', 'scheduled_at' => now()->addDay()])->save();
        Livewire::test(AssistPreps::class)->callAction(TestAction::make('cancel')->table($prep));
        expect($prep->fresh()->status)->toBe('cancelled');
        Livewire::test(AssistPreps::class)->set('tableFilters.shown.value', 'all')->callAction(TestAction::make('delete')->table($prep));
        expect(AssistPrep::query()->withoutGlobalScopes()->withTrashed()->find($prep->id))->trashed()->toBeTrue()
            ->and(AssistPrep::query()->withoutGlobalScopes()->withTrashed()->find($prep->id)->goals)->toBeNull();
    });

    it('lists only the person\'s own preps and offers only their own projects', function () {
        $device = assistPerson('pro');
        $user = consoleAs($device);
        $teammate = Fx::user();
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($device->workspace_id), $teammate);
        $theirs = Client::query()->withoutGlobalScopes()->create(['workspace_id' => $device->workspace_id, 'user_id' => $teammate->id, 'name' => 'TEAMMATE-PROJECT', 'color' => '#8B5CF6']);
        AssistPrep::query()->withoutGlobalScopes()->create(['workspace_id' => $device->workspace_id, 'user_id' => $teammate->id, 'meeting_type' => 'boss',
            'title' => 'TEAMMATE-PREP', 'hint_level' => 'full', 'mode' => 'fast', 'language_help' => 'off', 'status' => 'draft']);
        Fx::client($user, 'MY-PROJECT');

        $page = Livewire::test(AssistPreps::class)->assertDontSee('TEAMMATE-PREP')->assertCanNotSeeTableRecords(AssistPrep::query()->withoutGlobalScopes()->get());
        // The project list holds the person's own projects only; a teammate's id, sent anyway, is not kept.
        $page->mountAction('create');
        $options = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->getFlatFields()['client_id']->getOptions();
        expect(array_values($options))->toBe(['MY-PROJECT']);
        $page->call('unmountAction');
        Livewire::test(AssistPreps::class)->callAction('create', ['meeting_type' => 'boss', 'client_id' => $theirs->id])->assertHasActionErrors(['client_id']);
        expect(AssistPrep::query()->withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(0);
    });

    it('lets the small read-only console read preps and change nothing', function () {
        $owner = assistPerson('pro');
        $viewer = Fx::user();
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($owner->workspace_id), $viewer, WorkspaceRole::Viewer);
        app(Workspaces::class)->switchTo($viewer, Workspace::query()->findOrFail($owner->workspace_id));
        app(InterfaceFlags::class)->set('live_assist', false, 0, [], [$owner->user_id, $viewer->id]);
        AssistPrep::query()->withoutGlobalScopes()->create(['workspace_id' => $owner->workspace_id, 'user_id' => $viewer->id, 'meeting_type' => 'boss',
            'title' => 'My own prep', 'hint_level' => 'full', 'mode' => 'fast', 'language_help' => 'off', 'status' => 'draft']);
        $this->actingAs($viewer->fresh(), 'web');

        Livewire::test(AssistPreps::class)->assertSee('My own prep')->assertActionHidden('create')
            ->assertActionHidden(TestAction::make('delete')->table(AssistPrep::query()->withoutGlobalScopes()->sole()));
    });
});

describe('sessions and reports', function () {
    it('lists the person\'s own sessions and opens a report: summary, Q&A, coaching, email', function () {
        $device = assistPerson('pro');
        consoleAs($device);
        $mine = reportedSession($device);
        $other = reportedSession(assistPerson('pro'));
        consoleAs($device);

        Livewire::test(AssistSessions::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$other]);
        $this->get(AssistReportPage::getUrl(['session' => $mine->id]))->assertOk()
            ->assertSee('Live Assist report')->assertSee('They asked about your notice period and salary.')
            ->assertSee('Why this job?')->assertSee('Um I like Laravel.')->assertSee('Good')
            ->assertSee('Words per minute')->assertSee('Thank you for your time today.')->assertSee('Send my portfolio by Friday');
        // Anyone else's session is the same 404 as an unknown one.
        $this->get(AssistReportPage::getUrl(['session' => $other->id]))->assertNotFound();
        $this->get(AssistReportPage::getUrl(['session' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']))->assertNotFound();
    });

    it('says a report is being made, and that a running session has none yet', function () {
        $device = assistPerson('pro');
        consoleAs($device);
        $running = AssistSession::query()->create(['workspace_id' => $device->workspace_id, 'user_id' => $device->user_id, 'mode' => 'fast', 'practice' => true,
            'platform' => 'android', 'status' => 'active', 'started_at' => now()]);
        $this->get(AssistReportPage::getUrl(['session' => $running->id]))->assertOk()->assertSee('This session is on now.');
        $running->forceFill(['status' => 'ended', 'ended_at' => now(), 'report_status' => 'queued'])->save();
        $this->get(AssistReportPage::getUrl(['session' => $running->id]))->assertOk()->assertSee('The report is being made.')->assertSee('wire:poll.5s', false);
    });

    it('downloads the report as Markdown and deletes the session with every word of it', function () {
        $device = assistPerson('pro');
        consoleAs($device);
        $session = reportedSession($device);
        consoleAs($device);

        $page = Livewire::test(AssistReportPage::class, ['session' => $session->id])->callAction('download');
        $markdown = $page->effects['download']['content'] ?? '';
        expect(base64_decode($markdown))->toContain('## Questions and answers')->toContain('| 1 | Why this job? | Um I like Laravel. |')->toContain('## Follow-up email');

        $page->callAction('delete')->assertRedirect(AssistSessions::getUrl());
        expect(AssistSession::query()->withoutGlobalScopes()->withTrashed()->find($session->id))->trashed()->toBeTrue()
            ->and(AssistTurn::query()->where('assist_session_id', $session->id)->count())->toBe(0)
            ->and(AssistSession::query()->withoutGlobalScopes()->withTrashed()->find($session->id)->report)->toBeNull();
    });
});

it('writes a hostile report as inert Markdown: no HTML, no link, no image, no cell of its own', function () {
    $evil = [
        'version' => 1, 'practice' => false, 'meeting_type' => 'interview', 'title' => '<script>alert(1)</script> # Title', 'started_at' => '2026-11-17T10:00:00+00:00', 'seconds' => 60,
        'summary' => "Line one <img src=x onerror=alert(1)>\n\n- not a list\n1. not a number\n> not a quote\n![t](https://track.example/p.png)",
        'qa' => [['index' => 1, 'question' => 'Click [here](javascript:alert(1))', 'answer' => 'ends with a backslash\\', 'hint' => ['text' => '`code` **bold** ~~gone~~ _i_'],
            'verdict' => 'weak', 'better_answer' => 'A | B']],
        'mistakes' => [['said' => '<b>x</b>', 'fact' => '&amp; &#60;']], 'promises' => ['+ plus'], 'action_items' => ['#hash'],
        'coaching' => ['grammar' => [['said' => '[a](b)', 'better' => '<i>', 'why' => 'x']]],
        'follow_up_email' => ['subject' => '![s](https://t.example/s.png)', 'body' => "Hi\n<a href=\"https://evil.example\">x</a>"], 'facts' => ['<iframe>'],
    ];
    $md = AssistRecords::markdown($evil, 'UTC');

    // No tag, no entity of its own, no link or image syntax that a Markdown reader would act on.
    expect($md)->not->toMatch('/<(?!\/)/')->not->toContain('<script')->not->toContain('<img')->not->toContain('<a ')->not->toContain('<iframe')
        ->not->toMatch('/(?<!\\\\)!(?<!\\\\)\[/')->not->toMatch('/(?<!\\\\)\]\(/')->not->toContain('&#60;')
        ->toContain('# &lt;script\>alert\(1\)&lt;/script\> \# Title')
        ->toContain('Click \[here\]\(javascript:alert\(1\)\)')
        ->toContain('&amp;amp; &amp;\#60;')
        ->toContain("\\- not a list\n\n1\\. not a number\n\n\\> not a quote");
    // The table row keeps its six cells: the trailing backslash and the | inside a cell are escaped.
    $row = collect(explode("\n", $md))->first(fn (string $l): bool => str_starts_with($l, '| 1 |'));
    expect(preg_match_all('/(?<!\\\\)\|/', str_replace('\\\\', '', $row)))->toBe(7)
        ->and($row)->toContain('ends with a backslash\\\\ |')->toContain('A \| B')->toContain('\`code\` \*\*bold\*\* \~\~gone\~\~ \_i\_');
    expect(AssistRecords::md('\\'))->toBe('\\\\')->and(AssistRecords::md('a & <b>'))->toBe('a &amp; &lt;b\>');
});
